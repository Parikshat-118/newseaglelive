"""
Shared helper for sending an article card with action buttons.

Kept here so news/search/bookmarks/digest handlers all render consistently.
"""
from __future__ import annotations

from typing import Iterable

from telegram import Update
from telegram.constants import ParseMode

from src.database.models import NewsArticle
from src.services import user_service as us, news_service as ns
from src.utils.formatting import escape_md, truncate
from src.utils.i18n import t
from src.utils.keyboards import article_kb


def _format_article_md(a: NewsArticle, lang: str = "en") -> str:
    title = escape_md(a.title or "")
    source = escape_md(a.source_name or "")
    summary = escape_md(truncate(a.ai_summary or a.summary or "", 500))
    out = f"*{title}*"
    if source:
        out += f"\n_📰 {source}_"
    if summary:
        out += f"\n\n{summary}"
    return out


async def send_article_card(
    update: Update, a: NewsArticle, *, lang: str = "en", db_user_id: int | None = None
) -> None:
    chat = update.effective_chat
    if not chat:
        return
    bookmarked = us.is_bookmarked(db_user_id, a.id) if db_user_id else False
    text = _format_article_md(a, lang)
    kb = article_kb(a.id, bookmarked=bookmarked, url=a.url, lang=lang)
    try:
        if a.image_url:
            await chat.send_photo(
                photo=a.image_url, caption=text[:1024],
                parse_mode=ParseMode.MARKDOWN_V2, reply_markup=kb,
            )
        else:
            await chat.send_message(
                text=text, parse_mode=ParseMode.MARKDOWN_V2,
                reply_markup=kb, disable_web_page_preview=False,
            )
    except Exception:
        # If image URL is broken or MarkdownV2 parse fails, fall back to plain
        await chat.send_message(
            text=f"{a.title}\n\n{(a.summary or '')[:500]}\n\n{a.url}",
            reply_markup=kb,
        )
    if db_user_id:
        try:
            ns.record_view(db_user_id, a.id)
        except Exception:
            pass


async def send_article_list(
    update: Update, articles: Iterable[NewsArticle], *, lang: str = "en",
    db_user_id: int | None = None,
) -> int:
    count = 0
    for a in articles:
        await send_article_card(update, a, lang=lang, db_user_id=db_user_id)
        count += 1
    if count == 0 and update.effective_chat:
        await update.effective_chat.send_message(t("no_news", lang))
    return count
