# 🦅 News Eagle Live — AWS Ubuntu Deployment Guide

This walks through deploying the bot on a fresh **AWS EC2 Ubuntu 22.04 / 24.04 LTS** instance,
running MySQL + Redis + Python locally on the same box (LAMP-style), and managing the bot
as a `systemd` service. No Docker.

> 🎯 **Target capacity**: a `t3.medium` (2 vCPU / 4 GB) handles ~10K users; `t3.large` (2 vCPU / 8 GB) comfortably handles ~50–100K with the defaults.

---

## 0. Provisioning the EC2 instance

1. Launch Ubuntu 22.04 or 24.04 LTS AMI.
2. Instance type: `t3.medium` (start) or `t3.large` (recommended for >10K users).
3. Storage: 30 GB gp3 (logs + DB grow over time).
4. Security group: allow `22/tcp` from your IP, and `80/tcp + 443/tcp` from anywhere **only if you use webhook mode**. Long-poll mode needs **no inbound port** beyond SSH.
5. Allocate an Elastic IP if you'll use webhook mode (you need a stable DNS target).
6. Point your domain (e.g. `news.yourdomain.com`) to the Elastic IP.

SSH in:

```bash
ssh -i ~/.ssh/your-key.pem ubuntu@<elastic-ip>
```

---

## 1. System packages

```bash
sudo apt update && sudo apt upgrade -y

# Python 3.12 (Ubuntu 24.04 ships with 3.12; on 22.04 use deadsnakes)
# Ubuntu 22.04:
sudo add-apt-repository ppa:deadsnakes/ppa -y
sudo apt update
sudo apt install -y python3.12 python3.12-venv python3.12-dev build-essential pkg-config

# MySQL 8
sudo apt install -y mysql-server

# Redis 7
sudo apt install -y redis-server

# Web server (pick ONE; Apache is included for LAMP-purists, nginx is recommended)
sudo apt install -y nginx        # OR: sudo apt install -y apache2
sudo apt install -y certbot python3-certbot-nginx   # OR certbot python3-certbot-apache

# Misc
sudo apt install -y git curl ufw fail2ban
```

Enable firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'      # OR: sudo ufw allow 'Apache Full'
sudo ufw enable
```

---

## 2. MySQL setup

```bash
sudo mysql_secure_installation
```

Create the DB user and import the schema:

```bash
sudo mysql -u root
```

Inside the MySQL shell:

```sql
CREATE USER IF NOT EXISTS 'eagle'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON news_eagle.* TO 'eagle'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Then load the schema (after you've cloned the repo in step 4):

```bash
mysql -u eagle -p < /opt/news-eagle-live/src/database/schema.sql
```

**Tuning** `/etc/mysql/mysql.conf.d/mysqld.cnf` for ~50K users — append:

```ini
[mysqld]
innodb_buffer_pool_size = 2G          # ~50% of RAM
innodb_log_file_size    = 256M
innodb_flush_log_at_trx_commit = 2    # safer perf trade-off
max_connections         = 200
slow_query_log          = 1
slow_query_log_file     = /var/log/mysql/slow.log
long_query_time         = 1
character-set-server    = utf8mb4
collation-server        = utf8mb4_unicode_ci
```

Restart MySQL:

```bash
sudo systemctl restart mysql
```

---

## 3. Redis setup

Edit `/etc/redis/redis.conf`:

```ini
bind 127.0.0.1 ::1
protected-mode yes
maxmemory 512mb
maxmemory-policy allkeys-lru
appendonly yes
```

Restart:

```bash
sudo systemctl restart redis-server
sudo systemctl enable redis-server
redis-cli ping       # → PONG
```

---

## 4. Application user + code

Create a dedicated unprivileged user:

```bash
sudo adduser --system --group --home /opt/news-eagle-live --shell /bin/bash eagle
sudo mkdir -p /opt/news-eagle-live
sudo chown -R eagle:eagle /opt/news-eagle-live
```

Clone the repo (or rsync your local code up):

```bash
sudo -u eagle git clone <your-repo-url> /opt/news-eagle-live
# OR from local machine:
# rsync -avz --exclude '.venv' --exclude 'logs' ./news-eagle-live/ ubuntu@<ip>:/tmp/code/
# sudo rsync -a /tmp/code/ /opt/news-eagle-live/
# sudo chown -R eagle:eagle /opt/news-eagle-live
```

Create the venv and install deps:

```bash
sudo -u eagle bash -c '
cd /opt/news-eagle-live
python3.12 -m venv .venv
.venv/bin/pip install --upgrade pip wheel
.venv/bin/pip install -r requirements.txt
'
```

Create `.env`:

```bash
sudo -u eagle cp /opt/news-eagle-live/.env.example /opt/news-eagle-live/.env
sudo -u eagle nano /opt/news-eagle-live/.env
```

Fill in: `BOT_TOKEN`, `DATABASE_URL` (with the password you set above), `OPENROUTER_API_KEY`,
`ADMIN_IDS`, optionally `NEWSAPI_KEY` / `GNEWS_API_KEY` / `WEATHER_API_KEY`.

Lock the file down:

```bash
sudo chmod 600 /opt/news-eagle-live/.env
sudo chown eagle:eagle /opt/news-eagle-live/.env
```

Smoke test:

```bash
sudo -u eagle bash -c 'cd /opt/news-eagle-live && .venv/bin/python run.py'
```

Open Telegram, message your bot `/start`. If it responds — kill the test run (`Ctrl+C`)
and move on to making it a service.

---

## 5. systemd service

Install the unit:

```bash
sudo cp /opt/news-eagle-live/deployment/news-eagle.service /etc/systemd/system/news-eagle.service
sudo systemctl daemon-reload
sudo systemctl enable news-eagle
sudo systemctl start news-eagle
sudo systemctl status news-eagle
```

Tail logs:

```bash
sudo journalctl -u news-eagle -f
# or
tail -f /opt/news-eagle-live/logs/bot.log
```

Restart on config change:

```bash
sudo systemctl restart news-eagle
```

---

## 6. (Optional) Webhook mode behind nginx + TLS

Long-poll mode works out of the box and needs no public ports. Switch to webhook mode
once you have ≥10K active users for lower latency and lower bandwidth.

Set in `.env`:

```ini
WEBHOOK_URL=https://news.yourdomain.com/telegram/webhook
WEBHOOK_SECRET=a-long-random-string-min-32-chars
WEBHOOK_PORT=8443
```

Install the nginx config:

```bash
sudo cp /opt/news-eagle-live/deployment/nginx.conf.example /etc/nginx/sites-available/news-eagle
sudo ln -s /etc/nginx/sites-available/news-eagle /etc/nginx/sites-enabled/news-eagle
sudo nginx -t && sudo systemctl reload nginx
```

Issue a TLS cert (Telegram requires HTTPS for webhooks):

```bash
sudo certbot --nginx -d news.yourdomain.com
```

Restart the bot to pick up the new config:

```bash
sudo systemctl restart news-eagle
```

You can use Apache instead — see `apache.conf.example`.

---

## 7. Operational checklist

- [ ] **Backups**: `mysqldump news_eagle | gzip > /var/backups/eagle-$(date +%F).sql.gz` on a daily cron, ship to S3.
- [ ] **Log rotation**: bot writes rotated logs into `logs/`; check disk via `df -h /` weekly.
- [ ] **Updates**: `sudo unattended-upgrades` for security patches.
- [ ] **Monitoring**: enable CloudWatch agent or Netdata for CPU/RAM/disk.
- [ ] **Healthcheck**: `curl -s https://news.yourdomain.com/healthz` if you add a health endpoint.
- [ ] **Telegram quotas**: respect 30 msg/sec global and 1 msg/sec per chat. The bot already paces broadcasts.

---

## 8. Common upgrade workflow

```bash
ssh ubuntu@<ip>
cd /opt/news-eagle-live
sudo -u eagle git pull
sudo -u eagle .venv/bin/pip install -r requirements.txt
sudo systemctl restart news-eagle
sudo journalctl -u news-eagle -f
```

For DB migrations, apply schema changes manually for now:

```bash
mysql -u eagle -p news_eagle < migrations/your-change.sql
```

(For a proper migration tool, Alembic is already in `requirements.txt`.)

---

## 9. Troubleshooting

| Symptom                              | Check                                                    |
|--------------------------------------|----------------------------------------------------------|
| Bot starts but doesn't reply         | `journalctl -u news-eagle -n 100` — bad `BOT_TOKEN`?     |
| "Server has gone away" MySQL errors  | `pool_recycle` already set; raise `wait_timeout` in MySQL|
| Redis "Connection refused"           | `sudo systemctl status redis-server` — is it running?    |
| AI timeouts                          | Check `OPENROUTER_API_KEY` quota; bump `AI_REQUEST_TIMEOUT` |
| Webhook 401 from Telegram            | `WEBHOOK_SECRET` mismatch — re-set after `.env` edits    |
| `Forbidden: bot was blocked by user` | Expected. Bot auto-marks them banned in DB.              |

---

You're done. 🦅 *Stay Ahead, Stay Informed.*
