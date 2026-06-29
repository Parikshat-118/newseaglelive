"""URL canonicalisation + sha256 hashing for article deduplication."""
from __future__ import annotations

import hashlib
import re
from urllib.parse import urlparse, urlunparse, parse_qsl, urlencode

_TRACKING_PARAMS = {
    "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content",
    "fbclid", "gclid", "mc_cid", "mc_eid", "ref", "ref_src", "amp",
}


def canonical_url(url: str) -> str:
    """Drop tracking params, lowercase scheme/host, strip fragments."""
    try:
        p = urlparse(url.strip())
        host = (p.hostname or "").lower()
        scheme = (p.scheme or "https").lower()
        query = urlencode([
            (k, v) for k, v in parse_qsl(p.query, keep_blank_values=False)
            if k.lower() not in _TRACKING_PARAMS
        ])
        path = re.sub(r"/+", "/", p.path or "/").rstrip("/") or "/"
        return urlunparse((scheme, host, path, "", query, ""))
    except Exception:
        return url


def url_hash(url: str) -> str:
    return hashlib.sha256(canonical_url(url).encode("utf-8")).hexdigest()
