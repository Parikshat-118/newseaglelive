"""/help — list commands."""
from __future__ import annotations

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited


@rate_limited(limit=20, window=60, bucket="help")
async def cmd_help(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    await update.message.reply_text(t("help", lang), parse_mode=ParseMode.MARKDOWN)
