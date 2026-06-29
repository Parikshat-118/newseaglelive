"""
Weather service — OpenWeatherMap (free tier).
Returns a compact dict; handlers format for display.
"""
from __future__ import annotations

from typing import Optional

import httpx

from src.config.settings import get_settings
from src.utils.logger import log


async def get_weather(lat: float, lng: float, lang: str = "en") -> Optional[dict]:
    settings = get_settings()
    if not settings.weather_api_key:
        return None
    params = {
        "lat": lat, "lon": lng,
        "appid": settings.weather_api_key,
        "units": "metric",
        "lang": "hi" if lang == "hi" else "en",
    }
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get("https://api.openweathermap.org/data/2.5/weather", params=params)
            resp.raise_for_status()
            d = resp.json()
    except Exception as e:
        log.warning("Weather fetch failed: {}", e)
        return None

    return {
        "location": d.get("name"),
        "temp_c":   d.get("main", {}).get("temp"),
        "feels_c":  d.get("main", {}).get("feels_like"),
        "humidity": d.get("main", {}).get("humidity"),
        "condition": (d.get("weather") or [{}])[0].get("description"),
        "wind_kph": (d.get("wind", {}).get("speed") or 0) * 3.6,
    }
