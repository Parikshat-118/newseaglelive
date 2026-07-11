"""
Central handler registration. Importing `register_all_handlers(app)` from
run.py wires up every command and callback.
"""
from __future__ import annotations

from telegram.ext import (
    Application, CommandHandler, MessageHandler, CallbackQueryHandler,
    ConversationHandler, ContextTypes, filters,
)

from src.handlers import (
    start, language, news, categories, search, pincode, alerts,
    bookmarks, history, digest, quiz, chat, settings as settings_h,
    admin, help as help_h, callbacks, webauth,
)


def register_all_handlers(app: Application) -> None:
    # --- Commands ---
    app.add_handler(CommandHandler("start", start.cmd_start))
    app.add_handler(CommandHandler("help", help_h.cmd_help))
    app.add_handler(CommandHandler("language", language.cmd_language))
    app.add_handler(CommandHandler("settings", settings_h.cmd_settings))

    app.add_handler(CommandHandler("news", news.cmd_news))
    app.add_handler(CommandHandler("latest", news.cmd_latest))
    app.add_handler(CommandHandler("breaking", news.cmd_breaking))
    app.add_handler(CommandHandler("trending", news.cmd_trending))
    app.add_handler(CommandHandler("categories", categories.cmd_categories))
    app.add_handler(CommandHandler("search", search.cmd_search))

    app.add_handler(CommandHandler("localnews", pincode.cmd_localnews))
    app.add_handler(CommandHandler("setpincode", pincode.cmd_setpincode))
    app.add_handler(CommandHandler("mypincode", pincode.cmd_mypincode))
    app.add_handler(CommandHandler("changepincode", pincode.cmd_setpincode))
    app.add_handler(CommandHandler("removepincode", pincode.cmd_removepincode))

    app.add_handler(CommandHandler("alerts", alerts.cmd_alerts))
    app.add_handler(CommandHandler("bookmarks", bookmarks.cmd_bookmarks))
    app.add_handler(CommandHandler("history", history.cmd_history))
    app.add_handler(CommandHandler("digest", digest.cmd_digest))
    app.add_handler(CommandHandler("quiz", quiz.cmd_quiz))
    app.add_handler(CommandHandler("chat", chat.cmd_chat))

    # --- Web-app bridge (Telegram-OTP login + mobile binding) ---
    app.add_handler(ConversationHandler(
        entry_points=[CommandHandler("setmobile", webauth.cmd_setmobile_start)],
        states={
            webauth.WAITING_FOR_MOBILE: [MessageHandler(filters.TEXT & ~filters.COMMAND, webauth.process_mobile_input)]
        },
        fallbacks=[CommandHandler("cancel", webauth.cancel_setmobile)],
        per_message=False,
    ))
    app.add_handler(CommandHandler("mymobile",  webauth.cmd_mymobile))
    app.add_handler(CommandHandler("webauth",   webauth.cmd_webauth))
    app.add_handler(CommandHandler("login",     webauth.cmd_webauth))  # alias

    # --- Admin ---
    app.add_handler(CommandHandler("admin", admin.cmd_admin))
    app.add_handler(CommandHandler("stats", admin.cmd_stats))
    app.add_handler(CommandHandler("broadcast", admin.cmd_broadcast))
    app.add_handler(CommandHandler("users", admin.cmd_users))

    app.add_handler(CallbackQueryHandler(callbacks.route))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, callbacks.text_router))
    app.add_error_handler(_on_error)


async def _on_error(update: object, context: ContextTypes.DEFAULT_TYPE) -> None:
    from src.utils.logger import log
    log.exception("Handler error: {}", context.error)
