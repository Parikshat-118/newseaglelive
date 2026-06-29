"""
RSS / Atom feed adapter.

Why an async-fetch + sync-parse split:
    feedparser is synchronous. We fetch the bytes with httpx (async),
    then parse off the event loop using `asyncio.to_thread`.
"""
from __future__ import annotations

import asyncio
import email.utils as eut
from datetime import datetime, timezone
from typing import List, Optional

import feedparser
import httpx
from bs4 import BeautifulSoup

from src.config.categories import CATEGORIES
from src.services.source_adapters.base import BaseSourceAdapter, FetchedArticle
from src.utils.logger import log


class RSSAdapter(BaseSourceAdapter):
    name = "rss"

    def __init__(self, timeout: float = 15.0):
        self._timeout = timeout

    async def fetch(self, *, category: Optional[str] = None, language: str = "en") -> List[FetchedArticle]:
        feeds: list[tuple[str, str]] = []  # (feed_url, category_slug)
        if category:
            cat = CATEGORIES.get(category)
            if cat:
                feeds.extend((u, cat.slug) for u in cat.default_rss)
        else:
            for c in CATEGORIES.values():
                feeds.extend((u, c.slug) for u in c.default_rss)

        if not feeds:
            return []

        async with httpx.AsyncClient(
            timeout=self._timeout,
            follow_redirects=True,
            headers={"User-Agent": "NewsEagleLive/1.0 (+https://newseagle.live)"},
        ) as client:
            tasks = [self._fetch_one(client, url, cat, language) for url, cat in feeds]
            results = await asyncio.gather(*tasks, return_exceptions=True)

        out: list[FetchedArticle] = []
        for r in results:
            if isinstance(r, Exception):
                log.warning("RSS fetch error: {}", r)
                continue
            out.extend(r)
        return out

    async def _fetch_one(
        self, client: httpx.AsyncClient, url: str, category: str, language: str
    ) -> List[FetchedArticle]:
        try:
            resp = await client.get(url)
            resp.raise_for_status()
        except Exception as e:
            log.warning("RSS GET {} failed: {}", url, e)
            return []

        # Parse off the event loop
        parsed = await asyncio.to_thread(feedparser.parse, resp.content)
        if parsed.bozo and not parsed.entries:
            log.debug("RSS bozo for {}: {}", url, getattr(parsed, "bozo_exception", None))
            return []

        source_name = (parsed.feed.get("title") or url)[:128]
        out: list[FetchedArticle] = []
        for e in parsed.entries[:30]:
            link = e.get("link")
            title = e.get("title")
            if not (link and title):
                continue

            summary_html = e.get("summary") or e.get("description") or ""
            summary = _strip_html(summary_html)[:1000]
            content = None
            if e.get("content"):
                content = _strip_html(e.content[0].get("value", ""))[:8000]

            image_url = _find_image(e)
            published = _parse_date(e.get("published") or e.get("updated"))

            out.append(FetchedArticle(
                url=link,
                title=title.strip()[:512],
                summary=summary or None,
                content=content,
                image_url=image_url,
                category=category,
                language=language,
                source_name=source_name,
                published_at=published,
                is_breaking=False,
            ))
        return out


# -------- helpers --------
def _strip_html(html: str) -> str:
    if not html:
        return ""
    return BeautifulSoup(html, "lxml").get_text(" ", strip=True)


def _find_image(entry) -> Optional[str]:
    # media_content / media_thumbnail / enclosures / inline <img>
    for k in ("media_content", "media_thumbnail"):
        items = entry.get(k) or []
        if items and items[0].get("url"):
            return items[0]["url"]
    for enc in entry.get("enclosures", []) or []:
        if (enc.get("type") or "").startswith("image") and enc.get("href"):
            return enc["href"]
    html = entry.get("summary") or ""
    if "<img" in html:
        soup = BeautifulSoup(html, "lxml")
        img = soup.find("img")
        if img and img.get("src"):
            return img["src"]
    return None


def _parse_date(s: Optional[str]) -> Optional[datetime]:
    if not s:
        return None
    try:
        dt = eut.parsedate_to_datetime(s)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt.astimezone(timezone.utc).replace(tzinfo=None)
    except Exception:
        return None
