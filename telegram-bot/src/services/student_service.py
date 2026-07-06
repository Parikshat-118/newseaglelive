"""
student_service.py — Student Hub V3 content generator.

Generates every day at 06:30 IST (via scheduler):
  • UPSC/SSC MCQ quiz    (10 questions)
  • Media Exam quiz      (5 questions)
  • Editorial analysis   (fully normalized, no JSON blobs)
  • Mains Practice       (2 questions with answer points)

Architecture:
  • Uses ai_provider.get_ai_provider() — configurable via AI_PROVIDER / AI_MODEL
  • Tracks status in ai_generations (QUEUED → RUNNING → COMPLETED/FAILED)
  • Exponential backoff retry (up to 3 attempts)
  • Strict validation before inserting
  • Many-to-many source article linking
  • Prompt versioning for auditability
"""
from __future__ import annotations

import asyncio
import json
import os
from datetime import date, datetime
from typing import Any

from sqlalchemy import text

from src.database.connection import session_scope
from src.services.ai_provider import get_ai_provider, AIProviderError
from src.utils.logger import log

# ── Prompt versions — bump when prompts change significantly ──────
PROMPT_VER_UPSC_QUIZ    = "v1.1-upsc-quiz-20q"
PROMPT_VER_MEDIA_QUIZ   = "v1.1-media-quiz-20q"
PROMPT_VER_EDITORIAL    = "v1.0-editorial"
PROMPT_VER_MAINS        = "v1.0-mains"

MAX_RETRIES             = 3
BASE_BACKOFF_SEC        = 5


# ──────────────────────────────────────────────────────────────────
# Helpers
# ──────────────────────────────────────────────────────────────────

def _get_top_articles(hours: int = 24, limit: int = 30) -> list[dict]:
    """Return recent articles as list of {id, title, summary}."""
    with session_scope() as s:
        rows = s.execute(text(
            "SELECT id, title, COALESCE(summary,'') AS summary "
            "FROM news_articles "
            "WHERE fetched_at >= (NOW() - INTERVAL :h HOUR) "
            "ORDER BY trending_score DESC, published_at DESC LIMIT :n"
        ), {"h": hours, "n": limit}).all()
    return [{"id": r.id, "title": r.title, "summary": r.summary} for r in rows]


def _build_news_digest(articles: list[dict]) -> str:
    return "\n".join(
        f"- [{a['id']}] {a['title']}: {a['summary'][:200]}" for a in articles
    )


def _generation_exists(content_type: str, d: date) -> bool:
    with session_scope() as s:
        row = s.execute(text(
            "SELECT status FROM ai_generations "
            "WHERE content_type=:t AND content_date=:d LIMIT 1"
        ), {"t": content_type, "d": d}).first()
    if not row:
        return False
    # Re-attempt only if previously FAILED
    return row.status != "FAILED"


def _create_generation(content_type: str, d: date, provider: str, model: str, prompt_ver: str) -> int:
    with session_scope() as s:
        r = s.execute(text(
            "INSERT INTO ai_generations "
            "(content_type, content_date, provider, model, prompt_version, status) "
            "VALUES (:t, :d, :p, :m, :pv, 'QUEUED')"
        ), {"t": content_type, "d": d, "p": provider, "m": model, "pv": prompt_ver})
        return r.lastrowid


def _update_generation(gen_id: int, status: str, meta: dict | None = None, error: str | None = None) -> None:
    with session_scope() as s:
        params: dict[str, Any] = {"id": gen_id, "status": status}
        extra = ""
        if meta:
            extra += (
                ", generation_time_sec=:gt, prompt_tokens=:pt, "
                "completion_tokens=:ct, total_tokens=:tt"
            )
            params.update({
                "gt": meta.get("generation_time"),
                "pt": meta.get("prompt_tokens"),
                "ct": meta.get("completion_tokens"),
                "tt": meta.get("total_tokens"),
            })
        if error is not None:
            extra += ", error_msg=:err"
            params["err"] = error[:1000]
        s.execute(
            text(f"UPDATE ai_generations SET status=:status{extra} WHERE id=:id"),
            params,
        )


def _increment_retry(gen_id: int) -> None:
    with session_scope() as s:
        s.execute(
            text("UPDATE ai_generations SET retry_count=retry_count+1 WHERE id=:id"),
            {"id": gen_id},
        )


# ──────────────────────────────────────────────────────────────────
# Validation
# ──────────────────────────────────────────────────────────────────

def _validate_quiz(questions: list, expected: int) -> tuple[bool, str]:
    """Returns (is_valid, reason)."""
    if len(questions) < expected:
        return False, f"Expected {expected} questions, got {len(questions)}"

    for i, q in enumerate(questions):
        for field in ("q", "option_a", "option_b", "option_c", "option_d", "answer", "explanation"):
            if not q.get(field):
                return False, f"Question {i+1} missing '{field}'"

        answer = str(q["answer"]).upper()
        if answer not in ("A", "B", "C", "D"):
            return False, f"Question {i+1} has invalid answer '{answer}' (must be A/B/C/D)"

        opts = [q.get("option_a",""), q.get("option_b",""), q.get("option_c",""), q.get("option_d","")]
        if len(set(opts)) < 4:
            return False, f"Question {i+1} has duplicate options"

        if len(q["explanation"]) < 10:
            return False, f"Question {i+1} explanation too short"

    return True, ""


def _validate_editorial(data: dict) -> tuple[bool, str]:
    for field in ("title", "background", "exam_relevance", "conclusion"):
        if not data.get(field):
            return False, f"Editorial missing '{field}'"
    if not data.get("key_points"):
        return False, "Editorial missing 'key_points'"
    return True, ""


def _validate_mains(questions: list) -> tuple[bool, str]:
    if len(questions) < 1:
        return False, "No mains questions returned"
    for i, q in enumerate(questions):
        for field in ("q", "paper", "points"):
            if not q.get(field):
                return False, f"Mains Q{i+1} missing '{field}'"
        if len(q["points"]) < 3:
            return False, f"Mains Q{i+1} has fewer than 3 answer points"
    return True, ""


# ──────────────────────────────────────────────────────────────────
# DB Insertions
# ──────────────────────────────────────────────────────────────────

def _store_quiz(
    gen_id: int,
    exam_type: str,
    title: str,
    questions: list,
    d: date,
    article_ids: list[int],
) -> None:
    with session_scope() as s:
        r = s.execute(text(
            "INSERT INTO daily_quizzes (generation_id, exam_type, quiz_date, title) "
            "VALUES (:g, :e, :d, :t)"
        ), {"g": gen_id, "e": exam_type, "d": d, "t": title[:512]})
        quiz_id = r.lastrowid

        for i, q in enumerate(questions):
            s.execute(text(
                "INSERT INTO quiz_questions "
                "(quiz_id, question, option_a, option_b, option_c, option_d, "
                " correct_option, explanation, topic, difficulty, order_no) "
                "VALUES (:qi, :q, :a, :b, :c, :d, :ans, :ex, :tp, :df, :ord)"
            ), {
                "qi":  quiz_id,
                "q":   q["q"][:2000],
                "a":   q["option_a"][:1000],
                "b":   q["option_b"][:1000],
                "c":   q["option_c"][:1000],
                "d":   q["option_d"][:1000],
                "ans": str(q["answer"]).upper(),
                "ex":  q.get("explanation", "")[:4000],
                "tp":  q.get("topic", "GK")[:128],
                "df":  q.get("difficulty", "medium"),
                "ord": i,
            })

        for aid in article_ids:
            s.execute(text(
                "INSERT IGNORE INTO quiz_source_articles (quiz_id, article_id) VALUES (:qi, :ai)"
            ), {"qi": quiz_id, "ai": aid})

    log.info("student: stored {} {} quiz questions (quiz_id={})", len(questions), exam_type, quiz_id)


def _store_editorial(
    gen_id: int,
    data: dict,
    d: date,
    article_ids: list[int],
) -> None:
    with session_scope() as s:
        r = s.execute(text(
            "INSERT INTO daily_editorials "
            "(generation_id, editorial_date, title, background, exam_relevance, conclusion) "
            "VALUES (:g, :d, :t, :bg, :er, :con)"
        ), {
            "g":   gen_id,
            "d":   d,
            "t":   data["title"][:512],
            "bg":  data["background"],
            "er":  data["exam_relevance"],
            "con": data["conclusion"],
        })
        ed_id = r.lastrowid

        point_rows = []
        for i, pt in enumerate(data.get("key_points", [])):
            point_rows.append({"eid": ed_id, "pt": "key_point", "c": pt, "o": i})
        for i, pt in enumerate(data.get("arguments_for", [])):
            point_rows.append({"eid": ed_id, "pt": "arg_for", "c": pt, "o": i})
        for i, pt in enumerate(data.get("arguments_against", [])):
            point_rows.append({"eid": ed_id, "pt": "arg_against", "c": pt, "o": i})

        for row in point_rows:
            s.execute(text(
                "INSERT INTO editorial_points (editorial_id, point_type, content, order_no) "
                "VALUES (:eid, :pt, :c, :o)"
            ), row)

        for aid in article_ids:
            s.execute(text(
                "INSERT IGNORE INTO editorial_source_articles (editorial_id, article_id) "
                "VALUES (:ei, :ai)"
            ), {"ei": ed_id, "ai": aid})

    log.info("student: stored editorial '{}' (id={})", data["title"][:60], ed_id)


def _store_mains(
    gen_id: int,
    questions: list,
    d: date,
    article_ids: list[int],
) -> None:
    with session_scope() as s:
        for i, q in enumerate(questions[:2]):
            r = s.execute(text(
                "INSERT INTO daily_mains_questions "
                "(generation_id, mains_date, question, paper, hint, order_no) "
                "VALUES (:g, :d, :q, :p, :h, :o)"
            ), {
                "g": gen_id, "d": d,
                "q": q["q"],
                "p": q.get("paper", "GS-2")[:32],
                "h": q.get("hint", ""),
                "o": i,
            })
            mq_id = r.lastrowid

            for j, pt in enumerate(q.get("points", [])):
                s.execute(text(
                    "INSERT INTO mains_answer_points (mains_question_id, content, order_no) "
                    "VALUES (:mid, :c, :o)"
                ), {"mid": mq_id, "c": pt, "o": j})

            for aid in article_ids:
                s.execute(text(
                    "INSERT IGNORE INTO mains_source_articles (mains_question_id, article_id) "
                    "VALUES (:mi, :ai)"
                ), {"mi": mq_id, "ai": aid})

    log.info("student: stored {} mains questions", len(questions[:2]))


# ──────────────────────────────────────────────────────────────────
# Core generation with retry + exponential backoff
# ──────────────────────────────────────────────────────────────────

async def _generate_with_retry(
    provider,
    system: str,
    user: str,
    gen_id: int,
    validate_fn,
) -> dict:
    """
    Attempt provider.generate_json() up to MAX_RETRIES times.
    Uses exponential backoff. Updates retry_count in DB.
    Raises AIProviderError if all attempts fail.
    """
    last_error = ""
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            data = await provider.generate_json(system, user)
            meta = data.pop("__meta__", {})

            ok, reason = validate_fn(data)
            if not ok:
                raise AIProviderError(f"Validation failed: {reason}")

            data["__meta__"] = meta
            return data

        except AIProviderError as exc:
            last_error = str(exc)
            log.warning("student: attempt {}/{} failed: {}", attempt, MAX_RETRIES, last_error)
            _increment_retry(gen_id)
            if attempt < MAX_RETRIES:
                backoff = BASE_BACKOFF_SEC * (2 ** (attempt - 1))
                log.info("student: retrying in {}s...", backoff)
                await asyncio.sleep(backoff)

    raise AIProviderError(f"All {MAX_RETRIES} attempts failed. Last: {last_error}")


# ──────────────────────────────────────────────────────────────────
# Main Entry Point (called by scheduler at 06:30 IST)
# ──────────────────────────────────────────────────────────────────

async def generate_daily_student_content() -> None:
    today    = date.today()
    articles = _get_top_articles()

    if not articles:
        log.info("student: no news available to generate content from")
        return

    digest       = _build_news_digest(articles)
    article_ids  = [a["id"] for a in articles]

    try:
        provider = get_ai_provider()
    except RuntimeError as exc:
        log.error("student: AI provider not configured — {}", exc)
        return

    prov  = provider.provider_name
    model = provider.model_name

    # ── 1. UPSC/SSC Quiz ─────────────────────────────────────────
    content_type = "upsc_quiz"
    if not _generation_exists(content_type, today):
        gen_id = _create_generation(content_type, today, prov, model, PROMPT_VER_UPSC_QUIZ)
        _update_generation(gen_id, "RUNNING")
        try:
            data = await _generate_with_retry(
                provider,
                system=(
                    "You are an expert UPSC/SSC exam question creator. "
                    "Generate questions in strict JSON only. No markdown, no prose."
                ),
                user=(
                    "From today's Indian news below, create EXACTLY 20 multiple-choice questions "
                    "in UPSC Prelims / SSC CGL style. Mix difficulty levels. "
                    "For each question provide 4 distinct options, exactly one correct answer, "
                    "a 2-3 sentence explanation, topic, and difficulty.\n\n"
                    'Return ONLY this JSON structure:\n'
                    '{"title":"UPSC/SSC Daily Quiz — DD Mon YYYY",'
                    '"questions":[{"q":"...","option_a":"...","option_b":"...","option_c":"...","option_d":"...",'
                    '"answer":"A","explanation":"...","topic":"Polity|Economy|IR|S&T|Environment|Defence|Misc",'
                    '"difficulty":"easy|medium|hard"}]}\n\n'
                    f"News digest:\n{digest}"
                ),
                gen_id=gen_id,
                validate_fn=lambda d: _validate_quiz(d.get("questions", []), 16),
            )
            meta = data.pop("__meta__", {})
            _store_quiz(gen_id, "upsc", data.get("title", f"UPSC/SSC Daily Quiz — {today:%d %b %Y}"),
                        data["questions"][:20], today, article_ids)
            _update_generation(gen_id, "COMPLETED", meta)
        except AIProviderError as exc:
            _update_generation(gen_id, "FAILED", error=str(exc))
            log.error("student: UPSC quiz generation failed — {}", exc)

    # ── 2. Media Exam Quiz ────────────────────────────────────────
    content_type = "media_quiz"
    if not _generation_exists(content_type, today):
        gen_id = _create_generation(content_type, today, prov, model, PROMPT_VER_MEDIA_QUIZ)
        _update_generation(gen_id, "RUNNING")
        try:
            data = await _generate_with_retry(
                provider,
                system=(
                    "You are an expert for journalism & mass communication entrance exams "
                    "(IIMC, YMCA, JMI, ACJ). Generate questions in strict JSON only."
                ),
                user=(
                    "From today's news below, create EXACTLY 20 MCQs for media students — "
                    "focus on media current affairs, press freedom, media law/ethics, "
                    "famous journalists/editors, broadcast or digital media in the news.\n\n"
                    'Return ONLY this JSON structure:\n'
                    '{"title":"Media Exam Quiz — DD Mon YYYY",'
                    '"questions":[{"q":"...","option_a":"...","option_b":"...","option_c":"...","option_d":"...",'
                    '"answer":"A","explanation":"...","topic":"Media","difficulty":"easy|medium|hard"}]}\n\n'
                    f"News digest:\n{digest}"
                ),
                gen_id=gen_id,
                validate_fn=lambda d: _validate_quiz(d.get("questions", []), 16),
            )
            meta = data.pop("__meta__", {})
            _store_quiz(gen_id, "media", data.get("title", f"Media Students Quiz — {today:%d %b %Y}"),
                        data["questions"][:20], today, article_ids)
            _update_generation(gen_id, "COMPLETED", meta)
        except AIProviderError as exc:
            _update_generation(gen_id, "FAILED", error=str(exc))
            log.error("student: Media quiz generation failed — {}", exc)

    # ── 3. Editorial Analysis ─────────────────────────────────────
    content_type = "editorial"
    if not _generation_exists(content_type, today):
        gen_id = _create_generation(content_type, today, prov, model, PROMPT_VER_EDITORIAL)
        _update_generation(gen_id, "RUNNING")
        try:
            data = await _generate_with_retry(
                provider,
                system=(
                    "You are a UPSC editorial analyst. Write structured editorial breakdowns. "
                    "Return strict JSON only — no prose, no markdown."
                ),
                user=(
                    "Pick the single most exam-relevant news story from the digest below. "
                    "Produce a complete editorial analysis for UPSC/State-PCS aspirants.\n\n"
                    'Return ONLY this JSON:\n'
                    '{"title":"...","background":"2-3 sentence context paragraph",'
                    '"key_points":["point 1","point 2","point 3","point 4","point 5"],'
                    '"arguments_for":["...","...","..."],'
                    '"arguments_against":["...","...","..."],'
                    '"exam_relevance":"Which GS papers and topics this maps to",'
                    '"conclusion":"2-sentence conclusion"}\n\n'
                    f"News digest:\n{digest}"
                ),
                gen_id=gen_id,
                validate_fn=_validate_editorial,
            )
            meta = data.pop("__meta__", {})
            _store_editorial(gen_id, data, today, article_ids)
            _update_generation(gen_id, "COMPLETED", meta)
        except AIProviderError as exc:
            _update_generation(gen_id, "FAILED", error=str(exc))
            log.error("student: Editorial generation failed — {}", exc)

    # ── 4. Mains Practice ─────────────────────────────────────────
    content_type = "mains"
    if not _generation_exists(content_type, today):
        gen_id = _create_generation(content_type, today, prov, model, PROMPT_VER_MAINS)
        _update_generation(gen_id, "RUNNING")
        try:
            data = await _generate_with_retry(
                provider,
                system=(
                    "You are a UPSC Mains examiner. Generate 2 analytical questions. "
                    "Return strict JSON only."
                ),
                user=(
                    "From today's news below, create EXACTLY 2 UPSC Mains questions "
                    "(GS-2 or GS-3, 150-250 word answers). For each provide: "
                    "the full question, the GS paper, 5-7 model answer points, "
                    "and a 1-line approach hint.\n\n"
                    'Return ONLY this JSON:\n'
                    '{"questions":[{"q":"...","paper":"GS-2","hint":"...","points":["...","..."]}]}\n\n'
                    f"News digest:\n{digest}"
                ),
                gen_id=gen_id,
                validate_fn=lambda d: _validate_mains(d.get("questions", [])),
            )
            meta = data.pop("__meta__", {})
            _store_mains(gen_id, data["questions"], today, article_ids)
            _update_generation(gen_id, "COMPLETED", meta)
        except AIProviderError as exc:
            _update_generation(gen_id, "FAILED", error=str(exc))
            log.error("student: Mains generation failed — {}", exc)

    log.info("student: daily generation complete for {}", today)
