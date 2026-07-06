"""
APScheduler runner. v2 adds severe-alert detection + dispatch.
"""
from __future__ import annotations

from apscheduler.schedulers.asyncio import AsyncIOScheduler
from apscheduler.triggers.interval import IntervalTrigger
from apscheduler.triggers.cron import CronTrigger
from pytz import timezone as tz

from telegram.ext import Application

from src.config.settings import get_settings
from src.scheduler import jobs as J
from src.utils.logger import log


_scheduler: AsyncIOScheduler | None = None


def start_scheduler(app: Application) -> AsyncIOScheduler:
    global _scheduler
    if _scheduler is not None:
        return _scheduler

    settings = get_settings()
    tzinfo = tz(settings.scheduler_timezone)
    _scheduler = AsyncIOScheduler(timezone=tzinfo)

    bot = app.bot

    _scheduler.add_job(
        J.job_fetch_news,
        IntervalTrigger(minutes=settings.news_fetch_interval_min),
        id="fetch_news", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_keyword_alerts, IntervalTrigger(minutes=settings.alert_interval_min),
        args=[bot], id="keyword_alerts",
        max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_breaking_push, IntervalTrigger(minutes=10),
        args=[bot], id="breaking_push",
        max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_refresh_trending, IntervalTrigger(minutes=15),
        id="refresh_trending", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_morning_digest, CronTrigger(hour=settings.morning_digest_hour, minute=0),
        args=[bot], id="morning_digest",
        max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_evening_digest, CronTrigger(hour=settings.evening_digest_hour, minute=0),
        args=[bot], id="evening_digest",
        max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_generate_quizzes, CronTrigger(hour=6, minute=0),
        id="daily_quiz", max_instances=1, coalesce=True, replace_existing=True,
    )

    # ─── Severe alerts: AI detection every 30 min, dispatch every 10 min ───
    _scheduler.add_job(
        J.job_detect_severe_alerts, IntervalTrigger(minutes=30),
        id="severe_alerts_detect",
        max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_dispatch_severe_alerts, IntervalTrigger(minutes=10),
        args=[bot], id="severe_alerts_dispatch",
        max_instances=1, coalesce=True, replace_existing=True,
    )

    # ─── v3: videos, war meter, student hub ───
    _scheduler.add_job(
        J.job_fetch_videos, IntervalTrigger(minutes=30),
        id="fetch_videos", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_compute_warmeter, IntervalTrigger(minutes=30),
        id="warmeter", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_generate_upsc, CronTrigger(hour=6, minute=30),
        id="gen_upsc", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_generate_media, CronTrigger(hour=6, minute=35),
        id="gen_media", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_generate_editorial, CronTrigger(hour=6, minute=40),
        id="gen_editorial", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_generate_mains, CronTrigger(hour=6, minute=45),
        id="gen_mains", max_instances=1, coalesce=True, replace_existing=True,
    )
    _scheduler.add_job(
        J.job_student_recovery, CronTrigger(hour=8, minute=0),
        id="gen_recovery", max_instances=1, coalesce=True, replace_existing=True,
    )

    _scheduler.start()
    log.info("Scheduler started — timezone={}, jobs={}",
             settings.scheduler_timezone,
             [j.id for j in _scheduler.get_jobs()])
    return _scheduler


def shutdown_scheduler() -> None:
    global _scheduler
    if _scheduler is not None:
        try:
            _scheduler.shutdown(wait=False)
        except Exception:
            pass
        _scheduler = None
        log.info("Scheduler shut down")
