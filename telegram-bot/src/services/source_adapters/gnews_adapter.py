"""
GNews.io adapter — https://gnews.io/docs/v4
"""
from __future__ import annotations

from datetime import datetime
from typing import List, Optional

import httpx

from src.config.settings import get_settings
from src.services.source_adapters.base import BaseSourceAdapter, FetchedArticle
from src.utils.logger import log


_GNEWS_CAT = {
    "india": "nation", "world": "world", "business": "business",
    "economy": "business", "finance": "business", "stock_market": "business",
    "crypto": "business", "technology": "technology", "ai": "technology",
    "cybersecurity": "technology", "science": "science", "space": "science",
    "health": "health", "sports": "sports", "entertainment": "entertainment",
    "gaming": "entertainment", "environment": "science", "politics": "world",
    "education": "general", "agriculture": "general",
}


class GNewsAdapter(BaseSourceAdapter):
    name = "gnews"
    _BASE = "https://gnews.io/api/v4"

    async def fetch(self, *, category: Optional[str] = None, language: str = "en") -> List[FetchedArticle]:
        settings = get_settings()
        if not settings.gnews_api_key:
            return []

        params: dict = {
            "country": "in",
            "lang": language,
            "max": 25,
            "apikey": settings.gnews_api_key,
        }
        topic = _GNEWS_CAT.get(category) if category else None
        if topic:
            params["topic"] = topic

        try:
            async with httpx.AsyncClient(timeout=15.0) as client:
                resp = await client.get(f"{self._BASE}/top-headlines", params=params)
                resp.raise_for_status()
                data = resp.json()
        except Exception as e:
            log.warning("GNews fetch failed: {}", e)
            return []

        out: List[FetchedArticle] = []
        for a in data.get("articles", []):
            url = a.get("url")
            title = a.get("title")
            if not (url and title):
                continue
            out.append(FetchedArticle(
                url=url,
                title=title[:512],
                summary=(a.get("description") or "")[:1000] or None,
                content=(a.get("content") or "")[:8000] or None,
                image_url=a.get("image"),
                category=category,
                language=language,
                source_name=(a.get("source") or {}).get("name", "GNews")[:128],
                published_at=_parse(a.get("publishedAt")),
            ))
        return out


def _parse(s: Optional[str]) -> Optional[datetime]:
    if not s:
        return None
    try:
        return datetime.fromisoformat(s.replace("Z", "+00:00")).replace(tzinfo=None)
    except Exception:
        return None
