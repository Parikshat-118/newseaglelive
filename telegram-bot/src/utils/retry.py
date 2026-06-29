"""Retry / backoff helpers, thin wrapper over tenacity."""
from __future__ import annotations

from tenacity import (
    retry, retry_if_exception_type, stop_after_attempt, wait_exponential,
    before_sleep_log,
)
import logging

import httpx

_log = logging.getLogger(__name__)


# Use on outbound HTTP calls (OpenRouter, NewsAPI, GNews, RSS fetch).
http_retry = retry(
    reraise=True,
    stop=stop_after_attempt(3),
    wait=wait_exponential(multiplier=0.5, min=0.5, max=8),
    retry=retry_if_exception_type(
        (httpx.TimeoutException, httpx.TransportError, httpx.HTTPStatusError)
    ),
    before_sleep=before_sleep_log(_log, logging.WARNING),
)
