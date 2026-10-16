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


if __name__ == "__main__":
    unittest.main()
