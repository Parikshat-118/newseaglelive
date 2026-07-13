# News Eagle Live — Web Frontend

**The companion web application for the News Eagle Telegram Bot.**
A product of Tech Eagles, under Mahakumbrix Innovation.

This directory contains the entire PHP frontend for the News Eagle Live platform. It is designed to be lightweight, incredibly fast, and securely coupled with the Python Telegram Bot via a single, shared MySQL database.

---

## 🏗 Architecture & Philosophy

- **Zero Frameworks**: This frontend is built using pure, procedural **PHP 8+**, raw HTML5, and Vanilla CSS/JS. There is no React, Vue, Laravel, or heavy abstraction layer. This ensures absolute maximum performance and trivial deployment on standard LAMP/LEMP stacks.
- **Shared Database Pattern**: There is **no REST API** between the bot and the website. The integration layer is the MySQL database itself. The Python bot writes news and generates AI content; the PHP website reads it directly.
- **Telegram Auth Bridge**: Users log in to the website using their Telegram mobile number and a 6-digit OTP generated live by the bot (`/webauth`), guaranteeing 100% synchronized user identities.

---

## 🌟 Core Features

### 1. The Student Hub (`students.php`)
A massive draw for the platform. Displays daily, AI-generated educational content tailored for exam aspirants. 
- Features **UPSC/SSC Quizzes**, **Media Exam Prep**, **Mains Practice Questions**, and deep **Editorial Analyses**.
- **Fully Bilingual**: Content is automatically generated and seamlessly toggleable between **English** and **Hindi**.

### 2. AI Semantic Search (`search.php`)
Traditional SQL `LIKE` searches are limited to exact keyword matches. This frontend implements an advanced Semantic Search engine. It searches against hidden **AI Search Tags** (generated in the background by Groq AI during article ingestion), allowing users to find articles by concept, synonym, or theme—not just exact words.

### 3. AI Article Summarization (`article.php`)
Users can request on-demand, localized AI explanations of complex news articles via a direct API bridge to Groq's 70b models natively in the PHP application.

### 4. Severe Alerts Dashboard (`alerts.php`)
A live feed of AI-detected severe alerts (cyclones, terror threats, disease outbreaks) plotted and pushed directly from the bot's background threat-monitoring jobs.

### 5. Engagement Analytics (`like.php`, `share.php`)
Direct database interaction via lightweight JS fetch calls to track `view_count`, `like_count`, and `share_count` on every article, which directly feeds into the Bot's trending algorithms.

### 6. Admin Panel (`admin.php`)
Secure portal for `is_admin=1` users to moderate user testimonials, manage severe alert active states, and view high-level platform statistics.

---

## 📂 Directory Structure

```
frontend/
├── api/                   # Async endpoints for JS fetch() calls
│   ├── admin/             # Admin moderation endpoints
│   ├── ai-explain.php     # On-demand AI article summarizer
│   ├── like.php / share.php
│   └── verify-otp.php     # Telegram OTP login validator
├── includes/              # Core shared logic
│   ├── bootstrap.php      # PDO DB connection, session logic, auth helpers
│   ├── config.php         # Environment vars, API keys, brand strings
│   ├── header.php         # Navbar & global UI shell
│   └── footer.php         # Global JS & dark-mode toggle
├── search.php             # AI Semantic Search interface
├── students.php           # The Bilingual Student Hub
├── article.php            # Single article reader & AI explainer
├── index.php / news.php   # Paginated news feeds
├── login.php              # Webauth OTP entry UI
├── admin.php              # Super Admin dashboard
└── warmeter.php           # Global Conflict Index gauge
```

---

## 🚀 Deployment Requirements

1. **PHP 8.0+** with `pdo_mysql` extension.
2. **Apache** (with `mod_rewrite`) or **Nginx** (with `php-fpm`).
3. **Database**: Must connect to the *exact same* MySQL `news_eagle` database used by the Python bot.
4. **Configuration**: Edit `includes/config.php` to provide your MySQL credentials and your Groq/OpenRouter API key for the on-demand AI Explainer feature.

### Note on Security
Because the frontend connects directly to the shared database, the deployment server must have strict firewall rules to prevent raw DB access, and directory permissions should restrict public access to `/includes/config.php`.
