"""
Lightweight i18n. Strings live in src/locales/en.json and hi.json.

Use `t(key, lang, **fmt)` everywhere user-facing.
Falls back to English if a key is missing in Hindi.
"""
from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path
from typing import Any

_LOCALE_DIR = Path(__file__).resolve().parent.parent / "locales"


@lru_cache(maxsize=4)
def _load(lang: str) -> dict[str, str]:
    path = _LOCALE_DIR / f"{lang}.json"
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def t(key: str, lang: str = "en", **fmt: Any) -> str:
    """Translate `key` to `lang`. Apply str.format(**fmt) if kwargs given."""
    lang = (lang or "en").lower()
    if lang not in ("en", "hi"):
        lang = "en"
    s = _load(lang).get(key) or _load("en").get(key) or key
    if fmt:
        try:
            s = s.format(**fmt)
        except Exception:
            pass
    return s


def supported_languages() -> list[tuple[str, str]]:
    return [("en", "🇬🇧 English"), ("hi", "🇮🇳 हिन्दी")]
