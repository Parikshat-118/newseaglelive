# 🦅 News Eagle

> **AI-Powered News Platform with Web Application & Telegram Bot**

News Eagle is a full-stack AI-powered news platform that combines a PHP web application with a Python Telegram bot to deliver real-time news, AI-generated summaries, multilingual support, personalized alerts, quizzes, bookmarks, and location-based notifications.

---

# ✨ Features

* 🌍 AI-powered news summaries
* 🤖 Telegram News Bot
* 📰 Real-time news aggregation
* 🔐 Telegram OTP Web Login
* 📍 PIN-code based local news
* 🚨 AI-powered severe weather & disaster alerts
* ❤️ Like, Share & View counters
* 📚 Bookmarks & Reading History
* 🧠 AI Chat Assistant
* ❓ Current Affairs Quiz
* 📢 Admin Broadcast System
* 🌐 Multi-language support
* 🔔 Personalized keyword alerts

---

# 🏗️ Project Structure

```text
NEWS/
│
├── frontend/                 # PHP Web Application
│   ├── api/
│   ├── includes/
│   ├── assets/
│   └── *.php
│
├── telegram-bot/             # Python Telegram Bot
│   ├── src/
│   ├── deployment/
│   ├── tests/
│   ├── run.py
│   └── requirements.txt
│
├── database/
│
├── docs/
│
├── README.md
└── .gitignore
```

---

# 🛠️ Tech Stack

| Layer         | Technology          |
| ------------- | ------------------- |
| Frontend      | PHP                 |
| Backend       | Python              |
| Database      | MySQL               |
| Cache         | Redis               |
| AI            | OpenRouter (Grok)   |
| Bot Framework | python-telegram-bot |
| Scheduler     | APScheduler         |
| Hosting       | AWS EC2             |
| Web Server    | Apache              |

---

# 🚀 Getting Started

## Frontend

Configure your database credentials in:

```text
frontend/includes/config.php
```

Deploy the frontend to your web server.

---

## Telegram Bot

```bash
cd telegram-bot

python3 -m venv .venv

source .venv/bin/activate

pip install -r requirements.txt

cp .env.example .env

python run.py
```

---

# 📂 Documentation

Additional documentation can be found in the `docs/` directory.

Examples:

* Installation
* Deployment
* Database
* API
* Architecture
* Feature Documentation

---

# 🔒 Security

The following files are intentionally excluded from version control:

* `frontend/includes/config.php`
* `telegram-bot/.env`
* Database backups
* Logs
* Virtual environments

Never commit production credentials, API keys, or secrets.

---

# 📄 License

Proprietary © Mahakumbrix Innovation

All rights reserved.
