# AWS EC2 Production Server Deployment

This document explains the full architecture and deployment process for hosting the **News Eagle Live** platform on an Amazon Web Services (AWS) EC2 server.

## 🖥 Server Architecture
The entire application (both the PHP frontend and the Python Telegram bot) is designed to run efficiently on a single AWS EC2 Ubuntu instance (LAMP stack). It uses a **Shared Database Pattern** where the Python backend and PHP frontend talk to the exact same MySQL database instance.

### Server Specifications
- **Instance Type**: `t3.medium` or `t3.large` (depending on traffic, minimum 2 vCPU / 4GB RAM is recommended).
- **OS**: Ubuntu 22.04 LTS or 24.04 LTS.
- **Web Server**: Apache2 (or Nginx).
- **PHP Version**: PHP 8.1+ with `php-mysql` and `php-curl` extensions.
- **Database**: MySQL 8.0.
- **Cache / Rate-limiting**: Redis 7.

---

## 🚀 Deployment Guide

### 1. Initial Server Provisioning
When the EC2 instance is launched, SSH into the machine as the `ubuntu` user and install the core LAMP stack:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 mysql-server php libapache2-mod-php php-mysql php-curl php-mbstring redis-server python3-venv python3-pip unzip
```

### 2. Configure MySQL Database
1. Secure the MySQL installation: `sudo mysql_secure_installation`
2. Log in to MySQL as root: `sudo mysql`
3. Create the database and user:
   ```sql
   CREATE DATABASE news_eagle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'newseagle'@'localhost' IDENTIFIED BY 'StrongPassword123!';
   GRANT ALL PRIVILEGES ON news_eagle.* TO 'newseagle'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. Import the schema (`database/news_eagle.sql`) using `mysql -u newseagle -p news_eagle < news_eagle.sql`.

### 3. Deploy the PHP Web Frontend
1. The web root for Apache on Ubuntu is `/var/www/html/`.
2. Clear the default Apache page: `sudo rm /var/www/html/index.html`
3. Upload the contents of the `frontend/` directory directly into `/var/www/html/`.
4. Ensure Apache owns the files so it can serve them:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/
   ```
5. Update `/var/www/html/includes/config.php` with the live MySQL credentials and OpenRouter API keys.

### 4. Deploy the Python Telegram Bot
Unlike the web app, the bot runs as a continuous background process.
1. Move the `telegram-bot/` directory to a safe location, like `/opt/news-eagle-bot/`.
2. Set up the Python virtual environment:
   ```bash
   cd /opt/news-eagle-bot
   python3 -m venv .venv
   source .venv/bin/activate
   pip install -r requirements.txt
   ```
3. Create the `.env` file from `.env.example` and fill in the live database credentials and Telegram Bot Token.
4. Run the bot using `systemd` to ensure it stays alive and restarts on server reboots. Create a file `/etc/systemd/system/newseagle.service`:
   ```ini
   [Unit]
   Description=News Eagle Telegram Bot
   After=network.target mysql.service redis.service

   [Service]
   User=ubuntu
   WorkingDirectory=/opt/news-eagle-bot
   Environment="PATH=/opt/news-eagle-bot/.venv/bin"
   ExecStart=/opt/news-eagle-bot/.venv/bin/python run.py
   Restart=always
   RestartSec=5

   [Install]
   WantedBy=multi-user.target
   ```
5. Enable and start the bot:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable newseagle
   sudo systemctl start newseagle
   ```

### 5. Final Domain & SSL Setup
- Point your domain's A-record in AWS Route53 (or Cloudflare) to the Elastic IP of your EC2 instance.
- Run `sudo certbot --apache -d yourdomain.com` to install a free Let's Encrypt SSL certificate and secure your frontend traffic with HTTPS.

---

## 🛠 Maintenance & Updates
Whenever new code is written (like the Global Live Viewers or AI Audio Player):
1. **Frontend**: Use `scp` or `rsync` to push updated `.php` files straight into `/var/www/html/`. Changes take effect instantly.
2. **Backend**: Push updated `.py` files to `/opt/news-eagle-bot/` and run `sudo systemctl restart newseagle` to restart the bot.
