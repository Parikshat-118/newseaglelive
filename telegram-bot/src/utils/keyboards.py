"""
Inline keyboard factories. All callback_data follows the convention:

    <namespace>:<action>[:<arg>...]

e.g. cat:toggle:ai, art:bm:42, lang:set:hi

Keep callback_data <= 64 bytes (Telegram limit).
"""
from __future__ import annotations

from typing import Iterable

from telegram import InlineKeyboardButton, InlineKeyboardMarkup

from src.config.categories import CATEGORIES, label
from src.utils.i18n import t, supported_languages


def language_kb() -> InlineKeyboardMarkup:
    rows = [[InlineKeyboardButton(name, callback_data=f"lang:set:{code}")]
            for code, name in supported_languages()]
    return InlineKeyboardMarkup(rows)


def categories_kb(selected: Iterable[str], lang: str = "en", *, with_done: bool = True) -> InlineKeyboardMarkup:
    """Two-column toggle grid of all categories."""
    sel = set(selected)
    rows: list[list[InlineKeyboardButton]] = []
    items = list(CATEGORIES.values())
    for i in range(0, len(items), 2):
        row = []
        for c in items[i:i + 2]:
            mark = "✅ " if c.slug in sel else ""
            row.append(InlineKeyboardButton(
                f"{mark}{label(c.slug, lang)}",
                callback_data=f"cat:toggle:{c.slug}",
            ))
        rows.append(row)
    if with_done:
        rows.append([InlineKeyboardButton(t("btn_done", lang), callback_data="cat:done")])
    return InlineKeyboardMarkup(rows)


def article_kb(article_id: int, *, bookmarked: bool, url: str, lang: str = "en") -> InlineKeyboardMarkup:
    bk = t("btn_unbookmark", lang) if bookmarked else t("btn_bookmark", lang)
    bk_action = "unbm" if bookmarked else "bm"
    return InlineKeyboardMarkup([
        [
            InlineKeyboardButton(t("btn_summarize", lang), callback_data=f"art:sum:{article_id}"),
            InlineKeyboardButton(t("btn_explain", lang),   callback_data=f"art:exp:{article_id}"),
        ],
        [
            InlineKeyboardButton(bk, callback_data=f"art:{bk_action}:{article_id}"),
            InlineKeyboardButton(t("btn_translate_hi", lang),
                                 callback_data=f"art:tr:{article_id}"),
        ],
        [InlineKeyboardButton(t("btn_open", lang), url=url)],
    ])


def pincode_skip_kb(lang: str = "en") -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([
        [InlineKeyboardButton(t("btn_skip", lang), callback_data="pin:skip")]
    ])


def yes_no_kb(yes_data: str, no_data: str, lang: str = "en") -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup([[
        InlineKeyboardButton(t("btn_yes", lang), callback_data=yes_data),
        InlineKeyboardButton(t("btn_no", lang),  callback_data=no_data),
    ]])
