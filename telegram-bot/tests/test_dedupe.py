"""Pure-function tests (no external services needed)."""
from src.utils.dedupe import canonical_url, url_hash


def test_canonical_strips_tracking_params():
    raw = "https://example.com/Article/?utm_source=x&utm_medium=y&id=42"
    canon = canonical_url(raw)
    assert "utm_" not in canon
    assert "id=42" in canon


def test_canonical_lowercases_host():
    assert canonical_url("HTTPS://Example.com/path").startswith("https://example.com")


def test_hash_is_stable():
    h1 = url_hash("https://example.com/x?utm_source=a")
    h2 = url_hash("https://example.com/x?utm_source=b")
    assert h1 == h2  # tracking params shouldn't change the hash
    assert len(h1) == 64


def test_hash_distinguishes_paths():
    assert url_hash("https://example.com/a") != url_hash("https://example.com/b")
