"""
Admin commands.

/admin     → menu / status
/stats     → user counts, today's articles, AI usage
/broadcast → /broadcast <message>  (sends to all active users)
/users     → recent user count + last 10 signups
"""
from __future__ import annotations

from datetime import datetime, timedelta

from sqlalchemy import select, func, desc

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.database.connection import session_scope
from src.database.models import User, NewsArticle, Notification
from src.services.notification_service import broadcast
from src.utils.i18n import t
from src.middlewares.auth import admin_only
from src.utils.logger import log


@admin_only
async def cmd_admin(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    await update.message.reply_text(
        "🛠️ *Admin*\n\n"
        "/stats — usage stats\n"
        "/users — recent users\n"
        "/broadcast <message> — message all users",
        parse_mode=ParseMode.MARKDOWN,
    )


@admin_only
async def cmd_stats(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    day_ago = datetime.utcnow() - timedelta(hours=24)
    with session_scope() as s:
        total_users   = s.scalar(select(func.count(User.id))) or 0
        active_24h    = s.scalar(select(func.count(User.id)).where(User.last_active_at >= day_ago)) or 0
        banned        = s.scalar(select(func.count(User.id)).where(User.is_banned.is_(True))) or 0
        articles_24h  = s.scalar(select(func.count(NewsArticle.id)).where(NewsArticle.fetched_at >= day_ago)) or 0
        breaking_24h  = s.scalar(
            select(func.count(NewsArticle.id))
            .where(NewsArticle.fetched_at >= day_ago, NewsArticle.is_breaking.is_(True))
        ) or 0
        notif_24h     = s.scalar(select(func.count(Notification.id)).where(Notification.created_at >= day_ago)) or 0
    await update.message.reply_text(
        f"📊 *Stats — last 24h*\n\n"
        f"👤 Users total: *{total_users}*\n"
        f"🟢 Active 24h: *{active_24h}*\n"
        f"🚫 Banned: *{banned}*\n"
        f"📰 Articles fetched 24h: *{articles_24h}*\n"
        f"⚡ Breaking 24h: *{breaking_24h}*\n"
        f"🔔 Notifications 24h: *{notif_24h}*",
        parse_mode=ParseMode.MARKDOWN,
    )


@admin_only
async def cmd_users(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    with session_scope() as s:
        rows = s.execute(
            select(User.telegram_id, User.username, User.language, User.created_at)
            .order_by(desc(User.created_at)).limit(10)
        ).all()
    lines = ["🧑‍🤝‍🧑 *Last 10 signups:*\n"]
    for tg, uname, lang, ts in rows:
        lines.append(f"• `{tg}` @{uname or '—'} [{lang}] {ts:%Y-%m-%d %H:%M}")
    await update.message.reply_text("\n".join(lines), parse_mode=ParseMode.MARKDOWN)


@admin_only
async def cmd_broadcast(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    args = context.args or []
    if not args:
        await update.message.reply_text(
            "📢 Usage:\n`/broadcast Your message here`\n\n"
            "Optional filter: `/broadcast lang=hi Your message`",
            parse_mode=ParseMode.MARKDOWN,
        )
        return

    # Optional filter: lang=en|hi prefix
    filter_lang = None
    if args[0].startswith("lang="):
        filter_lang = args[0].split("=", 1)[1].strip().lower()
        args = args[1:]
        if filter_lang not in {"en", "hi"}:
            filter_lang = None
    message = " ".join(args).strip()
    if not message:
        await update.message.reply_text("Empty message — aborting.")
        return

    with session_scope() as s:
        stmt = select(User.telegram_id).where(
            User.is_banned.is_(False), User.notifications_enabled.is_(True),
        )
        if filter_lang:
            stmt = stmt.where(User.language == filter_lang)
        ids = [row[0] for row in s.execute(stmt).all()]

    await update.message.reply_text(t("broadcast_sent", "en", count=len(ids)))
    ok, fail = await broadcast(context.bot, ids, message)
    log.info("Broadcast finished: ok={} fail={}", ok, fail)
    await update.message.reply_text(f"✅ Broadcast complete\nSent: {ok}\nFailed: {fail}")
