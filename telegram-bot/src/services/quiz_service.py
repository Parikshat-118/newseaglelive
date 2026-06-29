"""
Quiz service — generates daily current-affairs quizzes via the AI service
and persists them to MySQL. Handlers fetch by date/cadence.
"""
from __future__ import annotations

from datetime import datetime, timedelta
from typing import Optional

from sqlalchemy import select, desc

from src.database.connection import session_scope
from src.database.models import Quiz, QuizResult
from src.services.ai_service import ai_json
from src.prompts.templates import generate_quiz
from src.utils.logger import log


async def generate_daily_quiz(topic: str = "current affairs (India + World)",
                              language: str = "en", n: int = 5) -> Optional[Quiz]:
    try:
        data = await ai_json(generate_quiz(topic, n=n, lang=language))
    except Exception as e:
        log.warning("Quiz generation failed: {}", e)
        return None

    questions = data.get("questions") if isinstance(data, dict) else None
    if not questions or not isinstance(questions, list):
        log.warning("Quiz response malformed: {}", data)
        return None

    with session_scope() as s:
        q = Quiz(topic=topic, cadence="daily", language=language, questions=questions)
        s.add(q)
        s.flush()
        s.expunge(q)
        return q


def latest_quiz(language: str = "en", cadence: str = "daily") -> Optional[Quiz]:
    with session_scope() as s:
        q = s.scalar(
            select(Quiz)
            .where(Quiz.language == language, Quiz.cadence == cadence)
            .order_by(desc(Quiz.created_at))
            .limit(1)
        )
        if q:
            s.expunge(q)
        return q


def record_result(user_db_id: int, quiz_id: int, score: int, total: int, answers: dict) -> None:
    with session_scope() as s:
        s.add(QuizResult(
            user_id=user_db_id, quiz_id=quiz_id,
            score=score, total=total, answers=answers,
        ))


def leaderboard(quiz_id: int, limit: int = 10):
    with session_scope() as s:
        rows = s.execute(
            select(QuizResult).where(QuizResult.quiz_id == quiz_id)
            .order_by(desc(QuizResult.score), QuizResult.taken_at)
            .limit(limit)
        ).scalars().all()
        for r in rows:
            s.expunge(r)
        return rows
