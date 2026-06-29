"""
War Meter — Global Conflict Index computed from the bot's own news DB.

Pure keyword scoring (zero AI cost). Every 30 min:
  1. Scan articles in the window (7d and 30d).
  2. Count conflict-related articles (keyword match in title/summary).
  3. Attribute them to regions via region keyword lists.
  4. Compute a 0-10 global index and per-region scores.
  5. Store one snapshot row per window in warmeter_snapshots.

The web page /warmeter.php just reads the latest snapshot per window.
"""
from __future__ import annotations

import json
import math

from sqlalchemy import text

from src.database.connection import session_scope
from src.utils.logger import log

CONFLICT_TERMS = [
    "war", "airstrike", "air strike", "missile", "drone attack", "shelling",
    "invasion", "offensive", "ceasefire", "troops", "military operation",
    "terror attack", "terrorist", "insurgent", "militant", "bombing", "artillery",
    "border clash", "border tension", "armed conflict", "hostilities", "combat",
    "nuclear threat", "mobilization", "warship", "fighter jet", "casualties",
    "gunmen", "ambush", "encounter", "infiltration", "loc ", " loc", "skirmish",
]

REGIONS: dict[str, list[str]] = {
    "Middle East":        ["israel", "gaza", "palestin", "iran", "lebanon", "hezbollah",
                           "syria", "yemen", "houthi", "iraq", "saudi", "red sea"],
    "Russia–Ukraine":     ["ukraine", "russia", "kyiv", "moscow", "crimea", "donetsk",
                           "zaporizhzhia", "kharkiv", "putin", "zelensky"],
    "India–Pakistan":     ["pakistan", "loc", "pok", "kashmir", "jammu", "pulwama",
                           "rajouri", "poonch", "indus"],
    "India–China":        ["china border", "lac", "ladakh", "arunachal", "galwan",
                           "doklam", "tawang"],
    "India Internal":     ["naxal", "maoist", "manipur", "insurgency", "militants in",
                           "encounter in", "terrorist in india"],
    "East Asia":          ["taiwan", "north korea", "south china sea", "pyongyang",
                           "korean peninsula", "senkaku"],
    "Africa":             ["sudan", "sahel", "mali", "niger", "somalia", "ethiopia",
                           "congo", "boko haram", "al-shabaab"],
    "Europe":             ["nato", "baltic", "kosovo", "balkan", "belarus border"],
    "Afghanistan–Pak":    ["afghanistan", "taliban", "kabul", "tehreek", "ttp", "durand"],
    "Americas":           ["cartel", "venezuela", "haiti gang", "colombia farc"],
}

_LABELS = [(2.0, "Low"), (4.0, "Guarded"), (6.0, "Elevated"), (8.0, "High"), (10.1, "Severe")]


def _label(idx: float) -> str:
    for cap, name in _LABELS:
        if idx < cap:
            return name
    return "Severe"


def compute_warmeter() -> None:
    """Compute and store snapshots for 7-day and 30-day windows."""
    for window in (7, 30):
        try:
            _compute_window(window)
        except Exception:
            log.exception("warmeter: window {} failed", window)


def _compute_window(window_days: int) -> None:
    with session_scope() as s:
        rows = s.execute(text(
            "SELECT title, COALESCE(summary,'') AS summary FROM news_articles "
            "WHERE fetched_at >= (NOW() - INTERVAL :d DAY)"
        ), {"d": window_days}).all()

    total = len(rows)
    if total == 0:
        return

    conflict_count = 0
    region_hits: dict[str, int] = {r: 0 for r in REGIONS}

    for title, summary in rows:
        blob = f"{title} {summary}".lower()
        if any(term in blob for term in CONFLICT_TERMS):
            conflict_count += 1
            for region, keys in REGIONS.items():
                if any(k in blob for k in keys):
                    region_hits[region] += 1

    # Global index: share of conflict coverage, log-scaled to 0-10.
    # 2% share ≈ 3.0, 5% ≈ 5.0, 12% ≈ 7.0, 30%+ ≈ 9-10
    share = conflict_count / max(total, 1)
    if share <= 0:
        index = 0.0
    else:
        index = max(0.0, min(10.0, 2.2 * math.log10(share * 100 + 1) * 2.1))
    index = round(index, 1)

    # Region scores: normalize hits against the max region (0-10)
    max_hits = max(region_hits.values()) or 1
    regions_out = []
    for region, hits in sorted(region_hits.items(), key=lambda kv: kv[1], reverse=True):
        if hits == 0:
            continue
        regions_out.append({
            "region": region,
            "score": round(10.0 * hits / max_hits, 1),
            "articles": hits,
        })

    with session_scope() as s:
        s.execute(text(
            "INSERT INTO warmeter_snapshots "
            "(window_days, global_index, threat_label, total_conflict_articles, regions) "
            "VALUES (:w, :i, :l, :c, :r)"
        ), {
            "w": window_days, "i": index, "l": _label(index),
            "c": conflict_count, "r": json.dumps(regions_out, ensure_ascii=False),
        })
        # keep last 200 snapshots per window
        s.execute(text(
            "DELETE FROM warmeter_snapshots WHERE window_days = :w AND id NOT IN ("
            "  SELECT id FROM (SELECT id FROM warmeter_snapshots WHERE window_days = :w "
            "  ORDER BY computed_at DESC LIMIT 200) k)"
        ), {"w": window_days})

    log.info("warmeter: window={}d index={} label={} conflict={}/{}",
             window_days, index, _label(index), conflict_count, total)
