"""
Student Hub content generator — runs daily at 06:30 IST.

Generates from the last 24h of news (one AI call each):
  - 10 UPSC/SSC MCQs            -> student_content(kind='mcq',  exam='upsc')
  - 5 Media/Journalism MCQs     -> student_content(kind='mcq',  exam='media')
  - 2 UPSC Mains questions      -> student_content(kind='mains',exam='upsc')
  - 1 Editorial analysis        -> student_content(kind='editorial', exam='upsc')

All stored as JSON payloads the web Student Hub renders.
"""
from __future__ import annotations

import json
import os
from datetime import date, datetime, timedelta
from typing import Any

import httpx
from sqlalchemy import text

from src.database.connection import session_scope
from src.utils.logger import log

OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
OPENROUTER_KEY = os.environ.get("OPENROUTER_API_KEY", "")
MODEL_PRIMARY  = os.environ.get("AI_PRIMARY_MODEL",  "x-ai/grok-4.3")
MODEL_FALLBACK = os.environ.get("AI_FALLBACK_MODEL", "x-ai/grok-4")


async def _ai_json(system: str, user: str) -> dict[str, Any]:
    if not OPENROUTER_KEY:
        return {}
    headers = {"Authorization": f"Bearer {OPENROUTER_KEY}",
               "Content-Type": "application/json",
               "HTTP-Referer": "https://newseagle.live",
               "X-Title": "News Eagle Live"}
    for model in (MODEL_PRIMARY, MODEL_FALLBACK):
        try:
            async with httpx.AsyncClient(timeout=90.0) as c:
                r = await c.post(OPENROUTER_URL, headers=headers, json={
                    "model": model,
                    "messages": [{"role": "system", "content": system},
                                 {"role": "user",   "content": user}],
                    "temperature": 0.4,
                    "response_format": {"type": "json_object"},
                })
            if r.status_code != 200:
                log.warning("student AI {} HTTP {}", model, r.status_code)
                continue
            return json.loads(r.json()["choices"][0]["message"]["content"])
        except Exception as e:
            log.warning("student AI {} failed: {}", model, e)
    return {}


def _news_digest(hours: int = 24, limit: int = 30) -> str:
    with session_scope() as s:
        rows = s.execute(text(
            "SELECT title, COALESCE(summary,'') FROM news_articles "
            "WHERE fetched_at >= (NOW() - INTERVAL :h HOUR) "
            "ORDER BY trending_score DESC, published_at DESC LIMIT :n"
        ), {"h": hours, "n": limit}).all()
    return "\n".join(f"- {t}: {s_[:200]}" for t, s_ in rows)


def _already_done(kind: str, exam: str, d: date) -> bool:
    with session_scope() as s:
        return bool(s.execute(text(
            "SELECT 1 FROM student_content WHERE kind=:k AND exam=:e AND content_date=:d LIMIT 1"
        ), {"k": kind, "e": exam, "d": d}).first())


def _store(kind: str, exam: str, title: str, payload: dict, d: date) -> None:
    with session_scope() as s:
        s.execute(text(
            "INSERT INTO student_content (kind, exam, title, payload, content_date) "
            "VALUES (:k, :e, :t, :p, :d)"
        ), {"k": kind, "e": exam, "t": title[:250],
            "p": json.dumps(payload, ensure_ascii=False), "d": d})


async def generate_daily_student_content() -> None:
    today = date.today()
    digest = _news_digest()
    if not digest:
        log.info("student: no news to work from")
        return

    # ── 1. UPSC/SSC MCQs ─────────────────────────────────────────────
    if not _already_done("mcq", "upsc", today):
        data = await _ai_json(
            "You create exam-grade current-affairs MCQs for UPSC and SSC aspirants. Strict JSON only.",
            "From today's news below, create EXACTLY 10 multiple-choice questions in UPSC Prelims/SSC style. "
            "Mix difficulty. Each: 4 options, one correct, a 1-2 line explanation.\n"
            'Return: {"questions":[{"q":"...","options":["A","B","C","D"],"answer":0,'
            '"explanation":"...","topic":"Polity|Economy|IR|S&T|Environment|Defence|Misc"}]}\n\n'
            f"News:\n{digest}"
        )
        qs = data.get("questions") or []
        if len(qs) >= 5:
            _store("mcq", "upsc", f"UPSC/SSC Daily Quiz — {today:%d %b %Y}", {"questions": qs[:10]}, today)
            log.info("student: stored {} upsc mcqs", len(qs[:10]))

    # ── 2. Media/Journalism MCQs (YMCA / IIMC / mass-comm exams) ────
    if not _already_done("mcq", "media", today):
        data = await _ai_json(
            "You create MCQs for journalism & mass communication entrance exams "
            "(IIMC, YMCA mass comm, JMI, etc). Strict JSON only.",
            "From today's news below, create EXACTLY 5 MCQs relevant to media students: "
            "media-related current affairs, press freedom, media law/ethics, famous journalists, "
            "media organisations in the news.\n"
            'Return: {"questions":[{"q":"...","options":["A","B","C","D"],"answer":0,'
            '"explanation":"...","topic":"Media"}]}\n\n'
            f"News:\n{digest}"
        )
        qs = data.get("questions") or []
        if len(qs) >= 3:
            _store("mcq", "media", f"Media Students Quiz — {today:%d %b %Y}", {"questions": qs[:5]}, today)
            log.info("student: stored {} media mcqs", len(qs[:5]))

    # ── 3. UPSC Mains questions ──────────────────────────────────────
    if not _already_done("mains", "upsc", today):
        data = await _ai_json(
            "You are a UPSC Mains examiner. Strict JSON only.",
            "From today's news below, create EXACTLY 2 UPSC Mains-style questions (GS-2/GS-3, 150-250 words). "
            "For each give: question, the GS paper, 5-7 model answer points, and a 1-line approach hint.\n"
            'Return: {"questions":[{"q":"...","paper":"GS-2","points":["...","..."],"hint":"..."}]}\n\n'
            f"News:\n{digest}"
        )
        qs = data.get("questions") or []
        if qs:
            _store("mains", "upsc", f"Mains Practice — {today:%d %b %Y}", {"questions": qs[:2]}, today)
            log.info("student: stored {} mains questions", len(qs[:2]))

    # ── 4. Editorial analysis ────────────────────────────────────────
    if not _already_done("editorial", "upsc", today):
        data = await _ai_json(
            "You analyse news for exam-oriented editorial breakdowns. Strict JSON only.",
            "Pick the single most exam-relevant issue from today's news below and produce an editorial "
            "analysis for UPSC/State-PCS aspirants.\n"
            'Return: {"title":"...","background":"...","key_points":["..."],'
            '"arguments_for":["..."],"arguments_against":["..."],'
            '"exam_relevance":"which papers/topics this maps to","conclusion":"..."}\n\n'
            f"News:\n{digest}"
        )
        if data.get("title"):
            _store("editorial", "upsc", data["title"], data, today)
            log.info("student: stored editorial: {}", data["title"])
