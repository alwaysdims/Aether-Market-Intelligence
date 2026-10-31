"""Aether Market Intelligence deterministic Telegram router plugin.
Intercepts Aether-related commands and natural queries before they reach the Hermes LLM agent.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import subprocess
import time
from typing import Any

logger = logging.getLogger("hermes.plugins.aether_router")

ALLOWLISTED_USERS = {"5923895922"}
PROJECT_DIR = r"E:\Aether-Market-Intelligence\dashboard"
PHP_EXE = r"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe"

# Patterns for Aether intents
AETHER_COMMANDS = {"/help", "/status", "/signals", "/signal", "/recap", "/news", "/analisis", "/analysis"}
TICKERS = {"bbca", "bbri", "bmri", "bbni", "tlkm", "asii", "arto", "medc", "mapi", "smra", "^jkse", "ihsg"}


def _is_aether_query(text: str) -> bool:
    if not text:
        return False
    t = text.strip().lower()
    
    # 1. Exact or prefix slash commands
    first_word = t.split()[0] if t else ""
    if first_word in AETHER_COMMANDS or any(first_word.startswith(cmd) for cmd in AETHER_COMMANDS):
        return True
    
    # 2. Status intent variations
    if any(p in t for p in ["status system", "status sistem", "kondisi sistem", "gimana sistem", "keadaan sistem"]):
        return True
        
    # 3. Ticker queries
    if any(re.search(rf"\b{tk}\b", t) for tk in TICKERS):
        return True
        
    # 4. Ticker analysis & emiten queries (including unknown tickers so they receive deterministic clarification instead of LLM)
    if any(p in t for p in ["analisis", "analisa", "bedah", "emiten", "kondisi saham", "kinerja saham"]):
        return True

    # 5. Rationale & abstain queries
    if any(p in t for p in ["kenapa abstain", "alasan", "probabilitas", "confidence"]):
        return True
        
    # 6. Validity queries
    if any(p in t for p in ["masih berlaku", "masa berlaku", "apakah berlaku", "sinyal valid"]):
        return True
        
    # 7. Recap queries
    if any(p in t for p in ["rekap", "kinerja", "performa"]):
        return True

    return False


def _is_consultative_query(text: str) -> bool:
    """Determine whether an inbound query is an analytical or consultative request."""
    t = text.lower()
    return any(p in t for p in [
        "analisis", "analisa", "diskon", "valuasi", "worth it", "crash",
        "layak", "risiko", "laporan keuangan", "lapkeu", "keterbukaan",
        "rugi", "laba", "divestasi", "modal saya"
    ])


def _invoke_laravel_chat(update_id: str, message_id: str, user_id: str, text: str, reply_to_id: str | None = None) -> dict:
    """Run Laravel TelegramTwoWayService synchronously via PHP artisan command."""
    # Sanitize inputs: cap length and remove null bytes
    sanitized_text = (text[:500] if text else "").replace("\x00", "")

    cmd = [
        PHP_EXE,
        "artisan",
        "hermes:telegram-chat",
        f"--text={sanitized_text}",
        f"--user={user_id}",
        f"--update-id={update_id}",
        f"--message-id={message_id}",
        "--raw",
    ]
    if reply_to_id:
        cmd.append(f"--reply-to={reply_to_id}")

    proc = None
    try:
        t0 = time.monotonic()
        proc = subprocess.Popen(
            cmd,
            cwd=PROJECT_DIR,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        try:
            stdout, stderr = proc.communicate(timeout=30)
        except subprocess.TimeoutExpired:
            logger.warning("[AetherRouter] Subprocess timeout after 30s, terminating PID %s...", proc.pid)
            proc.terminate()
            try:
                proc.wait(timeout=2)
            except subprocess.TimeoutExpired:
                logger.error("[AetherRouter] Subprocess PID %s did not terminate, killing...", proc.pid)
                proc.kill()
                proc.wait(timeout=2)

            return {
                "success": False,
                "stdout": None,
                "duration_ms": 30000,
                "error_type": "TIMEOUT",
                "error": "Process timed out after 30s",
            }

        duration_ms = int((time.monotonic() - t0) * 1000)
        stdout_clean = stdout.strip() if stdout else ""
        stderr_clean = stderr.strip() if stderr else ""

        return {
            "success": proc.returncode == 0,
            "stdout": stdout_clean,
            "duration_ms": duration_ms,
            "error_type": "NONE" if proc.returncode == 0 else "PROCESS_ERROR",
            "error": stderr_clean if proc.returncode != 0 else None,
        }
    except Exception as exc:
        if proc and proc.poll() is None:
            try:
                proc.kill()
            except Exception:
                pass
        return {
            "success": False,
            "stdout": None,
            "duration_ms": 0,
            "error_type": "EXCEPTION",
            "error": str(exc),
        }


def register(ctx: Any) -> None:
    """Register platform handler with Hermes Gateway."""
    logger.info("Registering Aether Router platform handler for telegram...")

    def telegram_handler_factory(native: Any, adapter: Any) -> None:
        try:
            from telegram import Update
            from telegram.ext import ApplicationHandlerStop, ContextTypes, MessageHandler, filters

            async def _aether_message_handler(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
                if not update or not update.message or not update.message.text:
                    return

                sender_id = str(update.effective_user.id) if update.effective_user else ""
                if sender_id not in ALLOWLISTED_USERS:
                    # Not in allowlist, allow core Hermes to handle or drop
                    return

                text = update.message.text.strip()
                logger.info("[AetherRouter v1.1.0] Intercepting chat from allowlisted user %s: %r", sender_id, text[:60])

                update_id = str(update.update_id) if update.update_id is not None else str(int(time.time()))
                msg_id = str(update.message.message_id)
                reply_to_id = str(update.message.reply_to_message.message_id) if update.message.reply_to_message else None

                # Execute Aether Laravel logic in background thread
                loop = asyncio.get_running_loop()
                result = await loop.run_in_executor(
                    None,
                    _invoke_laravel_chat,
                    update_id,
                    msg_id,
                    sender_id,
                    text,
                    reply_to_id,
                )

                if result.get("success") and result.get("stdout"):
                    out_text = result["stdout"]
                    sent_msg = await update.message.reply_text(out_text)
                    logger.info(
                        "[AetherRouter] Successfully replied (Telegram Msg ID: %s, duration: %d ms)",
                        sent_msg.message_id,
                        result.get("duration_ms", 0),
                    )
                else:
                    err_type = result.get("error_type", "UNKNOWN")
                    err_raw = result.get("error") or ""
                    logger.error("[AetherRouter] Laravel execution failed (%s): %s", err_type, err_raw)

                    if err_type == "TIMEOUT":
                        err_msg = "⚠️ Waktu pemrosesan kueri gateway melebihi batas (Timeout 30s). Sistem membatalkan eksekusi untuk menjaga stabilitas."
                    elif "Connection refused" in err_raw or "could not connect to server" in err_raw:
                        err_msg = "⚠️ Layanan basis data PostgreSQL Aether sedang tidak dapat diakses."
                    else:
                        err_msg = "⚠️ Terjadi kendala teknis saat memproses kueri pada subsistem Aether."

                    sent_msg = await update.message.reply_text(err_msg)
                    logger.info("[AetherRouter] Sent controlled failure notice (Msg ID: %s)", sent_msg.message_id)

                # STOP propagation so Hermes core never executes an unconstrained LLM agent turn!
                raise ApplicationHandlerStop()

            # Add handler to group 0 (ahead of core handlers)
            native.add_handler(MessageHandler(filters.TEXT, _aether_message_handler))
            logger.info("Aether Router MessageHandler wired successfully into PTB Application.")
        except Exception as e:
            logger.error("Failed to wire Aether Router MessageHandler: %s", e)

    ctx.register_telegram_handler(telegram_handler_factory)
