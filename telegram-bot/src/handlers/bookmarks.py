"""/bookmarks — list saved articles."""
from __future__ import annotations

from telegram import Update
from telegram.ext import ContextTypes

from src.services import user_service as us
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.handlers._render import send_article_list


@rate_limited(bucket="bookmarks")
async def cmd_bookmarks(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    if not db_uid:
        return
    articles = us.get_bookmarks(db_uid, limit=15)
    if not articles:
        await update.message.reply_text(t("bookmarks_empty", lang))
        return
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)
