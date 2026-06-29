"""
Notification & broadcast service.

`send_to_user` is for one-off messages from handlers / scheduler.
`broadcast` chunks recipients and respects Telegram's 30 msg/sec global limit
by sleeping between batches.
"""
from __future__ import annotations

import asyncio
from typing import Iterable, Optional

from telegram import Bot, InlineKeyboardMarkup
from telegram.constants import ParseMode
from telegram.error import Forbidden, BadRequest, RetryAfter, TimedOut, NetworkError

from src.database.connection import session_scope
from src.database.models import User
from src.utils.logger import log


async def send_to_user(
    bot: Bot,
    telegram_id: int,
    text: str,
    *,
    reply_markup: Optional[InlineKeyboardMarkup] = None,
    parse_mode: str = ParseMode.MARKDOWN,
    disable_preview: bool = False,
) -> bool:
    """Returns True on success. Marks users banned if they blocked the bot."""
    try:
        await bot.send_message(
            chat_id=telegram_id,
            text=text,
            reply_markup=reply_markup,
            parse_mode=parse_mode,
            disable_web_page_preview=disable_preview,
        )
        return True
    except Forbidden:
        # User blocked the bot — flag & stop sending
        with session_scope() as s:
            u = s.query(User).filter(User.telegram_id == telegram_id).first()
            if u:
                u.is_banned = True
                u.notifications_enabled = False
        return False
    except RetryAfter as e:
        log.warning("RetryAfter {}s for tg={}", e.retry_after, telegram_id)
        await asyncio.sleep(e.retry_after + 1)
        return await send_to_user(bot, telegram_id, text,
                                  reply_markup=reply_markup, parse_mode=parse_mode,
                                  disable_preview=disable_preview)
    except (TimedOut, NetworkError) as e:
        log.warning("Network error sending to tg={}: {}", telegram_id, e)
        return False
    except BadRequest as e:
        log.warning("BadRequest tg={}: {}", telegram_id, e)
        return False


async def broadcast(
    bot: Bot,
    recipient_ids: Iterable[int],
    text: str,
    *,
    reply_markup: Optional[InlineKeyboardMarkup] = None,
    batch_size: int = 25,
    inter_batch_pause: float = 1.0,
) -> tuple[int, int]:
    """
    Send a message to many users.
    Returns (success_count, failure_count).

    Telegram allows ~30 msgs/sec to different users globally; we use 25 + 1s pause
    to stay well under the limit.
    """
    ids = list(recipient_ids)
    ok = 0
    fail = 0
    for i in range(0, len(ids), batch_size):
        chunk = ids[i:i + batch_size]
        results = await asyncio.gather(*[
            send_to_user(bot, uid, text, reply_markup=reply_markup) for uid in chunk
        ], return_exceptions=True)
        for r in results:
            if r is True:
                ok += 1
            else:
                fail += 1
        await asyncio.sleep(inter_batch_pause)
    log.info("Broadcast complete: ok={} fail={} total={}", ok, fail, len(ids))
    return ok, fail
