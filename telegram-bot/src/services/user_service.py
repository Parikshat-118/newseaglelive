"""User-related queries: upsert on /start, prefs, subs, keywords."""
from __future__ import annotations

from datetime import datetime
from typing import List, Optional

from sqlalchemy import select, delete

from src.database.connection import session_scope
from src.database.models import User, UserSubscription, Keyword, Bookmark, Referral
from src.utils.logger import log


def upsert_user(
    telegram_id: int,
    *,
    username: Optional[str] = None,
    first_name: Optional[str] = None,
    last_name: Optional[str] = None,
    referred_by: Optional[int] = None,
) -> User:
    """Find-or-create. Updates name/username if changed."""
    with session_scope() as s:
        user = s.scalar(select(User).where(User.telegram_id == telegram_id))
        if user is None:
            user = User(
                telegram_id=telegram_id,
                username=username,
                first_name=first_name,
                last_name=last_name,
                referred_by=referred_by,
                last_active_at=datetime.utcnow(),
            )
            s.add(user)
            s.flush()  # populate user.id
            if referred_by:
                s.add(Referral(referrer_id=referred_by, referred_id=user.id))
            log.info("New user: tg={} db_id={}", telegram_id, user.id)
        else:
            user.username = username or user.username
            user.first_name = first_name or user.first_name
            user.last_name = last_name or user.last_name
            user.last_active_at = datetime.utcnow()
        s.flush()
        s.expunge(user)
        return user


def get_user_by_tg(telegram_id: int) -> Optional[User]:
    with session_scope() as s:
        u = s.scalar(select(User).where(User.telegram_id == telegram_id))
        if u:
            s.expunge(u)
        return u


def set_language(telegram_id: int, lang: str) -> None:
    with session_scope() as s:
        u = s.scalar(select(User).where(User.telegram_id == telegram_id))
        if u:
            u.language = "hi" if lang == "hi" else "en"


def set_pincode(telegram_id: int, pincode: str, city: str, district: str, state: str) -> None:
    with session_scope() as s:
        u = s.scalar(select(User).where(User.telegram_id == telegram_id))
        if u:
            u.pincode, u.city, u.district, u.state = pincode, city, district, state


def clear_pincode(telegram_id: int) -> None:
    with session_scope() as s:
        u = s.scalar(select(User).where(User.telegram_id == telegram_id))
        if u:
            u.pincode = u.city = u.district = u.state = None


# -------- subscriptions --------
def get_subscriptions(user_db_id: int) -> List[str]:
    with session_scope() as s:
        rows = s.scalars(
            select(UserSubscription.category).where(UserSubscription.user_id == user_db_id)
        ).all()
        return list(rows)


def toggle_subscription(user_db_id: int, category: str) -> bool:
    """Returns the new state (True=subscribed)."""
    with session_scope() as s:
        existing = s.scalar(
            select(UserSubscription).where(
                UserSubscription.user_id == user_db_id,
                UserSubscription.category == category,
            )
        )
        if existing:
            s.delete(existing)
            return False
        s.add(UserSubscription(user_id=user_db_id, category=category))
        return True


# -------- keywords --------
def get_keywords(user_db_id: int) -> List[str]:
    with session_scope() as s:
        rows = s.scalars(
            select(Keyword.keyword).where(Keyword.user_id == user_db_id)
        ).all()
        return list(rows)


def add_keyword(user_db_id: int, keyword: str) -> bool:
    keyword = (keyword or "").strip()
    if not keyword or len(keyword) > 128:
        return False
    with session_scope() as s:
        existing = s.scalar(
            select(Keyword).where(Keyword.user_id == user_db_id, Keyword.keyword == keyword)
        )
        if existing:
            return False
        s.add(Keyword(user_id=user_db_id, keyword=keyword))
        return True


def remove_keyword(user_db_id: int, keyword: str) -> bool:
    with session_scope() as s:
        result = s.execute(
            delete(Keyword).where(
                Keyword.user_id == user_db_id,
                Keyword.keyword == keyword,
            )
        )
        return result.rowcount > 0


# -------- bookmarks --------
def add_bookmark(user_db_id: int, article_id: int) -> bool:
    with session_scope() as s:
        existing = s.scalar(
            select(Bookmark).where(
                Bookmark.user_id == user_db_id, Bookmark.article_id == article_id
            )
        )
        if existing:
            return False
        s.add(Bookmark(user_id=user_db_id, article_id=article_id))
        return True


def remove_bookmark(user_db_id: int, article_id: int) -> bool:
    with session_scope() as s:
        result = s.execute(
            delete(Bookmark).where(
                Bookmark.user_id == user_db_id,
                Bookmark.article_id == article_id,
            )
        )
        return result.rowcount > 0


def is_bookmarked(user_db_id: int, article_id: int) -> bool:
    with session_scope() as s:
        return s.scalar(
            select(Bookmark.id).where(
                Bookmark.user_id == user_db_id, Bookmark.article_id == article_id
            )
        ) is not None


def get_bookmarks(user_db_id: int, limit: int = 20):
    from src.database.models import NewsArticle
    with session_scope() as s:
        rows = s.execute(
            select(NewsArticle).join(Bookmark, Bookmark.article_id == NewsArticle.id)
            .where(Bookmark.user_id == user_db_id)
            .order_by(Bookmark.created_at.desc())
            .limit(limit)
        ).scalars().all()
        for r in rows:
            s.expunge(r)
        return rows
