"""
Keyword-alert matcher.

Called by the scheduler after each ingest cycle. Finds articles whose title
or summary contains any user's keyword and enqueues notifications.
"""
from __future__ import annotations

from datetime import datetime, timedelta
from typing import Optional

from sqlalchemy import select, and_, or_, distinct

from src.database.connection import session_scope
from src.database.models import (
    NewsArticle, Keyword, User, Notification,
)
from src.utils.logger import log


def find_keyword_matches(since_minutes: int = 20) -> list[tuple[int, int, str]]:
    """
    Returns a list of (user_telegram_id, article_id, matched_keyword) tuples
    for articles that arrived in the last `since_minutes` and match any
    active keyword. Skips matches already recorded as notifications.
    """
    cutoff = datetime.utcnow() - timedelta(minutes=since_minutes)
    out: list[tuple[int, int, str]] = []
    with session_scope() as s:
        recent_articles = s.scalars(
            select(NewsArticle)
            .where(NewsArticle.fetched_at >= cutoff)
            .order_by(NewsArticle.fetched_at.desc())
            .limit(500)
        ).all()
        if not recent_articles:
            return out

        # All active keywords joined with their owning user
        rows = s.execute(
            select(Keyword.keyword, Keyword.user_id, User.telegram_id, User.is_banned, User.notifications_enabled)
            .join(User, User.id == Keyword.user_id)
        ).all()

        # Build keyword index: lowercase keyword -> [(user_id, telegram_id)]
        kw_index: dict[str, list[tuple[int, int]]] = {}
        for kw, uid, tg, banned, notif_on in rows:
            if banned or not notif_on:
                continue
            kw_index.setdefault(kw.lower(), []).append((uid, tg))

        # Match
        for art in recent_articles:
            text = f"{art.title} {art.summary or ''}".lower()
            for kw, users in kw_index.items():
                if kw in text:
                    for uid, tg in users:
                        # Skip if already notified
                        exists = s.scalar(
                            select(Notification.id).where(and_(
                                Notification.user_id == uid,
                                Notification.article_id == art.id,
                                Notification.kind == "keyword",
                            ))
                        )
                        if exists:
                            continue
                        s.add(Notification(
                            user_id=uid, article_id=art.id, kind="keyword",
                            payload={"keyword": kw},
                        ))
                        out.append((tg, art.id, kw))
    if out:
        log.info("Keyword matches enqueued: {}", len(out))
    return out
