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


def _sanitize_diagnostic(value: str | None) -> str:
    """Keep bridge diagnostics useful without exposing credentials or multiline noise."""
    if not value:
        return ""
    sanitized = re.sub(
        r"(?i)(password|token|secret|api[_ -]?key|authorization)\\s*[=:]\\s*[^\\s,;}]+",
        r"\1=[REDACTED]",
        value,
    )
    return re.sub(r"[\\r\\n\\t]+", " ", sanitized)[:1000]


ALLOWLISTED_USERS = {"5923895922"}
PROJECT_DIR = r"E:\Aether-Market-Intelligence\dashboard"
PHP_EXE = r"C:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe"
WRITER_LAUNCHER = r"E:\Aether-Market-Intelligence\scripts\launch_app_writer.php"

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
    """Run the recovery-scoped Telegram inbound command through its writer launcher."""
    # Sanitize inputs: cap length and remove null bytes
    sanitized_text = (text[:500] if text else "").replace("\x00", "")

    cmd = [
        PHP_EXE,
        WRITER_LAUNCHER,
        "--telegram-chat",
        f"--text={sanitized_text}",
        f"--user={user_id}",
        f"--update-id={update_id}",
        f"--message-id={message_id}",
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
        stderr_clean = _sanitize_diagnostic(stderr)
        if stderr_clean:
            logger.warning("[AetherRouter] Recovery writer stderr: %s", stderr_clean)

        try:
            receipt = json.loads(stdout_clean)
        except json.JSONDecodeError:
            return {
                "success": False,
                "stdout": None,
                "duration_ms": duration_ms,
                "error_type": "INVALID_RECEIPT" if proc.returncode == 0 else "PROCESS_ERROR",
                "error": "Recovery writer returned non-JSON output",
                "diagnostic": stderr_clean or "stdout was not valid JSON",
            }

        status = receipt.get("status")
        if status in {"success", "duplicate"} and proc.returncode == 0:
            reply_text = receipt.get("reply_text")
            if not isinstance(reply_text, str) or not reply_text:
                return {
                    "success": False,
                    "stdout": None,
                    "duration_ms": duration_ms,
                    "error_type": "INVALID_RECEIPT",
                    "error": "Recovery writer omitted inbound reply",
                    "diagnostic": stderr_clean,
                }
            return {
                "success": True,
                "stdout": reply_text,
                "duration_ms": duration_ms,
                "error_type": "NONE",
                "error": None,
            }

        return {
            "success": False,
            "stdout": None,
            "duration_ms": duration_ms,
            "error_type": receipt.get("error_type") or ("PROCESS_ERROR" if proc.returncode != 0 else "INVALID_RECEIPT"),
            "error": "Recovery writer rejected inbound request",
            "diagnostic": _sanitize_diagnostic(str(receipt.get("error") or stderr_clean)),
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


def _record_telegram_receipt(update_id: str, inbound_message_id: str, outbound_message_id: str) -> dict:
    """Persist a numeric outbound message receipt after Telegram confirms the reply."""
    cmd = [
        PHP_EXE,
        WRITER_LAUNCHER,
        "--record-telegram-receipt",
        f"--update-id={update_id}",
        f"--message-id={inbound_message_id}",
        f"--outbound-message-id={outbound_message_id}",
    ]
    try:
        proc = subprocess.run(
            cmd,
            cwd=PROJECT_DIR,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=30,
            check=False,
        )
    except Exception as exc:
        return {"success": False, "error": str(exc)}

    if proc.returncode != 0:
        return {"success": False, "error": proc.stderr.strip() or "receipt command failed"}

    try:
        payload = json.loads(proc.stdout)
    except json.JSONDecodeError:
        return {"success": False, "error": "receipt command returned invalid JSON"}

    return {"success": payload.get("status") == "recorded", "error": payload.get("error")}


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
                logger.info("[AetherRouter] inbound trace update_id=%s inbound_message_id=%s", update_id, msg_id)

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

                try:
                    if result.get("success") and result.get("stdout"):
                        out_text = str(result["stdout"])
                        if len(out_text) > 3900:
                            out_text = out_text[:3900] + "\n\n[OUTPUT_TRUNCATED_BY_TELEGRAM_LIMIT]"
                        sent_msg = await update.message.reply_text(out_text)
                        receipt = _record_telegram_receipt(update_id, msg_id, str(sent_msg.message_id))
                        if not receipt.get("success"):
                            logger.error("[AetherRouter] Telegram receipt write failed: %s", receipt.get("error"))
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
                except Exception as exc:
                    diagnostic = _sanitize_diagnostic(str(exc))
                    logger.exception("[AetherRouter] Response delivery failed; core dispatch blocked: %s", diagnostic)
                    try:
                        sent_msg = await update.message.reply_text(
                            "⚠️ Aether gagal mengirim respons. Permintaan dihentikan; tidak diteruskan ke agen umum."
                        )
                        logger.info("[AetherRouter] Sent delivery-failure notice (Msg ID: %s)", sent_msg.message_id)
                    except Exception as notice_exc:
                        logger.error(
                            "[AetherRouter] Delivery-failure notice also failed: %s",
                            _sanitize_diagnostic(str(notice_exc)),
                        )
                finally:
                    # STOP propagation even when bridge or Telegram response fails.
                    raise ApplicationHandlerStop()

            # Add handler to group -1 so allowlisted Telegram messages stop before Hermes core dispatch.
            native.add_handler(MessageHandler(filters.TEXT, _aether_message_handler), group=-1)
            logger.info(
                "Aether Router MessageHandler wired successfully into PTB Application: group=-1 filter=TEXT allowlist=%s stop_propagation=ApplicationHandlerStop",
                sorted(ALLOWLISTED_USERS),
            )
        except Exception as e:
            logger.error("Failed to wire Aether Router MessageHandler: %s", e)

    ctx.register_telegram_handler(telegram_handler_factory)
