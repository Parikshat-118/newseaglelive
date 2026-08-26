# Local Offline Testing with Docker

This document explains how the **News Eagle Live** platform is tested and developed in an offline local environment using Docker.

## 🛠 Technologies Used
- **Frontend**: Pure PHP 8+, HTML5, Vanilla CSS, and Vanilla JavaScript. (Zero frameworks used for maximum performance).
- **Backend (Bot)**: Python 3.12+ using `python-telegram-bot` v22 (async).
- **Database**: MySQL 8 (Shared between the frontend and the bot).
- **Environment**: Docker & Docker Compose (for containerized testing).
- **AI Integration**: OpenRouter API (Groq 70b & 8b models) for on-demand text explanation and categorization.

---

## 🐳 Docker Setup

When developing offline, we use Docker to spin up the web server and database instantly without cluttering the host machine.

### 1. Prerequisites Installed
- Docker Desktop (or Docker Engine on Linux)
- Docker Compose
- Python 3.12 (for running the bot locally alongside the containers)

### 2. The Docker Containers
Typically, a `docker-compose.yml` file is used to spin up two main containers:

1. **PHP/Apache Web Server Container**
   - **Image**: `php:8.2-apache`
   - **Ports**: Maps port `80` (container) to `localhost:80` (host).
   - **Volumes**: Mounts the local `frontend/` directory directly into `/var/www/html/` inside the container. This allows instant hot-reloading when editing PHP/JS files locally.
   - **Extensions**: We install the `pdo_mysql` extension inside this container so PHP can talk to the database.

2. **MySQL Database Container**
   - **Image**: `mysql:8.0`
   - **Ports**: Maps port `3306` to `localhost:3306`.
   - **Environment Variables**: Sets `MYSQL_ROOT_PASSWORD` and automatically creates the `news_eagle` database.
   - **Volumes**: Mounts a local folder to `/var/lib/mysql` to persist the database even if the container is destroyed.

### 3. How to Test the Flow Locally

1. **Start the Web Server & DB**: Run `docker-compose up -d` in your terminal.
2. **Import the Database**: Open a database GUI (like phpMyAdmin or DBeaver), connect to `localhost:3306`, and import `database/news_eagle.sql`.
3. **Configure the Web App**: Copy `frontend/includes/config.example.php` to `config.php` and set the database host to the Docker MySQL container (or `127.0.0.1`).
4. **Run the Bot Locally**:
   - Create a Python virtual environment: `python -m venv .venv`
   - Activate it: `source .venv/Scripts/activate` (Windows)
   - Install requirements: `pip install -r telegram-bot/requirements.txt`
   - Run the bot in long-polling mode: `python telegram-bot/run.py`
5. **View the Website**: Open `http://localhost/` in your web browser. Any changes you make to the local PHP files are instantly reflected.

By using Docker, the offline testing environment perfectly mirrors the production Linux environment, ensuring that code tested locally will work flawlessly when pushed to the AWS EC2 server.
