"""PIN-code handlers: /setpincode /mypincode /removepincode /localnews."""
from __future__ import annotations

from telegram import Update
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.services import user_service as us, pincode_service as pin, news_service as ns
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.handlers._render import send_article_list


@rate_limited(bucket="setpincode")
async def cmd_setpincode(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    args = context.args or []
    if args:
        # /setpincode 110001 — handle inline
        await _handle_pincode_input(update, context, args[0])
        return

    # Prompt for input; mark in-flight state for the text router
    context.user_data["awaiting"] = "pincode"
    await update.message.reply_text(
        t("onboarding_pincode", lang), parse_mode=ParseMode.MARKDOWN,
    )


async def _handle_pincode_input(update: Update, context: ContextTypes.DEFAULT_TYPE, raw: str) -> None:
    lang = context.user_data.get("lang", "en")
    if not update.message:
        return
    pincode = (raw or "").strip()
    if not pin.is_valid_pincode(pincode):
        await update.message.reply_text(t("pincode_invalid", lang))
        return
    resolved = await pin.resolve(pincode)
    if not resolved:
        await update.message.reply_text(t("pincode_not_found", lang))
        return
    user = update.effective_user
    if not user:
        return
    us.set_pincode(user.id, **resolved)
    context.user_data["awaiting"] = None
    await update.message.reply_text(
        t("pincode_saved", lang, **resolved),
        parse_mode=ParseMode.MARKDOWN,
    )


@rate_limited(bucket="mypincode")
async def cmd_mypincode(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    user = update.effective_user
    if not user:
        return
    db_user = us.get_user_by_tg(user.id)
    if not db_user or not db_user.pincode:
        await update.message.reply_text(t("no_pincode", lang))
        return
    await update.message.reply_text(
        t("pincode_current", lang,
          pincode=db_user.pincode, city=db_user.city or "",
          district=db_user.district or "", state=db_user.state or ""),
        parse_mode=ParseMode.MARKDOWN,
    )


@rate_limited(bucket="removepincode")
async def cmd_removepincode(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    user = update.effective_user
    if not user:
        return
    us.clear_pincode(user.id)
    await update.message.reply_text(t("pincode_removed", lang))


@rate_limited(bucket="localnews")
async def cmd_localnews(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    user = update.effective_user
    if not user:
        return
    db_user = us.get_user_by_tg(user.id)
    if not db_user or not db_user.pincode:
        await update.message.reply_text(t("no_pincode", lang))
        return
    articles = ns.get_local(state=db_user.state or "", district=db_user.district, limit=8)
    if not articles:
        await update.message.reply_text(t("no_news", lang))
        return
    await send_article_list(update, articles, lang=lang, db_user_id=db_user.id)
