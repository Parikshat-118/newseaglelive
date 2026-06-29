"""
/start handler — onboarding flow.

Flow:
    1. Upsert user (capture referral if `/start ref_<id>`)
    2. Show language picker if first time
    3. After language → show category picker
    4. After categories Done → prompt for PIN code (or skip)
    5. After PIN → show /help summary
"""
from __future__ import annotations

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.services import user_service as us
from src.utils.i18n import t
from src.utils.keyboards import language_kb, categories_kb, pincode_skip_kb
from src.middlewares.rate_limit import rate_limited


@rate_limited(limit=10, window=60, bucket="start")
async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if not user or not update.message:
        return

    # Capture referral: /start ref_12345
    referred_by = None
    args = context.args or []
    if args and args[0].startswith("ref_"):
        try:
            ref_tg = int(args[0][4:])
            ref_user = us.get_user_by_tg(ref_tg)
            if ref_user and ref_user.telegram_id != user.id:
                referred_by = ref_user.id
        except ValueError:
            pass

    db_user = us.upsert_user(
        telegram_id=user.id,
        username=user.username,
        first_name=user.first_name,
        last_name=user.last_name,
        referred_by=referred_by,
    )

    context.user_data["db_user_id"] = db_user.id
    context.user_data["lang"] = db_user.language

    # If user is brand-new (no subscriptions), run full onboarding
    subs = us.get_subscriptions(db_user.id)
    if not subs:
        context.user_data["onboarding_step"] = "language"
        await update.message.reply_text(
            t("welcome", db_user.language),
            parse_mode=ParseMode.MARKDOWN,
        )
        await update.message.reply_text(
            t("choose_language", db_user.language),
            parse_mode=ParseMode.MARKDOWN,
            reply_markup=language_kb(),
        )
    else:
        # Returning user — short greeting
        await update.message.reply_text(
            t("welcome", db_user.language) + "\n\n" + t("help", db_user.language),
            parse_mode=ParseMode.MARKDOWN,
        )


async def advance_onboarding_to_categories(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Called from the language callback after the user picks a language."""
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    selected = us.get_subscriptions(db_uid) if db_uid else []
    context.user_data["onboarding_step"] = "categories"
    if update.effective_chat:
        await update.effective_chat.send_message(
            t("onboarding_categories", lang),
            parse_mode=ParseMode.MARKDOWN,
            reply_markup=categories_kb(selected, lang),
        )


async def advance_onboarding_to_pincode(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    """Called when the user taps Done on the category picker."""
    lang = context.user_data.get("lang", "en")
    context.user_data["onboarding_step"] = "pincode"
    if update.effective_chat:
        await update.effective_chat.send_message(
            t("onboarding_pincode", lang),
            parse_mode=ParseMode.MARKDOWN,
            reply_markup=pincode_skip_kb(lang),
        )


async def finish_onboarding(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    lang = context.user_data.get("lang", "en")
    context.user_data["onboarding_step"] = None
    if update.effective_chat:
        await update.effective_chat.send_message(
            t("onboarding_done", lang) + "\n\n" + t("help", lang),
            parse_mode=ParseMode.MARKDOWN,
        )
