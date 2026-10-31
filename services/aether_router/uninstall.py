"""Rollback / Uninstall script for Aether Router plugin from Hermes Gateway."""

import os
import shutil

HERMES_HOME = os.environ.get("HERMES_HOME", os.path.expanduser(r"~\AppData\Local\hermes"))
TARGET_DIR = os.path.join(HERMES_HOME, "plugins", "aether_router")


def uninstall() -> None:
    print(f"[UNINSTALL] Removing Aether Router from {TARGET_DIR}...")
    if os.path.exists(TARGET_DIR):
        shutil.rmtree(TARGET_DIR)
        print("  ✓ Removed plugin directory.")
    else:
        print("  ℹ Plugin directory does not exist.")
    print("[UNINSTALL] Rollback complete.")


if __name__ == "__main__":
    uninstall()
