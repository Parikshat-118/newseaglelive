"""Admin-only guard for handlers."""
from __future__ import annotations

import functools
from typing import Callable

from telegram import Update
from telegram.ext import ContextTypes

from src.config.settings import get_settings
from src.utils.i18n import t


def admin_only(func: Callable) -> Callable:
    @functools.wraps(func)
    async def wrapper(update: Update, context: ContextTypes.DEFAULT_TYPE, *a, **kw):
        user = update.effective_user
        if user is None or user.id not in get_settings().admin_id_set:
            lang = (context.user_data or {}).get("lang", "en")
            if update.effective_chat:
                await update.effective_chat.send_message(t("admin_only", lang))
            return None
        return await func(update, context, *a, **kw)
    return wrapper
