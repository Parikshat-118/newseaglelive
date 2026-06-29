"""
SQLAlchemy ORM models for News Eagle Live (MySQL 8 / utf8mb4 / InnoDB).

Mirrors `schema.sql` + `migrate-v2.sql` exactly.

v2 additions:
    - users.mobile_number / mobile_verified / is_admin / display_name
"""
from __future__ import annotations

from datetime import datetime
from typing import Optional, List

from sqlalchemy import (
    BigInteger, Integer, String, Text, DateTime, Boolean, ForeignKey, JSON,
    Enum as SAEnum, UniqueConstraint, Index, func, Numeric,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


class User(Base):
    __tablename__ = "users"

    id:          Mapped[int]  = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    telegram_id: Mapped[int]  = mapped_column(BigInteger, unique=True, nullable=False, index=True)
    username:    Mapped[Optional[str]]  = mapped_column(String(64))
    first_name:  Mapped[Optional[str]]  = mapped_column(String(128))
    last_name:   Mapped[Optional[str]]  = mapped_column(String(128))
    language:    Mapped[str]  = mapped_column(SAEnum("en", "hi", name="lang_enum"), default="en", nullable=False)
    is_premium:  Mapped[bool] = mapped_column(Boolean, default=False)
    is_banned:   Mapped[bool] = mapped_column(Boolean, default=False)
    referred_by: Mapped[Optional[int]] = mapped_column(BigInteger)

    # ── v2: web app integration ──────────────────────────────────────
    mobile_number:   Mapped[Optional[str]] = mapped_column(String(15))
    mobile_verified: Mapped[bool] = mapped_column(Boolean, default=False)
    is_admin:        Mapped[bool] = mapped_column(Boolean, default=False)
    display_name:    Mapped[Optional[str]] = mapped_column(String(128))

    pincode:  Mapped[Optional[str]]  = mapped_column(String(10), index=True)
    city:     Mapped[Optional[str]]  = mapped_column(String(128))
    district: Mapped[Optional[str]]  = mapped_column(String(128))
    state:    Mapped[Optional[str]]  = mapped_column(String(128))

    notifications_enabled: Mapped[bool] = mapped_column(Boolean, default=True)
    breaking_alerts:       Mapped[bool] = mapped_column(Boolean, default=True)
    morning_digest:        Mapped[bool] = mapped_column(Boolean, default=True)
    evening_digest:        Mapped[bool] = mapped_column(Boolean, default=True)
    weather_alerts:        Mapped[bool] = mapped_column(Boolean, default=False)

    created_at:     Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    updated_at:     Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now())
    last_active_at: Mapped[Optional[datetime]] = mapped_column(DateTime)

    subscriptions: Mapped[List["UserSubscription"]] = relationship(
        back_populates="user", cascade="all, delete-orphan")
    keywords: Mapped[List["Keyword"]] = relationship(
        back_populates="user", cascade="all, delete-orphan")
    bookmarks: Mapped[List["Bookmark"]] = relationship(
        back_populates="user", cascade="all, delete-orphan")
    history: Mapped[List["ReadingHistory"]] = relationship(
        back_populates="user", cascade="all, delete-orphan")


class UserSubscription(Base):
    __tablename__ = "user_subscriptions"
    __table_args__ = (UniqueConstraint("user_id", "category", name="uq_user_category"),)
    id:        Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:   Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    category:  Mapped[str]      = mapped_column(String(32), nullable=False, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    user: Mapped["User"] = relationship(back_populates="subscriptions")


class Keyword(Base):
    __tablename__ = "keywords"
    __table_args__ = (UniqueConstraint("user_id", "keyword", name="uq_user_keyword"),)
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:    Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    keyword:    Mapped[str]      = mapped_column(String(128), nullable=False, index=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    user: Mapped["User"] = relationship(back_populates="keywords")


class NewsSource(Base):
    __tablename__ = "news_sources"
    id:        Mapped[int]  = mapped_column(Integer, primary_key=True, autoincrement=True)
    name:      Mapped[str]  = mapped_column(String(128), nullable=False)
    kind:      Mapped[str]  = mapped_column(SAEnum("rss", "newsapi", "gnews", name="source_kind"), nullable=False)
    url:       Mapped[str]  = mapped_column(String(1024), nullable=False)
    category:  Mapped[Optional[str]] = mapped_column(String(32), index=True)
    language:  Mapped[str]  = mapped_column(SAEnum("en", "hi", name="lang_enum"), default="en")
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    last_fetched_at: Mapped[Optional[datetime]] = mapped_column(DateTime)
    last_error: Mapped[Optional[str]] = mapped_column(String(512))


class NewsArticle(Base):
    __tablename__ = "news_articles"
    __table_args__ = (
        Index("ix_articles_category_pub", "category", "published_at"),
        Index("ix_articles_breaking", "is_breaking", "published_at"),
    )
    id:           Mapped[int]  = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    url_hash:     Mapped[str]  = mapped_column(String(64), unique=True, nullable=False)
    url:          Mapped[str]  = mapped_column(String(1024), nullable=False)
    title:        Mapped[str]  = mapped_column(String(512), nullable=False)
    summary:      Mapped[Optional[str]] = mapped_column(Text)
    ai_summary:   Mapped[Optional[str]] = mapped_column(Text)
    ai_summary_hi: Mapped[Optional[str]] = mapped_column(Text)
    content:      Mapped[Optional[str]] = mapped_column(Text)
    image_url:    Mapped[Optional[str]] = mapped_column(String(1024))
    category:     Mapped[Optional[str]] = mapped_column(String(32), index=True)
    language:     Mapped[str]  = mapped_column(SAEnum("en", "hi", name="lang_enum"), default="en", index=True)
    source_id:    Mapped[Optional[int]] = mapped_column(ForeignKey("news_sources.id", ondelete="SET NULL"))
    source_name:  Mapped[Optional[str]] = mapped_column(String(128))
    is_breaking:  Mapped[bool] = mapped_column(Boolean, default=False)
    trending_score: Mapped[int] = mapped_column(Integer, default=0, index=True)
    view_count:   Mapped[int]  = mapped_column(Integer, default=0)
    like_count:   Mapped[int]  = mapped_column(Integer, default=0)
    share_count:  Mapped[int]  = mapped_column(Integer, default=0)
    published_at: Mapped[Optional[datetime]] = mapped_column(DateTime, index=True)
    fetched_at:   Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class Bookmark(Base):
    __tablename__ = "bookmarks"
    __table_args__ = (UniqueConstraint("user_id", "article_id", name="uq_bookmark"),)
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:    Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    article_id: Mapped[int]      = mapped_column(ForeignKey("news_articles.id", ondelete="CASCADE"), nullable=False)
    note:       Mapped[Optional[str]] = mapped_column(String(512))
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    user: Mapped["User"] = relationship(back_populates="bookmarks")
    article: Mapped["NewsArticle"] = relationship()


class ReadingHistory(Base):
    __tablename__ = "reading_history"
    __table_args__ = (Index("ix_history_user_time", "user_id", "viewed_at"),)
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:    Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    article_id: Mapped[int]      = mapped_column(ForeignKey("news_articles.id", ondelete="CASCADE"), nullable=False)
    viewed_at:  Mapped[datetime] = mapped_column(DateTime, server_default=func.now())
    user: Mapped["User"] = relationship(back_populates="history")
    article: Mapped["NewsArticle"] = relationship()


class Notification(Base):
    __tablename__ = "notifications"
    id:          Mapped[int]  = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:     Mapped[int]  = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True)
    kind:        Mapped[str]  = mapped_column(
        SAEnum("breaking", "keyword", "digest", "quiz", "broadcast", "weather", name="notif_kind"),
        nullable=False,
    )
    article_id:  Mapped[Optional[int]] = mapped_column(ForeignKey("news_articles.id", ondelete="SET NULL"))
    payload:     Mapped[Optional[dict]] = mapped_column(JSON)
    delivered:   Mapped[bool] = mapped_column(Boolean, default=False, index=True)
    delivered_at: Mapped[Optional[datetime]] = mapped_column(DateTime)
    created_at:  Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class Quiz(Base):
    __tablename__ = "quizzes"
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    topic:      Mapped[str]      = mapped_column(String(128), nullable=False)
    cadence:    Mapped[str]      = mapped_column(SAEnum("daily", "weekly", name="quiz_cadence"), default="daily")
    language:   Mapped[str]      = mapped_column(SAEnum("en", "hi", name="lang_enum"), default="en")
    questions:  Mapped[list]     = mapped_column(JSON, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class QuizResult(Base):
    __tablename__ = "quiz_results"
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:    Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    quiz_id:    Mapped[int]      = mapped_column(ForeignKey("quizzes.id", ondelete="CASCADE"), nullable=False)
    score:      Mapped[int]      = mapped_column(Integer, default=0)
    total:      Mapped[int]      = mapped_column(Integer, default=0)
    answers:    Mapped[Optional[dict]] = mapped_column(JSON)
    taken_at:   Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class Referral(Base):
    __tablename__ = "referrals"
    __table_args__ = (UniqueConstraint("referrer_id", "referred_id", name="uq_referrer_referred"),)
    id:           Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    referrer_id:  Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    referred_id:  Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    created_at:   Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class PremiumUser(Base):
    __tablename__ = "premium_users"
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:    Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    plan:       Mapped[str]      = mapped_column(String(32), default="monthly")
    starts_at:  Mapped[datetime] = mapped_column(DateTime, nullable=False)
    expires_at: Mapped[datetime] = mapped_column(DateTime, nullable=False)
    is_active:  Mapped[bool]     = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now())


class ChatSession(Base):
    __tablename__ = "chat_sessions"
    id:         Mapped[int]      = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id:    Mapped[int]      = mapped_column(ForeignKey("users.id", ondelete="CASCADE"),
                                                 nullable=False, unique=True)
    messages:   Mapped[list]     = mapped_column(JSON, nullable=False, default=list)
    updated_at: Mapped[datetime] = mapped_column(DateTime, server_default=func.now(), onupdate=func.now())


class PincodeEntry(Base):
    __tablename__ = "pincode_directory"
    pincode:  Mapped[str]   = mapped_column(String(10), primary_key=True)
    city:     Mapped[str]   = mapped_column(String(128), nullable=False)
    district: Mapped[str]   = mapped_column(String(128), nullable=False, index=True)
    state:    Mapped[str]   = mapped_column(String(128), nullable=False, index=True)
    lat:      Mapped[Optional[float]] = mapped_column(Numeric(9, 6))
    lng:      Mapped[Optional[float]] = mapped_column(Numeric(9, 6))
