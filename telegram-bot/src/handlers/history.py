"""/history — recently viewed articles."""
from __future__ import annotations

from sqlalchemy import select, desc

from telegram import Update
from telegram.ext import ContextTypes

from src.database.connection import session_scope
from src.database.models import ReadingHistory, NewsArticle
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.handlers._render import send_article_list


def _recent_for(user_db_id: int, limit: int = 15):
    with session_scope() as s:
        rows = s.execute(
            select(NewsArticle)
            .join(ReadingHistory, ReadingHistory.article_id == NewsArticle.id)
            .where(ReadingHistory.user_id == user_db_id)
            .order_by(desc(ReadingHistory.viewed_at))
            .limit(limit)
        ).scalars().all()
        for r in rows:
            s.expunge(r)
        return list(rows)


@rate_limited(bucket="history")
async def cmd_history(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    if not db_uid:
        return
    articles = _recent_for(db_uid, limit=10)
    if not articles:
        await update.message.reply_text(t("history_empty", lang))
        return
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)
