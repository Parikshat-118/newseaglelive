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
from src.services.ai_provider import get_ai_provider


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

    settings = get_settings()
    _PLACEHOLDER_VALUES = {"", "null", "none", "n/a", "undefined"}
    MIN_SIZE = settings.ai_image_min_size_kb * 1024  # bytes

    # AI generation statistics
    stats = {"generated": 0, "skipped": 0, "failed": 0, "reused": 0, "gen_times": []}

    # 2. Process AI covers outside the DB transaction to prevent locking the DB!
    final_rows = []
    for f, url, h in candidates:
        if h in existing:
            continue

        t_lower = (f.title or "").lower()
        is_breaking = f.is_breaking or any(k in t_lower for k in _BREAKING_HINTS)

        image_url = f.image_url

        # --- Robust missing image detection (#1) ---
        image_is_missing = False
        if not image_url or str(image_url).strip().lower() in _PLACEHOLDER_VALUES:
            image_is_missing = True
            log.debug("Article '{}': No publisher image provided.", (f.title or "")[:60])
        elif settings.verify_publisher_images:
            # --- Validate publisher image (#17) ---
            is_valid = await _verify_publisher_image(image_url)
            if not is_valid:
                image_is_missing = True
                log.info("Article '{}': Publisher image invalid/unreachable → generating AI cover.", (f.title or "")[:60])
            else:
                log.debug("Article '{}': Publisher image verified ✓. Skipping AI generation.", (f.title or "")[:60])
                stats["skipped"] += 1
        else:
            log.debug("Article '{}': Publisher image exists. Skipping AI generation.", (f.title or "")[:60])
            stats["skipped"] += 1

        if image_is_missing:
            # --- Deduplication check (#13) ---
            dedup_filename = f"{h}.jpg"
            dedup_path = os.path.join(settings.covers_dir, dedup_filename)
            if os.path.exists(dedup_path) and os.path.getsize(dedup_path) >= MIN_SIZE:
                image_url = f"/assets/covers/{dedup_filename}"
                stats["reused"] += 1
                log.info("Article '{}': Reusing existing AI cover ({:.1f} KB).",
                         (f.title or "")[:60], os.path.getsize(dedup_path) / 1024)
            else:
                # Delete corrupted/small file if it exists
                if os.path.exists(dedup_path):
                    try:
                        os.remove(dedup_path)
                        log.warning("Deleted corrupted/small AI cover: {}", dedup_filename)
                    except OSError:
                        pass
                gen_result = await _generate_ai_cover(f.title, f.summary, h)
                if gen_result:
                    image_url = gen_result["url"]
                    stats["generated"] += 1
                    stats["gen_times"].append(gen_result["time"])
                else:
                    image_url = None
                    stats["failed"] += 1

        # Generate search tags via AI
        search_tags = await _generate_search_tags(f.title, f.summary)

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
            search_tags=search_tags,
            published_at=f.published_at or datetime.utcnow(),
        ))

    if not final_rows:
        return 0

    # 3. Bulk insert the finalized rows
    with session_scope() as s:
        s.add_all(final_rows)

    # --- Log AI generation statistics (#15, #16) ---
    total_gen = stats["generated"] + stats["failed"]
    if total_gen > 0 or stats["reused"] > 0:
        total_time = sum(stats["gen_times"])
        avg_time = (total_time / len(stats["gen_times"])) if stats["gen_times"] else 0
        log.info(
            "AI Cover Summary | Generated: {} | Skipped: {} | Reused: {} | Failed: {} | "
            "Avg time: {:.1f}s | Total AI time: {:.1f}s",
            stats["generated"], stats["skipped"], stats["reused"], stats["failed"],
            avg_time, total_time,
        )

    return len(final_rows)


async def _generate_search_tags(title: str, summary: Optional[str]) -> str:
    try:
        provider = get_ai_provider(model_override="llama-3.1-8b-instant")
        safe_title = (title or "").strip()
        safe_summary = (summary or "").strip()
        system = "You are an SEO and indexing expert. Generate highly relevant search keywords. Return ONLY a JSON object."
        user = (
            f"Generate 10-20 highly relevant search keywords and related concepts for the news article below. "
            f"Include abbreviations, alternate names, broader topics, UPSC-relevant terminology, and synonyms. "
            f"Return them as a comma-separated string. Do not prefix with '#'.\n\n"
            f'Return ONLY this JSON:\n{{"search_tags":"..."}}\n\n'
            f"Title: {safe_title}\n"
            f"Summary: {safe_summary}"
        )
        data = await provider.generate_json(system, user)
        return data.get("search_tags", "")
    except Exception as e:
        log.warning("Failed to generate search tags for {}: {}", (title or "")[:30], e)
        return ""

async def _generate_ai_cover(
    title: str, summary: Optional[str], article_hash: str
) -> Optional[dict]:
    """
    Generate an AI cover image. Returns {"url": str, "time": float} on success, None on failure.
    Uses deterministic filenames based on article_hash to enable deduplication.
    """
    import asyncio
    import time as _time

    settings = get_settings()
    MIN_SIZE = settings.ai_image_min_size_kb * 1024
    RETRYABLE_CODES = {429, 500, 502, 503, 504}

    # --- Graceful prompt construction (#11) ---
    safe_title = re.sub(r'<[^>]+>', '', (title or "")[:150]).replace('\n', ' ').strip()
    safe_summary = re.sub(r'<[^>]+>', '', (summary or "")[:350]).replace('\n', ' ').strip()

    if safe_summary:
        prompt_body = f"Headline: {safe_title}. Summary: {safe_summary}."
    else:
        prompt_body = f"Headline: {safe_title}."

    prompt = (
        "Professional editorial news photograph, highly realistic, "
        "illustrating the following news story. "
        f"{prompt_body} "
        "Natural lighting, documentary style, accurate context, 16:9 composition. "
        "No text, logos, watermarks, captions, or graphics."
    )
    encoded_prompt = urllib.parse.quote(prompt)

    # --- Configurable provider URL (#10) ---
    url = settings.ai_image_provider_url.replace("{prompt}", encoded_prompt)

    covers_dir = settings.covers_dir
    os.makedirs(covers_dir, exist_ok=True)
    filename = f"{article_hash}.jpg"
    local_path = os.path.join(covers_dir, filename)

    log.info("Generating AI cover [{}] for '{}'...", settings.ai_image_provider, safe_title[:60])
    start_time = _time.monotonic()

    # --- Smart retries with exponential backoff (#6, #8, #9) ---
    last_error = None
    for attempt in range(1, settings.ai_image_max_retries + 1):
        try:
            async with httpx.AsyncClient(timeout=float(settings.ai_image_timeout)) as client:
                resp = await client.get(url)

            if resp.status_code in RETRYABLE_CODES:
                wait = 2 ** attempt  # exponential backoff: 2, 4, 8 seconds
                log.warning(
                    "AI cover attempt {}/{} failed (HTTP {}). Retrying in {}s...",
                    attempt, settings.ai_image_max_retries, resp.status_code, wait,
                )
                await asyncio.sleep(wait)
                continue

            resp.raise_for_status()

            # --- Response validation (#3) ---
            content_type = resp.headers.get("Content-Type", "")
            if not content_type.startswith("image/"):
                log.error(
                    "AI cover: Invalid content type '{}' from {}. Aborting.",
                    content_type, settings.ai_image_provider,
                )
                return None

            # --- Write file ---
            try:
                with open(local_path, "wb") as f:
                    f.write(resp.content)
            except OSError as write_err:
                log.error("AI cover: Failed to write file {}: {}", local_path, write_err)
                return None

            # --- File verification (#4) + Size validation (#5) ---
            if not os.path.exists(local_path):
                log.error("AI cover: File not found after write: {}", local_path)
                return None

            file_size = os.path.getsize(local_path)
            if file_size < MIN_SIZE:
                log.warning(
                    "AI cover: File too small ({:.1f} KB < {} KB). Deleting: {}",
                    file_size / 1024, settings.ai_image_min_size_kb, filename,
                )
                # --- Cleanup (#14) ---
                try:
                    os.remove(local_path)
                except OSError:
                    pass
                return None

            elapsed = _time.monotonic() - start_time
            log.info(
                "AI cover saved ✓ | File: {} | Size: {:.1f} KB | Time: {:.1f}s",
                filename, file_size / 1024, elapsed,
            )
            return {"url": f"/assets/covers/{filename}", "time": elapsed}

        except (httpx.ReadTimeout, httpx.ConnectTimeout) as timeout_err:
            wait = 2 ** attempt
            log.warning(
                "AI cover attempt {}/{} timed out ({}). Retrying in {}s...",
                attempt, settings.ai_image_max_retries, type(timeout_err).__name__, wait,
            )
            last_error = timeout_err
            await asyncio.sleep(wait)
            continue

        except Exception as e:
            elapsed = _time.monotonic() - start_time
            log.exception("AI cover generation failed after {:.1f}s: {}", elapsed, e)
            # --- Cleanup (#14) ---
            if os.path.exists(local_path):
                try:
                    os.remove(local_path)
                except OSError:
                    pass
            return None

    # All retries exhausted
    log.error(
        "AI cover: All {} attempts failed for '{}'. Last error: {}",
        settings.ai_image_max_retries, safe_title[:60], last_error,
    )
    return None


async def _verify_publisher_image(image_url: str) -> bool:
    """
    Lightweight validation of a publisher-provided image URL (#17).
    Performs a HEAD request to verify the URL returns HTTP 200 with an image Content-Type.
    """
    try:
        # Basic URL syntax check
        parsed = urllib.parse.urlparse(image_url)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            log.debug("Publisher image URL has invalid scheme/host: {}", image_url[:100])
            return False

        async with httpx.AsyncClient(timeout=5.0) as client:
            resp = await client.head(image_url, follow_redirects=True)

        if resp.status_code != 200:
            log.debug("Publisher image returned HTTP {}: {}", resp.status_code, image_url[:100])
            return False

        content_type = resp.headers.get("Content-Type", "")
        if not content_type.startswith("image/"):
            log.debug("Publisher image has non-image Content-Type '{}': {}", content_type, image_url[:100])
            return False

        return True

    except Exception as e:
        log.debug("Publisher image verification failed for {}: {}", image_url[:100], e)
        return False


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
