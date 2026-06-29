"""
Central callback_query router and free-text message router.

Callback data convention:  <namespace>:<action>[:<arg>...]

Namespaces:
    lang  — language picker
    cat   — category toggle (onboarding + /categories)
    pin   — pincode (e.g. pin:skip)
    news  — news category quick-pick (news:cat:<slug>)
    art   — article actions (sum/exp/bm/unbm/tr)
    kw    — keyword alerts (add/del)
    set   — settings toggles
    quiz  — quiz answer

Free-text router handles in-flight states:
    awaiting == 'pincode' → process PIN code input
    awaiting == 'keyword' → add a new alert keyword
    awaiting == 'chat'    → forward to AI assistant
"""
from __future__ import annotations

import re

from telegram import Update
from telegram.constants import ChatAction, ParseMode
from telegram.ext import ContextTypes

from src.config.categories import CATEGORIES
from src.database.connection import session_scope
from src.database.models import NewsArticle
from src.services import (
    user_service as us, news_service as ns, ai_service, pincode_service as pin,
)
from src.prompts.templates import summarize_article, explain_news, translate_to_hindi
from src.utils.formatting import escape_md, truncate
from src.utils.i18n import t
from src.utils.keyboards import categories_kb
from src.utils.logger import log
from src.handlers import start as start_h, settings as settings_h, quiz as quiz_h
from src.handlers.pincode import _handle_pincode_input
from src.handlers.chat import handle_chat_text


# ---------------------------------------------------------------------
# Callback query router
# ---------------------------------------------------------------------
async def route(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    q = update.callback_query
    if not q or not q.data:
        return
    try:
        await q.answer()
    except Exception:
        pass

    parts = q.data.split(":")
    ns_ = parts[0]
    action = parts[1] if len(parts) > 1 else ""
    args = parts[2:]

    try:
        if ns_ == "lang":
            await _cb_lang(update, context, action, args)
        elif ns_ == "cat":
            await _cb_category(update, context, action, args)
        elif ns_ == "pin":
            await _cb_pincode(update, context, action, args)
        elif ns_ == "news":
            await _cb_news(update, context, action, args)
        elif ns_ == "art":
            await _cb_article(update, context, action, args)
        elif ns_ == "kw":
            await _cb_keyword(update, context, action, args)
        elif ns_ == "set":
            await _cb_settings(update, context, action, args)
        elif ns_ == "quiz":
            await _cb_quiz(update, context, action, args)
        else:
            log.debug("Unknown callback ns: {}", q.data)
    except Exception:
        log.exception("Callback route error for data={}", q.data)


# ---------------------------------------------------------------------
# lang
# ---------------------------------------------------------------------
async def _cb_lang(update, context, action, args):
    if action != "set" or not args:
        return
    code = args[0].lower()
    if code not in {"en", "hi"}:
        return
    user = update.effective_user
    if not user:
        return
    us.set_language(user.id, code)
    context.user_data["lang"] = code

    q = update.callback_query
    if q and q.message:
        try:
            await q.edit_message_text(t("language_set", code), parse_mode=ParseMode.MARKDOWN)
        except Exception:
            pass

    # If we're mid-onboarding, advance to categories
    if context.user_data.get("onboarding_step") == "language":
        await start_h.advance_onboarding_to_categories(update, context)


# ---------------------------------------------------------------------
# cat (categories toggle)
# ---------------------------------------------------------------------
async def _cb_category(update, context, action, args):
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    user = update.effective_user
    if db_uid is None and user is not None:
        db_user = us.get_user_by_tg(user.id)
        if db_user:
            db_uid = db_user.id
            context.user_data["db_user_id"] = db_uid

    if action == "toggle" and args and db_uid:
        slug = args[0]
        if slug not in CATEGORIES:
            return
        us.toggle_subscription(db_uid, slug)
        # Re-render the picker
        selected = us.get_subscriptions(db_uid)
        q = update.callback_query
        if q and q.message:
            try:
                await q.edit_message_reply_markup(
                    reply_markup=categories_kb(selected, lang, with_done=True)
                )
            except Exception:
                pass

    elif action == "done":
        # If onboarding → next step is PIN
        if context.user_data.get("onboarding_step") == "categories":
            await start_h.advance_onboarding_to_pincode(update, context)
        else:
            if update.effective_chat:
                await update.effective_chat.send_message(
                    "✅ Saved." if lang == "en" else "✅ सहेज लिया।"
                )


# ---------------------------------------------------------------------
# pin
# ---------------------------------------------------------------------
async def _cb_pincode(update, context, action, args):
    lang = context.user_data.get("lang", "en")
    if action == "skip":
        # Skip during onboarding
        if context.user_data.get("onboarding_step") == "pincode":
            await start_h.finish_onboarding(update, context)
        elif update.effective_chat:
            await update.effective_chat.send_message(
                "⏭️ Skipped." if lang == "en" else "⏭️ छोड़ा गया।"
            )


# ---------------------------------------------------------------------
# news (category quick-pick from /news)
# ---------------------------------------------------------------------
async def _cb_news(update, context, action, args):
    if action != "cat" or not args:
        return
    slug = args[0]
    if slug not in CATEGORIES:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    articles = ns.get_latest(category=slug, language=lang, limit=8)
    from src.handlers._render import send_article_list
    await send_article_list(update, articles, lang=lang, db_user_id=db_uid)


# ---------------------------------------------------------------------
# art (article actions)
# ---------------------------------------------------------------------
async def _cb_article(update, context, action, args):
    if not args or not args[0].isdigit():
        return
    article_id = int(args[0])
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    chat = update.effective_chat
    article = ns.get_article(article_id)
    if not article:
        if chat:
            await chat.send_message(t("no_news", lang))
        return

    if action in {"sum", "exp", "tr"}:
        if not chat:
            return
        await chat.send_chat_action(ChatAction.TYPING)
        try:
            if action == "sum":
                msgs = summarize_article(article.title, article.content or article.summary or "", lang)
            elif action == "exp":
                msgs = explain_news(article.title, article.content or article.summary or "", lang)
            else:
                # Translate the existing summary/content to Hindi
                src_text = article.ai_summary or article.summary or article.title
                msgs = translate_to_hindi(src_text or "")
            text = await ai_service.ai_complete(msgs, temperature=0.3)
        except Exception as e:
            log.warning("AI op '{}' failed: {}", action, e)
            await chat.send_message(t("ai_unavailable", lang))
            return

        # Persist summary for future calls (best effort)
        if action == "sum":
            try:
                with session_scope() as s:
                    db_art = s.get(NewsArticle, article_id)
                    if db_art:
                        if lang == "hi":
                            db_art.ai_summary_hi = text[:4000]
                        else:
                            db_art.ai_summary = text[:4000]
            except Exception:
                pass

        try:
            await chat.send_message(text, parse_mode=ParseMode.MARKDOWN)
        except Exception:
            await chat.send_message(text)
        return

    if action == "bm" and db_uid:
        us.add_bookmark(db_uid, article_id)
        if chat:
            await chat.send_message(t("bookmark_added", lang))
    elif action == "unbm" and db_uid:
        us.remove_bookmark(db_uid, article_id)
        if chat:
            await chat.send_message(t("bookmark_removed", lang))


# ---------------------------------------------------------------------
# kw (keyword alerts)
# ---------------------------------------------------------------------
async def _cb_keyword(update, context, action, args):
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    chat = update.effective_chat
    if not db_uid or not chat:
        return

    if action == "add":
        context.user_data["awaiting"] = "keyword"
        await chat.send_message(t("keyword_add_prompt", lang), parse_mode=ParseMode.MARKDOWN)
        return
    if action == "del" and args:
        kw = args[0]
        us.remove_keyword(db_uid, kw)
        await chat.send_message(t("keyword_removed", lang, kw=kw), parse_mode=ParseMode.MARKDOWN)


# ---------------------------------------------------------------------
# set (settings toggles)
# ---------------------------------------------------------------------
async def _cb_settings(update, context, action, args):
    lang = context.user_data.get("lang", "en")
    if action == "toggle" and args:
        attr = args[0]
        user = update.effective_user
        if not user:
            return
        settings_h.toggle_attr(user.id, attr)
        await settings_h.rerender_settings(update, context)
    elif action == "lang":
        from src.utils.keyboards import language_kb
        if update.callback_query and update.callback_query.message:
            try:
                await update.callback_query.edit_message_text(
                    t("choose_language", lang),
                    parse_mode=ParseMode.MARKDOWN,
                    reply_markup=language_kb(),
                )
            except Exception:
                pass


# ---------------------------------------------------------------------
# quiz (answer)
# ---------------------------------------------------------------------
async def _cb_quiz(update, context, action, args):
    if action != "ans" or len(args) < 2:
        return
    try:
        idx = int(args[0]); picked = int(args[1])
    except ValueError:
        return
    await quiz_h.handle_quiz_answer(update, context, idx, picked)


# ---------------------------------------------------------------------
# Free-text router (catches messages outside commands)
# ---------------------------------------------------------------------
_PIN_RE = re.compile(r"^\d{6}$")


async def text_router(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message or not update.message.text:
        return

    text = update.message.text.strip()
    lang = context.user_data.get("lang", "en")
    awaiting = context.user_data.get("awaiting")
    db_uid = context.user_data.get("db_user_id")

    # In-flight: PIN code
    if awaiting == "pincode" or (context.user_data.get("onboarding_step") == "pincode" and _PIN_RE.match(text)):
        await _handle_pincode_input(update, context, text)
        # If onboarding, transition to done
        if context.user_data.get("onboarding_step") == "pincode":
            await start_h.finish_onboarding(update, context)
        return

    # In-flight: keyword add
    if awaiting == "keyword" and db_uid:
        if us.add_keyword(db_uid, text):
            await update.message.reply_text(
                t("keyword_added", lang, kw=text), parse_mode=ParseMode.MARKDOWN
            )
        else:
            await update.message.reply_text("⚠️ Keyword already exists or invalid.")
        context.user_data["awaiting"] = None
        return

    # In-flight: chat
    if awaiting == "chat":
        await handle_chat_text(update, context)
        return

    # Fall-through
    await update.message.reply_text(t("unknown_command", lang))
