"""
/chat — converse with the AI assistant.

Flow:
    /chat                 → enter chat mode (next free-text msg is forwarded to AI)
    /chat <message>       → one-shot ask
    /chat stop / /endchat → exit chat mode

Rolling 10-message context is stored in `context.user_data['chat_history']`.
"""
from __future__ import annotations

from telegram import Update
from telegram.constants import ChatAction, ParseMode
from telegram.ext import ContextTypes

from src.services.ai_service import ai_complete
from src.prompts.templates import chat_about_news
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.utils.logger import log


@rate_limited(limit=10, window=60, bucket="chat_ai")
async def cmd_chat(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    args = context.args or []

    if args and args[0].lower() in {"stop", "end", "quit", "exit"}:
        context.user_data["awaiting"] = None
        context.user_data["chat_history"] = []
        await update.message.reply_text("👋 Chat ended." if lang == "en" else "👋 चैट समाप्त।")
        return

    if args:
        await _answer(update, context, " ".join(args))
        return

    context.user_data["awaiting"] = "chat"
    await update.message.reply_text(
        "💬 *Chat mode on.* Send any message. Type `/chat stop` to exit."
        if lang == "en" else
        "💬 *चैट मोड चालू।* कोई संदेश भेजें। बाहर निकलने के लिए `/chat stop` लिखें।",
        parse_mode=ParseMode.MARKDOWN,
    )


async def _answer(update: Update, context: ContextTypes.DEFAULT_TYPE, user_msg: str) -> None:
    if not update.effective_chat:
        return
    lang = context.user_data.get("lang", "en")
    history: list[dict] = context.user_data.get("chat_history", [])

    await update.effective_chat.send_chat_action(ChatAction.TYPING)
    try:
        reply = await ai_complete(chat_about_news(history, user_msg, lang=lang), temperature=0.5)
    except Exception as e:
        log.warning("AI chat failed: {}", e)
        await update.effective_chat.send_message(t("ai_unavailable", lang))
        return

    # Trim rolling window to 10 turns
    history.append({"role": "user", "content": user_msg})
    history.append({"role": "assistant", "content": reply})
    context.user_data["chat_history"] = history[-20:]

    # Send reply — fall back to plain text if formatting fails
    try:
        await update.effective_chat.send_message(reply, parse_mode=ParseMode.MARKDOWN)
    except Exception:
        await update.effective_chat.send_message(reply)


# Used by callbacks.text_router when awaiting == "chat"
async def handle_chat_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message or not update.message.text:
        return
    await _answer(update, context, update.message.text)
