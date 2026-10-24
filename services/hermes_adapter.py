r"""Aether Market Intelligence — Python Adapter Bridge.

Connects Laravel Control Plane with Hermes Stock Agent (E:\hermes-stock-agent)
and verified market news feeds through standard JSON input/output contracts.

STRICT CONSTRAINTS:
- Zero live broker execution.
- No credential leakage in stdout or logs.
- Absolute paths only.
- Deterministic structured JSON output on both success and failure.
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import re
import sys
import shutil
import traceback
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path
from zoneinfo import ZoneInfo
from typing import Any, Dict, List, Optional, Tuple

# Set explicit paths
PROJECT_ROOT = str(Path(__file__).resolve().parent.parent)
LOCAL_QUANT_ENGINE = os.path.join(PROJECT_ROOT, "quant-engine")
if os.path.isdir(LOCAL_QUANT_ENGINE):
    HERMES_STOCK_AGENT_ROOT = LOCAL_QUANT_ENGINE
else:
    HERMES_STOCK_AGENT_ROOT = r"E:\hermes-stock-agent"

if HERMES_STOCK_AGENT_ROOT not in sys.path:
    sys.path.insert(0, HERMES_STOCK_AGENT_ROOT)

PROJECT_BOT_DATA_DIR = os.path.join(PROJECT_ROOT, "data", "raw_daily")

JAKARTA_TZ = ZoneInfo("Asia/Jakarta")

# Default universe of 10 liquid IDX stocks
DEFAULT_UNIVERSE = [
    "BBCA", "BBRI", "BMRI", "BBNI", "ASII",
    "TLKM", "MAPI", "ARTO", "MEDC", "SMRA"
]


def get_timestamp_wib() -> str:
    """Return current ISO-8601 timestamp in Asia/Jakarta timezone."""
    return datetime.datetime.now(JAKARTA_TZ).isoformat()


def build_response(
    action: str,
    ok: bool,
    data: Any = None,
    error: Optional[str] = None,
    warnings: Optional[List[str]] = None
) -> Dict[str, Any]:
    """Build standard JSON response dictionary."""
    return {
        "ok": ok,
        "action": action,
        "generated_at_wib": get_timestamp_wib(),
        "data": data,
        "warnings": warnings or [],
        "error": error
    }


def handle_ping(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Health ping checking Python runtime and Hermes project path accessibility."""
    agent_exists = os.path.isdir(HERMES_STOCK_AGENT_ROOT)
    data_raw_exists = os.path.isdir(os.path.join(HERMES_STOCK_AGENT_ROOT, "data", "raw_daily"))
    paper_dir_exists = os.path.isdir(os.path.join(HERMES_STOCK_AGENT_ROOT, "data", "paper_trading"))

    return {
        "status": "healthy" if (agent_exists and data_raw_exists) else "degraded",
        "python_version": sys.version.split()[0],
        "hermes_root": HERMES_STOCK_AGENT_ROOT,
        "hermes_root_accessible": agent_exists,
        "data_raw_accessible": data_raw_exists,
        "paper_trading_dir_accessible": paper_dir_exists,
        "supported_actions": [
            "ping",
            "market_snapshots",
            "paper_trading_status",
            "accumulation_summary",
            "fetch_news_feed"
        ]
    }



def validate_eod_dataframe(
    df: Any,
    ticker: str,
    as_of_date: Optional[datetime.date] = None
) -> Tuple[bool, List[str]]:
    """Validate EOD OHLCV dataframe integrity, value boundaries, and dates."""
    import pandas as pd

    issues = []
    if df is None or getattr(df, "empty", True):
        return False, [f"{ticker}: Dataframe is empty or None."]

    required_cols = {"Close", "High", "Low", "Open", "Volume"}
    if not required_cols.issubset(set(df.columns)):
        missing = required_cols - set(df.columns)
        return False, [f"{ticker}: Missing required OHLCV columns: {missing}"]

    null_counts = df[list(required_cols)].isnull().sum()
    if null_counts.any():
        return False, [f"{ticker}: Null values detected in OHLCV columns: {null_counts.to_dict()}"]

    for col in ["Close", "High", "Low", "Open"]:
        if (df[col] <= 0).any():
            issues.append(f"{ticker}: Non-positive values detected in price column '{col}'.")

    if (df["Volume"] < 0).any():
        issues.append(f"{ticker}: Negative volume detected.")

    invalid_hl = df["High"] < df["Low"]
    if invalid_hl.any():
        issues.append(f"{ticker}: High < Low detected in {invalid_hl.sum()} bars.")

    invalid_high = (df["High"] < df["Open"] * 0.999) | (df["High"] < df["Close"] * 0.999)
    if invalid_high.any():
        issues.append(f"{ticker}: High < max(Open, Close) detected in {invalid_high.sum()} bars.")

    invalid_low = (df["Low"] > df["Open"] * 1.001) | (df["Low"] > df["Close"] * 1.001)
    if invalid_low.any():
        issues.append(f"{ticker}: Low > min(Open, Close) detected in {invalid_low.sum()} bars.")

    date_col = "Date" if "Date" in df.columns else None
    if date_col is None:
        dates = pd.to_datetime(df.index)
    else:
        dates = pd.to_datetime(df[date_col])

    if dates.duplicated().any():
        issues.append(f"{ticker}: Duplicate dates detected.")

    max_date = as_of_date or datetime.datetime.now(JAKARTA_TZ).date()
    if hasattr(dates, "dt"):
        future_dates = dates[dates.dt.date > max_date]
    else:
        future_dates = dates[dates.date > max_date]
    if len(future_dates) > 0:
        issues.append(f"{ticker}: Future dates detected beyond {max_date}: {future_dates.tolist()}")

    return len(issues) == 0, issues


def download_eod_data(
    ticker: str,
    period: str = "1mo",
    timeout: int = 15,
    custom_downloader=None
) -> Tuple[bool, Any, Optional[str]]:
    """Download daily EOD candles for ticker with timeout and error handling."""
    import pandas as pd

    clean_ticker = ticker.strip().upper().replace(".JK", "")
    sym = f"{clean_ticker}.JK"
    try:
        if custom_downloader:
            df = custom_downloader(sym, period=period, interval="1d")
        else:
            import yfinance as yf
            df = yf.download(sym, period=period, interval="1d", progress=False, auto_adjust=False, timeout=timeout)

        if df is None or getattr(df, "empty", True):
            return False, None, f"{clean_ticker}: Empty download response from data provider."

        if isinstance(df.columns, pd.MultiIndex):
            df.columns = [c[0] for c in df.columns]

        df = df.dropna(subset=["Open", "High", "Low", "Close"])
        is_valid, issues = validate_eod_dataframe(df, clean_ticker)
        if not is_valid:
            return False, None, f"{clean_ticker}: Validation failed: {'; '.join(issues)}"

        return True, df, None
    except Exception as e:
        return False, None, f"{clean_ticker}: Download exception: {str(e)}"


def merge_and_save_eod_csv(csv_path: str, new_df: Any) -> Tuple[bool, Optional[str], Optional[str]]:
    """Atomically merge new EOD bars into existing CSV, keeping history intact on failure."""
    import pandas as pd

    tmp_path = csv_path + ".tmp"
    try:
        clean_new = new_df.copy()
        if "Date" not in clean_new.columns:
            clean_new["Date"] = [str(d)[:10] for d in clean_new.index]
        else:
            clean_new["Date"] = pd.to_datetime(clean_new["Date"]).dt.strftime("%Y-%m-%d")

        cols = ["Date", "Close", "High", "Low", "Open", "Volume"]
        clean_new = clean_new[[c for c in cols if c in clean_new.columns]]

        if os.path.exists(csv_path):
            existing_df = pd.read_csv(csv_path)
            date_col = "Date" if "Date" in existing_df.columns else existing_df.columns[0]
            existing_df["Date"] = pd.to_datetime(existing_df[date_col]).dt.strftime("%Y-%m-%d")
            existing_df = existing_df[[c for c in cols if c in existing_df.columns]]
            combined = pd.concat([existing_df, clean_new], ignore_index=True)
        else:
            combined = clean_new

        combined.drop_duplicates(subset=["Date"], keep="last", inplace=True)
        combined.sort_values(by="Date", inplace=True)

        os.makedirs(os.path.dirname(os.path.abspath(csv_path)), exist_ok=True)
        combined.to_csv(tmp_path, index=False)
        os.replace(tmp_path, csv_path)

        latest_date = str(combined["Date"].iloc[-1])[:10]
        return True, latest_date, None
    except Exception as e:
        if os.path.exists(tmp_path):
            try:
                os.remove(tmp_path)
            except OSError:
                pass
        return False, None, str(e)


def handle_market_snapshots(payload: Any) -> Dict[str, Any]:
    """Read and optionally sync latest EOD market snapshots and technical indicators."""
    if isinstance(payload, list):
        symbols = payload if payload else DEFAULT_UNIVERSE
        opts = {}
    elif isinstance(payload, dict):
        symbols = payload.get("symbols") or DEFAULT_UNIVERSE
        opts = payload
    else:
        symbols = DEFAULT_UNIVERSE
        opts = {}

    if not isinstance(symbols, list):
        raise ValueError("Field 'symbols' must be a list of ticker strings.")

    import pandas as pd
    from src.indicators import add_indicators
    from src.calendar_utils import is_trading_day, get_current_trading_day

    hermes_stock_agent_data_dir = os.path.join(HERMES_STOCK_AGENT_ROOT, "data", "raw_daily")
    raw_dir = opts.get("raw_dir") or os.environ.get(
        "RAW_DAILY_DIR",
        PROJECT_BOT_DATA_DIR
    )
    auto_download = opts.get("auto_download", False)
    strict_freshness = opts.get("strict_freshness", False)
    mock_data = opts.get("mock_data")

    now_wib = datetime.datetime.now(JAKARTA_TZ)
    today = now_wib.date()
    is_today_trading = is_trading_day(today)
    is_final = is_today_trading and (now_wib.hour >= 16)

    if is_final:
        expected_trading_date = today.strftime("%Y-%m-%d")
    else:
        # Before 16:00 WIB or on non-trading days, expected EOD date is the latest completed trading day
        curr = today - datetime.timedelta(days=1)
        while not is_trading_day(curr):
            curr -= datetime.timedelta(days=1)
        expected_trading_date = curr.strftime("%Y-%m-%d")

    snapshots = []
    warnings = []
    synced_tickers = []
    failed_tickers = []
    ticker_bar_dates = {}

    # Pre-fetch batch download if auto_download is enabled and not in mock mode
    batch_dfs = {}
    if auto_download and not mock_data:
        try:
            import yfinance as yf
            sym_map = {s.strip().upper().replace(".JK", ""): f"{s.strip().upper().replace('.JK', '')}.JK" for s in symbols}
            raw_batch = yf.download(list(sym_map.values()), period="1mo", interval="1d", progress=False, auto_adjust=False, timeout=20)
            if raw_batch is not None and not getattr(raw_batch, "empty", True):
                if isinstance(raw_batch.columns, pd.MultiIndex):
                    for clean_sym, full_sym in sym_map.items():
                        try:
                            sub_df = pd.DataFrame({
                                attr: raw_batch[attr][full_sym]
                                for attr in ["Open", "High", "Low", "Close", "Volume"]
                                if attr in raw_batch.columns.levels[0] and full_sym in raw_batch[attr]
                            }).dropna(subset=["Open", "High", "Low", "Close"])
                            if not sub_df.empty:
                                val_ok, _ = validate_eod_dataframe(sub_df, clean_sym)
                                if val_ok:
                                    batch_dfs[clean_sym] = sub_df
                        except Exception:
                            pass
        except Exception:
            pass

    for sym in symbols:
        clean_sym = sym.strip().upper().replace(".JK", "")
        fpath = os.path.join(raw_dir, f"{clean_sym}_1d.csv")
        if not os.path.exists(fpath):
            alt_fpath = os.path.join(raw_dir, f"{clean_sym}_daily.csv")
            if os.path.exists(alt_fpath):
                fpath = alt_fpath
            else:
                # Seed from hermes_stock_agent if available and not using custom mock raw_dir
                seed_fpath = os.path.join(hermes_stock_agent_data_dir, f"{clean_sym}_1d.csv")
                if os.path.exists(seed_fpath) and "scratch" not in raw_dir and not mock_data:
                    os.makedirs(raw_dir, exist_ok=True)
                    try:
                        shutil.copy2(seed_fpath, fpath)
                    except Exception:
                        pass

        # 1. Ingestion / Download stage
        if auto_download:
            new_df = None
            dl_err = None
            if mock_data and clean_sym in mock_data:
                raw_mock = mock_data[clean_sym]
                if isinstance(raw_mock, pd.DataFrame):
                    new_df = raw_mock
                elif isinstance(raw_mock, list):
                    new_df = pd.DataFrame(raw_mock)
                elif raw_mock is None or (isinstance(raw_mock, dict) and raw_mock.get("error")):
                    dl_err = str(raw_mock.get("error", "Mock error")) if isinstance(raw_mock, dict) else "Mock download failed."
                else:
                    new_df = pd.DataFrame(raw_mock)

                if new_df is not None and not new_df.empty:
                    val_ok, issues = validate_eod_dataframe(new_df, clean_sym)
                    if not val_ok:
                        dl_err = f"Validation failed: {'; '.join(issues)}"
                        new_df = None
            elif clean_sym in batch_dfs:
                new_df = batch_dfs[clean_sym]
                dl_ok = True
                dl_err = None
            else:
                dl_ok, new_df, dl_err = download_eod_data(clean_sym, period="1mo")

            if new_df is not None and not new_df.empty:
                target_fpath = os.path.join(raw_dir, f"{clean_sym}_1d.csv")
                save_ok, latest_date, save_err = merge_and_save_eod_csv(target_fpath, new_df)
                if save_ok:
                    synced_tickers.append(clean_sym)
                    fpath = target_fpath
                else:
                    failed_tickers.append(clean_sym)
                    warnings.append(f"Gagal menyimpan data baru untuk {clean_sym}: {save_err}. Menggunakan data lama.")
            else:
                failed_tickers.append(clean_sym)
                warnings.append(f"Unduhan/validasi EOD gagal untuk {clean_sym}: {dl_err}. Menggunakan data lama.")

        # 2. Reading and technical indicator computation
        if not os.path.exists(fpath):
            warnings.append(f"Data file for {clean_sym} not found in {raw_dir}.")
            continue

        try:
            df = pd.read_csv(fpath)
            if df.empty or len(df) < 5:
                warnings.append(f"Insufficient historical data for {clean_sym}.")
                continue

            date_col = "Date" if "Date" in df.columns else df.columns[0]
            df[date_col] = pd.to_datetime(df[date_col])
            df.sort_values(by=date_col, inplace=True)

            df_ind = add_indicators(df)
            last_row = df_ind.iloc[-1]

            bar_date = str(last_row[date_col])[:10]
            ticker_bar_dates[clean_sym] = bar_date
            close_p = float(last_row["Close"])
            open_p = float(last_row["Open"]) if "Open" in last_row else close_p
            high_p = float(last_row["High"]) if "High" in last_row else close_p
            low_p = float(last_row["Low"]) if "Low" in last_row else close_p
            volume_val = int(last_row["Volume"]) if "Volume" in last_row else 0

            denom = (high_p - low_p) if (high_p - low_p) > 0 else 1.0
            close_pos = round((close_p - low_p) / denom, 4)

            indicators = {
                "atr14": round(float(last_row.get("ATR14", 0.0)), 2),
                "rvol20": round(float(last_row.get("RVOL", 1.0)), 2),
                "ema9": round(float(last_row.get("EMA9", close_p)), 2),
                "ema20": round(float(last_row.get("EMA20", close_p)), 2),
                "rsi14": round(float(last_row.get("RSI14", 50.0)), 2),
                "close_pos": close_pos
            }

            snapshots.append({
                "ticker": clean_sym,
                "bar_date": bar_date,
                "source_timestamp": f"{bar_date}T16:00:00+07:00",
                "open_price": round(open_p, 2),
                "high_price": round(high_p, 2),
                "low_price": round(low_p, 2),
                "close_price": round(close_p, 2),
                "volume": volume_val,
                "data_classification": "end_of_day",
                "indicators": indicators
            })
        except Exception as e:
            warnings.append(f"Failed processing {clean_sym}: {str(e)}")

    # 3. Freshness Assessment
    all_dates = list(ticker_bar_dates.values())
    latest_bar_date = max(all_dates) if all_dates else None
    min_bar_date = min(all_dates) if all_dates else None

    has_all_symbols = len(snapshots) == len(symbols)
    is_fresh = True
    freshness_issues = []

    if not has_all_symbols:
        is_fresh = False
        freshness_issues.append(f"Hanya {len(snapshots)} dari {len(symbols)} saham yang berhasil dibaca.")

    if len(failed_tickers) > 0:
        is_fresh = False
        freshness_issues.append(f"Gagal sinkronisasi untuk ticker: {failed_tickers}")

    if min_bar_date and min_bar_date < expected_trading_date:
        is_fresh = False
        freshness_issues.append(
            f"Data EOD belum final atau masih stale. Bar paling lama: {min_bar_date}, tanggal diharapkan: {expected_trading_date}."
        )

    res_data = {
        "count": len(snapshots),
        "expected_trading_date": expected_trading_date,
        "latest_bar_date": latest_bar_date,
        "is_fresh": is_fresh,
        "synced_tickers": synced_tickers,
        "failed_tickers": failed_tickers,
        "snapshots": snapshots,
        "warnings": warnings,
    }

    if not is_fresh:
        res_data["error"] = "; ".join(freshness_issues)

    if strict_freshness and not is_fresh:
        res_data["ok"] = False
    else:
        res_data["ok"] = True

    return res_data


def handle_paper_trading_status(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Retrieve comparative metrics of EXP-01 and EXP-04 paper portfolios."""
    from src.forward_paper_runner import get_engine_pair

    engine_01, engine_04 = get_engine_pair()
    st_01 = engine_01.get_portfolio_status({})
    st_04 = engine_04.get_portfolio_status({})

    return {
        "exp01": {
            "name": "Baseline Trend Pullback (Control)",
            "initial_capital": st_01["initial_capital"],
            "cash": st_01["cash"],
            "net_liquidation_value": st_01["net_liquidation_value"],
            "final_mtm_equity": st_01["final_mtm_equity"],
            "realized_net_pnl": st_01["realized_net_pnl_idr"],
            "return_pct": st_01["portfolio_return_pct"],
            "open_positions_count": st_01["open_positions_count"],
            "closed_trades_count": st_01["closed_trades_count"]
        },
        "exp04": {
            "name": "Stricter Volume & Close Position Confirmation (Candidate)",
            "initial_capital": st_04["initial_capital"],
            "cash": st_04["cash"],
            "net_liquidation_value": st_04["net_liquidation_value"],
            "final_mtm_equity": st_04["final_mtm_equity"],
            "realized_net_pnl": st_04["realized_net_pnl_idr"],
            "return_pct": st_04["portfolio_return_pct"],
            "open_positions_count": st_04["open_positions_count"],
            "closed_trades_count": st_04["closed_trades_count"]
        }
    }


def handle_accumulation_summary(payload: Any) -> Dict[str, Any]:
    """Run VPA & Broker Accumulation evaluation across universe."""
    if isinstance(payload, list):
        symbols = payload if payload else DEFAULT_UNIVERSE
    elif isinstance(payload, dict):
        symbols = payload.get("symbols") or DEFAULT_UNIVERSE
    else:
        symbols = DEFAULT_UNIVERSE
    import pandas as pd
    from src.accumulation_scanner import evaluate_accumulation_setup

    raw_dir = os.path.join(HERMES_STOCK_AGENT_ROOT, "data", "raw_daily")
    results = []

    for sym in symbols:
        clean_sym = sym.strip().upper().replace(".JK", "")
        fpath = os.path.join(raw_dir, f"{clean_sym}_1d.csv")
        if not os.path.exists(fpath):
            fpath = os.path.join(raw_dir, f"{clean_sym}_daily.csv")
        if not os.path.exists(fpath):
            continue

        try:
            df = pd.read_csv(fpath)
            if len(df) < 20:
                continue
            date_col = "Date" if "Date" in df.columns else df.columns[0]
            df[date_col] = pd.to_datetime(df[date_col])
            df.sort_values(by=date_col, inplace=True)

            setup = evaluate_accumulation_setup(df, clean_sym, broker_data=None)
            results.append({
                "ticker": clean_sym,
                "category": setup.get("category", "Netral"),
                "score": setup.get("score", 0.0),
                "reason": setup.get("reason", ""),
                "details": setup.get("details", {})
            })
        except Exception as e:
            results.append({
                "ticker": clean_sym,
                "category": "Error",
                "score": 0.0,
                "reason": f"Evaluasi gagal: {str(e)}",
                "details": {}
            })

    return {
        "count": len(results),
        "results": results
    }


def handle_fetch_news_feed(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Fetch and parse verified legal RSS news items without synthetic generation."""
    feed_url = payload.get("feed_url") or "https://www.antaranews.com/rss/ekonomi-finansial.xml"
    source_name = payload.get("source_name") or "Antara News Ekonomi & Finansial"
    max_items = int(payload.get("limit", 15))

    req = urllib.request.Request(
        feed_url,
        headers={"User-Agent": "AetherMarketIntelligence/1.0 (+https://github.com/alwaysdims/Aether-Market-Intelligence)"}
    )

    now_wib = get_timestamp_wib()
    fetched_items = []
    warnings = []

    try:
        with urllib.request.urlopen(req, timeout=12) as response:
            xml_data = response.read()

        root = ET.fromstring(xml_data)
        items = root.findall(".//item")

        # Regex for 4-letter Indonesian stock tickers
        ticker_regex = re.compile(r"\b(" + "|".join(DEFAULT_UNIVERSE) + r")\b", re.IGNORECASE)

        for it in items[:max_items]:
            title_el = it.find("title")
            link_el = it.find("link")
            desc_el = it.find("description")
            pub_el = it.find("pubDate")

            title = title_el.text.strip() if (title_el is not None and title_el.text) else ""
            link = link_el.text.strip() if (link_el is not None and link_el.text) else ""
            desc = desc_el.text.strip() if (desc_el is not None and desc_el.text) else ""
            pub_str = pub_el.text.strip() if (pub_el is not None and pub_el.text) else ""

            # Clean HTML tags from description if present
            clean_desc = re.sub(r"<[^>]+>", "", desc).strip()

            # Detect relevant IDX tickers mentioned
            combined_text = f"{title} {clean_desc}"
            matched_tickers = list(set([m.upper() for m in ticker_regex.findall(combined_text)]))

            # Determine verification status
            if "keterbukaan informasi" in combined_text.lower() or "idx" in combined_text.lower() or "bei" in combined_text.lower():
                v_status = "official_statement"
            elif matched_tickers:
                v_status = "verified_fact"
            else:
                v_status = "unverified"

            if title and link:
                fetched_items.append({
                    "title": title,
                    "url": link,
                    "source_name": source_name,
                    "published_at_raw": pub_str,
                    "fetched_at_wib": now_wib,
                    "summary": clean_desc[:400] if clean_desc else title,
                    "verification_status": v_status,
                    "related_tickers": matched_tickers
                })

    except Exception as e:
        warnings.append(f"Feed fetch error from {feed_url}: {str(e)}")

    return {
        "feed_url": feed_url,
        "source_name": source_name,
        "count": len(fetched_items),
        "items": fetched_items,
        "warnings": warnings
    }


def main():
    """CLI dispatcher for hermes_adapter.py."""
    parser = argparse.ArgumentParser(description="Aether Market Intelligence Python Adapter Bridge")
    parser.add_argument("--action", required=True, help="Adapter action to execute")
    parser.add_argument("--payload", default="{}", help="JSON payload string")
    parser.add_argument("--stdin", action="store_true", help="Read payload from stdin")
    args = parser.parse_args()

    action = args.action.strip()

    # Parse payload safely
    try:
        if args.stdin:
            payload_str = sys.stdin.read()
            payload = json.loads(payload_str) if payload_str.strip() else {}
        else:
            payload = json.loads(args.payload)
    except Exception as e:
        resp = build_response(action=action, ok=False, error=f"Invalid JSON payload: {str(e)}")
        print(json.dumps(resp))
        sys.exit(1)

    # Route action
    action_handlers = {
        "ping": handle_ping,
        "market_snapshots": handle_market_snapshots,
        "paper_trading_status": handle_paper_trading_status,
        "accumulation_summary": handle_accumulation_summary,
        "fetch_news_feed": handle_fetch_news_feed,
    }

    handler = action_handlers.get(action)
    if not handler:
        resp = build_response(
            action=action,
            ok=False,
            error=f"Unknown action '{action}'. Supported actions: {list(action_handlers.keys())}"
        )
        print(json.dumps(resp))
        sys.exit(2)

    try:
        data = handler(payload)
        is_ok = True
        error_msg = None
        if isinstance(data, dict):
            is_ok = bool(data.get("ok", True))
            error_msg = data.get("error")
        resp = build_response(action=action, ok=is_ok, data=data, error=error_msg)
        print(json.dumps(resp, ensure_ascii=False))
        sys.exit(0 if is_ok else 1)
    except Exception as e:
        tb = traceback.format_exc()
        resp = build_response(
            action=action,
            ok=False,
            error=f"Execution error in handler '{action}': {str(e)}",
            warnings=[tb]
        )
        print(json.dumps(resp, ensure_ascii=False))
        sys.exit(3)


if __name__ == "__main__":
    main()
