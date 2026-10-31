"""On-demand EOD Market Data Collector for IDX Equities via yfinance.
Returns JSON with validated bar data, raw immutable archive path, and SHA-256 provenance.
Zero paid budget constraint (Rp0).
"""

import sys
import os
import json
import hashlib
import datetime
import math
from pathlib import Path

def fetch_ticker_data(ticker_symbol: str) -> dict:
    import yfinance as yf

    clean_ticker = ticker_symbol.upper().strip()
    idx_symbol = f"{clean_ticker}.JK" if clean_ticker != "^JKSE" else clean_ticker

    now_wib = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7)))
    date_folder = now_wib.strftime("%Y%m%d")

    project_root = Path(__file__).resolve().parent.parent
    raw_dir = project_root / "data" / "raw_immutable" / date_folder
    os.makedirs(raw_dir, exist_ok=True)

    try:
        yf_obj = yf.Ticker(idx_symbol)
        raw_hist = yf_obj.history(period="5d", interval="1d", auto_adjust=False)

        if raw_hist.empty:
            return {
                "success": False,
                "error_type": "NO_DATA_OR_DELISTED",
                "message": f"Tidak ada data ditemukan untuk simbol {idx_symbol}. Simbol mungkin tidak terdaftar atau delisted.",
            }

        def validate_row_values(row) -> tuple[bool, str]:
            for col in ["Open", "High", "Low", "Close"]:
                if col not in row or row[col] is None:
                    return False, f"Kolom {col} bernilai null"
                val = float(row[col])
                if not math.isfinite(val) or val <= 0:
                    return False, f"Nilai {col} ({val}) tidak valid (non-finite atau <= 0)"
            vol = row.get("Volume", 0)
            if vol is None or not math.isfinite(float(vol)) or int(vol) < 0:
                return False, f"Nilai Volume ({vol}) tidak valid"
            return True, ""

        # Inspect raw latest row
        raw_latest = raw_hist.iloc[-1]
        raw_latest_date = str(raw_hist.index[-1])[:10]
        discarded_latest = False
        discarded_bar_date = None
        discarded_reason = None

        is_raw_latest_valid, raw_reason = validate_row_values(raw_latest)
        if not is_raw_latest_valid:
            discarded_latest = True
            discarded_bar_date = raw_latest_date
            discarded_reason = f"Bar terakhir ({raw_latest_date}) dibuang: {raw_reason}"

        # Filter strictly valid rows
        valid_indices = []
        for idx in raw_hist.index:
            row = raw_hist.loc[idx]
            ok, _ = validate_row_values(row)
            if ok:
                valid_indices.append(idx)

        if not valid_indices:
            return {
                "success": False,
                "error_type": "NO_VALID_BARS_AVAILABLE",
                "message": f"Seluruh bar untuk {idx_symbol} dalam 5 hari terakhir tidak valid / non-finite.",
                "discarded_latest_bar": discarded_latest,
                "discarded_bar_date": discarded_bar_date,
                "discarded_reason": discarded_reason,
            }

        chosen_idx = valid_indices[-1]
        chosen_bar = raw_hist.loc[chosen_idx]
        bar_date = str(chosen_idx)[:10]

        # Strict ISO date validation
        datetime.date.fromisoformat(bar_date)

        open_p = float(chosen_bar["Open"])
        high_p = float(chosen_bar["High"])
        low_p = float(chosen_bar["Low"])
        close_p = float(chosen_bar["Close"])
        volume = int(chosen_bar["Volume"])

        # OHLC Validation checks
        validation_errors = []
        if open_p <= 0 or high_p <= 0 or low_p <= 0 or close_p <= 0:
            validation_errors.append("Harga tidak boleh <= 0")
        if volume < 0:
            validation_errors.append("Volume tidak boleh negatif")
        if high_p < max(open_p, close_p):
            validation_errors.append(f"High ({high_p}) lebih rendah dari open/close")
        if low_p > min(open_p, close_p):
            validation_errors.append(f"Low ({low_p}) lebih tinggi dari open/close")

        replacement_bar_date = bar_date if discarded_latest else None

        raw_payload = {
            "ticker": clean_ticker,
            "symbol_used": idx_symbol,
            "bar_date": bar_date,
            "open": open_p,
            "high": high_p,
            "low": low_p,
            "close": close_p,
            "volume": volume,
            "fetched_at": now_wib.isoformat(),
            "source": "Yahoo Finance (via yfinance)",
            "source_url": f"https://finance.yahoo.com/quote/{idx_symbol}",
            "currency": "IDR",
            "exchange": "JKT",
            "discarded_latest_bar": discarded_latest,
            "discarded_bar_date": discarded_bar_date,
            "discarded_reason": discarded_reason,
            "replacement_bar_date": replacement_bar_date,
            "validation_errors": validation_errors,
        }

        # Write immutable raw archive
        raw_filename = f"{clean_ticker}_{now_wib.strftime('%H%M%S')}.json"
        raw_filepath = raw_dir / raw_filename
        with open(raw_filepath, "w", encoding="utf-8") as f:
            json.dump(raw_payload, f, indent=2, allow_nan=False)

        # Calculate SHA-256
        h = hashlib.sha256()
        with open(raw_filepath, "rb") as f:
            while chunk := f.read(8192):
                h.update(chunk)
        payload_hash = h.hexdigest()

        return {
            "success": len(validation_errors) == 0,
            "ticker": clean_ticker,
            "symbol": idx_symbol,
            "bar_date": bar_date,
            "open_price": open_p,
            "high_price": high_p,
            "low_price": low_p,
            "close_price": close_p,
            "volume": volume,
            "currency": "IDR",
            "timezone": "Asia/Jakarta",
            "interval": "1d",
            "source": "Yahoo Finance (via yfinance)",
            "source_url": f"https://finance.yahoo.com/quote/{idx_symbol}",
            "raw_payload_path": str(raw_filepath),
            "raw_payload_hash": payload_hash,
            "published_at": f"{bar_date}T16:00:00+07:00",
            "first_seen_at": now_wib.isoformat(),
            "fetched_at": now_wib.isoformat(),
            "available_at": now_wib.isoformat(),
            "validation_status": "VALID" if len(validation_errors) == 0 else "VALIDATION_FAILED",
            "validation_errors": validation_errors,
            "discarded_latest_bar": discarded_latest,
            "discarded_bar_date": discarded_bar_date,
            "discarded_reason": discarded_reason,
            "replacement_bar_date": replacement_bar_date,
        }

    except Exception as e:
        return {
            "success": False,
            "error_type": "FETCHER_EXCEPTION",
            "message": str(e),
        }

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No ticker provided"}))
        sys.exit(1)

    ticker_arg = sys.argv[1]
    res = fetch_ticker_data(ticker_arg)
    print(json.dumps(res, allow_nan=False))
