"""
/quiz — interactive current-affairs quiz.

State is kept in `context.user_data['quiz']`:
    { quiz_id, idx, score, answers: {} }
"""
from __future__ import annotations

from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.constants import ParseMode
from telegram.ext import ContextTypes

from src.services import quiz_service as qs
from src.utils.i18n import t
from src.middlewares.rate_limit import rate_limited
from src.utils.logger import log


def _question_kb(idx: int, options: list[str]) -> InlineKeyboardMarkup:
    rows = []
    for i, opt in enumerate(options[:4]):
        # Truncate to keep callback_data short and keep text readable
        label = (opt[:48] + "…") if len(opt) > 48 else opt
        rows.append([InlineKeyboardButton(f"{chr(65 + i)}. {label}", callback_data=f"quiz:ans:{idx}:{i}")])
    return InlineKeyboardMarkup(rows)


async def _send_question(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    state = context.user_data.get("quiz")
    if not state or not update.effective_chat:
        return
    quiz_id = state["quiz_id"]
    idx = state["idx"]
    questions = state["questions"]
    if idx >= len(questions):
        await _finish(update, context)
        return
    q = questions[idx]
    qtext = q.get("q", "")
    opts = q.get("options", [])
    await update.effective_chat.send_message(
        f"*Q{idx + 1}.* {qtext}",
        parse_mode=ParseMode.MARKDOWN,
        reply_markup=_question_kb(idx, opts),
    )


async def _finish(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    state = context.user_data.get("quiz")
    if not state:
        return
    lang = context.user_data.get("lang", "en")
    db_uid = context.user_data.get("db_user_id")
    score, total = state["score"], len(state["questions"])
    try:
        if db_uid:
            qs.record_result(db_uid, state["quiz_id"], score, total, state["answers"])
    except Exception as e:
        log.warning("Quiz record failed: {}", e)
    context.user_data["quiz"] = None
    if update.effective_chat:
        await update.effective_chat.send_message(
            t("quiz_done", lang, score=score, total=total),
            parse_mode=ParseMode.MARKDOWN,
        )


@rate_limited(limit=10, window=60, bucket="quiz")
async def cmd_quiz(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not update.message:
        return
    lang = context.user_data.get("lang", "en")
    quiz = qs.latest_quiz(language=lang)
    if not quiz:
        # Try the other language as a fallback (admin may have generated one)
        other = "hi" if lang == "en" else "en"
        quiz = qs.latest_quiz(language=other)
    if not quiz or not quiz.questions:
        await update.message.reply_text(
            "🧠 No quiz available yet — try again later." if lang == "en"
            else "🧠 अभी कोई क्विज़ नहीं — बाद में प्रयास करें।"
        )
        return

    context.user_data["quiz"] = {
        "quiz_id": quiz.id,
        "idx": 0,
        "score": 0,
        "answers": {},
        "questions": quiz.questions,
    }
    await update.message.reply_text(
        t("quiz_intro", lang, n=len(quiz.questions)),
        parse_mode=ParseMode.MARKDOWN,
    )
    await _send_question(update, context)


async def handle_quiz_answer(update: Update, context: ContextTypes.DEFAULT_TYPE,
                             idx: int, picked: int) -> None:
    state = context.user_data.get("quiz")
    if not state:
        return
    questions = state["questions"]
    if idx != state["idx"] or idx >= len(questions):
        return
    q = questions[idx]
    correct = int(q.get("correct_index", -1))
    state["answers"][str(idx)] = picked
    if picked == correct:
        state["score"] += 1
        feedback = "✅ Correct!"
    else:
        feedback = f"❌ Correct: *{chr(65 + correct)}*"
        explanation = q.get("explanation")
        if explanation:
            feedback += f"\n_{explanation}_"
    state["idx"] += 1
    if update.effective_chat:
        await update.effective_chat.send_message(feedback, parse_mode=ParseMode.MARKDOWN)
    if state["idx"] >= len(questions):
        await _finish(update, context)
    else:
        await _send_question(update, context)
