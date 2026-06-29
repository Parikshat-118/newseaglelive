"""News commands: /news /latest /breaking /trending."""
from __future__ import annotations

from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.config.categories import CATEGORIES, label
from src.services import news_service as ns, user_service as us
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.handlers._render import send_article_list


def _category_picker_kb(lang: str = "en") -> InlineKeyboardMarkup:
    """3-column grid; tapping a category fetches its latest articles."""
    items = list(CATEGORIES.values())
    rows: list[list[InlineKeyboardButton]] = []
    for i in range(0, len(items), 3):
        rows.append([
            InlineKeyboardButton(label(c.slug, lang), callback_data=f"news:cat:{c.slug}")
            for c in items[i:i + 3]
        ])
    return InlineKeyboardMarkup(rows)


@rate_limited(bucket="news")
async def cmd_news(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    await update.message.reply_text(
        "📰 *Choose a category:*" if lang == "en" else "📰 *श्रेणी चुनें:*",
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=_category_picker_kb(lang),
    )


@rate_limited(bucket="latest")
async def cmd_latest(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    articles = ns.get_latest(language=lang, limit=8)
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)


@rate_limited(bucket="breaking")
async def cmd_breaking(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    articles = ns.get_breaking(language=lang, limit=8)
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)


@rate_limited(bucket="trending")
async def cmd_trending(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    articles = ns.get_trending(language=lang, limit=8)
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)
