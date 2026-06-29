"""
NewsAPI.org adapter — https://newsapi.org/docs/endpoints/top-headlines
"""
from __future__ import annotations

from datetime import datetime
from typing import List, Optional

import httpx

from src.config.settings import get_settings
from src.services.source_adapters.base import BaseSourceAdapter, FetchedArticle
from src.utils.logger import log


# Map our slugs to NewsAPI's coarse category set
_NEWSAPI_CAT = {
    "india": None, "world": None, "politics": None,
    "business": "business", "economy": "business", "finance": "business",
    "stock_market": "business", "crypto": "business",
    "technology": "technology", "ai": "technology", "cybersecurity": "technology",
    "science": "science", "space": "science",
    "health": "health", "sports": "sports", "entertainment": "entertainment",
    "gaming": "entertainment", "environment": "science",
    "education": None, "agriculture": None,
}


class NewsAPIAdapter(BaseSourceAdapter):
    name = "newsapi"
    _BASE = "https://newsapi.org/v2"

    async def fetch(self, *, category: Optional[str] = None, language: str = "en") -> List[FetchedArticle]:
        settings = get_settings()
        if not settings.newsapi_key:
            return []

        params: dict = {
            "country": "in",
            "language": "en" if language == "en" else "hi",
            "pageSize": 50,
            "apiKey": settings.newsapi_key,
        }
        api_cat = _NEWSAPI_CAT.get(category) if category else None
        if api_cat:
            params["category"] = api_cat

        try:
            async with httpx.AsyncClient(timeout=15.0) as client:
                resp = await client.get(f"{self._BASE}/top-headlines", params=params)
                resp.raise_for_status()
                data = resp.json()
        except Exception as e:
            log.warning("NewsAPI fetch failed: {}", e)
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
                image_url=a.get("urlToImage"),
                category=category,
                language=language,
                source_name=(a.get("source") or {}).get("name", "NewsAPI")[:128],
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
