"""
Daily digest builder. Morning vs evening differ only in copy + content focus.
"""
from __future__ import annotations

from datetime import datetime, timedelta
from typing import List

from sqlalchemy import select, desc

from src.database.connection import session_scope
from src.database.models import NewsArticle


def build_digest(when: str = "morning", language: str = "en", top_n: int = 10) -> List[NewsArticle]:
    """
    `when`='morning' surfaces last-24h top stories.
    `when`='evening' surfaces last-12h stories — fresher, day-summary style.
    """
    hours = 24 if when == "morning" else 12
    cutoff = datetime.utcnow() - timedelta(hours=hours)
    with session_scope() as s:
        stmt = (
            select(NewsArticle)
            .where(
                NewsArticle.language == language,
                NewsArticle.published_at >= cutoff,
            )
            .order_by(desc(NewsArticle.trending_score), desc(NewsArticle.published_at))
            .limit(top_n)
        )
        rows = s.scalars(stmt).all()
        for r in rows:
            s.expunge(r)
        return list(rows)


def render_digest(articles: List[NewsArticle], lang: str = "en") -> str:
    if not articles:
        return "📭 No digest articles available."
    header_en = "🦅 *News Eagle Live — Today's Digest*\n\n"
    header_hi = "🦅 *News Eagle Live — आज का सारांश*\n\n"
    out = [header_hi if lang == "hi" else header_en]
    for i, a in enumerate(articles, 1):
        out.append(f"*{i}.* [{a.title}]({a.url})")
        if a.summary:
            snip = (a.summary[:160] + "…") if len(a.summary) > 160 else a.summary
            out.append(f"_{snip}_")
        out.append("")
    return "\n".join(out)
