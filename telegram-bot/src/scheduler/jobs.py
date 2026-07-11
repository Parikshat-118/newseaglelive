"""
APScheduler job definitions.

Each job is an async function. They are registered in `runner.start_scheduler`.
All jobs are idempotent / safe to retry.

v2 additions: severe-alerts detection + dispatch.
"""
from __future__ import annotations

import asyncio
from datetime import datetime

from sqlalchemy import select

from telegram import Bot
from telegram.constants import ParseMode

from src.database.connection import session_scope
from src.database.models import User, Notification, NewsArticle
from src.services import (
    news_service as ns, keyword_service as ks, digest_service as ds, quiz_service as qs,
)
from src.services.notification_service import send_to_user, broadcast
from src.services import severe_alerts_service as sa
from src.utils.formatting import escape_md, truncate
from src.utils.i18n import t
from src.utils.logger import log


async def job_fetch_news() -> None:
    log.info("[job] fetch_news started")
    try:
        n = await ns.ingest_all()
        log.info("[job] fetch_news done — {} new articles", n)
    except Exception:
        log.exception("[job] fetch_news failed")


async def job_keyword_alerts(bot: Bot) -> None:
    log.info("[job] keyword_alerts started")
    try:
        matches = ks.find_keyword_matches(since_minutes=20)
    except Exception:
        log.exception("[job] keyword match failed")
        return
    for tg_id, article_id, kw in matches:
        article = ns.get_article(article_id)
        if not article:
            continue
        title = escape_md(article.title or "")
        snippet = escape_md(truncate(article.summary or "", 200))
        text = f"🔔 *Keyword:* `{escape_md(kw)}`\n\n*{title}*\n{snippet}\n\n{article.url}"
        try:
            await send_to_user(bot, tg_id, text, parse_mode=ParseMode.MARKDOWN_V2)
        except Exception as e:
            log.warning("keyword alert send failed tg={}: {}", tg_id, e)
        await asyncio.sleep(0.05)


async def job_breaking_push(bot: Bot) -> None:
    log.info("[job] breaking_push started")
    from datetime import timedelta
    cutoff = datetime.utcnow() - timedelta(minutes=15)
    try:
        with session_scope() as s:
            articles = list(s.scalars(select(NewsArticle).where(
                NewsArticle.is_breaking.is_(True),
                NewsArticle.fetched_at >= cutoff,
            )).all())
            for a in articles:
                s.expunge(a)
    except Exception:
        log.exception("[job] breaking_push query failed")
        return

    if not articles:
        return

    with session_scope() as s:
        rows = s.execute(
            select(User.telegram_id, User.language).where(
                User.is_banned.is_(False),
                User.notifications_enabled.is_(True),
                User.breaking_alerts.is_(True),
            )
        ).all()
    by_lang: dict[str, list[int]] = {}
    for tg, lang in rows:
        by_lang.setdefault(lang or "en", []).append(tg)

    for a in articles:
        text_msg = (
            f"⚡ *BREAKING*\n\n*{escape_md(a.title or '')}*\n"
            f"{escape_md(truncate(a.summary or '', 240))}\n\n{a.url}"
        )
        recipients: list[int] = []
        if a.language and a.language in by_lang:
            recipients = by_lang[a.language]
        else:
            for ids in by_lang.values():
                recipients.extend(ids)
        if not recipients:
            continue
        ok, fail = await broadcast(bot, recipients, text_msg)
        log.info("breaking push article={} ok={} fail={}", a.id, ok, fail)


async def job_morning_digest(bot: Bot) -> None:
    await _send_digest(bot, when="morning", pref_attr="morning_digest")


async def job_evening_digest(bot: Bot) -> None:
    await _send_digest(bot, when="evening", pref_attr="evening_digest")


async def _send_digest(bot: Bot, *, when: str, pref_attr: str) -> None:
    log.info("[job] digest {} started", when)
    for lang in ("en", "hi"):
        articles = ds.build_digest(when=when, language=lang, top_n=10)
        if not articles:
            continue
        text_msg = ds.render_digest(articles, lang=lang)
        if len(text_msg) > 4000:
            text_msg = text_msg[:3990] + "…"

        with session_scope() as s:
            stmt = select(User.telegram_id).where(
                User.language == lang,
                User.is_banned.is_(False),
                User.notifications_enabled.is_(True),
            )
            if pref_attr == "morning_digest":
                stmt = stmt.where(User.morning_digest.is_(True))
            else:
                stmt = stmt.where(User.evening_digest.is_(True))
            ids = [r[0] for r in s.execute(stmt).all()]
        if not ids:
            continue
        ok, fail = await broadcast(bot, ids, text_msg)
        log.info("digest {}/{} ok={} fail={}", when, lang, ok, fail)


async def job_generate_quizzes() -> None:
    log.info("[job] generate_quizzes started")
    for lang in ("en", "hi"):
        try:
            await qs.generate_daily_quiz(language=lang, n=5)
        except Exception:
            log.exception("quiz generation failed for lang={}", lang)


async def job_refresh_trending() -> None:
    log.info("[job] refresh_trending")
    try:
        ns.refresh_trending_scores()
    except Exception:
        log.exception("trending refresh failed")


# ---------------------------------------------------------------------
# v2 — Severe alerts
# ---------------------------------------------------------------------
async def job_detect_severe_alerts() -> None:
    log.info("[job] severe_alerts_detect started")
    try:
        n = await sa.detect_alerts(window_minutes=90)
        log.info("[job] severe_alerts_detect done — {} new", n)
    except Exception:
        log.exception("[job] severe_alerts_detect failed")


async def job_dispatch_severe_alerts(bot: Bot) -> None:
    log.info("[job] severe_alerts_dispatch started")
    try:
        sent = await sa.dispatch_pending(bot)
        log.info("[job] severe_alerts_dispatch done — {} push messages", sent)
    except Exception:
        log.exception("[job] severe_alerts_dispatch failed")


# ---------------------------------------------------------------------
# v3 — Videos, War Meter, Student Hub
# ---------------------------------------------------------------------
async def job_fetch_videos() -> None:
    log.info("[job] fetch_videos started")
    try:
        from src.services import videos_service as vs
        n = await vs.fetch_videos()
        log.info("[job] fetch_videos done — {} new", n)
    except Exception:
        log.exception("[job] fetch_videos failed")


async def job_compute_warmeter() -> None:
    log.info("[job] warmeter started")
    try:
        from src.services import warmeter_service as wm
        wm.compute_warmeter()
        log.info("[job] warmeter done")
    except Exception:
        log.exception("[job] warmeter failed")


async def job_generate_upsc() -> None:
    log.info("[job] generate upsc started")
    try:
        from src.services.student_service import generate_content
        await generate_content("upsc_quiz")
    except Exception:
        log.exception("[job] generate upsc failed")


async def job_generate_media() -> None:
    log.info("[job] generate media started")
    try:
        from src.services.student_service import generate_content
        await generate_content("media_quiz")
    except Exception:
        log.exception("[job] generate media failed")


async def job_generate_editorial() -> None:
    log.info("[job] generate editorial started")
    try:
        from src.services.student_service import generate_content
        await generate_content("editorial")
    except Exception:
        log.exception("[job] generate editorial failed")


async def job_generate_mains() -> None:
    log.info("[job] generate mains started")
    try:
        from src.services.student_service import generate_content
        await generate_content("mains")
    except Exception:
        log.exception("[job] generate mains failed")


async def job_generate_upsc_hi() -> None:
    log.info("[job] generate upsc hi started")
    try:
        from src.services.student_service import generate_content
        await generate_content("upsc_quiz", language="hi")
    except Exception:
        log.exception("[job] generate upsc hi failed")


async def job_generate_media_hi() -> None:
    log.info("[job] generate media hi started")
    try:
        from src.services.student_service import generate_content
        await generate_content("media_quiz", language="hi")
    except Exception:
        log.exception("[job] generate media hi failed")


async def job_generate_editorial_hi() -> None:
    log.info("[job] generate editorial hi started")
    try:
        from src.services.student_service import generate_content
        await generate_content("editorial", language="hi")
    except Exception:
        log.exception("[job] generate editorial hi failed")


async def job_generate_mains_hi() -> None:
    log.info("[job] generate mains hi started")
    try:
        from src.services.student_service import generate_content
        await generate_content("mains", language="hi")
    except Exception:
        log.exception("[job] generate mains hi failed")


async def job_student_recovery() -> None:
    log.info("[job] student recovery started")
    try:
        from datetime import date
        from src.services.student_service import generate_content, _generation_exists
        today = date.today()
        for lang in ["en", "hi"]:
            for t in ["upsc_quiz", "media_quiz", "editorial", "mains"]:
                if not _generation_exists(t, today, lang):
                    log.info("Recovery: generating missing {} lang={}", t, lang)
                    await generate_content(t, today, language=lang)
    except Exception:
        log.exception("[job] student recovery failed")
