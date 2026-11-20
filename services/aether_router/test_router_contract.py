import importlib.util
import pathlib
import unittest
from unittest.mock import MagicMock, patch


MODULE_PATH = pathlib.Path(__file__).with_name("__init__.py")
SPEC = importlib.util.spec_from_file_location("aether_router", MODULE_PATH)
router = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(router)


class RouterContractTest(unittest.TestCase):
    @patch.object(router.subprocess, "Popen")
    def test_inbound_uses_recovery_launcher_with_exact_receipt_fields(self, popen):
        process = MagicMock()
        process.returncode = 0
        process.communicate.return_value = ('{"status":"success","reply_text":"jawaban"}\n', "")
        popen.return_value = process

        result = router._invoke_laravel_chat("123", "456", "5923895922", "status BBCA", "455")

        self.assertTrue(result["success"])
        self.assertEqual("jawaban", result["stdout"])
        args = popen.call_args.args[0]
        self.assertEqual(router.PHP_EXE, args[0])
        self.assertEqual(router.WRITER_LAUNCHER, args[1])
        self.assertEqual("--telegram-chat", args[2])
        self.assertIn("--update-id=123", args)
        self.assertIn("--message-id=456", args)
        self.assertIn("--user=5923895922", args)
        self.assertIn("--reply-to=455", args)
        self.assertIn("--text=status BBCA", args)
        self.assertNotIn("artisan", args)


if __name__ == "__main__":
    unittest.main()
