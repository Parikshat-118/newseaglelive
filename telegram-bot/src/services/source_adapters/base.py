"""
Source adapter interface. Each adapter normalizes upstream data into
a canonical `FetchedArticle` dict, so the news_service is source-agnostic.
"""
from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, asdict
from datetime import datetime
from typing import List, Optional


@dataclass
class FetchedArticle:
    url: str
    title: str
    summary: Optional[str]
    content: Optional[str]
    image_url: Optional[str]
    category: Optional[str]
    language: str
    source_name: str
    published_at: Optional[datetime]
    is_breaking: bool = False

    def as_dict(self) -> dict:
        return asdict(self)


class BaseSourceAdapter(ABC):
    """Stateless fetcher — instantiated once, called repeatedly by scheduler."""

    name: str = "base"

    @abstractmethod
    async def fetch(self, *, category: Optional[str] = None, language: str = "en") -> List[FetchedArticle]:
        ...
