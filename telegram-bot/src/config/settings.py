"""
Settings — centralized, validated, env-driven configuration.

Use `get_settings()` everywhere. The instance is cached so .env is parsed once.
"""
from __future__ import annotations

from functools import lru_cache
from typing import List, Optional

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """All runtime config. Names match the .env keys (case-insensitive)."""

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )

    # --- Telegram ---
    bot_token: str
    bot_username: str = "NewsEagleLiveBot"
    webhook_url: Optional[str] = None
    webhook_secret: Optional[str] = None
    webhook_port: int = 8443

    # --- MySQL ---
    database_url: str
    db_pool_size: int = 20
    db_max_overflow: int = 10
    db_pool_recycle: int = 3600

    # --- Redis ---
    redis_url: str = "redis://127.0.0.1:6379/0"
    redis_prefix: str = "eagle:"

    # --- AI / OpenRouter ---
    # --- AI / Groq ---
    groq_api_key: str
    groq_base_url: str = "https://api.groq.com/openai/v1"

    ai_primary_model: str = "llama-3.3-70b-versatile"
    ai_fallback_model: str = "llama-3.1-8b-instant"

    ai_request_timeout: int = 45
    ai_max_tokens: int = 1024

    # --- News sources ---
    newsapi_key: Optional[str] = None
    gnews_api_key: Optional[str] = None
    rss_extra_feeds: str = ""

    # --- Weather ---
    weather_api_key: Optional[str] = None

    # --- Admin ---
    admin_ids: str = ""

    # --- Scheduler ---
    scheduler_timezone: str = "Asia/Kolkata"
    news_fetch_interval_min: int = 5
    summary_interval_min: int = 10
    alert_interval_min: int = 15
    morning_digest_hour: int = 7
    evening_digest_hour: int = 19
    scheduler_standalone: bool = False

    # --- Rate limiting ---
    rate_limit_per_minute: int = 30
    rate_limit_ai_per_minute: int = 10

    # --- Logging ---
    log_level: str = "INFO"
    log_file: str = "logs/bot.log"

    # --- Misc ---
    default_language: str = "en"
    app_env: str = "production"

    # -------- Derived helpers --------

    @property
    def admin_id_set(self) -> set[int]:
        if not self.admin_ids:
            return set()
        return {int(x.strip()) for x in self.admin_ids.split(",") if x.strip().isdigit()}

    @property
    def extra_rss_list(self) -> List[str]:
        if not self.rss_extra_feeds:
            return []
        return [u.strip() for u in self.rss_extra_feeds.split(",") if u.strip()]

    @field_validator("default_language")
    @classmethod
    def _valid_lang(cls, v: str) -> str:
        v = v.lower()
        if v not in {"en", "hi"}:
            raise ValueError("default_language must be 'en' or 'hi'")
        return v


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    """Cached settings accessor. First call reads .env, subsequent calls reuse it."""
    return Settings()  # type: ignore[call-arg]
