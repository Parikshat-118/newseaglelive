"""
News service — orchestrates fetching from all adapters, dedupes via Redis,
persists to MySQL, and exposes query helpers for handlers.

Public surface:
    await ingest_all() -> int                       # scheduled
    await ingest_category(category, language) -> int
    get_latest(category=None, language='en', limit=10, offset=0) -> list[Article]
    get_breaking(language='en', limit=10) -> list[Article]
    get_trending(language='en', limit=10) -> list[Article]
    search(query, language='en', limit=10) -> list[Article]
    get_local(state, district=None, limit=10) -> list[Article]   # PIN-based local
    record_view(user_id, article_id) -> None
"""
from __future__ import annotations

from datetime import datetime, timedelta
from typing import Iterable, List, Optional
import os
import re
import urllib.parse
from uuid import uuid4

import httpx
from sqlalchemy import select, or_, and_, func, desc

from src.config.settings import get_settings
from src.database.connection import session_scope
from src.database.models import NewsArticle, ReadingHistory
from src.services.source_adapters.base import FetchedArticle
from src.services.source_adapters.rss_adapter import RSSAdapter
from src.services.source_adapters.newsapi_adapter import NewsAPIAdapter
from src.services.source_adapters.gnews_adapter import GNewsAdapter
from src.utils.cache import seen_article
from src.utils.dedupe import canonical_url, url_hash
from src.utils.logger import log


_adapters = [RSSAdapter(), NewsAPIAdapter(), GNewsAdapter()]

# Breaking-news heuristics: title keywords from any source
_BREAKING_HINTS = (
    "breaking", "just in", "live updates", "alert", "explosion",
    "bombing", "earthquake", "ban imposed", "arrested", "passes away",
)


async def ingest_all() -> int:
    """Fetch from all adapters across all categories. Returns # new articles saved."""
    total_new = 0
    for adapter in _adapters:
        try:
            fetched = await adapter.fetch(category=None, language="en")
            saved = await _persist(fetched)
            total_new += saved
            log.info("Adapter {} → fetched={} saved={}", adapter.name, len(fetched), saved)
        except Exception as e:
            log.exception("Adapter {} failed: {}", adapter.name, e)
    return total_new


async def ingest_category(category: str, language: str = "en") -> int:
    total_new = 0
    for adapter in _adapters:
        try:
            fetched = await adapter.fetch(category=category, language=language)
            total_new += await _persist(fetched)
        except Exception as e:
            log.warning("Adapter {}/{} failed: {}", adapter.name, category, e)
    return total_new


async def _persist(fetched: Iterable[FetchedArticle]) -> int:
    """Dedupe + bulk insert. Returns count of new rows."""
    candidates = []
    for f in fetched:
        url = canonical_url(f.url)
        h = url_hash(url)
        if await seen_article(h):
            continue
        candidates.append((f, url, h))

    if not candidates:
        return 0

    # 1. First, check which ones already exist in the DB
    with session_scope() as s:
        hashes = [h for _, _, h in candidates]
        existing = {
            row[0] for row in s.execute(
                select(NewsArticle.url_hash).where(NewsArticle.url_hash.in_(hashes))
            ).all()
        }

    # 2. Process AI covers outside the DB transaction to prevent locking the DB!
    final_rows = []
    for f, url, h in candidates:
        if h in existing:
            continue
        
        t_lower = (f.title or "").lower()
        is_breaking = f.is_breaking or any(k in t_lower for k in _BREAKING_HINTS)
        
        image_url = f.image_url
        if not image_url:
            image_url = await _generate_ai_cover(f.title, f.summary)
            # Pollinations AI enforces rate limits; sleep to prevent 429 Too Many Requests
            import asyncio
            await asyncio.sleep(2)

        final_rows.append(NewsArticle(
            url_hash=h,
            url=url,
            title=f.title or "",
            summary=f.summary,
            content=f.content,
            image_url=image_url,
            category=f.category,
            language=f.language or "en",
            source_name=f.source_name,
            is_breaking=is_breaking,
            published_at=f.published_at or datetime.utcnow(),
        ))
        
    if not final_rows:
        return 0

    # 3. Bulk insert the finalized rows
    with session_scope() as s:
        s.add_all(final_rows)
        return len(final_rows)


async def _generate_ai_cover(title: str, summary: Optional[str]) -> Optional[str]:
    """Generates an AI cover image if missing, returning the local relative URL."""
    try:
        safe_title = (title or "")[:150]
        safe_summary = (summary or "")[:350]
        
        # Clean newlines and HTML tags
        safe_title = re.sub(r'<[^>]+>', '', safe_title).replace('\n', ' ').strip()
        safe_summary = re.sub(r'<[^>]+>', '', safe_summary).replace('\n', ' ').strip()
        
        prompt = (f"Professional editorial news photograph, highly realistic, illustrating the following news story. "
                  f"Headline: {safe_title}. Summary: {safe_summary}. "
                  f"Natural lighting, documentary style, accurate context, 16:9 composition. "
                  f"No text, logos, watermarks, captions, or graphics.")
        encoded_prompt = urllib.parse.quote(prompt)
        url = f"https://image.pollinations.ai/prompt/{encoded_prompt}?width=1200&height=630&nologo=true"
        
        covers_dir = get_settings().covers_dir
        os.makedirs(covers_dir, exist_ok=True)
        filename = f"{uuid4().hex}.jpg"
        local_path = os.path.join(covers_dir, filename)
        
        async with httpx.AsyncClient(timeout=20.0) as client:
            resp = await client.get(url)
            if resp.status_code == 429:
                log.warning("Pollinations rate limit hit (429).")
                return None
            resp.raise_for_status()
            with open(local_path, "wb") as f:
                f.write(resp.content)
                
        return f"/assets/covers/{filename}"
    except Exception as e:
        log.warning("Failed to generate AI cover: {}", e)
        return None


# ----------------- Queries -----------------
def get_latest(
    category: Optional[str] = None,
    language: str = "en",
    limit: int = 10,
    offset: int = 0,
) -> List[NewsArticle]:
    with session_scope() as s:
        stmt = select(NewsArticle).where(NewsArticle.language == language)
        if category:
            stmt = stmt.where(NewsArticle.category == category)
        stmt = stmt.order_by(desc(NewsArticle.published_at)).offset(offset).limit(limit)
        return list(s.scalars(stmt).all())


def get_breaking(language: str = "en", limit: int = 10) -> List[NewsArticle]:
    cutoff = datetime.utcnow() - timedelta(hours=24)
    with session_scope() as s:
        stmt = (
            select(NewsArticle)
            .where(
                NewsArticle.is_breaking.is_(True),
                NewsArticle.language == language,
                NewsArticle.published_at >= cutoff,
            )
            .order_by(desc(NewsArticle.published_at))
            .limit(limit)
        )
        return list(s.scalars(stmt).all())


def get_trending(language: str = "en", limit: int = 10) -> List[NewsArticle]:
    """Trending = most-bookmarked + most-read in last 24h. Approximated by
    `trending_score` which scheduler refreshes; falls back to recent articles."""
    cutoff = datetime.utcnow() - timedelta(hours=24)
    with session_scope() as s:
        stmt = (
            select(NewsArticle)
            .where(
                NewsArticle.language == language,
                NewsArticle.published_at >= cutoff,
            )
            .order_by(desc(NewsArticle.trending_score), desc(NewsArticle.published_at))
            .limit(limit)
        )
        return list(s.scalars(stmt).all())


def search(query: str, language: str = "en", limit: int = 10) -> List[NewsArticle]:
    """Use MySQL FULLTEXT if available, else LIKE fallback."""
    q = (query or "").strip()
    if not q:
        return []
    with session_scope() as s:
        # Try FULLTEXT (boolean mode for partial matches)
        try:
            stmt = (
                select(NewsArticle)
                .where(NewsArticle.language == language)
                .where(func.match(NewsArticle.title, NewsArticle.summary).bool_op("AGAINST")(
                    f"{q}* IN BOOLEAN MODE"))
                .order_by(desc(NewsArticle.published_at))
                .limit(limit)
            )
            results = list(s.scalars(stmt).all())
            if results:
                return results
        except Exception:
            pass
        # Fallback LIKE
        like = f"%{q}%"
        stmt = (
            select(NewsArticle)
            .where(
                NewsArticle.language == language,
                or_(NewsArticle.title.like(like), NewsArticle.summary.like(like)),
            )
            .order_by(desc(NewsArticle.published_at))
            .limit(limit)
        )
        return list(s.scalars(stmt).all())


def get_local(state: str, district: Optional[str] = None, limit: int = 10) -> List[NewsArticle]:
    """
    Naive local news: match state/district names in title/summary among recent India articles.
    For production, swap in a geo-tagging pipeline.
    """
    cutoff = datetime.utcnow() - timedelta(hours=48)
    needles = [n for n in (state, district) if n]
    if not needles:
        return []
    with session_scope() as s:
        conds = []
        for n in needles:
            like = f"%{n}%"
            conds.append(or_(NewsArticle.title.like(like), NewsArticle.summary.like(like)))
        stmt = (
            select(NewsArticle)
            .where(and_(NewsArticle.published_at >= cutoff, or_(*conds)))
            .order_by(desc(NewsArticle.published_at))
            .limit(limit)
        )
        return list(s.scalars(stmt).all())


def record_view(user_db_id: int, article_id: int) -> None:
    with session_scope() as s:
        s.add(ReadingHistory(user_id=user_db_id, article_id=article_id))


def get_article(article_id: int) -> Optional[NewsArticle]:
    with session_scope() as s:
        return s.get(NewsArticle, article_id)


def refresh_trending_scores() -> None:
    """
    Lightweight trending refresh — bookmarks(24h)*3 + views(24h)*1.
    Run from the scheduler every 15 minutes.
    """
    cutoff = datetime.utcnow() - timedelta(hours=24)
    with session_scope() as s:
        s.execute(
            text_update_trending(cutoff_iso=cutoff.strftime("%Y-%m-%d %H:%M:%S"))
        )


# Raw SQL for the update — kept here so it stays close to its caller.
def text_update_trending(cutoff_iso: str):
    from sqlalchemy import text
    return text("""
        UPDATE news_articles a
        LEFT JOIN (
            SELECT article_id, COUNT(*) c FROM bookmarks
            WHERE created_at >= :cutoff GROUP BY article_id
        ) b ON b.article_id = a.id
        LEFT JOIN (
            SELECT article_id, COUNT(*) c FROM reading_history
            WHERE viewed_at >= :cutoff GROUP BY article_id
        ) r ON r.article_id = a.id
        SET a.trending_score = COALESCE(b.c,0)*3 + COALESCE(r.c,0)
        WHERE a.published_at >= :cutoff
    """).bindparams(cutoff=cutoff_iso)
