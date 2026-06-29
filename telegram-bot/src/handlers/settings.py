"""
/settings — preferences menu.

Toggles: breaking alerts, morning digest, evening digest, weather alerts,
notifications master switch. State stored in `users` table; UI rebuilt on
each toggle.
"""
from __future__ import annotations

from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.database.connection import session_scope
from src.database.models import User
from src.services import user_service as us
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited


_TOGGLES = [
    ("notifications_enabled", "🔔 All notifications", "🔔 सभी सूचनाएँ"),
    ("breaking_alerts",       "⚡ Breaking alerts",   "⚡ ब्रेकिंग अलर्ट"),
    ("morning_digest",        "🌅 Morning digest",    "🌅 प्रातः सारांश"),
    ("evening_digest",        "🌆 Evening digest",    "🌆 सायं सारांश"),
    ("weather_alerts",        "🌦️ Weather alerts",    "🌦️ मौसम अलर्ट"),
]


def _settings_kb(user: User, lang: str) -> InlineKeyboardMarkup:
    rows: list[list[InlineKeyboardButton]] = []
    for attr, en, hi in _TOGGLES:
        on = bool(getattr(user, attr))
        label = (hi if lang == "hi" else en) + (" — ✅" if on else " — ⬜")
        rows.append([InlineKeyboardButton(label, callback_data=f"set:toggle:{attr}")])
    rows.append([InlineKeyboardButton(
        "🌐 Language" if lang == "en" else "🌐 भाषा",
        callback_data="set:lang"
    )])
    return InlineKeyboardMarkup(rows)


def toggle_attr(telegram_id: int, attr: str) -> bool:
    """Flip a boolean preference. Returns the new value."""
    if attr not in {a for a, _, _ in _TOGGLES}:
        return False
    with session_scope() as s:
        u = s.query(User).filter(User.telegram_id == telegram_id).first()
        if not u:
            return False
        new = not bool(getattr(u, attr))
        setattr(u, attr, new)
        return new


@rate_limited(bucket="settings")
async def cmd_settings(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    user = update.effective_user
    if not user:
        return
    db_user = us.get_user_by_tg(user.id)
    if not db_user:
        return
    await update.message.reply_text(
        t("settings_title", lang),
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=_settings_kb(db_user, lang),
    )


async def rerender_settings(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Re-render after a toggle (called from the callback router)."""
    user = update.effective_user
    if not user or not update.callback_query or not update.callback_query.message:
        return
    lang = context.user_data.get("lang", "en")
    db_user = us.get_user_by_tg(user.id)
    if not db_user:
        return
    try:
        await update.callback_query.edit_message_reply_markup(
            reply_markup=_settings_kb(db_user, lang)
        )
    except Exception:
        pass
