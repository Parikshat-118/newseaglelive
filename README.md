# 🦅 News Eagle Live — Full Project Overview

**Stay Ahead, Stay Informed**
A product of Tech Eagles, under Mahakumbrix Innovation.

This document is the single map of the whole system: a Telegram bot (Python) and a
companion website (PHP) that **share one MySQL database**. There is no API between
the two codebases — MySQL tables *are* the integration layer. Understanding that one
fact explains most of how this project behaves.

---

## 1. High-level architecture

```
                    ┌─────────────────────────┐
                    │        MySQL 8           │
                    │      news_eagle DB        │
                    │   (26 tables + 2 views)    │
                    └───────────┬───────────────┘
                    reads/writes │  reads/writes
        ┌───────────────────────┼───────────────────────┐
        │                                               │
┌───────▼────────┐                             ┌────────▼────────┐
│  telegram-bot/   │                             │    frontend/      │
│  Python 3.12+     │                             │   PHP, no framework │
│  python-telegram- │                             │                    │
│  bot v22 (async)  │                             │  Apache/Nginx +    │
│  + Redis + APScheduler│                         │  PHP-FPM           │
└───────┬────────┘                             └────────┬────────┘
        │                                               │
        │ OpenRouter (Grok)  ── AI ──  OpenRouter (Grok) │
        │ Telegram Bot API                               │
        └──────────────► users on Telegram                │
                                       users on the web ◄──┘
```

**Key coupling points:**
- **Auth bridge**: bot's `/webauth` writes a 6-digit code to `web_auth_tokens`; the
  website's `verify-otp.php` reads it and creates a `web_sessions` row (cookie `ne_sid`).
- **Content**: bot ingests news → `news_articles`; website only *reads* `news_articles`
  (plus writes `view_count`/`like_count`/`share_count` back to it directly).
- **Admin**: two independent gates that must stay in sync manually (see §7).
- No REST/gRPC API between the two — everything is DB-mediated.

---

## 2. Repository layout

```
news-eagle-live/
├── frontend/                       # PHP website (no framework)
│   ├── api/
│   │   ├── admin/
│   │   │   ├── approve-feedback.php
│   │   │   └── promote.php
│   │   ├── ai-explain.php
│   │   ├── feedback.php
│   │   ├── like.php
│   │   ├── quiz-attempt.php
│   │   ├── share.php
│   │   ├── ticker.php
│   │   └── verify-otp.php
│   ├── includes/
│   │   ├── bootstrap.php           # DB handle, session/auth helpers, CSRF, ne_json()
│   │   ├── config.php              # brand, social links, DB creds, OpenRouter key, categories
│   │   ├── header.php
│   │   └── footer.php              # theme toggle, like/share JS
│   ├── account.php
│   ├── admin.php                   # tabs: feedback / admins / alerts / stats
│   ├── alerts.php                  # public severe-alerts feed
│   ├── article.php                 # single article + AI explain + like/share
│   ├── feedback.php                # testimonials + submission form
│   ├── index.php                   # homepage
│   ├── login.php                   # mobile + OTP login UI
│   ├── logout.php
│   ├── news.php                    # paginated/filterable article list
│   ├── students.php                # UPSC/SSC/media quiz, mains, editorial, leaderboard
│   ├── videos.php                  # trending news videos
│   └── warmeter.php                # Global Conflict Index gauge
│
├── telegram-bot/                   # Python bot
│   ├── src/
│   │   ├── config/
│   │   │   ├── settings.py         # pydantic-settings, reads .env
│   │   │   └── categories.py       # 20-category catalog (must match frontend/config.php)
│   │   ├── database/
│   │   │   ├── connection.py       # SQLAlchemy engine + session_scope()
│   │   │   ├── models.py           # ORM models (subset of full schema — see §4)
│   │   │   └── schema.sql          # canonical DDL
│   │   ├── services/
│   │   │   ├── user_service.py
│   │   │   ├── news_service.py          # ingest, dedupe, query
│   │   │   ├── ai_service.py            # OpenRouter client #1 (settings-driven)
│   │   │   ├── pincode_service.py
│   │   │   ├── notification_service.py  # send/broadcast, auto-ban on Forbidden
│   │   │   ├── digest_service.py
│   │   │   ├── quiz_service.py          # bot-native quiz (Quiz/QuizResult)
│   │   │   ├── keyword_service.py
│   │   │   ├── severe_alerts_service.py # OpenRouter client #2 (env-driven, raw SQL)
│   │   │   ├── student_service.py       # OpenRouter client #3 (env-driven, raw SQL)
│   │   │   ├── videos_service.py        # YouTube RSS, no API key
│   │   │   ├── warmeter_service.py      # keyword-only, zero AI cost
│   │   │   ├── weather_service.py       # OpenWeatherMap (not yet wired to a handler)
│   │   │   └── source_adapters/         # base.py, rss_adapter.py, newsapi_adapter.py, gnews_adapter.py
│   │   ├── handlers/
│   │   │   ├── start.py            # onboarding: language → categories → pincode → done
│   │   │   ├── news.py / search.py / categories.py
│   │   │   ├── pincode.py / alerts.py / bookmarks.py / history.py
│   │   │   ├── digest.py / quiz.py / chat.py / settings.py / language.py / help.py
│   │   │   ├── webauth.py          # /setmobile /webauth /mymobile — web login bridge
│   │   │   ├── admin.py            # /admin /stats /broadcast /users (Telegram-ID gated)
│   │   │   ├── callbacks.py        # central callback_query + free-text router
│   │   │   └── _render.py          # shared article-card renderer
│   │   ├── middlewares/
│   │   │   ├── auth.py             # @admin_only (checks ADMIN_IDS env var)
│   │   │   └── rate_limit.py       # @rate_limited (Redis fixed-window)
│   │   ├── scheduler/
│   │   │   ├── jobs.py             # all async job functions
│   │   │   └── runner.py           # APScheduler wiring (intervals/cron, IST)
│   │   ├── utils/
│   │   │   ├── i18n.py             # t(key, lang, **fmt)
│   │   │   ├── cache.py            # Redis: dedupe, rate limit, generic cache
│   │   │   ├── dedupe.py           # canonical_url() + sha256 url_hash()
│   │   │   ├── keyboards.py        # InlineKeyboard factories
│   │   │   ├── formatting.py       # MarkdownV2 escape + truncate
│   │   │   ├── retry.py            # tenacity http_retry wrapper
│   │   │   └── logger.py           # loguru, console + rotated file sink
│   │   ├── prompts/
│   │   │   └── templates.py        # all AI prompt builders (EN + HI)
│   │   └── locales/
│   │       ├── en.json
│   │       └── hi.json
│   ├── tests/                      # test_dedupe, test_i18n, test_pincode_service, ...
│   ├── deployment/
│   │   ├── DEPLOY_AWS_UBUNTU.md
│   │   ├── news-eagle.service      # systemd unit
│   │   ├── nginx.conf.example
│   │   └── apache.conf.example
│   ├── run.py                      # entry point (long-poll or webhook)
│   ├── requirements.txt
│   ├── install.sh / install-ready.sh
│   └── .env / .env.example
│
└── database/
    └── news_eagle.sql              # full dump (26 tables + 2 views, InnoDB, utf8mb4)
```

---

## 3. Tech stack

| Layer            | Frontend (web)                     | Backend (bot)                          |
|-------------------|-------------------------------------|------------------------------------------|
| Language          | PHP 8+ (procedural)                 | Python 3.12+                             |
| Framework         | None — hand-rolled includes         | python-telegram-bot v22 (async)          |
| DB access         | PDO (prepared statements)           | SQLAlchemy 2.x + PyMySQL                 |
| Session/state     | Cookie (`ne_sid`) → `web_sessions`  | PTB `context.user_data` (in-memory, **not persisted**) |
| Cache/queue       | —                                    | Redis 7 (dedupe, rate-limit, cache)      |
| Scheduler         | —                                    | APScheduler (Asia/Kolkata tz)            |
| AI provider       | OpenRouter (Grok), 1 integration    | Groq/OpenRouter with **Dual-Lane Multi-Key Round-Robin Load Balancing** |
| Web server        | Apache/nginx + PHP-FPM              | systemd unit, long-poll or webhook       |

---

## 4. Database — 26 tables + 2 views (confirmed from live phpMyAdmin dump)

### Identity & auth
| Table | Owner | Purpose |
|---|---|---|
| `users` | shared | Central identity. `telegram_id` (bot key) + `mobile_number`/`is_admin`/`display_name` (web fields, v2). |
| `web_sessions` | web | Cookie-token → user_id, expiry. Read by `bootstrap.php::ne_current_user()`. |
| `web_auth_tokens` | bridge | 6-digit OTP codes, written by bot's `/webauth`, consumed by web's `verify-otp.php`. |
| `user_subscriptions` | bot | Category subscriptions (`/categories`). |
| `keywords` | bot | Keyword-alert list per user. |
| `referrals` | bot | Referral chain (`/start ref_<id>`). |
| `premium_users` | bot | Subscription plan rows (present in schema; no handler wires it yet). |

### News content
| Table | Owner | Purpose |
|---|---|---|
| `news_articles` | bot writes, web reads+counts | Core article table. `ai_summary`/`ai_summary_hi` written by **both** bot (`_cb_article`) and web (`ai-explain.php`) — same cache-check pattern, parallel implementations. `view_count`/`like_count`/`share_count` incremented by web API endpoints directly. |
| `news_sources` | bot | RSS/NewsAPI/GNews source registry. |
| `news_videos` | bot writes, web reads | YouTube RSS ingestion, 7-day retention. |
| `article_views` | web | Per-view dedup (6h window per IP/user) — separate from bot's `reading_history`. |
| `article_likes` | web | Toggle-like records, backs `news_articles.like_count`. |
| `article_shares` | web | Anonymous-allowed share logging. |
| `reading_history` | bot | Bot-side view log, feeds `trending_score`. **Not the same signal as web likes/views.** |
| `bookmarks` | bot | `/bookmarks`, also feeds trending score. |

### Alerts & conflict index
| Table | Owner | Purpose |
|---|---|---|
| `severe_alerts` | bot writes, web reads | AI-detected severe events (weather/disease/disaster/security). Raw-SQL only, no ORM model. |
| `alert_deliveries` | bot | Per-user delivery dedup for severe alerts. |
| `warmeter_snapshots` | bot writes, web reads | Global Conflict Index, 7d/30d windows, 200-snapshot retention. |
| `v_active_alerts` | view | Convenience view over `severe_alerts` (active + not expired). |

### Quizzes — two independent systems
| Table | System | Purpose |
|---|---|---|
| `quizzes` / `quiz_results` | **Bot-native** | Casual `/quiz` command, AI-generated on demand, ORM-backed. |
| `daily_quizzes`, `daily_editorials`, `daily_mains_questions` | **Student Hub** | UPSC/SSC/media exam content, **bilingual (EN/HI)**, cron-generated daily 6:30 IST. Includes AI-generated `search_tags` for global frontend searching. |

These do not share code, data, or leaderboards — treat as two separate products.

### Engagement / misc
| Table | Owner | Purpose |
|---|---|---|
| `feedback` | web | Testimonials, admin-approval gated (`is_approved`). |
| `notifications` | bot | Keyword/breaking/digest/quiz/broadcast/weather delivery log. |
| `chat_sessions` | bot | Present in schema for persisted AI-chat history; current `chat.py` actually uses `context.user_data['chat_history']` instead — this table appears unused by current handler code. |
| `pincode_directory` | shared | PIN → city/district/state/lat/lng cache (India Post API fallback). |
| `v_top_liked_24h` | view | Convenience view, presumably backing homepage "trending" query. |

**28 total objects** (26 tables + 2 views) — matches phpMyAdmin exactly.

## Frontend

## 5. Feature map

| Feature | Bot command/page | Web page | AI involved |
|---|---|---|---|
| Browse/search news | `/news /latest /breaking /trending /search` | `news.php`, `index.php` | No |
| Read article + AI summary | `art:sum` / `art:exp` callbacks | `article.php` (AI Explain button) | Yes — 2 separate impls |
| Like / share / view count | (bot: bookmarks/reading_history) | `like.php` / `share.php` / `article.php` | No |
| Local news by PIN | `/setpincode /localnews` | — (web has no PIN UI; managed via bot only) | No |
| Severe alerts | Pushed automatically | `alerts.php` (read-only) | Yes (detection) |
| War Meter | — | `warmeter.php` | No (keyword scoring) |
| Trending videos | — | `videos.php` | No |
| Casual quiz | `/quiz` | — (bot-only) | Yes |
| Student Hub (exam prep) | — | `students.php` | Yes (4 daily generations) |
| Feedback | — | `feedback.php` | No |
| AI chat assistant | `/chat` | — (bot-only) | Yes |
| Daily digest | `/digest` + scheduled push | — | No (uses `trending_score`) |
| Keyword alerts | `/alerts` | — (bot-only) | No |
| Web login | `/webauth /setmobile /mymobile` | `login.php` | No |
| Admin panel | `/admin /stats /broadcast /users` | `admin.php` (feedback/admins/alerts/stats tabs) | No |

---

## 6. Scheduled jobs (bot, `scheduler/runner.py`)

| Job | Cadence | Writes to |
|---|---|---|
| `job_fetch_news` | every 5 min | `news_articles` |
| `job_keyword_alerts` | every 15 min | `notifications` → pushes |
| `job_breaking_push` | every 10 min | pushes (no new table) |
| `job_refresh_trending` | every 15 min | `news_articles.trending_score` |
| `job_morning_digest` | cron 07:00 IST | pushes |
| `job_evening_digest` | cron 19:00 IST | pushes |
| `job_generate_quizzes` | cron 06:00 IST | `quizzes` |
| `job_detect_severe_alerts` | every 30 min | `severe_alerts` |
| `job_dispatch_severe_alerts` | every 10 min | `alert_deliveries`, pushes |
| `job_fetch_videos` | every 30 min | `news_videos` |
| `job_compute_warmeter` | every 30 min | `warmeter_snapshots` |
| `job_student_content` | cron 06:30 IST | `student_content` |

---

## 7. Known coupling risks & tech debt

1. **Dual admin authorization** — bot gates on Telegram ID (`ADMIN_IDS` env var); web
   gates on `users.is_admin` DB column with a hardcoded super-admin mobile
   (`9540739137`) repeated literally in `config.php`, `promote.php`, and `webauth.py`.
   No single source of truth for "who can grant admin."

2. **Dual quiz systems** — see §4. Confusing for anyone extending "the quiz feature"
   without realizing there are two.

3. **Multiple AI integrations** — `ai_service.py`, `severe_alerts_service.py`, `news_service.py`, and `student_service.py` now share a unified `get_ai_provider` factory with **Dual-Lane Multi-Key Round-Robin Load Balancing**. However, PHP's `ai-explain.php` still uses a separate key in `config.php`.

4. **Split trending signals** — bot's `trending_score` (bookmarks×3 + reading_history)
   and web's ranking (`like_count×3 + view_count`) draw from disjoint activity tables.
   A like on the website never affects what the bot considers "trending" and vice versa.

5. **Two live secrets committed in plaintext**:
   - `frontend/includes/config.php` — OpenRouter key
   - `telegram-bot/install.sh` / `install-ready.sh` — a **different** OpenRouter key,
     plus a hardcoded MySQL password in `install-ready.sh`
   - A live `.env` (not just `.env.example`) sits in the `telegram-bot/` tree.
   All of these should be rotated and removed from source before any public exposure.

6. **Ephemeral bot state** — onboarding step, in-flight quiz, chat history, and cached
   `lang`/`db_user_id` live only in PTB's `context.user_data`, with no persistence
   backend configured in `run.py`. A bot restart mid-flow silently drops that user's
   progress (though DB-durable state like subscriptions/pincode survives fine).

7. **Timezone approximation bug** — `handlers/digest.py`'s on-demand `/digest` uses a
   naive UTC+5 shift to guess IST hour (drops the 30-minute offset), while the
   scheduler correctly uses `pytz.timezone("Asia/Kolkata")`. The code comments even
   flag this as a known approximation.

8. **`chat_sessions` table appears unused** — schema has it, but `chat.py` keeps
   rolling history in `context.user_data` instead, so the table is likely dead weight
   or a leftover from an earlier persistence design.

9. **`weather_service.py` appears unwired** — the service and the `weather_alerts`
   user preference both exist, but no handler currently calls `get_weather()`.

---

## 8. Deployment model

- Single AWS Ubuntu box (LAMP-style), targeting up to ~100K users per
  `DEPLOY_AWS_UBUNTU.md`.
- Bot runs as a systemd service (`news-eagle.service`), long-polling by default,
  webhook mode (behind nginx/Apache + TLS) recommended past ~10K users.
- Website served by Apache/nginx + PHP-FPM, same box, same MySQL instance.
- MySQL 8, InnoDB, utf8mb4 throughout.
- Redis 7 local, `allkeys-lru` eviction, AOF persistence enabled.

---

*This document was compiled from a full review of the frontend (PHP), backend
(Python bot), and live database schema. It reflects the system as of the files
reviewed on 2026-07-13, including the v2.1 Bilingual & AI Search upgrade.*
