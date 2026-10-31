"""Installation and deployment script for Aether Router plugin into Hermes Gateway."""

import os
import shutil
import sys

SOURCE_DIR = os.path.dirname(os.path.abspath(__file__))
HERMES_HOME = os.environ.get("HERMES_HOME", os.path.expanduser(r"~\AppData\Local\hermes"))
TARGET_DIR = os.path.join(HERMES_HOME, "plugins", "aether_router")


def install() -> None:
    print(f"[INSTALL] Deploying Aether Router from {SOURCE_DIR} to {TARGET_DIR}...")
    os.makedirs(TARGET_DIR, exist_ok=True)
    for fname in ["plugin.yaml", "__init__.py"]:
        src = os.path.join(SOURCE_DIR, fname)
        dst = os.path.join(TARGET_DIR, fname)
        if os.path.exists(src):
            shutil.copy2(src, dst)
            print(f"  ✓ Copied {fname}")
        else:
            print(f"  ✗ Missing source file: {src}")
            sys.exit(1)

    print("[INSTALL] Aether Router installed successfully.")
    print("Note: Ensure 'plugins.enabled' in config.yaml includes 'aether_router'.")


if __name__ == "__main__":
    install()
