"""
Centralised logger using loguru. Imported as `from src.utils.logger import log`.
"""
from __future__ import annotations

import sys
from pathlib import Path

from loguru import logger as log

_configured = False


def setup_logging() -> None:
    """Configure sinks. Safe to call multiple times."""
    global _configured
    if _configured:
        return

    from src.config.settings import get_settings
    settings = get_settings()

    log.remove()  # clear default handler

    # Console
    log.add(
        sys.stdout,
        level=settings.log_level,
        format=(
            "<green>{time:YYYY-MM-DD HH:mm:ss.SSS}</green> | "
            "<level>{level: <8}</level> | "
            "<cyan>{name}:{function}:{line}</cyan> - <level>{message}</level>"
        ),
        backtrace=True,
        diagnose=False,  # avoid leaking variables in prod
    )

    # File (rotated)
    log_path = Path(settings.log_file)
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log.add(
        log_path,
        level=settings.log_level,
        rotation="50 MB",
        retention="14 days",
        compression="gz",
        enqueue=True,         # safer under multi-worker / async
        backtrace=True,
        diagnose=False,
    )

    _configured = True
    log.info("Logging configured: level={}, file={}", settings.log_level, log_path)


__all__ = ["log", "setup_logging"]
