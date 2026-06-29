"""
Severe Alerts — AI-detected, PIN-code-targeted, push-notified.

Pipeline (every 30 min via scheduler):
    1. Pull recent news (last 90 min) from news_articles.
    2. Ask AI (OpenRouter directly): "Identify severe location-specific events."
    3. AI returns structured JSON.
    4. Persist any new ones to severe_alerts.
    5. Dispatcher (separate job, every 10 min) sends to users whose
       state/district matches. Dedupe via alert_deliveries.
"""
from __future__ import annotations

import json
import os
from datetime import datetime, timedelta
from typing import Any

import httpx
from sqlalchemy import select, text

from telegram import Bot
from telegram.constants import ParseMode

from src.database.connection import session_scope
from src.database.models import NewsArticle
from src.utils.logger import log


# ---------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------
OPENROUTER_URL    = "https://openrouter.ai/api/v1/chat/completions"
OPENROUTER_KEY    = os.environ.get("OPENROUTER_API_KEY", "")
MODEL_PRIMARY     = os.environ.get("AI_PRIMARY_MODEL",  "x-ai/grok-4.3")
MODEL_FALLBACK    = os.environ.get("AI_FALLBACK_MODEL", "x-ai/grok-4")
SITE_URL          = os.environ.get("SITE_URL", "https://newseagle.live")
SITE_NAME         = os.environ.get("SITE_NAME", "News Eagle Live")


_SYSTEM = (
    "You analyse Indian news for SEVERE, LOCATION-SPECIFIC events that affect "
    "people's lives or safety. You always output strict JSON only, no markdown, "
    "no commentary."
)


def _build_user_prompt(snippets: list[str]) -> str:
    joined = "\n\n---\n\n".join(s[:800] for s in snippets[:25])
    return (
        "From the Indian news snippets below, identify ALL severe, "
        "location-specific events. Categories: weather (cyclone, heatwave, "
        "flood, snowstorm, severe rain), disease (outbreak, advisory), "
        "disaster (earthquake, fire, major accident), security (terror, "
        "communal violence, evacuation), other.\n\n"
        "For each event output severity 1-5 (5=catastrophic, 4=major, "
        "3=significant, 2=advisory, 1=watch).\n\n"
        "Be CONSERVATIVE — only flag genuinely severe items affecting many "
        "lives. Routine news is NOT an alert.\n\n"
        "Return JSON in this EXACT shape:\n"
        '{"alerts":[{'
        '"kind":"weather","severity":4,'
        '"headline":"Cyclone Remal makes landfall in West Bengal",'
        '"summary_en":"Coast braces for 120 kmph winds; evacuation under way.",'
        '"summary_hi":"बंगाल तट 120 किमी/घंटा हवाओं के लिए तैयार; निकासी जारी।",'
        '"regions":[{"state":"West Bengal","district":"South 24 Parganas"}]'
        '}]}\n\n'
        'If no severe events: return {"alerts":[]}\n\n'
        "News snippets:\n" + joined
    )


# ---------------------------------------------------------------------
# AI call (direct OpenRouter)
# ---------------------------------------------------------------------
async def _ai_json(snippets: list[str]) -> dict[str, Any]:
    """Call OpenRouter, return parsed JSON. Empty dict on failure."""
    if not OPENROUTER_KEY:
        log.warning("severe_alerts: OPENROUTER_API_KEY not set")
        return {}

    user_msg = _build_user_prompt(snippets)
    headers = {
        "Authorization": f"Bearer {OPENROUTER_KEY}",
        "HTTP-Referer":  SITE_URL,
        "X-Title":       SITE_NAME,
        "Content-Type":  "application/json",
    }

    for model in (MODEL_PRIMARY, MODEL_FALLBACK):
        body = {
            "model": model,
            "messages": [
                {"role": "system", "content": _SYSTEM},
                {"role": "user",   "content": user_msg},
            ],
            "temperature": 0.2,
            "response_format": {"type": "json_object"},
        }
        try:
            async with httpx.AsyncClient(timeout=45.0) as c:
                r = await c.post(OPENROUTER_URL, json=body, headers=headers)
            if r.status_code != 200:
                log.warning("severe_alerts AI {} -> HTTP {}: {}",
                            model, r.status_code, r.text[:200])
                continue
            data = r.json()
            txt  = data["choices"][0]["message"]["content"]
            return json.loads(txt)
        except Exception as e:
            log.warning("severe_alerts AI {} failed: {}", model, e)
            continue
    return {}


# ---------------------------------------------------------------------
# Detection
# ---------------------------------------------------------------------
async def detect_alerts(window_minutes: int = 90) -> int:
    """Scan recent news, ask AI, persist new severe alerts. Returns # created."""
    cutoff = datetime.utcnow() - timedelta(minutes=window_minutes)
    with session_scope() as s:
        rows = s.scalars(
            select(NewsArticle)
            .where(NewsArticle.fetched_at >= cutoff)
            .order_by(NewsArticle.fetched_at.desc())
            .limit(40)
        ).all()
        snippets = [
            f"[{a.id}] {a.title}\n{(a.summary or '')[:400]}"
            for a in rows
        ]

    if not snippets:
        return 0

    data = await _ai_json(snippets)
    alerts = data.get("alerts", []) if isinstance(data, dict) else []
    if not alerts:
        log.info("severe_alerts: AI found none in window ({} articles)", len(snippets))
        return 0

    created = 0
    with session_scope() as s:
        for a in alerts:
            try:
                kind = (a.get("kind") or "other").lower()
                if kind not in {"weather", "disease", "disaster", "security", "other"}:
                    kind = "other"
                severity = max(1, min(5, int(a.get("severity", 3))))
                headline = (a.get("headline") or "")[:255]
                if not headline:
                    continue

                # Dedupe by headline within last 12h
                exists = s.execute(text(
                    "SELECT id FROM severe_alerts "
                    "WHERE headline = :h AND created_at >= (NOW() - INTERVAL 12 HOUR) LIMIT 1"
                ), {"h": headline}).first()
                if exists:
                    continue

                regions = a.get("regions") or []
                expires_at = datetime.utcnow() + timedelta(hours=12 if severity >= 4 else 6)

                s.execute(text(
                    "INSERT INTO severe_alerts "
                    "(kind, severity, headline, summary_en, summary_hi, regions, expires_at) "
                    "VALUES (:kind, :sev, :h, :en, :hi, :r, :exp)"
                ), {
                    "kind": kind, "sev": severity, "h": headline,
                    "en":   (a.get("summary_en") or "")[:2000],
                    "hi":  ((a.get("summary_hi") or "")[:2000]) or None,
                    "r":    json.dumps(regions, ensure_ascii=False),
                    "exp":  expires_at,
                })
                created += 1
                log.info("severe alert created: {} (sev={}, regions={})",
                         headline, severity, regions)
            except Exception as e:
                log.warning("severe_alerts: skipping malformed: {}", e)
    return created


# ---------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------
async def dispatch_pending(bot: Bot) -> int:
    """Push undelivered severe alerts to users in affected regions."""
    with session_scope() as s:
        alerts = list(s.execute(text(
            "SELECT id, kind, severity, headline, summary_en, summary_hi, regions "
            "FROM severe_alerts "
            "WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) "
            "ORDER BY severity DESC, created_at DESC "
            "LIMIT 20"
        )).mappings().all())

    total_sent = 0
    for a in alerts:
        try:
            regions = json.loads(a["regions"]) if a["regions"] else []
        except Exception:
            regions = []

        recipients = _resolve_recipients(regions)
        if not recipients:
            continue

        for tg_id, user_id, lang in recipients:
            with session_scope() as s:
                already = s.execute(text(
                    "SELECT 1 FROM alert_deliveries WHERE alert_id = :a AND user_id = :u"
                ), {"a": a["id"], "u": user_id}).first()
                if already:
                    continue

                body = a["summary_hi"] if lang == "hi" and a["summary_hi"] else a["summary_en"]
                emoji = _kind_emoji(a["kind"])
                sev_label = _sev_label(a["severity"], lang)
                msg = (
                    f"🚨 *{sev_label}* {emoji}\n\n"
                    f"*{_md_escape(a['headline'])}*\n\n"
                    f"{_md_escape((body or '')[:800])}"
                )
                try:
                    await bot.send_message(
                        chat_id=tg_id, text=msg,
                        parse_mode=ParseMode.MARKDOWN, disable_web_page_preview=True,
                    )
                    s.execute(text(
                        "INSERT INTO alert_deliveries (alert_id, user_id) VALUES (:a, :u)"
                    ), {"a": a["id"], "u": user_id})
                    s.execute(text(
                        "UPDATE severe_alerts SET pushed_count = pushed_count + 1 WHERE id = :a"
                    ), {"a": a["id"]})
                    total_sent += 1
                except Exception as e:
                    log.warning("severe alert send fail tg={}: {}", tg_id, e)

    if total_sent:
        log.info("severe alerts dispatched: {}", total_sent)
    return total_sent


def _resolve_recipients(regions: list) -> list[tuple[int, int, str]]:
    """(telegram_id, user_id, language) tuples whose location matches any region."""
    if not regions:
        return []

    states    = {r.get("state")    for r in regions if r.get("state")}
    districts = {r.get("district") for r in regions if r.get("district")}

    conditions, params = [], {}
    if districts:
        ph = ",".join(f":d{i}" for i in range(len(districts)))
        conditions.append(f"district IN ({ph})")
        for i, d in enumerate(districts):
            params[f"d{i}"] = d
    if states:
        ph = ",".join(f":s{i}" for i in range(len(states)))
        conditions.append(f"state IN ({ph})")
        for i, st in enumerate(states):
            params[f"s{i}"] = st

    if not conditions:
        return []

    sql = (
        "SELECT telegram_id, id, language FROM users "
        "WHERE is_banned = 0 AND notifications_enabled = 1 "
        f"AND ({' OR '.join(conditions)})"
    )
    with session_scope() as s:
        rows = s.execute(text(sql), params).all()
    return [(r[0], r[1], r[2]) for r in rows]


def _md_escape(s: str) -> str:
    """Light escape for Telegram Markdown (legacy mode)."""
    if not s:
        return ""
    return s.replace("*", " ").replace("_", " ").replace("`", "'").replace("[", "(").replace("]", ")")


def _kind_emoji(k: str) -> str:
    return {"weather": "🌪️", "disease": "🦠", "disaster": "⚠️",
            "security": "🚓", "other": "❗"}.get(k, "❗")


def _sev_label(s: int, lang: str = "en") -> str:
    if lang == "hi":
        return ["", "सूचना", "एडवाइज़री", "अलर्ट",
                "गंभीर अलर्ट", "अति गंभीर अलर्ट"][min(5, max(1, s))]
    return ["", "Watch", "Advisory", "Alert",
            "Severe Alert", "Critical Alert"][min(5, max(1, s))]
