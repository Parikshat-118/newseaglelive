"""
Reusable AI prompt templates.

Conventions:
- Each template is a callable returning a list of {role, content} dicts ready
  for an OpenRouter chat-completions request.
- All prompts are concise, instruct the model NOT to add disclaimers, and ask
  for plain text (Markdown-light) suitable for Telegram MarkdownV2 conversion.
"""
from __future__ import annotations

from typing import List, Dict


def _system(lang: str) -> str:
    if lang == "hi":
        return (
            "आप News Eagle Live के AI सहायक हैं। "
            "संक्षिप्त, तथ्यात्मक, और तटस्थ उत्तर दें। "
            "जब अनिश्चित हों तो स्पष्ट रूप से कहें। डिस्क्लेमर न जोड़ें। "
            "उत्तर 1500 अक्षरों के भीतर रखें।"
        )
    return (
        "You are the AI assistant for News Eagle Live. "
        "Be concise, factual, and neutral. "
        "If unsure, say so plainly. No disclaimers. "
        "Keep responses under 1500 characters."
    )


def summarize_article(title: str, content: str, lang: str = "en") -> List[Dict[str, str]]:
    instr = (
        "Summarize the news article in 4–6 bullet points. "
        "Lead with the most important fact. End with one line on 'Why it matters'."
        if lang == "en" else
        "इस समाचार लेख का 4–6 बुलेट पॉइंट्स में सारांश दें। "
        "सबसे महत्वपूर्ण तथ्य से शुरू करें। अंत में एक पंक्ति 'क्यों मायने रखता है' लिखें।"
    )
    return [
        {"role": "system", "content": _system(lang)},
        {"role": "user",   "content": f"{instr}\n\nTitle: {title}\n\nArticle:\n{content[:6000]}"},
    ]


def explain_news(title: str, content: str, lang: str = "en") -> List[Dict[str, str]]:
    instr = (
        "Explain this news to a non-expert in 5–7 short paragraphs. "
        "Cover: what happened, the key players, the background, and the likely impact."
        if lang == "en" else
        "इस समाचार को गैर-विशेषज्ञ के लिए 5–7 छोटे अनुच्छेदों में समझाएँ: "
        "क्या हुआ, मुख्य पात्र, पृष्ठभूमि, और संभावित प्रभाव।"
    )
    return [
        {"role": "system", "content": _system(lang)},
        {"role": "user",   "content": f"{instr}\n\nTitle: {title}\n\nArticle:\n{content[:6000]}"},
    ]


def why_it_matters(title: str, content: str, lang: str = "en") -> List[Dict[str, str]]:
    instr = (
        "In exactly 3 short bullet points, explain why this news matters and to whom."
        if lang == "en" else
        "बिल्कुल 3 छोटे बुलेट पॉइंट्स में बताएँ कि यह समाचार क्यों मायने रखता है और किसके लिए।"
    )
    return [
        {"role": "system", "content": _system(lang)},
        {"role": "user",   "content": f"{instr}\n\nTitle: {title}\n\n{content[:5000]}"},
    ]


def key_takeaways(title: str, content: str, lang: str = "en") -> List[Dict[str, str]]:
    instr = (
        "List 5 key takeaways as bullet points. Be specific and factual."
        if lang == "en" else
        "5 मुख्य बिंदु बुलेट के रूप में दें। तथ्यात्मक और विशिष्ट रहें।"
    )
    return [
        {"role": "system", "content": _system(lang)},
        {"role": "user",   "content": f"{instr}\n\nTitle: {title}\n\n{content[:5000]}"},
    ]


def chat_about_news(history: List[Dict[str, str]], user_msg: str, lang: str = "en") -> List[Dict[str, str]]:
    msgs: List[Dict[str, str]] = [{"role": "system", "content": _system(lang)}]
    msgs.extend(history[-10:])  # rolling window
    msgs.append({"role": "user", "content": user_msg})
    return msgs


def translate_to_hindi(text: str) -> List[Dict[str, str]]:
    return [
        {"role": "system", "content": (
            "You are a precise English→Hindi translator. "
            "Translate the user's text into natural, news-register Hindi. "
            "Preserve proper nouns and numbers. Output Hindi only — no commentary."
        )},
        {"role": "user", "content": text[:6000]},
    ]


def generate_quiz(topic: str, n: int = 5, lang: str = "en") -> List[Dict[str, str]]:
    instr_en = (
        f"Generate {n} multiple-choice current-affairs questions on '{topic}'. "
        "Return STRICT JSON only, no prose, no markdown. Schema:\n"
        '{"questions":[{"q":"...","options":["A","B","C","D"],"correct_index":0,"explanation":"..."}]}'
    )
    instr_hi = (
        f"विषय '{topic}' पर {n} बहुविकल्पीय करेंट अफेयर्स प्रश्न बनाएँ। "
        "केवल STRICT JSON लौटाएँ, कोई गद्य या markdown नहीं। स्कीमा:\n"
        '{"questions":[{"q":"...","options":["A","B","C","D"],"correct_index":0,"explanation":"..."}]}'
    )
    return [
        {"role": "system", "content": "You output only valid JSON when asked."},
        {"role": "user",   "content": instr_hi if lang == "hi" else instr_en},
    ]


def daily_current_affairs(snippets: List[str], lang: str = "en") -> List[Dict[str, str]]:
    joined = "\n\n---\n\n".join(s[:1500] for s in snippets[:15])
    instr = (
        "Compose a tight, neutral 'Daily Current Affairs' brief from these snippets. "
        "Group by theme (India, World, Economy, Tech, Sports). 8–12 bullets total."
        if lang == "en" else
        "इन समाचार-अंशों से एक संक्षिप्त, तटस्थ 'दैनिक करेंट अफेयर्स' संक्षेप बनाएँ। "
        "विषयवार समूहित करें (भारत, विश्व, अर्थव्यवस्था, तकनीक, खेल)। कुल 8–12 बुलेट।"
    )
    return [
        {"role": "system", "content": _system(lang)},
        {"role": "user",   "content": f"{instr}\n\n{joined}"},
    ]
