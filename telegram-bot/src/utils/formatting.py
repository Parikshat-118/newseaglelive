"""Telegram MarkdownV2 safe-formatting helpers."""
from __future__ import annotations

_MD2_SPECIAL = r"_*[]()~`>#+-=|{}.!\\"


def escape_md(text: str) -> str:
    """Escape user/source text for MarkdownV2 sending."""
    if text is None:
        return ""
    return "".join("\\" + c if c in _MD2_SPECIAL else c for c in str(text))


def truncate(text: str, n: int = 600, suffix: str = "…") -> str:
    text = text or ""
    if len(text) <= n:
        return text
    return text[: n - len(suffix)].rsplit(" ", 1)[0] + suffix
