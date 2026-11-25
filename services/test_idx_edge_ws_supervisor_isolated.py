import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from idx_edge_ws_supervisor import (
    classify_frame_type,
    compute_value_idr,
    event_time_metadata,
    is_duplicate_trade_number,
    is_shadow_trade_candidate,
    record_reconnect_count,
    should_emit_shadow_notification,
    run_artifact_paths,
    _write_shadow_summary,
    make_counter_state,
    begin_connection_counters,
    record_snapshot_counters,
    seed_snapshot_trade_numbers,
    record_raw_frame_counter,
    record_live_trade_counters,
    connection_error_metadata,
    IdxEdgeWsSupervisor,
    _wib_date,
    _wib_stamp,
)


class RunningTradeSupervisorIsolatedTest(unittest.TestCase):
    def test_session_clock_uses_wib(self):
        self.assertRegex(_wib_date(), r"^20\d{2}-\d{2}-\d{2}$")
        self.assertTrue(_wib_stamp().endswith(" WIB"))

    def test_snapshot_never_becomes_shadow_candidate(self):
        frame = {"type": "snapshot", "recent": [{"t": "BBCA", "v": 200_000_000}]}
        self.assertEqual(classify_frame_type(frame), "snapshot")
        self.assertFalse(is_shadow_trade_candidate("snapshot", 200_000_000))

    def test_duplicate_trade_number_can_be_filtered_by_caller(self):
        seen = {"tn_42"}
        self.assertTrue(is_duplicate_trade_number("tn_42", seen))
        self.assertFalse(is_duplicate_trade_number("tn_43", seen))

    def test_reconnect_frame_keeps_event_time_unverified(self):
        metadata = event_time_metadata("10:23:53", "2026-11-16T03:23:52Z", 1.0)
        self.assertEqual(record_reconnect_count(0), 1)
        self.assertEqual(metadata["status"], "EVENT_TIME_UNVERIFIED")
        self.assertEqual(metadata["event_time_raw"], "10:23:53")
        self.assertEqual(metadata["received_at_utc"], "2026-11-16T03:23:52Z")

    def test_connection_error_metadata_preserves_timeout_and_unknown_code(self):
        error = TimeoutError("handshake")
        metadata = connection_error_metadata(error)
        self.assertEqual(metadata["error_type"], "TimeoutError")
        self.assertTrue(metadata["handshake_timeout"])
        self.assertIsNone(metadata["close_code"])
        self.assertTrue(metadata["retryable"])

    def test_counter_scope_separates_frames_from_items(self):
        state = make_counter_state()
        begin_connection_counters(state, "CONN_1")
        record_raw_frame_counter(state)
        record_raw_frame_counter(state)
        record_live_trade_counters(state, "tn_1", accepted=True)
        self.assertEqual(state["run"]["raw_frames"], 2)
        self.assertEqual(state["run"]["live_trade_items"], 1)
        self.assertNotEqual(state["run"]["raw_frames"], state["run"]["live_trade_items"])

    def test_value_fallback_uses_price_lot_and_lot_size(self):
        self.assertEqual(compute_value_idr(None, 500, 2), 100_000)
        self.assertEqual(compute_value_idr(125_000, 500, 2), 125_000)

    def test_notification_cooldown_blocks_same_ticker(self):
        last = {"BBCA": 100.0}
        self.assertFalse(should_emit_shadow_notification("BBCA", 150.0, last, 60))
        self.assertTrue(should_emit_shadow_notification("BBCA", 161.0, last, 60))
        self.assertTrue(should_emit_shadow_notification("BBRI", 150.0, last, 60))

    def test_run_counter_survives_reconnect_snapshot(self):
        state = make_counter_state()
        begin_connection_counters(state, "CONN_1")
        record_live_trade_counters(state, "tn_1", accepted=True)
        record_live_trade_counters(state, "tn_2", accepted=True)
        begin_connection_counters(state, "CONN_2")
        record_snapshot_counters(state, 2)
        record_live_trade_counters(state, "tn_2", accepted=False, duplicate=True)
        record_live_trade_counters(state, "tn_3", accepted=True)
        self.assertEqual(state["run"]["live_trade_items"], 3)
        self.assertEqual(state["run"]["unique_accepted_live_trade_items"], 3)
        self.assertEqual(state["run"]["snapshot_trade_items"], 2)
        self.assertEqual(state["connection"]["live_trade_items"], 1)
        self.assertEqual(state["run"]["duplicate_trade_items"], 1)
        self.assertEqual(state["run"]["ring_buffer_size"], 3)

    def test_snapshot_trade_numbers_seed_dedup_ring_without_live_count(self):
        state = make_counter_state()
        begin_connection_counters(state, "CONN_1")
        seed_snapshot_trade_numbers(state, ["tn_100", "tn_101"])
        self.assertEqual(state["run"]["snapshot_trade_items"], 0)
        self.assertEqual(state["run"]["ring_buffer_size"], 2)
        record_live_trade_counters(state, "tn_100", accepted=False, duplicate=True)
        self.assertEqual(state["run"]["duplicate_trade_items"], 1)
        self.assertEqual(state["run"]["live_trade_items"], 0)

    def test_run_artifacts_are_partitioned_by_run_id(self):
        paths = run_artifact_paths("WSRUN_TEST_001")
        self.assertTrue(paths["summary"].endswith("ws_shadow_summary_WSRUN_TEST_001.json"))
        self.assertTrue(paths["notification_candidates"].endswith("ws_shadow_notification_candidates_WSRUN_TEST_001.jsonl"))

    def test_top5_preserves_all_documented_boards(self):
        supervisor = IdxEdgeWsSupervisor.__new__(IdxEdgeWsSupervisor)
        supervisor.state = {"accepted_messages": 0, "total_top5": 0}
        supervisor._process_message(json.dumps({
            "type": "top5",
            "top": [{"ticker": "BBRI", "metric": 10}],
            "boards": {"freq": [], "lot": [], "value": [], "net_buy": [], "net_sell": []},
            "window_seconds": 17,
        }))
        self.assertEqual(supervisor.state["top5_snapshots"]["boards"]["net_buy"], [])
        self.assertEqual(supervisor.state["top5_snapshots"]["window_seconds"], 17)

    def test_filter_error_is_retained_without_disconnect(self):
        supervisor = IdxEdgeWsSupervisor.__new__(IdxEdgeWsSupervisor)
        supervisor.state = {"accepted_messages": 0, "rejected_messages": 0, "total_other_messages": 0}
        supervisor._process_message(json.dumps({"type": "filter_error", "message": "action invalid"}))
        self.assertEqual(supervisor.state["accepted_messages"], 1)
        self.assertEqual(supervisor.state["last_filter_error"]["message"], "action invalid")

    def test_filter_applied_preserves_contract_metadata(self):
        supervisor = IdxEdgeWsSupervisor.__new__(IdxEdgeWsSupervisor)
        supervisor.state = {"accepted_messages": 0, "rejected_messages": 0, "total_other_messages": 0}
        supervisor._process_message(json.dumps({
            "type": "filter_applied",
            "filter": {"action": "buy", "codes": ["BBCA"], "min_lot": 1000, "min_value": None},
            "recent": [],
            "matched": 37,
        }))
        self.assertEqual(supervisor.state["active_filter"]["action"], "buy")
        self.assertEqual(supervisor.state["active_filter"]["codes"], ["BBCA"])
        self.assertEqual(supervisor.state["active_filter"]["matched"], 37)

    def test_summary_writer_keeps_run_specific_target(self):
        with tempfile.TemporaryDirectory() as directory:
            target = str(Path(directory) / "summary.json")
            _write_shadow_summary({"run_id": "WSRUN_TEST_001"}, target)
            self.assertTrue(Path(target).exists())
            self.assertEqual(Path(target).read_text(encoding="utf-8").strip(), '{\n  "run_id": "WSRUN_TEST_001"\n}')


if __name__ == "__main__":
    unittest.main()
