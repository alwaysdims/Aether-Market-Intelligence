import asyncio
import json
import logging
import os
import signal
import subprocess
import sys
import time
import random
import uuid
from typing import Dict, List, Optional
import websockets
from websockets.exceptions import ConnectionClosedError, ConnectionClosedOK

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("IdxEdgeWsSupervisor")

WS_URL = "wss://stock.arjum.com/ws/running-trade"
STATE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../dashboard/storage/app/running_trade_state.json")
PID_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ws_supervisor.pid")
LOCK_FILE = PID_FILE + ".lock"
RAW_EVIDENCE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../logs/ws_running_trade_raw.jsonl")
RAW_EVIDENCE_MAX_BYTES = 50 * 1024 * 1024
SHADOW_EVENT_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../logs/ws_shadow_events.jsonl")
SHADOW_EVENT_MAX_BYTES = 10 * 1024 * 1024
SHADOW_NOTIFICATION_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../logs/ws_shadow_notification_candidates.jsonl")
SHADOW_NOTIFICATION_MAX_BYTES = 10 * 1024 * 1024
SHADOW_NOTIFICATION_COOLDOWN_SECONDS = 60
SHADOW_SUMMARY_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../logs/ws_shadow_summary.json")
SHADOW_LARGE_TRADE_VALUE_IDR = 100_000_000
_instance_lock = None

# Provider has not certified event-time semantics. Preserve raw values and expose this label.
EVENT_TIME_STATUS = "EVENT_TIME_UNVERIFIED"


def classify_frame_type(frame: dict) -> str:
    if not isinstance(frame, dict):
        return "non_object"
    return str(frame.get("type") or frame.get("action") or "unknown").lower()


def compute_value_idr(value, price, lot, lot_size=100):
    if value is not None:
        return float(value)
    return float(price or 0) * float(lot or 0) * float(lot_size)


def is_shadow_trade_candidate(frame_type: str, value_idr: float) -> bool:
    return frame_type == "trade" and float(value_idr or 0) >= SHADOW_LARGE_TRADE_VALUE_IDR


def is_duplicate_trade_number(trade_number: str, seen_trade_numbers: set) -> bool:
    return bool(trade_number) and trade_number in seen_trade_numbers


def record_reconnect_count(previous_count: int) -> int:
    return max(0, int(previous_count)) + 1


def connection_error_metadata(error: BaseException) -> dict:
    code = getattr(error, "code", None)
    error_type = type(error).__name__
    return {
        "error_type": error_type,
        "close_code": code,
        "handshake_timeout": error_type == "TimeoutError",
        "retryable": code not in (4401, 4403, 4408),
    }


def append_connection_event(state: dict, event: dict) -> None:
    events = state.setdefault("connection_events", [])
    events.append(event)
    del events[:-100]


def should_emit_shadow_notification(ticker: str, received_epoch: float, last_by_ticker: dict, cooldown_seconds: float) -> bool:
    previous = last_by_ticker.get(ticker)
    return previous is None or float(received_epoch) - float(previous) >= float(cooldown_seconds)


def event_time_metadata(event_time_raw, received_at_utc, provider_ts_raw) -> dict:
    return {
        "status": EVENT_TIME_STATUS,
        "event_time_raw": event_time_raw,
        "received_at_utc": received_at_utc,
        "provider_ts_raw": provider_ts_raw,
        "latency_claim": "NOT_CLAIMED",
    }


def run_artifact_paths(run_id: str) -> dict:
    safe_run_id = ''.join(char if char.isalnum() or char in ('_', '-') else '_' for char in run_id)
    log_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../logs")
    return {
        "summary": os.path.join(log_dir, f"ws_shadow_summary_{safe_run_id}.json"),
        "notification_candidates": os.path.join(log_dir, f"ws_shadow_notification_candidates_{safe_run_id}.jsonl"),
    }


def make_counter_state() -> dict:
    return {
        "dedup_scope": "RUN_SCOPED_BOUNDED_RING_BUFFER_TN",
        "ring_buffer_capacity": 2000,
        "seen_trade_keys": [],
        "run": {
            "successful_connection_count": 0,
            "reconnect_attempts": 0,
            "successful_reconnects": 0,
            "raw_frames": 0,
            "live_trade_items": 0,
            "unique_accepted_live_trade_items": 0,
            "snapshot_trade_items": 0,
            "duplicate_trade_items": 0,
            "ring_buffer_size": 0,
        },
        "connection": {
            "connection_id": None,
            "raw_frames": 0,
            "live_trade_items": 0,
            "unique_accepted_live_trade_items": 0,
            "snapshot_trade_items": 0,
            "duplicate_trade_items": 0,
            "ring_buffer_size": 0,
        },
    }


def begin_connection_counters(counter_state: dict, connection_id: str) -> None:
    run = counter_state["run"]
    run["successful_connection_count"] += 1
    if run["successful_connection_count"] > 1:
        run["successful_reconnects"] += 1
    counter_state["connection"] = {
        "connection_id": connection_id,
        "raw_frames": 0,
        "live_trade_items": 0,
        "unique_accepted_live_trade_items": 0,
        "snapshot_trade_items": 0,
        "duplicate_trade_items": 0,
        "ring_buffer_size": len(counter_state["seen_trade_keys"]),
    }


def record_snapshot_counters(counter_state: dict, item_count: int) -> None:
    count = max(0, int(item_count))
    counter_state["run"]["snapshot_trade_items"] += count
    counter_state["connection"]["snapshot_trade_items"] += count


def seed_snapshot_trade_numbers(counter_state: dict, trade_numbers: list[str]) -> None:
    for trade_number in trade_numbers:
        if not trade_number:
            continue
        key = f"tn_{trade_number}"
        if key not in counter_state["seen_trade_keys"]:
            counter_state["seen_trade_keys"].append(key)
    overflow = len(counter_state["seen_trade_keys"]) - counter_state["ring_buffer_capacity"]
    if overflow > 0:
        del counter_state["seen_trade_keys"][:overflow]
    counter_state["run"]["ring_buffer_size"] = len(counter_state["seen_trade_keys"])
    counter_state["connection"]["ring_buffer_size"] = len(counter_state["seen_trade_keys"])


def record_raw_frame_counter(counter_state: dict) -> None:
    counter_state["run"]["raw_frames"] += 1
    counter_state["connection"]["raw_frames"] += 1


def record_live_trade_counters(counter_state: dict, trade_number: str, accepted: bool, duplicate: bool = False) -> None:
    run = counter_state["run"]
    connection = counter_state["connection"]
    if duplicate:
        run["duplicate_trade_items"] += 1
        connection["duplicate_trade_items"] += 1
        return
    if not accepted:
        return
    run["live_trade_items"] += 1
    run["unique_accepted_live_trade_items"] += 1
    connection["live_trade_items"] += 1
    connection["unique_accepted_live_trade_items"] += 1
    if trade_number:
        key = f"tn_{trade_number}"
        if key not in counter_state["seen_trade_keys"]:
            counter_state["seen_trade_keys"].append(key)
            if len(counter_state["seen_trade_keys"]) > counter_state["ring_buffer_capacity"]:
                counter_state["seen_trade_keys"].pop(0)
    run["ring_buffer_size"] = len(counter_state["seen_trade_keys"])
    connection["ring_buffer_size"] = len(counter_state["seen_trade_keys"])


def _lock_instance_file(handle) -> None:
    handle.seek(0)
    handle.write("0")
    handle.flush()
    handle.seek(0)
    if sys.platform == "win32":
        import msvcrt
        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
    else:
        import fcntl
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)


def _unlock_instance_file(handle) -> None:
    handle.seek(0)
    if sys.platform == "win32":
        import msvcrt
        msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
    else:
        import fcntl
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _append_raw_evidence(raw_msg: str, received_at_utc: str, received_epoch_ns: int, session_date: str, run_id: str, connection_id: str) -> bool:
    try:
        os.makedirs(os.path.dirname(RAW_EVIDENCE_FILE), exist_ok=True)
        if os.path.exists(RAW_EVIDENCE_FILE) and os.path.getsize(RAW_EVIDENCE_FILE) >= RAW_EVIDENCE_MAX_BYTES:
            rotated = RAW_EVIDENCE_FILE + ".1"
            if os.path.exists(rotated):
                os.remove(rotated)
            os.replace(RAW_EVIDENCE_FILE, rotated)
        record = {
            "run_id": run_id,
            "connection_id": connection_id,
            "received_at_utc": received_at_utc,
            "received_epoch_ns": received_epoch_ns,
            "session_date": session_date,
            "raw_frame": raw_msg,
        }
        with open(RAW_EVIDENCE_FILE, "a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
            f.flush()
        return True
    except Exception as e:
        logger.warning("Raw evidence write failed: %s", type(e).__name__)
        return False


def _utc_now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def _append_shadow_event(event: dict) -> None:
    try:
        os.makedirs(os.path.dirname(SHADOW_EVENT_FILE), exist_ok=True)
        if os.path.exists(SHADOW_EVENT_FILE) and os.path.getsize(SHADOW_EVENT_FILE) >= SHADOW_EVENT_MAX_BYTES:
            rotated = SHADOW_EVENT_FILE + ".1"
            if os.path.exists(rotated):
                os.remove(rotated)
            os.replace(SHADOW_EVENT_FILE, rotated)
        with open(SHADOW_EVENT_FILE, "a", encoding="utf-8") as f:
            f.write(json.dumps(event, ensure_ascii=False) + "\n")
            f.flush()
    except Exception as e:
        logger.warning("Shadow event write failed: %s", type(e).__name__)


def _append_shadow_notification_candidate(candidate: dict, path: str = SHADOW_NOTIFICATION_FILE) -> None:
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        if os.path.exists(path) and os.path.getsize(path) >= SHADOW_NOTIFICATION_MAX_BYTES:
            rotated = path + ".1"
            if os.path.exists(rotated):
                os.remove(rotated)
            os.replace(path, rotated)
        with open(path, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(candidate, ensure_ascii=False) + "\n")
            handle.flush()
    except Exception as exc:
        logger.warning("Shadow notification candidate write failed: %s", type(exc).__name__)


def _write_shadow_summary(summary: dict, path: str = SHADOW_SUMMARY_FILE) -> None:
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        tmp_file = path + f".tmp.{os.getpid()}"
        with open(tmp_file, "w", encoding="utf-8") as handle:
            json.dump(summary, handle, ensure_ascii=False, indent=2)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_file, path)
    except Exception as exc:
        logger.warning("Shadow summary write failed: %s", type(exc).__name__)


def is_pid_running(pid: int) -> bool:
    if pid <= 0:
        return False
    if sys.platform == "win32":
        try:
            import ctypes
            kernel32 = ctypes.windll.kernel32
            SYNCHRONIZE = 0x00100000
            process = kernel32.OpenProcess(SYNCHRONIZE, False, pid)
            if process != 0:
                kernel32.CloseHandle(process)
                return True
            return False
        except Exception:
            return False
    else:
        try:
            os.kill(pid, 0)
            return True
        except (OSError, ProcessLookupError):
            return False

class IdxEdgeWsSupervisor:
    def __init__(self):
        self.api_key = self._resolve_api_key()
        self.running = True
        self.run_id = f"WSRUN_{time.strftime('%Y%m%d_%H%M%S')}_{uuid.uuid4().hex[:8]}"
        self.connection_id = None
        self.reconnect_delay = 2.0
        self.max_reconnect_delay = 30.0
        self.last_flush_time = 0.0
        self.today_date_str = time.strftime("%Y-%m-%d")
        self.run_artifacts = run_artifact_paths(self.run_id)

        # Load existing state to preserve yesterday's archive if present
        prev_archive = None
        if os.path.exists(STATE_FILE):
            try:
                with open(STATE_FILE, "r", encoding="utf-8") as f:
                    old_data = json.load(f)
                    old_date = old_data.get("session_date")
                    if old_date and old_date != self.today_date_str:
                        prev_archive = {
                            "session_date": old_date,
                            "last_trade_at": old_data.get("last_trade_at"),
                            "total_trades": old_data.get("total_trades", 0),
                            "session_coverage": old_data.get("session_coverage", {}),
                            "archived_at": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
                            "note": "Archived previous trading session. Never counted in today's session aggregates."
                        }
                    elif old_date == self.today_date_str:
                        prev_archive = old_data.get("previous_session_archive")
            except Exception as e:
                logger.warning(f"Could not load old state for archival check: {e}")

        # Initialize fresh state isolated strictly to today's session
        self.state = {
            "session_date": self.today_date_str,
            "session_id": f"SESSION_{self.today_date_str.replace('-', '')}",
            "run_id": self.run_id,
            "connection_id": None,
            "supervisor_pid": os.getpid(),
            "launcher_path": os.path.abspath(__file__),
            "run_artifacts": self.run_artifacts,
            "process_started_at": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
            "process_alive_last_observed_at": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
            "status": "DISCONNECTED",
            "connected_at": None,
            "socket_connected_at": None,
            "last_heartbeat_at": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
            "heartbeat_epoch": int(time.time()),
            "last_message_at": None,
            "last_raw_frame_at": None,
            "last_raw_frame_epoch_ns": None,
            "last_upstream_alive_at": None,
            "upstream_ping_latency_ms": None,
            "last_market_event_at": None,
            "last_trade_at": None,
            "connected_socket": False,
            "disconnected_at": None,
            "stopped_at": None,
            "total_messages": 0,
            "total_trades": 0,
            "total_trade_frames": 0,
            "total_snapshot_trade_items": 0,
            "total_filter_replays": 0,
            "shadow_events_emitted": 0,
            "shadow_notification_candidates": 0,
            "shadow_notification_suppressed_cooldown": 0,
            "shadow_summary": {
                "status": "OBSERVED_FEED_ACTIVITY_ONLY",
                "transactions_count": 0,
                "total_value_idr": 0.0,
                "tickers": {},
                "observed_from_received_at_utc": None,
                "observed_until_received_at_utc": None,
                "event_time_status": EVENT_TIME_STATUS,
                "market_total_claim": "NOT_CLAIMED",
                "net_buying_claim": "NOT_CLAIMED",
            },
            "shadow_notification_last_by_ticker": {},
            "total_top5": 0,
            "total_snapshots": 0,
            "total_other_messages": 0,
            "invalid_items": 0,
            "parse_errors": 0,
            "rejected_messages": 0,
            "accepted_messages": 0,
            "raw_frames_written": 0,
            "raw_frames_failed": 0,
            "flush_sequence": 0,
            "last_flush_at": None,
            "reconnect_count": 0,
            "reconnect_attempts": 0,
            "successful_connection_count": 0,
            "successful_reconnects": 0,
            "handshake_timeout_count": 0,
            "last_close_code": None,
            "last_error_details": None,
            "connection_events": [],
            "gap_count": 0,
            "counter_semantics": {
                "raw_frames": "RUN_AND_CONNECTION_SCOPED",
                "live_trade_items": "RUN_AND_CONNECTION_SCOPED",
                "unique_accepted_live_trade_items": "RUN_AND_CONNECTION_SCOPED_BOUNDED_TN_DEDUP",
                "snapshot_trade_items": "RUN_AND_CONNECTION_SCOPED_REPLAY_ONLY",
                "duplicate_trade_items": "RUN_AND_CONNECTION_SCOPED",
                "ring_buffer_size": "CURRENT_CONNECTION_VIEW",
                "total_trades_legacy": "COUNTER_UNVERIFIED_ACROSS_CONNECTIONS"
            },
            "counter_state": make_counter_state(),
            "board_scope": "BOARD_SCOPE_UNVERIFIED", # Default until proved otherwise by official contract
            "ihsg": {
                "available": False,
                "value": None,
                "timestamp": None,
                "source": "EOD_BASELINE"
            },
            "recent_trades": [], # Bounded ring buffer: max 50 items
            "seen_trade_keys": [], # Dedup tracker for snapshot and recent trades
            "dedup_stats": {
                "buffer_capacity": 2000,
                "duplicates_dropped": 0,
                "evicted_beyond_buffer": 0,
                "dedup_scope": "BOUNDED_RING_BUFFER_TN",
                "guarantee": "PARTIAL_BURST_PROTECTION_ONLY", # Replay >2000 items not guaranteed duplicate-free
                "evicted_note": "Counter mencatat rotasi ring buffer (kapasitas 2.000 tn), bukan paket hilang atau selisih transaksi unik"
            },
            "top5_snapshots": {}, # latest per ticker
            "session_coverage": {
                "session_date": self.today_date_str,
                "observed_from": None,
                "observed_until": None,
                "window_status": "PARTIAL_SESSION_UNTIL_PROVEN",
                "session_1_coverage": "PARTIAL_IF_STARTED_AFTER_SESSION_1_OPEN",
                "session_2_coverage": "PARTIAL_OR_ACTIVE_UNTIL_PROVEN",
                "observation_start_at": None,
                "observation_end_at": None,
                "gap": "NOT_ESTABLISHED",
                "last_event_time": None,
                "reconnect_count": 0,
                "trades_count": 0,
                "board_scope": "BOARD_SCOPE_UNVERIFIED",
                "board_rg_guaranteed": False,
                "has_official_trade_id": True,
                "has_event_timestamp": True,
                "raw_schema_verified": {
                    "a": "exchange_match_time (HH:MM:SS)",
                    "tn": "exchange_trade_number (string)",
                    "ts": "provider_ts_raw (float epoch from provider; not exchange matching engine certified)",
                    "p": "price_idr (float)",
                    "l": "volume_lots (float)",
                    "v": "transaction_value_idr (float)",
                    "pc": "provider_change_pct (float)",
                    "c": "provider_action (buy/sell raw flag)"
                }
            },
            "session_aggregates": {}, # Bounded per-ticker dictionary, max 1000 tickers
            "previous_session_archive": prev_archive,
            "last_error": None
        }
        self._flush_state(force=True)

    def _resolve_api_key(self) -> str:
        # 1. Environment variable langsung
        key = os.environ.get("IDX_EDGE_API_KEY", "").strip()
        if key:
            return key

        # 2. Baca dari dashboard/.env jika dijalankan langsung via python tanpa export env
        env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "../dashboard/.env")
        if os.path.exists(env_path):
            try:
                with open(env_path, "r", encoding="utf-8") as f:
                    for line in f:
                        line = line.strip()
                        if line.startswith("IDX_EDGE_API_KEY=") and not line.startswith("#"):
                            val = line.split("=", 1)[1].strip()
                            if (val.startswith('"') and val.endswith('"')) or (val.startswith("'") and val.endswith("'")):
                                val = val[1:-1]
                            if val:
                                return val
            except Exception as e:
                logger.warning(f"Could not read {env_path}: {e}")

        # 3. Fallback konfigurasi Laravel existing via artisan
        try:
            cmd = ["php", "artisan", "tinker", "--execute=echo config('services.idx_edge.api_key');"]
            res = subprocess.run(cmd, cwd=os.path.join(os.path.dirname(os.path.abspath(__file__)), "../dashboard"), capture_output=True, text=True, timeout=5)
            key = res.stdout.strip()
        except Exception as e:
            logger.warning(f"Could not read API key from artisan tinker: {e}")
        return key

    def _flush_state(self, force: bool = False):
        now_ts = time.time()
        if not force and (now_ts - self.last_flush_time < 0.5):
            return
        try:
            os.makedirs(os.path.dirname(STATE_FILE), exist_ok=True)
            self.state["flush_sequence"] += 1
            self.state["last_flush_at"] = _utc_now()
            payload = json.dumps(self.state, indent=2)
            tmp_file = STATE_FILE + f".tmp.{os.getpid()}"
            with open(tmp_file, "w", encoding="utf-8") as f:
                f.write(payload)
                f.flush()
                os.fsync(f.fileno())
            # Atomic replace to prevent partial/empty reads by Laravel
            for attempt in range(5):
                try:
                    os.replace(tmp_file, STATE_FILE)
                    self.last_flush_time = now_ts
                    break
                except (PermissionError, OSError):
                    time.sleep(0.02)
            if os.path.exists(tmp_file):
                try:
                    os.remove(tmp_file)
                except Exception:
                    pass
        except Exception as e:
            logger.error(f"Error flushing state: {e}")

    async def _heartbeat_loop(self):
        while self.running:
            try:
                self.state["last_heartbeat_at"] = time.strftime("%Y-%m-%d %H:%M:%S WIB")
                self.state["process_alive_last_observed_at"] = self.state["last_heartbeat_at"]
                self.state["heartbeat_epoch"] = int(time.time())
                self._flush_state(force=False)
            except Exception as e:
                logger.warning(f"Heartbeat loop warning: {e}")
            await asyncio.sleep(2.0)

    async def run(self):
        if not self.api_key:
            logger.error("API Key not found. State set to PENDING_CREDENTIAL.")
            self.state["status"] = "PENDING_CREDENTIAL"
            self.state["last_error"] = "API key not configured in environment or services.idx_edge.api_key"
            self._flush_state(force=True)
            return

        headers = [("X-API-Key", self.api_key)]
        asyncio.create_task(self._heartbeat_loop())

        while self.running:
            try:
                logger.info(f"Connecting to {WS_URL} ...")
                self.state["status"] = "CONNECTING"
                if self.state["counter_state"]["run"]["successful_connection_count"] > 0:
                    self.state["reconnect_attempts"] += 1
                    self.state["counter_state"]["run"]["reconnect_attempts"] += 1
                self._flush_state(force=True)

                self.connection_id = f"{self.run_id}_CONN_{self.state['reconnect_count'] + 1}"
                self.state["connection_id"] = self.connection_id
                async with websockets.connect(WS_URL, additional_headers=headers, ping_interval=20, ping_timeout=10) as ws:
                    logger.info("Connected successfully to upstream WebSocket.")
                    begin_connection_counters(self.state["counter_state"], self.connection_id)
                    self.state["successful_connection_count"] = self.state["counter_state"]["run"]["successful_connection_count"]
                    self.state["successful_reconnects"] = self.state["counter_state"]["run"]["successful_reconnects"]
                    append_connection_event(self.state, {
                        "event": "CONNECTED",
                        "connection_id": self.connection_id,
                        "at_wib": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
                        "attempt_number": self.state["reconnect_attempts"] + 1,
                    })
                    self.state["successful_reconnects"] = self.state["counter_state"]["run"]["successful_reconnects"]
                    self.state["status"] = "CONNECTED"
                    self.state["connected_socket"] = True
                    self.state["connected_at"] = time.strftime("%Y-%m-%d %H:%M:%S WIB")
                    self.state["socket_connected_at"] = self.state["connected_at"]
                    self.state["disconnected_at"] = None
                    self.reconnect_delay = 2.0
                    self._flush_state(force=True)

                    # Register ping/pong ping response listener or track ping pong via websocket connection object
                    async for raw_msg in ws:
                        now_epoch = int(time.time())
                        now_str = time.strftime("%Y-%m-%d %H:%M:%S WIB")
                        self.state["last_message_at"] = now_str
                        self.state["last_message_epoch"] = now_epoch
                        self.state["total_messages"] += 1
                        
                        # Inspect websocket protocol level pong / ping latency
                        if hasattr(ws, "latency"):
                            self.state["upstream_ping_latency_ms"] = round(ws.latency * 1000.0, 2)
                        self.state["last_upstream_alive_at"] = now_str
                        
                        msg_str = raw_msg.decode("utf-8") if isinstance(raw_msg, bytes) else str(raw_msg)
                        received_epoch_ns = time.time_ns()
                        record_raw_frame_counter(self.state["counter_state"])
                        if _append_raw_evidence(msg_str, _utc_now(), received_epoch_ns, self.today_date_str, self.run_id, self.connection_id or "UNKNOWN"):
                            self.state["raw_frames_written"] += 1
                            self.state["run_raw_frames"] = self.state["counter_state"]["run"]["raw_frames"]
                            self.state["connection_raw_frames"] = self.state["counter_state"]["connection"]["raw_frames"]
                        else:
                            self.state["raw_frames_failed"] += 1
                        self.state["last_raw_frame_at"] = now_str
                        self.state["last_raw_frame_epoch_ns"] = received_epoch_ns
                        self._process_message(msg_str)
                        self._flush_state(force=False) # Throttled flush during trade stream

            except asyncio.CancelledError:
                logger.info("Supervisor loop cancelled.")
                break
            except (ConnectionClosedError, ConnectionClosedOK) as e:
                code = getattr(e, "code", None)
                error_meta = connection_error_metadata(e)
                if code not in (4401, 4403, 4408):
                    self.state["reconnect_count"] += 1
                self.state["last_close_code"] = code
                self.state["last_error_details"] = error_meta
                self.state["counter_state"]["run"]["reconnect_attempts"] = self.state["reconnect_attempts"]
                self.state["counter_state"]["run"]["last_close_code"] = code
                self.state["counter_state"]["run"]["last_error_details"] = error_meta
                append_connection_event(self.state, {
                    "event": "CLOSED",
                    "connection_id": self.connection_id,
                    "at_wib": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
                    "close_code": code,
                    "error_type": error_meta["error_type"],
                    "handshake_timeout": error_meta["handshake_timeout"],
                })
                self.state["disconnected_at"] = time.strftime("%Y-%m-%d %H:%M:%S WIB")
                self.state["connected_socket"] = False
                close_statuses = {
                    4401: "AUTH_FAILED",
                    4403: "ENTITLEMENT_REQUIRED",
                    4408: "OWNER_CONFLICT",
                }
                self.state["status"] = close_statuses[code] if code in close_statuses else "DISCONNECTED"
                self.state["last_error"] = f"upstream_close_code_{code}" if code is not None else type(e).__name__
                self._flush_state(force=True)
                if code in (4401, 4403, 4408):
                    logger.error("WebSocket closed with non-retryable code %s; stopping.", code)
                    break
                await asyncio.sleep(min(self.reconnect_delay + random.uniform(0, 0.5), self.max_reconnect_delay))
                self.reconnect_delay = min(self.reconnect_delay * 2.0, self.max_reconnect_delay)
            except Exception as e:
                self.state["status"] = "DISCONNECTED"
                self.state["reconnect_count"] += 1
                error_meta = connection_error_metadata(e)
                self.state["last_error"] = type(e).__name__
                self.state["last_error_details"] = error_meta
                if error_meta["handshake_timeout"]:
                    self.state["handshake_timeout_count"] += 1
                append_connection_event(self.state, {
                    "event": "ERROR",
                    "connection_id": self.connection_id,
                    "at_wib": time.strftime("%Y-%m-%d %H:%M:%S WIB"),
                    "close_code": error_meta["close_code"],
                    "error_type": error_meta["error_type"],
                    "handshake_timeout": error_meta["handshake_timeout"],
                })
                logger.warning("WebSocket error %s. Reconnecting with bounded backoff.", type(e).__name__)
                self._flush_state(force=True)
                await asyncio.sleep(min(self.reconnect_delay + random.uniform(0, 0.5), self.max_reconnect_delay))
                self.reconnect_delay = min(self.reconnect_delay * 2.0, self.max_reconnect_delay)

    def _process_message(self, raw_msg: str):
        try:
            data = json.loads(raw_msg)
        except Exception:
            self.state["parse_errors"] += 1
            self.state["rejected_messages"] += 1
            return

        # Inspect format: data can be dict or list
        received_at = time.strftime("%Y-%m-%d %H:%M:%S")

        # Check for message type
        msg_type = (data.get("type") or data.get("action") or "UNKNOWN").lower()
        if msg_type in {"snapshot", "trade", "top5", "filter_applied", "filter_error"}:
            self.state["accepted_messages"] += 1
        else:
            self.state["total_other_messages"] += 1
            self.state["rejected_messages"] += 1
            return

        if msg_type == "filter_error":
            self.state["last_filter_error"] = {
                "message": str(data.get("message") or ""),
                "received_at": received_at,
            }
            return

        if msg_type == "snapshot":
            self.state["total_snapshots"] += 1
            self.state["last_market_event_at"] = received_at
            # Market status snapshot received
            market = data.get("market") or {}
            self.state["market_status"] = {
                "status": market.get("status", "unknown"),
                "label": market.get("label", ""),
                "next_open": market.get("next_open", "")
            }
            # Snapshot recent or top (Replay buffer saat koneksi awal)
            recent = data.get("recent") or []
            # Snapshot recent mengganti/mengisi buffer awal, bukan append ganda transaksi live
            snapshot_trades = []
            for item in recent:
                # Pemetaan dokumentasi resmi:
                # t -> ticker
                # a -> exchange_match_time (HH:MM:SS)
                # tn -> exchange_trade_number (string)
                # p -> price_idr
                # l -> volume_lots
                # v -> transaction_value_idr
                # pc -> provider_change_pct
                # c -> provider_action (buy/sell)
                ticker = item.get("t") or item.get("ticker") or item.get("code") or "UNKNOWN"
                price = float(item.get("p") or item.get("price") or 0.0)
                lot = float(item.get("l") or item.get("lot") or item.get("vol") or 0.0)
                flag_c = item.get("c") or item.get("action")
                match_time_raw = item.get("a") or item.get("time") or item.get("timestamp") or None
                trade_number = str(item.get("tn") or item.get("id") or item.get("trade_id") or "")
                val_idr = float(item.get("v") or (price * lot * 100.0))
                change_pct = float(item.get("pc") or 0.0)
                raw_ts = item.get("ts") # Provider epoch timestamp (e.g. 1793848718.6899142)

                if ticker == "UNKNOWN" and price == 0 and lot == 0:
                    self.state["invalid_items"] += 1
                    continue

                if trade_number:
                    self.state["has_official_trade_id"] = True
                    trade_key = f"tn_{trade_number}"
                    if trade_key not in self.state["seen_trade_keys"]:
                        self.state["seen_trade_keys"].append(trade_key)
                        if len(self.state["seen_trade_keys"]) > 2000:
                            self.state["seen_trade_keys"].pop(0)

                # Parse ts as provider unix epoch if available, without claiming it as verified exchange matching time
                parsed_ts_wib = None
                if raw_ts is not None:
                    try:
                        parsed_ts_wib = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(float(raw_ts)))
                    except Exception:
                        parsed_ts_wib = None

                snapshot_trades.append({
                    "trade_id": trade_number if trade_number else None,
                    "ticker": ticker,
                    "price": price,
                    "lot": lot,
                    "value_idr": val_idr,
                    "change_pct": change_pct,
                    "c_raw": flag_c,
                    "board": "BOARD_SCOPE_UNVERIFIED",
                    "event_time": match_time_raw,
                    "exchange_match_time_raw": match_time_raw,
                    "provider_ts_raw": raw_ts,
                    "provider_ts_parsed": parsed_ts_wib,
                    "received_at": received_at,
                    "is_snapshot": True
                })

            self.state["total_snapshot_trade_items"] += len(snapshot_trades)
            record_snapshot_counters(self.state["counter_state"], len(snapshot_trades))
            seed_snapshot_trade_numbers(
                self.state["counter_state"],
                [trade["trade_id"] for trade in snapshot_trades],
            )
            self.state["run_snapshot_trade_items"] = self.state["counter_state"]["run"]["snapshot_trade_items"]
            self.state["connection_snapshot_trade_items"] = self.state["counter_state"]["connection"]["snapshot_trade_items"]
            self.state["ring_buffer_size"] = self.state["counter_state"]["run"]["ring_buffer_size"]
            if snapshot_trades:
                self.state["recent_trades"] = snapshot_trades[:50]
                self.state["last_snapshot_received_at"] = received_at
                self.state["snapshot_counter_note"] = "Snapshot items are replay/display-only; they do not reset run live-trade counters."
            self.state["total_trades_legacy_scope"] = "CURRENT_CONNECTION_OR_SNAPSHOT_AFFECTED"
            self.state["total_trades_counter_status"] = "COUNTER_UNVERIFIED_ACROSS_CONNECTIONS"
            self.state["total_trades"] = self.state["counter_state"]["run"]["unique_accepted_live_trade_items"]
            if snapshot_trades:
                self.state["last_trade_at"] = time.strftime("%Y-%m-%d %H:%M:%S WIB")

        elif msg_type == "trade":
            self.state["last_market_event_at"] = received_at
            self.state["total_trade_frames"] += 1
            # Live Trade event
            trades_list = []
            if "trades" in data and isinstance(data["trades"], list):
                trades_list = data["trades"]
            elif "data" in data and isinstance(data["data"], list):
                trades_list = data["data"]
            elif "data" in data and isinstance(data["data"], dict):
                trades_list = [data["data"]]
            else:
                trades_list = [data]

            for item in trades_list:
                ticker = item.get("t") or item.get("ticker") or item.get("code") or "UNKNOWN"
                price = float(item.get("p") or item.get("price") or 0.0)
                lot = float(item.get("l") or item.get("lot") or item.get("vol") or 0.0)
                flag_c = item.get("c") or item.get("action")
                match_time_raw = item.get("a") or item.get("time") or item.get("timestamp") or None
                trade_number = str(item.get("tn") or item.get("id") or item.get("trade_id") or "")
                val_idr = float(item.get("v") or (price * lot * 100.0))
                change_pct = float(item.get("pc") or 0.0)
                raw_ts = item.get("ts") # Provider epoch timestamp

                if ticker == "UNKNOWN" and price == 0 and lot == 0:
                    self.state["invalid_items"] += 1
                    continue

                if trade_number:
                    self.state["has_official_trade_id"] = True
                    trade_key = f"tn_{trade_number}"
                    if trade_key in self.state["seen_trade_keys"]:
                        self.state["dedup_stats"]["duplicates_dropped"] += 1
                        record_live_trade_counters(self.state["counter_state"], trade_number, accepted=False, duplicate=True)
                        self.state["run_duplicate_trade_items"] = self.state["counter_state"]["run"]["duplicate_trade_items"]
                        self.state["connection_duplicate_trade_items"] = self.state["counter_state"]["connection"]["duplicate_trade_items"]
                        continue # Deduplikasi live trade vs snapshot/replay
                    self.state["seen_trade_keys"].append(trade_key)
                    if len(self.state["seen_trade_keys"]) > 2000:
                        self.state["seen_trade_keys"].pop(0)
                        self.state["dedup_stats"]["evicted_beyond_buffer"] += 1

                record_live_trade_counters(self.state["counter_state"], trade_number, accepted=True)
                self.state["run_live_trade_items"] = self.state["counter_state"]["run"]["live_trade_items"]
                self.state["run_unique_accepted_live_trade_items"] = self.state["counter_state"]["run"]["unique_accepted_live_trade_items"]
                self.state["connection_live_trade_items"] = self.state["counter_state"]["connection"]["live_trade_items"]
                self.state["connection_unique_accepted_live_trade_items"] = self.state["counter_state"]["connection"]["unique_accepted_live_trade_items"]
                self.state["ring_buffer_size"] = self.state["counter_state"]["run"]["ring_buffer_size"]

                # Parse ts as provider unix epoch, without converting it into verified event time.
                parsed_ts_wib = None
                if raw_ts is not None:
                    try:
                        parsed_ts_wib = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(float(raw_ts)))
                    except Exception:
                        parsed_ts_wib = None
                event_time = event_time_metadata(match_time_raw, _utc_now(), raw_ts)

                self.state["total_trades"] += 1
                self.state["last_trade_at"] = time.strftime("%Y-%m-%d %H:%M:%S WIB")

                # Update observed window tracking
                cov = self.state["session_coverage"]
                cov["observed_window_trades_count"] = self.state["total_trades"]
                if not cov["observation_start_at"]:
                    cov["observation_start_at"] = received_at
                cov["observation_end_at"] = received_at
                cov["observed_from"] = cov["observation_start_at"]
                cov["observed_until"] = cov["observation_end_at"]
                cov["window_status"] = "PARTIAL_SESSION_UNTIL_PROVEN"
                cov["gap"] = "NOT_ESTABLISHED"
                cov["trades_count"] += 1
                cov["reconnect_count"] = self.state["reconnect_count"]
                if match_time_raw:
                    cov["last_event_time"] = match_time_raw
                    cov["has_event_timestamp"] = True
                if raw_ts is not None:
                    cov["last_provider_ts_raw"] = raw_ts
                    cov["last_provider_ts_parsed"] = parsed_ts_wib

                # Bounded per-ticker session aggregates
                if ticker != "UNKNOWN" and price > 0:
                    if ticker not in self.state["session_aggregates"] and len(self.state["session_aggregates"]) < 1000:
                        self.state["session_aggregates"][ticker] = {
                            "ticker": ticker,
                            "trades_count": 0,
                            "volume_lots": 0.0,
                            "turnover_est": 0.0,
                            "last_price": price,
                            "high_price": price,
                            "low_price": price,
                            "first_price": price,
                            "c_buy_lots": 0.0,
                            "c_sell_lots": 0.0,
                            "board_scope": "BOARD_SCOPE_UNVERIFIED"
                        }
                    if ticker in self.state["session_aggregates"]:
                        agg = self.state["session_aggregates"][ticker]
                        agg["trades_count"] += 1
                        agg["volume_lots"] += lot
                        agg["turnover_est"] += val_idr
                        agg["last_price"] = price
                        agg["high_price"] = max(agg["high_price"], price)
                        agg["low_price"] = min(agg["low_price"], price)
                        if flag_c == "buy":
                            agg["c_buy_lots"] += lot
                        elif flag_c == "sell":
                            agg["c_sell_lots"] += lot

                trade_entry = {
                    "trade_id": trade_number if trade_number else None,
                    "exchange_trade_number": trade_number,
                    "ticker": ticker,
                    "price": price,
                    "lot": lot,
                    "value_idr": val_idr,
                    "change_pct": change_pct,
                    "c_raw": flag_c,
                    "board": "BOARD_SCOPE_UNVERIFIED",
                    "event_time": match_time_raw,
                    "exchange_match_time_raw": match_time_raw,
                    "provider_ts_raw": raw_ts,
                    "provider_ts_parsed": parsed_ts_wib,
                    "event_time_status": event_time["status"],
                    "received_at": received_at,
                    "is_snapshot": False
                }

                self.state["recent_trades"].insert(0, trade_entry)
                if len(self.state["recent_trades"]) > 50:
                    self.state["recent_trades"].pop()

                if is_shadow_trade_candidate("trade", val_idr):
                    received_epoch = time.time()
                    received_at_utc = _utc_now()
                    summary = self.state["shadow_summary"]
                    summary["transactions_count"] += 1
                    summary["total_value_idr"] += val_idr
                    ticker_summary = summary["tickers"].setdefault(ticker, {"transactions_count": 0, "total_value_idr": 0.0})
                    ticker_summary["transactions_count"] += 1
                    ticker_summary["total_value_idr"] += val_idr
                    summary["observed_from_received_at_utc"] = summary["observed_from_received_at_utc"] or received_at_utc
                    summary["observed_until_received_at_utc"] = received_at_utc
                    summary["event_time_status"] = EVENT_TIME_STATUS
                    _write_shadow_summary(summary, self.run_artifacts["summary"])

                    self.state["shadow_events_emitted"] += 1
                    _append_shadow_event({
                        "event_type": "LARGE_TRADE_OBSERVED",
                        "mode": "SHADOW_ONLY",
                        "run_id": self.run_id,
                        "connection_id": self.connection_id,
                        "session_date": self.today_date_str,
                        "received_at_utc": received_at_utc,
                        "ticker": ticker,
                        "trade_number": trade_number or None,
                        "price_idr": price,
                        "volume_lots": lot,
                        "value_idr": val_idr,
                        "event_time_raw": match_time_raw,
                        "provider_ts_raw": raw_ts,
                        "event_time_status": EVENT_TIME_STATUS,
                        "board_scope": "BOARD_SCOPE_UNVERIFIED",
                        "action_classification": "NOT_USED",
                        "publication": "DISABLED",
                        "gemini_called": False,
                        "ml_called": False,
                    })

                    last_by_ticker = self.state["shadow_notification_last_by_ticker"]
                    if should_emit_shadow_notification(ticker, received_epoch, last_by_ticker, SHADOW_NOTIFICATION_COOLDOWN_SECONDS):
                        last_by_ticker[ticker] = received_epoch
                        self.state["shadow_notification_candidates"] += 1
                        _append_shadow_notification_candidate({
                            "event_type": "LARGE_TRADE_NOTIFICATION_CANDIDATE",
                            "mode": "SHADOW_ONLY",
                            "ticker": ticker,
                            "trade_number": trade_number or None,
                            "value_idr": val_idr,
                            "received_at_utc": received_at_utc,
                            "event_time_status": EVENT_TIME_STATUS,
                            "publication": "DISABLED",
                        }, self.run_artifacts["notification_candidates"])
                    else:
                        self.state["shadow_notification_suppressed_cooldown"] += 1

                if ticker in ["^JKSE", "IHSG", "JKSE"]:
                    self.state["ihsg"] = {
                        "available": True,
                        "value": price,
                        "timestamp": match_time_raw or received_at,
                        "source": "WEBSOCKET_STREAM"
                    }

        elif msg_type == "top5":
            self.state["total_top5"] += 1
            # Top5 adalah ringkasan buffer bergulir (rolling buffer, misal window_seconds).
            # window_seconds adalah usia data tertua di buffer, bukan jaminan 300 detik penuh atau cakupan sesi.
            # DILARANG menjumlahkan frekuensi/lot top5 ke agregat sesi transaksi (session_aggregates)!
            # Key 'boards' (freq, lot, value, net_buy, net_sell) BUKAN pemisahan papan RG/NG/TN bursa.
            # net_buy / net_sell BUKAN akumulasi broker atau aggressor flow tanpa definisi perhitungan provider.
            top_data = data.get("top") or []
            boards = data.get("boards") or {}
            self.state["top5_snapshots"] = {
                "snapshot_data": top_data,
                "boards": boards,
                "window_seconds": data.get("window_seconds"),
                "received_at": received_at,
                "note": "Rolling buffer metrics. Not broker accumulation or exchange RG board separation."
            }

        elif msg_type == "filter_applied":
            # Event acknowledgement filter upstream:
            # Mengganti tampilan buffer tabel lokal untuk sesi filter aktif, BUKAN transaksi baru.
            # DILARANG di-append atau dihitung ulang ke session_aggregates!
            filter_recent = data.get("recent") or []
            filtered_trades = []
            for item in filter_recent:
                t_num = str(item.get("tn") or item.get("id") or "")
                filtered_trades.append({
                    "trade_id": t_num if t_num else None,
                    "exchange_trade_number": t_num,
                    "ticker": item.get("t") or item.get("ticker") or "UNKNOWN",
                    "price": float(item.get("p") or item.get("price") or 0.0),
                    "lot": float(item.get("l") or item.get("lot") or 0.0),
                    "value_idr": float(item.get("v") or 0.0),
                    "change_pct": float(item.get("pc") or 0.0),
                    "c_raw": item.get("c") or item.get("action"),
                    "board": "BOARD_SCOPE_UNVERIFIED",
                    "event_time": item.get("a") or item.get("time") or None,
                    "exchange_match_time_raw": item.get("a") or item.get("time") or None,
                    "received_at": received_at,
                    "is_snapshot": True,
                    "is_filter_replay": True
                })
            self.state["recent_trades"] = filtered_trades[:50]
            applied_filter = data.get("filter") or {}
            self.state["active_filter"] = {
                "action": applied_filter.get("action"),
                "codes": applied_filter.get("codes"),
                "tickers": applied_filter.get("codes") or data.get("tickers") or [],
                "min_lot": applied_filter.get("min_lot", data.get("min_lot")),
                "min_value": applied_filter.get("min_value"),
                "matched": data.get("matched"),
                "applied_at": received_at,
                "warning": "Filtered upstream stream. Coverage reduced."
            }

        elif "index" in msg_type or "ihsg" in str(data).lower():
            # Potential index message
            ticker = data.get("t") or data.get("index") or "IHSG"
            val = data.get("p") or data.get("val") or data.get("price")
            if val:
                self.state["ihsg"] = {
                    "available": True,
                    "value": val,
                    "timestamp": data.get("time") or received_at,
                    "source": "WEBSOCKET_STREAM"
                }

def acquire_single_instance_lock() -> bool:
    global _instance_lock
    try:
        import fcntl
    except ImportError:
        fcntl = None
    try:
        _instance_lock = open(LOCK_FILE, "a+", encoding="utf-8")
        try:
            _lock_instance_file(_instance_lock)
        except (BlockingIOError, OSError):
            logger.warning("Supervisor lock already held; exiting to prevent duplicate connection.")
            _instance_lock.close()
            _instance_lock = None
            return False
        _instance_lock.seek(0)
        _instance_lock.truncate()
        _instance_lock.write(str(os.getpid()))
        _instance_lock.flush()
        with open(PID_FILE, "w", encoding="utf-8") as f:
            f.write(str(os.getpid()))
        return True
    except Exception as e:
        logger.error("Could not acquire supervisor lock: %s", type(e).__name__)
        if _instance_lock is not None:
            _instance_lock.close()
            _instance_lock = None
        return False

def release_single_instance_lock():
    global _instance_lock
    try:
        if _instance_lock is not None:
            try:
                _unlock_instance_file(_instance_lock)
            except (ImportError, OSError):
                pass
            _instance_lock.close()
            _instance_lock = None
        if os.path.exists(PID_FILE):
            with open(PID_FILE, "r", encoding="utf-8") as f:
                if f.read().strip() == str(os.getpid()):
                    os.remove(PID_FILE)
    except Exception:
        pass

if __name__ == "__main__":
    if not acquire_single_instance_lock():
        sys.exit(0)

    supervisor = IdxEdgeWsSupervisor()

    def handle_exit(sig, frame):
        supervisor.running = False
        now = time.strftime("%Y-%m-%d %H:%M:%S WIB")
        supervisor.state["status"] = "STOPPED"
        supervisor.state["connected_socket"] = False
        supervisor.state["disconnected_at"] = now
        supervisor.state["stopped_at"] = now
        supervisor._flush_state(force=True)
        release_single_instance_lock()
        sys.exit(0)

    try:
        signal.signal(signal.SIGINT, handle_exit)
        signal.signal(signal.SIGTERM, handle_exit)
    except Exception:
        pass

    try:
        asyncio.run(supervisor.run())
    except KeyboardInterrupt:
        logger.info("Supervisor terminated by user.")
    finally:
        if supervisor.state.get("status") not in {"STOPPED", "AUTH_FAILED", "ENTITLEMENT_REQUIRED", "OWNER_CONFLICT"}:
            supervisor.state["status"] = "DISCONNECTED"
        supervisor.state["connected_socket"] = False
        supervisor.state["disconnected_at"] = supervisor.state.get("disconnected_at") or time.strftime("%Y-%m-%d %H:%M:%S WIB")
        supervisor.state["stopped_at"] = supervisor.state.get("stopped_at") or time.strftime("%Y-%m-%d %H:%M:%S WIB")
        supervisor._flush_state(force=True)
        release_single_instance_lock()