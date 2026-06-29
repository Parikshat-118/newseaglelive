"""
Async Redis wrapper.

Used for:
- Article deduplication (SET with TTL keyed by url_hash)
- Rate limiting (INCR + EXPIRE per user/feature/minute)
- Session storage (chat state, onboarding step)
- Hot caches (latest articles per category)

The Redis client is created once at startup and reused.
"""
from __future__ import annotations

import json
from typing import Any, Optional

import redis.asyncio as aioredis

from src.config.settings import get_settings
from src.utils.logger import log

_client: aioredis.Redis | None = None


async def init_redis() -> None:
    global _client
    if _client is not None:
        return
    settings = get_settings()
    _client = aioredis.from_url(
        settings.redis_url,
        encoding="utf-8",
        decode_responses=True,
        socket_keepalive=True,
        health_check_interval=30,
    )
    try:
        pong = await _client.ping()
        log.info("Redis connected ({}): PING -> {}", settings.redis_url, pong)
    except Exception as e:
        log.error("Redis connection failed: {}", e)
        raise


async def close_redis() -> None:
    global _client
    if _client is not None:
        await _client.close()
        _client = None
        log.info("Redis closed")


def _r() -> aioredis.Redis:
    if _client is None:
        raise RuntimeError("Redis not initialized — call init_redis() first")
    return _client


def _key(suffix: str) -> str:
    return f"{get_settings().redis_prefix}{suffix}"


# -------- generic helpers --------
async def cache_set(suffix: str, value: Any, ttl: int = 300) -> None:
    payload = value if isinstance(value, str) else json.dumps(value, ensure_ascii=False)
    await _r().setex(_key(suffix), ttl, payload)


async def cache_get(suffix: str, *, json_decode: bool = False) -> Optional[Any]:
    raw = await _r().get(_key(suffix))
    if raw is None:
        return None
    return json.loads(raw) if json_decode else raw


async def cache_del(suffix: str) -> None:
    await _r().delete(_key(suffix))


# -------- dedupe --------
async def seen_article(url_hash: str, ttl: int = 7 * 24 * 3600) -> bool:
    """
    Atomic "set if not exists". Returns True if we've seen this article before.
    """
    key = _key(f"dedupe:{url_hash}")
    # NX = only set if missing; returns True on first set, None thereafter
    ok = await _r().set(key, "1", ex=ttl, nx=True)
    return not ok


# -------- rate limiting --------
async def rate_limit(bucket: str, limit: int, window_seconds: int = 60) -> tuple[bool, int]:
    """
    Fixed-window rate limiter. Returns (allowed, current_count).
    """
    key = _key(f"rl:{bucket}")
    pipe = _r().pipeline()
    pipe.incr(key, 1)
    pipe.expire(key, window_seconds)
    count, _ = await pipe.execute()
    return count <= limit, count
