"""
PIN code resolution.

1. Check `pincode_directory` table (fast, no network).
2. Fall back to the free India Post API
   (https://api.postalpincode.in/pincode/{pincode}) and cache the result.
"""
from __future__ import annotations

import re
from typing import Optional

import httpx
from sqlalchemy import select

from src.database.connection import session_scope
from src.database.models import PincodeEntry
from src.utils.logger import log


_PIN_RE = re.compile(r"^[1-9][0-9]{5}$")


def is_valid_pincode(pin: str) -> bool:
    return bool(pin and _PIN_RE.match(pin))


def lookup_cached(pincode: str) -> Optional[PincodeEntry]:
    with session_scope() as s:
        entry = s.get(PincodeEntry, pincode)
        if entry:
            s.expunge(entry)
        return entry


async def resolve(pincode: str) -> Optional[dict]:
    """Returns {'pincode','city','district','state'} or None."""
    if not is_valid_pincode(pincode):
        return None

    cached = lookup_cached(pincode)
    if cached:
        return {
            "pincode": cached.pincode,
            "city": cached.city,
            "district": cached.district,
            "state": cached.state,
        }

    # Network fallback
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get(f"https://api.postalpincode.in/pincode/{pincode}")
            resp.raise_for_status()
            data = resp.json()
    except Exception as e:
        log.warning("PIN API failed for {}: {}", pincode, e)
        return None

    if not data or data[0].get("Status") != "Success":
        return None
    offices = data[0].get("PostOffice") or []
    if not offices:
        return None

    # Pick the first office as the canonical record
    o = offices[0]
    result = {
        "pincode": pincode,
        "city":    o.get("Name") or o.get("Block") or "",
        "district": o.get("District") or "",
        "state":   o.get("State") or "",
    }

    # Cache for next time
    try:
        with session_scope() as s:
            s.merge(PincodeEntry(
                pincode=pincode,
                city=result["city"][:128],
                district=result["district"][:128],
                state=result["state"][:128],
            ))
    except Exception as e:
        log.warning("Failed to cache PIN {}: {}", pincode, e)

    return result
