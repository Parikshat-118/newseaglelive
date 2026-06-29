"""
News Eagle Live — Entry point.

Boots the Telegram application, wires handlers, starts the scheduler,
and begins polling (or webhook mode if configured).
"""
from __future__ import annotations

import asyncio
import signal
import sys

from telegram import BotCommand
from telegram.ext import Application, ApplicationBuilder, AIORateLimiter

from src.config.settings import get_settings
from src.database.connection import init_db, dispose_db
from src.utils.cache import init_redis, close_redis
from src.utils.logger import setup_logging, log
from src.handlers import register_all_handlers
from src.scheduler.runner import start_scheduler, shutdown_scheduler


COMMANDS_EN = [
    BotCommand("start", "Start / restart the bot"),
    BotCommand("news", "Browse news by category"),
    BotCommand("latest", "Latest headlines"),
    BotCommand("breaking", "Breaking news"),
    BotCommand("trending", "Trending stories"),
    BotCommand("categories", "Manage your categories"),
    BotCommand("search", "Search news"),
    BotCommand("localnews", "Local news for your PIN code"),
    BotCommand("setpincode", "Set your Indian PIN code"),
    BotCommand("mypincode", "Show your saved PIN code"),
    BotCommand("alerts", "Manage keyword alerts"),
    BotCommand("bookmarks", "Your saved articles"),
    BotCommand("history", "Your reading history"),
    BotCommand("digest", "Get today's digest"),
    BotCommand("quiz", "Current-affairs quiz"),
    BotCommand("chat", "Chat with the AI assistant"),
    BotCommand("setmobile", "Link mobile number for web login"),
    BotCommand("webauth", "Get a 6-digit web login code"),
    BotCommand("mymobile", "Show your linked mobile"),
    BotCommand("settings", "Preferences"),
    BotCommand("language", "Change language"),
    BotCommand("help", "Help"),
]


async def post_init(app: Application) -> None:
    """Runs once after the Application is built, before polling/webhook starts."""
    settings = get_settings()
    log.info("post_init: registering commands, opening connections")

    await app.bot.set_my_commands(COMMANDS_EN)
    await app.bot.set_my_short_description("Stay Ahead, Stay Informed — AI-powered news in EN/HI")
    await app.bot.set_my_description(
        "🦅 News Eagle Live — AI-curated breaking news, local PIN-code updates, "
        "keyword alerts, daily digests, and current-affairs quiz. EN + HI."
    )

    init_db()
    await init_redis()

    if not settings.scheduler_standalone:
        start_scheduler(app)

    log.info("Bot is up. Username: @{}", settings.bot_username)


async def post_shutdown(app: Application) -> None:
    log.info("post_shutdown: closing resources")
    shutdown_scheduler()
    await close_redis()
    dispose_db()


def build_app() -> Application:
    settings = get_settings()

    builder = (
        ApplicationBuilder()
        .token(settings.bot_token)
        .rate_limiter(AIORateLimiter(overall_max_rate=30, max_retries=3))
        .post_init(post_init)
        .post_shutdown(post_shutdown)
        .concurrent_updates(True)
    )

    app = builder.build()
    register_all_handlers(app)
    return app


def main() -> int:
    setup_logging()
    settings = get_settings()
    app = build_app()

    log.info("Starting News Eagle Live (env={})", settings.app_env)

    # Python 3.14: no implicit event loop in MainThread — create one for PTB
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)

    if settings.webhook_url:
        log.info("Mode: webhook → {}", settings.webhook_url)
        app.run_webhook(
            listen="127.0.0.1",
            port=settings.webhook_port,
            url_path="telegram/webhook",
            secret_token=settings.webhook_secret,
            webhook_url=settings.webhook_url,
        )
    else:
        log.info("Mode: long-polling")
        app.run_polling(drop_pending_updates=False, allowed_updates=None)

    return 0


if __name__ == "__main__":
    sys.exit(main())
