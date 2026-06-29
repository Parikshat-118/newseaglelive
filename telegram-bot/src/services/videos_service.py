"""
Trending news videos — fetched from YouTube RSS feeds of major Indian
news channels (free, no API key). Runs every 30 min via scheduler.
"""
from __future__ import annotations

import xml.etree.ElementTree as ET
from datetime import datetime

import httpx
from sqlalchemy import text

from src.database.connection import session_scope
from src.utils.logger import log

# channel_id -> display name (YouTube RSS: /feeds/videos.xml?channel_id=...)
CHANNELS = {
    "UCt4t-jeY85JegMlZ-E5UWtA": "Aaj Tak",
    "UCZFMm1mMw0F81Z37aaEzTUA": "NDTV",
    "UCYPvAwZP8pZhSMW8qs7cVCw": "India Today",
    "UC_gUM8rL-Lrg6O3adPW9K1g": "WION",
    "UCRWFSbif-RFENbBrSiez1DA": "ABP News",
    "UCttspZesZIDEwwpVIgoZtWQ": "India TV",
    "UCmphdqZNmqL72WJ2uyiNw5w": "ANI News",
    "UC6RJ7-PaXg6TIH2BzZfTV7w": "Republic World",
}

_NS = {
    "atom": "http://www.w3.org/2005/Atom",
    "yt": "http://www.youtube.com/xml/schemas/2015",
    "media": "http://search.yahoo.com/mrss/",
}


async def fetch_videos() -> int:
    """Pull latest videos from each channel RSS; upsert into news_videos."""
    saved = 0
    async with httpx.AsyncClient(timeout=15.0, headers={"User-Agent": "Mozilla/5.0"}) as client:
        for cid, name in CHANNELS.items():
            url = f"https://www.youtube.com/feeds/videos.xml?channel_id={cid}"
            try:
                r = await client.get(url)
                if r.status_code != 200:
                    log.warning("videos: {} -> HTTP {}", name, r.status_code)
                    continue
                root = ET.fromstring(r.text)
                entries = root.findall("atom:entry", _NS)[:8]
                with session_scope() as s:
                    for e in entries:
                        vid = e.findtext("yt:videoId", default="", namespaces=_NS)
                        title = e.findtext("atom:title", default="", namespaces=_NS)
                        pub = e.findtext("atom:published", default="", namespaces=_NS)
                        thumb = None
                        mg = e.find("media:group", _NS)
                        if mg is not None:
                            t = mg.find("media:thumbnail", _NS)
                            if t is not None:
                                thumb = t.get("url")
                        if not vid or not title:
                            continue
                        pub_dt = None
                        try:
                            pub_dt = datetime.fromisoformat(pub.replace("Z", "+00:00")).replace(tzinfo=None)
                        except Exception:
                            pass
                        res = s.execute(text(
                            "INSERT IGNORE INTO news_videos (video_id, title, channel, thumbnail, published_at) "
                            "VALUES (:v, :t, :c, :th, :p)"
                        ), {"v": vid, "t": title[:500], "c": name, "th": thumb, "p": pub_dt})
                        if res.rowcount:
                            saved += 1
            except Exception as e:
                log.warning("videos: {} failed: {}", name, e)

    # Keep table lean: only last 7 days
    try:
        with session_scope() as s:
            s.execute(text("DELETE FROM news_videos WHERE fetched_at < (NOW() - INTERVAL 7 DAY)"))
    except Exception:
        pass

    log.info("videos: saved {} new", saved)
    return saved
