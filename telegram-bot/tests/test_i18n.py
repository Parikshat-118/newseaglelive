from src.utils.i18n import t


def test_known_key_en():
    assert t("rate_limited", "en")
    assert "fast" in t("rate_limited", "en").lower()


def test_known_key_hi():
    assert t("rate_limited", "hi")
    # Should NOT silently fall back to English when the key exists in hi.json
    assert t("rate_limited", "en") != t("rate_limited", "hi")


def test_unknown_key_returns_key():
    assert t("this_key_does_not_exist_xyz", "en") == "this_key_does_not_exist_xyz"


def test_format_args():
    out = t("pincode_saved", "en", pincode="110001",
            city="New Delhi", district="Central", state="Delhi")
    assert "110001" in out
    assert "New Delhi" in out


def test_unknown_language_falls_back_to_english():
    # Asking for "fr" should fall through to English
    assert t("rate_limited", "fr") == t("rate_limited", "en")
