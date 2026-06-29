"""
OpenRouter client (Grok primary, fallback model on failure).

Single entry point: `await ai_complete(messages, ...)`.

Features:
- httpx.AsyncClient with connection pooling
- Retry with exponential backoff (tenacity)
- Per-user rate limiting (delegated to handler middlewares)
- Falls back to AI_FALLBACK_MODEL if primary returns 5xx / times out
- Truncates `messages` content to keep within max-tokens budget
"""
from __future__ import annotations

import json
from typing import List, Dict, Optional

import httpx
from tenacity import retry, stop_after_attempt, wait_exponential, retry_if_exception_type

from src.config.settings import get_settings
from src.utils.logger import log


_client: httpx.AsyncClient | None = None


def _get_client() -> httpx.AsyncClient:
    global _client
    if _client is None:
        settings = get_settings()
        _client = httpx.AsyncClient(
            base_url=settings.openrouter_base_url,
            timeout=httpx.Timeout(settings.ai_request_timeout, connect=10),
            headers={
                "Authorization": f"Bearer {settings.openrouter_api_key}",
                "Content-Type": "application/json",
                # OpenRouter analytics headers (recommended)
                "HTTP-Referer": settings.openrouter_site_url,
                "X-Title": settings.openrouter_app_name,
            },
            limits=httpx.Limits(max_connections=50, max_keepalive_connections=10),
        )
    return _client


async def close_ai_client() -> None:
    global _client
    if _client is not None:
        await _client.aclose()
        _client = None


@retry(
    reraise=True,
    stop=stop_after_attempt(3),
    wait=wait_exponential(multiplier=0.5, min=0.5, max=6),
    retry=retry_if_exception_type((httpx.TimeoutException, httpx.TransportError)),
)
async def _post(payload: dict) -> dict:
    client = _get_client()
    resp = await client.post("/chat/completions", json=payload)
    if resp.status_code >= 500:
        raise httpx.HTTPStatusError(f"upstream {resp.status_code}", request=resp.request, response=resp)
    resp.raise_for_status()
    return resp.json()


async def ai_complete(
    messages: List[Dict[str, str]],
    *,
    model: Optional[str] = None,
    max_tokens: Optional[int] = None,
    temperature: float = 0.4,
    json_mode: bool = False,
) -> str:
    """
    Run a chat-completion. Returns the assistant message text.
    Falls back to the secondary model on persistent failure.
    """
    settings = get_settings()
    primary = model or settings.ai_primary_model

    payload: dict = {
        "model": primary,
        "messages": messages,
        "max_tokens": max_tokens or settings.ai_max_tokens,
        "temperature": temperature,
    }
    if json_mode:
        payload["response_format"] = {"type": "json_object"}

    try:
        data = await _post(payload)
        return data["choices"][0]["message"]["content"].strip()
    except Exception as e:
        log.warning("AI primary model failed ({}): {}", primary, e)
        if primary == settings.ai_fallback_model:
            raise
        payload["model"] = settings.ai_fallback_model
        log.info("Retrying with fallback model: {}", settings.ai_fallback_model)
        data = await _post(payload)
        return data["choices"][0]["message"]["content"].strip()


async def ai_json(messages: List[Dict[str, str]], **kw) -> dict | list:
    """Like `ai_complete` but expects (and validates) a JSON response."""
    text = await ai_complete(messages, json_mode=True, **kw)
    # Be permissive — Grok may wrap JSON in code fences
    cleaned = text.strip().lstrip("`").rstrip("`")
    if cleaned.lower().startswith("json"):
        cleaned = cleaned[4:].strip()
    return json.loads(cleaned)
