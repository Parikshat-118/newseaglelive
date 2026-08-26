# News Eagle Live — Complete Bot v2.2 (Fresh Install)

**This is the FULL bot** — all original features PLUS:

- 🌏 **Full Hindi Support (Student Hub)** — The AI generates localized UPSC Quizzes, Editorials, and Mains Practice seamlessly in both English and Hindi.
- 🧠 **AI Semantic Search Indexing** — Every incoming news article is automatically tagged with 10-20 AI-generated concepts and synonyms to supercharge the PHP web-search engine.
- 🎧 **Modern AI Audio Player** — Integrated into the web app frontend, providing a seamless podcast-like experience for localized AI explanations.
- 🟢 **Global Live Viewers** — A site-wide real-time active users counter driven by a new lightweight API and `site_live_viewers` table.
- ⚖️ **Multi-Key Round-Robin Load Balancing** — Deterministic round-robin cycling across multiple Groq API keys to infinitely scale token limits and guarantee zero downtime during API rate limits.
- ⚡ **Dual-Lane AI Model Routing** — Dedicated `GROQ_QUIZ_API_KEY` routes complex quizzes to heavy models (e.g. 70b), while background tasks are routed to ultra-fast, cheap models (e.g. 8b) to save hundreds of thousands of tokens per day.
- 🔐 **Web login (Telegram-OTP)** — `/setmobile`, `/webauth`, `/mymobile`. Bot generates a 6-digit code valid 3 minutes; user logs in on the website with mobile + code.
- 🚨 **AI Severe Alerts** — every 30 min the AI scans news for severe location-specific events (cyclone, flood, disease outbreak, terror...) and pushes them ONLY to users in affected states/districts (PIN-code based).
- 👑 **Super Admin** — mobile `9540739137` auto-promotes to admin on `/setmobile`. Admin can promote others via the web admin panel.
- 📊 **Web-app counters** — views, likes, shares per article (shared MySQL with the web app).
- ✅ Pre-configured: Multi-key AI routing, Python 3.14-compatible requirements, fixed systemd unit, and automatic rate-limit recovery.

## Fresh install (one command)

```bash
unzip news-eagle-live-v2-complete.zip
cd news-eagle-live
chmod +x install.sh
sudo ./install.sh
```

The installer keeps your existing `.env` (bot token + DB password) if found at `/opt/news-eagle-live/.env`, and only refreshes the OpenRouter key. On a brand-new server it asks for your values.

**Database:** create a fresh empty `news_eagle` database first (phpMyAdmin → drop old → create new, `utf8mb4_unicode_ci`). The installer offers to load `src/database/schema.sql` for you, or import it in phpMyAdmin.

## Test after install

```
/start
/setmobile 9540739137   ← Super Admin
/webauth                ← 6-digit web login code
/news /latest /breaking /quiz ...
```

---

# 🦅 News Eagle Live

**Stay Ahead, Stay Informed**

Powered by Tech Eagles · A Product of Mahakumbrix Innovation

A production-ready Telegram news bot built with Python 3.12+, MySQL 8, Redis, and the
OpenRouter API (Grok). Designed to scale to 100,000+ users on a single AWS LAMP-style
Ubuntu server, with a path to horizontal scaling.

---

## What this delivers

- 22+ user-facing features (multi-language EN/HI, breaking alerts, daily digests,
  keyword alerts, AI chat, current-affairs quiz, bookmarks, PIN-code local news,
  weather alerts, referrals, admin broadcast, etc.)
- 20 news categories across India / World / Business / Tech / AI / Sports / etc.
- Clean architecture: handlers → services → repositories → models
- Async I/O via `python-telegram-bot` v22+
- Scheduled jobs via APScheduler (fetch, summarize, alert, digest)
- Redis for caching, deduplication, rate-limiting, and session state
- MySQL for durable storage (users, articles, bookmarks, quizzes, history)
- OpenRouter integration with retry, backoff, and prompt templates
- Admin panel with broadcast targeting (language / category / PIN code)
- systemd unit + nginx reverse proxy for webhook mode
- Long-polling fallback for first-boot testing

## Tech stack

| Layer        | Choice                                      |
|--------------|---------------------------------------------|
| Language     | Python 3.12+                                |
| Bot          | python-telegram-bot v22+                    |
| DB           | MySQL 8.x (via SQLAlchemy 2.x + PyMySQL)    |
| Cache/Queue  | Redis 7                                     |
| Scheduler    | APScheduler 3.x                             |
| AI           | OpenRouter API (Grok primary)               |
| News sources | RSS, NewsAPI, GNews (adapter pattern)       |
| Web layer    | Apache or nginx reverse proxy (optional)    |
| Process mgr  | systemd                                     |

## Repository layout

```
news-eagle-live/
├── README.md
├── requirements.txt
├── .env.example
├── run.py                          # Entry point (long-poll or webhook)
├── src/
│   ├── config/
│   │   ├── settings.py             # Pydantic settings, env loading
│   │   └── categories.py           # Category catalog
│   ├── database/
│   │   ├── connection.py           # SQLAlchemy engine + session
│   │   ├── schema.sql              # Raw MySQL schema (canonical)
│   │   └── models.py               # SQLAlchemy ORM models
│   ├── services/
│   │   ├── user_service.py
│   │   ├── news_service.py
│   │   ├── ai_service.py           # OpenRouter / Grok wrapper
│   │   ├── pincode_service.py
│   │   ├── notification_service.py
│   │   ├── digest_service.py
│   │   ├── quiz_service.py
│   │   ├── keyword_service.py
│   │   ├── source_adapters/
│   │   │   ├── base.py
│   │   │   ├── rss_adapter.py
│   │   │   ├── newsapi_adapter.py
│   │   │   └── gnews_adapter.py
│   │   └── weather_service.py
│   ├── handlers/
│   │   ├── start.py                # /start onboarding flow
│   │   ├── language.py
│   │   ├── news.py                 # /news /latest /trending /breaking
│   │   ├── categories.py
│   │   ├── search.py
│   │   ├── pincode.py              # /setpincode etc.
│   │   ├── alerts.py               # keyword alerts
│   │   ├── bookmarks.py
│   │   ├── history.py
│   │   ├── digest.py
│   │   ├── quiz.py
│   │   ├── chat.py                 # AI chat assistant
│   │   ├── settings.py
│   │   ├── admin.py                # /admin /broadcast /stats
│   │   ├── help.py
│   │   └── callbacks.py            # Central callback_query router
│   ├── middlewares/
│   │   ├── rate_limit.py
│   │   ├── auth.py                 # Admin gate
│   │   └── logging_mw.py
│   ├── scheduler/
│   │   ├── jobs.py                 # APScheduler job definitions
│   │   └── runner.py
│   ├── utils/
│   │   ├── i18n.py                 # English / Hindi translator
│   │   ├── cache.py                # Redis wrapper
│   │   ├── dedupe.py               # Article URL hashing
│   │   ├── keyboards.py            # Inline keyboard factories
│   │   ├── formatting.py           # MarkdownV2 helpers
│   │   ├── retry.py
│   │   └── logger.py
│   ├── prompts/
│   │   ├── templates.py            # All AI prompt templates
│   │   └── i18n_prompts.py
│   └── locales/
│       ├── en.json
│       └── hi.json
├── tests/
│   ├── test_ai_service.py
│   ├── test_news_service.py
│   ├── test_pincode_service.py
│   └── test_handlers.py
└── deployment/
    ├── DEPLOY_AWS_UBUNTU.md        # Step-by-step deployment
    ├── news-eagle.service          # systemd unit
    ├── nginx.conf.example          # webhook reverse proxy
    └── apache.conf.example         # if using Apache (LAMP)
```

## Quick start

See `deployment/DEPLOY_AWS_UBUNTU.md` for full production setup. Local dev:

```bash
git clone <your-repo> news-eagle-live
cd news-eagle-live
python3.12 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env          # fill in BOT_TOKEN, DB creds, OPENROUTER_API_KEY
mysql -u root -p < src/database/schema.sql
python run.py                 # starts long-poll mode
```

## Environment

All config is via environment variables loaded from `.env`. See `.env.example` for the
full list — at minimum you need `BOT_TOKEN`, `DATABASE_URL`, `REDIS_URL`,
`OPENROUTER_API_KEY`, and `ADMIN_IDS`.

## Operational model

- **Long-poll mode** (default in dev): `python run.py` — no public IP needed.
- **Webhook mode** (production): set `WEBHOOK_URL`, run behind nginx/Apache TLS.
- **Scheduler**: runs in-process by default. For very high load, run as a separate
  systemd unit by setting `SCHEDULER_STANDALONE=true` and starting `python -m
  src.scheduler.runner`.

## Scaling notes

A single 4-vCPU / 8GB AWS instance comfortably handles ~100K users with the
following knobs:
- Redis on the same box, persistence enabled, `maxmemory-policy allkeys-lru`.
- MySQL with InnoDB buffer pool ~50% of RAM, slow-query log on.
- Webhook mode strongly preferred over polling at >10K users.
- News-fetch workers (RSS/NewsAPI/GNews) are I/O-bound and run async.
- Broadcasts use chunked send with `asyncio.gather` and respect Telegram rate limits.

Past that, split scheduler + workers onto a second instance and put MySQL +
Redis on dedicated hosts.

## License

Proprietary — Mahakumbrix Innovation.
