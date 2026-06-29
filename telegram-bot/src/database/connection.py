"""
SQLAlchemy engine & session factory.

- Pooled connections (size + overflow tunable via env).
- `pool_pre_ping=True` survives MySQL idle disconnects (`server has gone away`).
- `pool_recycle` rotates connections before wait_timeout fires.
"""
from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

from sqlalchemy import create_engine
from sqlalchemy.engine import Engine
from sqlalchemy.orm import Session, sessionmaker

from src.config.settings import get_settings
from src.utils.logger import log

_engine: Engine | None = None
_SessionLocal: sessionmaker | None = None


def init_db() -> None:
    """Create the engine + session factory. Idempotent."""
    global _engine, _SessionLocal
    if _engine is not None:
        return

    settings = get_settings()
    _engine = create_engine(
        settings.database_url,
        pool_size=settings.db_pool_size,
        max_overflow=settings.db_max_overflow,
        pool_recycle=settings.db_pool_recycle,
        pool_pre_ping=True,
        future=True,
        echo=False,
    )
    _SessionLocal = sessionmaker(bind=_engine, expire_on_commit=False, autoflush=False)
    log.info("DB initialized — pool_size={}, max_overflow={}",
             settings.db_pool_size, settings.db_max_overflow)


def dispose_db() -> None:
    global _engine, _SessionLocal
    if _engine is not None:
        _engine.dispose()
        log.info("DB engine disposed")
    _engine = None
    _SessionLocal = None


def get_engine() -> Engine:
    if _engine is None:
        init_db()
    return _engine  # type: ignore[return-value]


@contextmanager
def session_scope() -> Iterator[Session]:
    """
    Transactional session context manager.

        with session_scope() as s:
            user = s.get(User, 1)
            user.name = "X"   # auto-committed on exit, rolled back on exception
    """
    if _SessionLocal is None:
        init_db()
    session: Session = _SessionLocal()  # type: ignore[misc]
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()
