"""/alerts — manage keyword alerts. List + add/remove inline."""
from __future__ import annotations

from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.services import user_service as us
from src.utils.i18n import t
from src.utils.formatting import escape_md
from src.middlewares.rate_limit import rate_limited


def _alerts_kb(keywords: list[str], lang: str) -> InlineKeyboardMarkup:
    rows: list[list[InlineKeyboardButton]] = []
    for kw in keywords:
        rows.append([InlineKeyboardButton(f"🗑️ {kw}", callback_data=f"kw:del:{kw}")])
    add_lbl = "➕ Add keyword" if lang == "en" else "➕ कीवर्ड जोड़ें"
    rows.append([InlineKeyboardButton(add_lbl, callback_data="kw:add")])
    return InlineKeyboardMarkup(rows)


@rate_limited(bucket="alerts")
async def cmd_alerts(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    if not db_uid:
        return
    keywords = us.get_keywords(db_uid)
    header = "🔔 *Your keyword alerts:*" if lang == "en" else "🔔 *आपके कीवर्ड अलर्ट:*"
    if not keywords:
        header = t("keywords_empty", lang)
    await update.message.reply_text(
        header,
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=_alerts_kb(keywords, lang),
    )
