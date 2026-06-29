"""/categories — manage subscribed categories."""
from __future__ import annotations

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.services import user_service as us
from src.utils.i18n import t
from src.utils.keyboards import categories_kb
from src.middlewares.rate_limit import rate_limited


@rate_limited(bucket="categories")
async def cmd_categories(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    selected = us.get_subscriptions(db_uid) if db_uid else []
    await update.message.reply_text(
        t("categories_title", lang),
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=categories_kb(selected, lang, with_done=True),
    )
