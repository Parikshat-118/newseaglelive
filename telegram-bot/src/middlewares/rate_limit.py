"""
Decorator-based rate limiter using Redis fixed-window counters.

    @rate_limited(limit=30, window=60, bucket="cmd")
    async def handler(update, ctx): ...

Buckets are namespaced by `user_id:bucket` so different commands don't share a counter.
"""
from __future__ import annotations

import functools
from typing import Callable

from telegram import Update
from telegram.ext import ContextTypes

from src.config.settings import get_settings
from src.utils.cache import rate_limit
from src.utils.i18n import t
from src.utils.logger import log


def rate_limited(limit: int | None = None, window: int = 60, bucket: str = "cmd") -> Callable:
    def decorator(func: Callable):
        @functools.wraps(func)
        async def wrapper(update: Update, context: ContextTypes.DEFAULT_TYPE, *a, **kw):
            settings = get_settings()
            effective_limit = limit or settings.rate_limit_per_minute
            user = update.effective_user
            if user is None:
                return await func(update, context, *a, **kw)
            allowed, count = await rate_limit(f"{user.id}:{bucket}", effective_limit, window)
            if not allowed:
                lang = (context.user_data or {}).get("lang", settings.default_language)
                if update.effective_chat:
                    await update.effective_chat.send_message(t("rate_limited", lang))
                log.info("Rate-limited tg={} bucket={} count={}", user.id, bucket, count)
                return None
            return await func(update, context, *a, **kw)
        return wrapper
    return decorator
