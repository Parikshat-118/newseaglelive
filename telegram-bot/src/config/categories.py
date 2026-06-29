"""
News category catalog — single source of truth for category slugs, names, and
default RSS sources. Add/remove categories here only.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Dict, List


@dataclass(frozen=True)
class Category:
    slug: str            # stable ID used in DB and callbacks
    name_en: str
    name_hi: str
    emoji: str
    default_rss: List[str] = field(default_factory=list)


CATEGORIES: Dict[str, Category] = {c.slug: c for c in [
    Category("india",         "India",            "भारत",           "🇮🇳",
             ["https://www.thehindu.com/news/national/feeder/default.rss",
              "https://feeds.feedburner.com/ndtvnews-top-stories"]),
    Category("world",         "World",            "विश्व",            "🌍",
             ["https://feeds.bbci.co.uk/news/world/rss.xml",
              "https://www.reuters.com/world/rss"]),
    Category("politics",      "Politics",         "राजनीति",         "🏛️",
             ["https://www.thehindu.com/news/national/politics/feeder/default.rss"]),
    Category("business",      "Business",         "बिज़नेस",          "💼",
             ["https://www.business-standard.com/rss/home_page_top_stories.rss"]),
    Category("economy",       "Economy",          "अर्थव्यवस्था",       "📈",
             ["https://economictimes.indiatimes.com/rssfeedstopstories.cms"]),
    Category("finance",       "Finance",          "वित्त",            "💰", []),
    Category("stock_market",  "Stock Market",     "शेयर बाज़ार",       "📊",
             ["https://www.moneycontrol.com/rss/MCtopnews.xml"]),
    Category("crypto",        "Cryptocurrency",   "क्रिप्टोकरेंसी",       "₿",
             ["https://cointelegraph.com/rss"]),
    Category("technology",    "Technology",       "तकनीक",           "💻",
             ["https://feeds.feedburner.com/TechCrunch/",
              "https://www.theverge.com/rss/index.xml"]),
    Category("ai",            "Artificial Intelligence", "एआई",     "🤖",
             ["https://www.artificialintelligence-news.com/feed/"]),
    Category("science",       "Science",          "विज्ञान",          "🔬",
             ["https://www.sciencedaily.com/rss/top/science.xml"]),
    Category("space",         "Space",            "अंतरिक्ष",         "🚀",
             ["https://www.space.com/feeds/all"]),
    Category("cybersecurity", "Cybersecurity",    "साइबर सुरक्षा",     "🛡️",
             ["https://krebsonsecurity.com/feed/",
              "https://www.bleepingcomputer.com/feed/"]),
    Category("education",     "Education",        "शिक्षा",          "🎓", []),
    Category("agriculture",   "Agriculture",      "कृषि",            "🌾", []),
    Category("health",        "Health",           "स्वास्थ्य",         "🩺",
             ["https://www.who.int/feeds/entity/news/en/rss.xml"]),
    Category("sports",        "Sports",           "खेल",             "⚽",
             ["https://www.espn.com/espn/rss/news"]),
    Category("entertainment", "Entertainment",    "मनोरंजन",          "🎬",
             ["https://variety.com/feed/"]),
    Category("gaming",        "Gaming",           "गेमिंग",           "🎮",
             ["https://www.polygon.com/rss/index.xml"]),
    Category("environment",   "Environment",      "पर्यावरण",         "🌱",
             ["https://www.theguardian.com/environment/rss"]),
]}


def all_slugs() -> List[str]:
    return list(CATEGORIES.keys())


def get_category(slug: str) -> Category | None:
    return CATEGORIES.get(slug)


def label(slug: str, lang: str = "en") -> str:
    c = CATEGORIES.get(slug)
    if not c:
        return slug
    name = c.name_hi if lang == "hi" else c.name_en
    return f"{c.emoji} {name}"
