"""/search — keyword search across articles."""
from __future__ import annotations

from telegram import Update
from telegram.ext import ContextTypes

from src.services import news_service as ns
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.handlers._render import send_article_list


@rate_limited(bucket="search")
async def cmd_search(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    args = context.args or []
    if not args:
        prompt = "🔎 Send: `/search <query>`" if lang == "en" else "🔎 भेजें: `/search <खोज>`"
        await update.message.reply_text(prompt)
        return
    query = " ".join(args).strip()
    articles = ns.search(query, language=lang, limit=8)
    if not articles:
        await update.message.reply_text(t("no_news", lang))
        return
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)
