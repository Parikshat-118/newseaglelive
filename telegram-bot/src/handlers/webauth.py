"""
/webauth, /setmobile, /mymobile — Telegram-OTP web login bridge.

Flow:
    1. User opens website, enters their mobile, clicks "Get code".
    2. User opens Telegram bot, sends /webauth.
    3. Bot generates a 6-digit numeric code valid for 3 minutes.
    4. Bot stores (telegram_id, mobile, code, expires_at) in web_auth_tokens.
    5. Bot replies the code in chat.
    6. User enters the code on the website -> a session is created.

Mobile binding: user must first /setmobile <number>.
Super-admin: mobile 9540739137 is auto-promoted on /setmobile.
"""
from __future__ import annotations

import re
import secrets
from datetime import datetime, timedelta

from sqlalchemy import select, text

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.database.connection import session_scope
from src.database.models import User
from src.utils.logger import log

# Rate-limit decorator is optional. If it doesn't exist (or has a different
# signature in this repo), fall back to a no-op so the handlers still work.
try:
    from src.middlewares.rate_limit import rate_limited  # type: ignore
except Exception:  # pragma: no cover
    def rate_limited(*_args, **_kwargs):           # type: ignore
        def deco(fn):
            return fn
        return deco


_MOBILE_RE = re.compile(r'^(?:\+?91[\s\-]*)?([6-9]\d{9})$')

# Super-admin mobile — promoted to admin on /setmobile
SUPER_ADMIN_MOBILE = "9540739137"


def _normalize_mobile(raw: str) -> str | None:
    if not raw:
        return None
    m = _MOBILE_RE.match(raw.strip())
    return m.group(1) if m else None


# ---------------------------------------------------------------------
# /setmobile
# ---------------------------------------------------------------------
@rate_limited(limit=5, window=60, bucket="setmobile")
async def cmd_setmobile(upd: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not upd.message or not upd.effective_user:
        return

    args = context.args or []
    if not args:
        await upd.message.reply_text(
            "📱 *Set your mobile number*\n\n"
            "Send: `/setmobile <your 10-digit Indian mobile>`\n"
            "Example: `/setmobile 9876543210`\n\n"
            "Needed for web login at News Eagle Live.",
            parse_mode=ParseMode.MARKDOWN,
        )
        return

    mobile = _normalize_mobile(" ".join(args))
    if not mobile:
        await upd.message.reply_text(
            "❌ Invalid mobile. Send 10 digits starting with 6/7/8/9.",
        )
        return

    with session_scope() as s:
        existing = s.scalar(select(User).where(User.mobile_number == mobile))
        if existing and existing.telegram_id != upd.effective_user.id:
            await upd.message.reply_text(
                "⚠️ That mobile is already linked to another account."
            )
            return

        u = s.scalar(select(User).where(User.telegram_id == upd.effective_user.id))
        if not u:
            await upd.message.reply_text("Run /start first.")
            return

        u.mobile_number   = mobile
        u.mobile_verified = True
        if mobile == SUPER_ADMIN_MOBILE:
            u.is_admin = True

    extra = "\n👑 You are now Super Admin." if mobile == SUPER_ADMIN_MOBILE else ""
    await upd.message.reply_text(
        f"✅ Mobile saved: *+91 {mobile}*{extra}\n"
        "Use /webauth to log in to the website.",
        parse_mode=ParseMode.MARKDOWN,
    )


# ---------------------------------------------------------------------
# /mymobile
# ---------------------------------------------------------------------
@rate_limited(limit=5, window=60, bucket="mymobile")
async def cmd_mymobile(upd: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not upd.message or not upd.effective_user:
        return
    with session_scope() as s:
        u = s.scalar(select(User).where(User.telegram_id == upd.effective_user.id))
        if not u or not u.mobile_number:
            await upd.message.reply_text(
                "📱 No mobile linked. Use `/setmobile <number>`.",
                parse_mode=ParseMode.MARKDOWN,
            )
            return
        admin_tag = " · 👑 Admin" if u.is_admin else ""
        await upd.message.reply_text(
            f"📱 Your mobile: *+91 {u.mobile_number}*{admin_tag}",
            parse_mode=ParseMode.MARKDOWN,
        )


# ---------------------------------------------------------------------
# /webauth  (alias: /login)
# ---------------------------------------------------------------------
@rate_limited(limit=5, window=60, bucket="webauth")
async def cmd_webauth(upd: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Generate a 6-digit code valid 3 min and store in web_auth_tokens."""
    if not upd.message or not upd.effective_user:
        return

    with session_scope() as s:
        u = s.scalar(select(User).where(User.telegram_id == upd.effective_user.id))
        if not u:
            await upd.message.reply_text("Run /start first.")
            return
        if not u.mobile_number or not u.mobile_verified:
            await upd.message.reply_text(
                "📱 Link a mobile first: `/setmobile <number>`",
                parse_mode=ParseMode.MARKDOWN,
            )
            return

        code = f"{secrets.randbelow(1000000):06d}"
        expires_at = datetime.utcnow() + timedelta(minutes=3)

        # Invalidate any prior unused codes for this user
        s.execute(text(
            "UPDATE web_auth_tokens SET used = 1 "
            "WHERE telegram_id = :tg AND used = 0"
        ), {"tg": upd.effective_user.id})
        s.execute(text(
            "INSERT INTO web_auth_tokens "
            "(telegram_id, mobile_number, code, expires_at) "
            "VALUES (:tg, :mob, :code, :exp)"
        ), {"tg": upd.effective_user.id, "mob": u.mobile_number,
            "code": code, "exp": expires_at})
        mobile = u.mobile_number

    await upd.message.reply_text(
        f"🔐 *Your web login code*\n\n"
        f"`{code}`\n\n"
        f"⏱ Valid for *3 minutes*.\n"
        f"On the website, enter this code with mobile *+91 {mobile}*.",
        parse_mode=ParseMode.MARKDOWN,
    )
    log.info("webauth code generated for tg={} mobile={}",
             upd.effective_user.id, mobile)
