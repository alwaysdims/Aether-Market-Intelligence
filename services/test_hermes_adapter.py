"""Unit and regression tests for Hermes Adapter Bridge."""

import json
import subprocess
import sys
import unittest
from pathlib import Path

ADAPTER_PATH = Path(__file__).resolve().parent / "hermes_adapter.py"


class TestHermesAdapter(unittest.TestCase):

    def run_adapter(self, action: str, payload: dict | None = None) -> tuple[int, dict]:
        cmd = [sys.executable, str(ADAPTER_PATH), "--action", action]
        if payload is not None:
            cmd.extend(["--payload", json.dumps(payload)])
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
        try:
            res_json = json.loads(proc.stdout)
        except Exception:
            res_json = {"raw_stdout": proc.stdout, "raw_stderr": proc.stderr}
        return proc.returncode, res_json

    def test_ping_action(self):
        code, resp = self.run_adapter("ping")
        self.assertEqual(code, 0)
        self.assertTrue(resp["ok"])
        self.assertEqual(resp["action"], "ping")
        self.assertIn("status", resp["data"])
        self.assertTrue(resp["data"]["hermes_root_accessible"])

    def test_market_snapshots_valid_symbols(self):
        code, resp = self.run_adapter("market_snapshots", {"symbols": ["BBCA", "MEDC"]})
        self.assertEqual(code, 0)
        self.assertTrue(resp["ok"])
        self.assertEqual(resp["data"]["count"], 2)
        tickers = [s["ticker"] for s in resp["data"]["snapshots"]]
        self.assertIn("BBCA", tickers)
        self.assertIn("MEDC", tickers)

        # Validate snapshot schema
        snap = resp["data"]["snapshots"][0]
        self.assertIn("bar_date", snap)
        self.assertIn("close_price", snap)
        self.assertIn("indicators", snap)
        self.assertIn("atr14", snap["indicators"])
        self.assertIn("rvol20", snap["indicators"])

    def test_paper_trading_status(self):
        code, resp = self.run_adapter("paper_trading_status")
        self.assertEqual(code, 0)
        self.assertTrue(resp["ok"])
        self.assertIn("exp01", resp["data"])
        self.assertIn("exp04", resp["data"])
        self.assertEqual(resp["data"]["exp01"]["initial_capital"], 500000.0)
        self.assertEqual(resp["data"]["exp04"]["initial_capital"], 500000.0)

    def test_accumulation_summary(self):
        code, resp = self.run_adapter("accumulation_summary", {"symbols": ["MEDC"]})
        self.assertEqual(code, 0)
        self.assertTrue(resp["ok"])
        self.assertEqual(resp["data"]["count"], 1)
        self.assertEqual(resp["data"]["results"][0]["ticker"], "MEDC")

    def test_unknown_action_rejection(self):
        code, resp = self.run_adapter("random_invalid_action")
        self.assertEqual(code, 2)
        self.assertFalse(resp["ok"])
        self.assertIn("Unknown action", resp["error"])

    def test_invalid_json_payload(self):
        cmd = [sys.executable, str(ADAPTER_PATH), "--action", "ping", "--payload", "{broken json"]
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
        self.assertEqual(proc.returncode, 1)
        resp = json.loads(proc.stdout)
        self.assertFalse(resp["ok"])
        self.assertIn("Invalid JSON payload", resp["error"])

    def test_eod_validation_valid_and_invalid_scenarios(self):
        """Scenarios 1, 3, 5: Test OHLCV validation rules on valid and invalid dataframes."""
        import pandas as pd
        from hermes_adapter import validate_eod_dataframe

        # Scenario 1: All valid
        df_ok = pd.DataFrame([
            {"Date": "2026-10-16", "Open": 5000.0, "High": 5100.0, "Low": 4950.0, "Close": 5050.0, "Volume": 1000000},
            {"Date": "2026-10-19", "Open": 5050.0, "High": 5200.0, "Low": 5000.0, "Close": 5150.0, "Volume": 1200000},
        ])
        ok, issues = validate_eod_dataframe(df_ok, "BBCA")
        self.assertTrue(ok)
        self.assertEqual(len(issues), 0)

        # Scenario 3: Provider returns empty
        df_empty = pd.DataFrame()
        ok_empty, issues_empty = validate_eod_dataframe(df_empty, "BBCA")
        self.assertFalse(ok_empty)
        self.assertTrue(any("empty" in str(s).lower() for s in issues_empty))

        # Scenario 5: Duplicate dates and High < Low
        df_dup = pd.DataFrame([
            {"Date": "2026-10-19", "Open": 5000.0, "High": 5100.0, "Low": 4950.0, "Close": 5050.0, "Volume": 1000000},
            {"Date": "2026-10-19", "Open": 5000.0, "High": 4800.0, "Low": 4900.0, "Close": 4850.0, "Volume": 1000000},
        ])
        ok_dup, issues_dup = validate_eod_dataframe(df_dup, "BBCA")
        self.assertFalse(ok_dup)
        self.assertTrue(any("Duplicate dates" in s for s in issues_dup))
        self.assertTrue(any("High < Low" in s for s in issues_dup))

    def test_eod_atomic_csv_preservation_on_failure(self):
        """Scenario 7: Ensure old CSV remains intact if atomic merge or validation fails."""
        import tempfile
        import pandas as pd
        from hermes_adapter import merge_and_save_eod_csv

        with tempfile.TemporaryDirectory() as tmpdir:
            csv_path = Path(tmpdir) / "TEST_1d.csv"
            df_orig = pd.DataFrame([
                {"Date": "2026-10-14", "Open": 5000.0, "High": 5100.0, "Low": 4900.0, "Close": 5050.0, "Volume": 500000}
            ])
            df_orig.to_csv(csv_path, index=False)

            # Attempt invalid merge
            class BadData:
                pass

            save_ok, _, err = merge_and_save_eod_csv(str(csv_path), BadData())
            self.assertFalse(save_ok)
            self.assertIsNotNone(err)

            # Verify original file untouched and no .tmp file left
            df_after = pd.read_csv(csv_path)
            self.assertEqual(len(df_after), 1)
            self.assertEqual(df_after.iloc[0]["Date"], "2026-10-14")
            self.assertFalse(Path(str(csv_path) + ".tmp").exists())

    def test_market_snapshots_mock_freshness_enforcement(self):
        """Scenarios 2, 4, 6: Test strict freshness enforcement with mock data."""
        import tempfile
        import pandas as pd

        with tempfile.TemporaryDirectory() as tmpdir:
            # Seed mock CSVs for BBCA and MEDC with at least 5 bars
            for t in ["BBCA", "MEDC"]:
                f = Path(tmpdir) / f"{t}_1d.csv"
                pd.DataFrame([
                    {"Date": "2026-10-08", "Open": 5000.0, "High": 5100.0, "Low": 4900.0, "Close": 5050.0, "Volume": 500000},
                    {"Date": "2026-10-09", "Open": 5050.0, "High": 5150.0, "Low": 4950.0, "Close": 5100.0, "Volume": 500000},
                    {"Date": "2026-10-12", "Open": 5100.0, "High": 5200.0, "Low": 5000.0, "Close": 5150.0, "Volume": 500000},
                    {"Date": "2026-10-13", "Open": 5150.0, "High": 5250.0, "Low": 5050.0, "Close": 5200.0, "Volume": 500000},
                    {"Date": "2026-10-14", "Open": 5200.0, "High": 5300.0, "Low": 5100.0, "Close": 5250.0, "Volume": 500000},
                ]).to_csv(f, index=False)

            # Scenario 4 & 6: Data remains stale (2026-10-14 < expected date) with strict_freshness
            payload_stale = {
                "symbols": ["BBCA", "MEDC"],
                "raw_dir": tmpdir,
                "auto_download": False,
                "strict_freshness": True
            }
            code, resp = self.run_adapter("market_snapshots", payload_stale)
            self.assertEqual(code, 1)
            self.assertFalse(resp["ok"])
            self.assertFalse(resp["data"]["is_fresh"])
            self.assertIn("stale", resp["data"]["error"].lower())

            # Scenario 2: Partial failure with mock_data
            mock_payload_partial = {
                "symbols": ["BBCA", "MEDC"],
                "raw_dir": tmpdir,
                "auto_download": True,
                "strict_freshness": True,
                "mock_data": {
                    "BBCA": [
                        {"Date": "2026-10-19", "Open": 5000.0, "High": 5100.0, "Low": 4900.0, "Close": 5050.0, "Volume": 500000}
                    ],
                    "MEDC": {"error": "Connection timeout to exchange"}
                }
            }
            code2, resp2 = self.run_adapter("market_snapshots", mock_payload_partial)
            self.assertEqual(code2, 1)
            self.assertFalse(resp2["ok"])
            self.assertIn("MEDC", resp2["data"]["failed_tickers"])
            self.assertIn("BBCA", resp2["data"]["synced_tickers"])



if __name__ == "__main__":
    unittest.main()
