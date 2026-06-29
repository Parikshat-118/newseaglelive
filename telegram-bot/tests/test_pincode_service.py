from src.services.pincode_service import is_valid_pincode


def test_valid_pincodes():
    assert is_valid_pincode("110001")
    assert is_valid_pincode("560001")
    assert is_valid_pincode("400001")


def test_invalid_pincodes():
    assert not is_valid_pincode("")
    assert not is_valid_pincode(None)            # type: ignore[arg-type]
    assert not is_valid_pincode("00000")          # too short
    assert not is_valid_pincode("012345")         # starts with 0
    assert not is_valid_pincode("abcdef")         # non-numeric
    assert not is_valid_pincode("1100001")        # too long
    assert not is_valid_pincode("11000a")         # alphanumeric
