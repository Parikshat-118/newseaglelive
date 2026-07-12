"""
ai_provider.py — Modular AI Provider Abstraction for News Eagle Live.

Supports multiple providers via a common BaseAIProvider interface.
New providers (Gemini, OpenAI, Claude) can be added by implementing the interface.

Configuration (via .env):
    AI_PROVIDER=groq              # groq | openrouter | gemini | openai
    AI_MODEL=llama3-70b-8192      # any model supported by the chosen provider
    GROQ_API_KEY=gsk_...
    OPENROUTER_API_KEY=sk-or-...
    AI_REQUEST_TIMEOUT=60
"""
from __future__ import annotations

import json
import os
import time
from abc import ABC, abstractmethod
from typing import Any

import httpx

from src.utils.logger import log


# ──────────────────────────────────────────────────────────────────────────────
# Base interface
# ──────────────────────────────────────────────────────────────────────────────

class BaseAIProvider(ABC):
    """All providers must implement generate_json()."""

    @abstractmethod
    async def generate_json(
        self,
        system: str,
        user: str,
        temperature: float = 0.4,
    ) -> dict[str, Any]:
        """
        Call the AI provider and return the parsed JSON response.
        Raises AIProviderError on failure.
        """

    @property
    @abstractmethod
    def provider_name(self) -> str: ...

    @property
    @abstractmethod
    def model_name(self) -> str: ...


class AIProviderError(Exception):
    """Raised when a provider call fails after all internal retries."""


# ──────────────────────────────────────────────────────────────────────────────
# Groq Provider
# ──────────────────────────────────────────────────────────────────────────────

class GroqProvider(BaseAIProvider):
    """
    Uses the Groq Chat Completions API.
    Free tier supports llama3-70b-8192 with generous rate limits.
    """
    BASE_URL = "https://api.groq.com/openai/v1/chat/completions"
    _DEFAULT_MODEL = "llama-3.3-70b-versatile"

    def __init__(self, api_key: str, model: str = "") -> None:
        self._api_keys = [k.strip() for k in api_key.split(",") if k.strip()]
        self._key_index = 0
        self._model   = model or self._DEFAULT_MODEL
        self._timeout = float(os.environ.get("AI_REQUEST_TIMEOUT", "60"))

    @property
    def provider_name(self) -> str:
        return "groq"

    @property
    def model_name(self) -> str:
        return self._model

    async def generate_json(
        self,
        system: str,
        user: str,
        temperature: float = 0.4,
    ) -> dict[str, Any]:
        
        # Round-robin selection ensures retries always use a different key
        selected_key = self._api_keys[self._key_index]
        self._key_index = (self._key_index + 1) % len(self._api_keys)
        
        headers = {
            "Authorization": f"Bearer {selected_key}",
            "Content-Type":  "application/json",
        }
        payload = {
            "model": self._model,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user",   "content": user},
            ],
            "temperature":     temperature,
            "response_format": {"type": "json_object"},
            "max_tokens":      4096,
        }
        try:
            async with httpx.AsyncClient(timeout=self._timeout) as client:
                t0 = time.monotonic()
                r  = await client.post(self.BASE_URL, headers=headers, json=payload)
                elapsed = round(time.monotonic() - t0, 3)

            if r.status_code != 200:
                raise AIProviderError(
                    f"Groq HTTP {r.status_code}: {r.text[:300]}"
                )

            body   = r.json()
            raw    = body["choices"][0]["message"]["content"]
            result = json.loads(raw)

            # Attach token usage so the caller can persist it
            usage = body.get("usage", {})
            result["__meta__"] = {
                "provider":         self.provider_name,
                "model":            self._model,
                "prompt_tokens":    usage.get("prompt_tokens"),
                "completion_tokens":usage.get("completion_tokens"),
                "total_tokens":     usage.get("total_tokens"),
                "generation_time":  elapsed,
            }
            return result

        except (httpx.TimeoutException, httpx.ConnectError) as exc:
            raise AIProviderError(f"Groq network error: {exc}") from exc
        except json.JSONDecodeError as exc:
            raise AIProviderError(f"Groq returned non-JSON: {exc}") from exc


# ──────────────────────────────────────────────────────────────────────────────
# OpenRouter Provider
# ──────────────────────────────────────────────────────────────────────────────

class OpenRouterProvider(BaseAIProvider):
    BASE_URL      = "https://openrouter.ai/api/v1/chat/completions"
    _DEFAULT_MODEL = "meta-llama/llama-3.1-70b-instruct"

    def __init__(self, api_key: str, model: str = "") -> None:
        self._api_key = api_key
        self._model   = model or self._DEFAULT_MODEL
        self._timeout = float(os.environ.get("AI_REQUEST_TIMEOUT", "60"))

    @property
    def provider_name(self) -> str:
        return "openrouter"

    @property
    def model_name(self) -> str:
        return self._model

    async def generate_json(
        self,
        system: str,
        user: str,
        temperature: float = 0.4,
    ) -> dict[str, Any]:
        headers = {
            "Authorization":  f"Bearer {self._api_key}",
            "Content-Type":   "application/json",
            "HTTP-Referer":   "https://newseagle.live",
            "X-Title":        "News Eagle Live",
        }
        payload = {
            "model": self._model,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user",   "content": user},
            ],
            "temperature":     temperature,
            "response_format": {"type": "json_object"},
        }
        try:
            async with httpx.AsyncClient(timeout=self._timeout) as client:
                t0 = time.monotonic()
                r  = await client.post(self.BASE_URL, headers=headers, json=payload)
                elapsed = round(time.monotonic() - t0, 3)

            if r.status_code != 200:
                raise AIProviderError(
                    f"OpenRouter HTTP {r.status_code}: {r.text[:300]}"
                )

            body   = r.json()
            raw    = body["choices"][0]["message"]["content"]
            result = json.loads(raw)

            usage = body.get("usage", {})
            result["__meta__"] = {
                "provider":         self.provider_name,
                "model":            self._model,
                "prompt_tokens":    usage.get("prompt_tokens"),
                "completion_tokens":usage.get("completion_tokens"),
                "total_tokens":     usage.get("total_tokens"),
                "generation_time":  elapsed,
            }
            return result

        except (httpx.TimeoutException, httpx.ConnectError) as exc:
            raise AIProviderError(f"OpenRouter network error: {exc}") from exc
        except json.JSONDecodeError as exc:
            raise AIProviderError(f"OpenRouter returned non-JSON: {exc}") from exc


# ──────────────────────────────────────────────────────────────────────────────
# Factory — reads AI_PROVIDER and AI_MODEL from environment
# ──────────────────────────────────────────────────────────────────────────────

def get_ai_provider() -> BaseAIProvider:
    """
    Instantiate and return the configured AI provider.

    .env keys:
        AI_PROVIDER  — groq (default) | openrouter
        AI_MODEL     — provider-specific model string
    """
    provider_name = os.environ.get("AI_PROVIDER", "groq").lower()
    model         = os.environ.get("AI_MODEL", "")

    if provider_name == "groq":
        api_key = os.environ.get("GROQ_API_KEY", "")
        if not api_key:
            raise RuntimeError(
                "GROQ_API_KEY is not set in .env. "
                "Get a free key at https://console.groq.com/"
            )
        return GroqProvider(api_key=api_key, model=model or GroqProvider._DEFAULT_MODEL)

    if provider_name == "openrouter":
        api_key = os.environ.get("OPENROUTER_API_KEY", "")
        if not api_key:
            raise RuntimeError("OPENROUTER_API_KEY is not set in .env.")
        return OpenRouterProvider(api_key=api_key, model=model)

    raise ValueError(
        f"Unknown AI_PROVIDER='{provider_name}'. "
        "Supported: groq, openrouter"
    )
