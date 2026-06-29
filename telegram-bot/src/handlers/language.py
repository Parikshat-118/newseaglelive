"""/language — change language."""
from __future__ import annotations

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.utils.i18n import t
from src.utils.keyboards import language_kb
from src.middlewares.rate_limit import rate_limited


@rate_limited(limit=10, window=60, bucket="language")
async def cmd_language(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    await update.message.reply_text(
        t("choose_language", lang),
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=language_kb(),
    )
