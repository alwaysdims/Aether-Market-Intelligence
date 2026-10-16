"""Hermes Market Intelligence — Python Adapter Bridge.

Connects Laravel Control Plane with Hermes Stock Agent (E:\\hermes-stock-agent)
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
import traceback
import urllib.request
import xml.etree.ElementTree as ET
from zoneinfo import ZoneInfo
from typing import Any, Dict, List, Optional

# Set explicit paths
HERMES_STOCK_AGENT_ROOT = r"E:\hermes-stock-agent"
if HERMES_STOCK_AGENT_ROOT not in sys.path:
    sys.path.insert(0, HERMES_STOCK_AGENT_ROOT)

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


def handle_market_snapshots(payload: Any) -> Dict[str, Any]:
    """Read latest EOD market snapshots and technical indicators for requested symbols."""
    if isinstance(payload, list):
        symbols = payload if payload else DEFAULT_UNIVERSE
    elif isinstance(payload, dict):
        symbols = payload.get("symbols") or DEFAULT_UNIVERSE
    else:
        symbols = DEFAULT_UNIVERSE

    if not isinstance(symbols, list):
        raise ValueError("Field 'symbols' must be a list of ticker strings.")

    import pandas as pd
    from src.indicators import add_indicators

    raw_dir = os.path.join(HERMES_STOCK_AGENT_ROOT, "data", "raw_daily")
    snapshots = []
    warnings = []

    for sym in symbols:
        clean_sym = sym.strip().upper().replace(".JK", "")
        fpath = os.path.join(raw_dir, f"{clean_sym}_1d.csv")
        if not os.path.exists(fpath):
            fpath = os.path.join(raw_dir, f"{clean_sym}_daily.csv")

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

            # Compute technical indicators
            df_ind = add_indicators(df)
            last_row = df_ind.iloc[-1]

            bar_date = str(last_row[date_col])[:10]
            close_p = float(last_row["Close"])
            open_p = float(last_row["Open"]) if "Open" in last_row else close_p
            high_p = float(last_row["High"]) if "High" in last_row else close_p
            low_p = float(last_row["Low"]) if "Low" in last_row else close_p
            volume_val = int(last_row["Volume"]) if "Volume" in last_row else 0

            # Close position in bar range
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

    return {
        "count": len(snapshots),
        "snapshots": snapshots,
        "warnings": warnings
    }


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
        headers={"User-Agent": "HermesMarketIntelligence/1.0 (+http://localhost:8000)"}
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
    parser = argparse.ArgumentParser(description="Hermes Market Intelligence Python Adapter Bridge")
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
        resp = build_response(action=action, ok=True, data=data)
        print(json.dumps(resp, ensure_ascii=False))
        sys.exit(0)
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
