"""
Groq AI client (OpenAI-compatible API).

Public API remains unchanged:
    await ai_complete(...)
    await ai_json(...)
"""

from __future__ import annotations

import json
from typing import Dict, List, Optional

from openai import AsyncOpenAI
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type

from src.config.settings import get_settings
from src.utils.logger import log


_key_index = 0

def _get_client() -> AsyncOpenAI:
    global _key_index

    settings = get_settings()
    
    # Safely handle comma-separated multi-key architecture and strip any accidental quotes
    raw_keys = settings.groq_api_key
    key_list = [k.strip().strip("'\"") for k in raw_keys.split(",") if k.strip().strip("'\"")]
    
    if not key_list:
        raise ValueError("No valid GROQ_API_KEY found in settings!")

    # Round-Robin selection
    selected_key = key_list[_key_index]
    _key_index = (_key_index + 1) % len(key_list)

    return AsyncOpenAI(
        api_key=selected_key,
        base_url=settings.groq_base_url,
    )


async def close_ai_client() -> None:
    pass


@retry(
    reraise=True,
    stop=stop_after_attempt(3),
    wait=wait_exponential(multiplier=0.5, min=0.5, max=6),
    retry=retry_if_exception_type(Exception),
)
async def _chat(payload: dict):
    # Instantiate a fresh client (with a new round-robin key) on every attempt
    client = _get_client()

    return await client.chat.completions.create(**payload)


async def ai_complete(
    messages: List[Dict[str, str]],
    *,
    model: Optional[str] = None,
    max_tokens: Optional[int] = None,
    temperature: float = 0.4,
    json_mode: bool = False,
) -> str:

    settings = get_settings()

    primary = model or settings.ai_primary_model

    payload = {
        "model": primary,
        "messages": messages,
        "temperature": temperature,
        "max_tokens": max_tokens or settings.ai_max_tokens,
    }

    if json_mode:
        payload["response_format"] = {"type": "json_object"}

    try:
        response = await _chat(payload)

        return response.choices[0].message.content.strip()

    except Exception as e:

        log.warning("Primary model failed ({}): {}", primary, e)

        if primary == settings.ai_fallback_model:
            raise

        payload["model"] = settings.ai_fallback_model

        log.info(
            "Retrying with fallback model: {}",
            settings.ai_fallback_model,
        )

        response = await _chat(payload)

        return response.choices[0].message.content.strip()


async def ai_json(messages: List[Dict[str, str]], **kw) -> dict | list:

    text = await ai_complete(
        messages,
        json_mode=True,
        **kw,
    )

    cleaned = text.strip()

    if cleaned.startswith("```"):
        cleaned = cleaned.strip("`")

        if cleaned.lower().startswith("json"):
            cleaned = cleaned[4:].strip()

    return json.loads(cleaned)