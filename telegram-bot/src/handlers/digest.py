"""/digest — today's curated digest on-demand."""
from __future__ import annotations

from datetime import datetime

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.services import digest_service as ds
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited


@rate_limited(limit=5, window=60, bucket="digest")
async def cmd_digest(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    # Choose flavor based on current hour (IST will be set in scheduler/runner)
    hour = datetime.utcnow().hour + 5  # naive IST shift; the scheduler version is correct
    when = "morning" if 0 <= hour < 14 else "evening"
    articles = ds.build_digest(when=when, language=lang, top_n=10)
    if not articles:
        await update.message.reply_text(t("no_news", lang))
        return
    text = ds.render_digest(articles, lang=lang)
    # Telegram limit: 4096 chars per message; truncate if needed.
    if len(text) > 4000:
        text = text[:3990] + "…"
    await update.message.reply_text(
        text, parse_mode=ParseMode.MARKDOWN, disable_web_page_preview=True,
    )
