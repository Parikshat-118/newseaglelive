-- =====================================================================
-- News Eagle Live — COMPLETE SCHEMA (base + v2 in one file)
-- For fresh database installs. Import this single file in phpMyAdmin.
-- Charset: utf8mb4 / Engine: InnoDB
-- =====================================================================

USE `news_eagle`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             BIGINT       NOT NULL AUTO_INCREMENT,
  `telegram_id`    BIGINT       NOT NULL,
  `username`       VARCHAR(64)  NULL,
  `first_name`     VARCHAR(128) NULL,
  `last_name`      VARCHAR(128) NULL,
  `language`       ENUM('en','hi') NOT NULL DEFAULT 'en',
  `is_premium`     TINYINT(1)   NOT NULL DEFAULT 0,
  `is_banned`      TINYINT(1)   NOT NULL DEFAULT 0,
  `referred_by`    BIGINT       NULL,

  -- v2: web integration
  `mobile_number`   VARCHAR(15)  NULL,
  `mobile_verified` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_admin`        TINYINT(1)   NOT NULL DEFAULT 0,
  `display_name`    VARCHAR(128) NULL,

  `pincode`        VARCHAR(10)  NULL,
  `city`           VARCHAR(128) NULL,
  `district`       VARCHAR(128) NULL,
  `state`          VARCHAR(128) NULL,
  `notifications_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `breaking_alerts`       TINYINT(1) NOT NULL DEFAULT 1,
  `morning_digest`        TINYINT(1) NOT NULL DEFAULT 1,
  `evening_digest`        TINYINT(1) NOT NULL DEFAULT 1,
  `weather_alerts`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_active_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_telegram_id` (`telegram_id`),
  UNIQUE KEY `uq_users_mobile`      (`mobile_number`),
  KEY `ix_users_language`        (`language`),
  KEY `ix_users_pincode`         (`pincode`),
  KEY `ix_users_district`        (`district`),
  KEY `ix_users_state`           (`state`),
  KEY `ix_users_is_admin`        (`is_admin`),
  KEY `ix_users_referred_by`     (`referred_by`),
  KEY `ix_users_last_active_at`  (`last_active_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. user_subscriptions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT   NOT NULL,
  `category`   VARCHAR(32) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_category` (`user_id`,`category`),
  KEY `ix_subs_category` (`category`),
  CONSTRAINT `fk_subs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 3. keywords
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `keywords` (
  `id`         BIGINT       NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT       NOT NULL,
  `keyword`    VARCHAR(128) NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_keyword` (`user_id`,`keyword`),
  KEY `ix_keywords_kw` (`keyword`),
  CONSTRAINT `fk_kw_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 4. news_sources
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news_sources` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(128) NOT NULL,
  `kind`       ENUM('rss','newsapi','gnews') NOT NULL,
  `url`        VARCHAR(1024) NOT NULL,
  `category`   VARCHAR(32)  NULL,
  `language`   ENUM('en','hi') NOT NULL DEFAULT 'en',
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `last_fetched_at` DATETIME NULL,
  `last_error`      VARCHAR(512) NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sources_active` (`is_active`),
  KEY `ix_sources_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 5. news_articles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news_articles` (
  `id`             BIGINT       NOT NULL AUTO_INCREMENT,
  `url_hash`       CHAR(64)     NOT NULL,
  `url`            VARCHAR(1024) NOT NULL,
  `title`          VARCHAR(512) NOT NULL,
  `summary`        TEXT         NULL,
  `ai_summary`     TEXT         NULL,
  `ai_summary_hi`  TEXT         NULL,
  `content`        MEDIUMTEXT   NULL,
  `image_url`      VARCHAR(1024) NULL,
  `category`       VARCHAR(32)  NULL,
  `language`       ENUM('en','hi') NOT NULL DEFAULT 'en',
  `source_id`      INT          NULL,
  `source_name`    VARCHAR(128) NULL,
  `is_breaking`    TINYINT(1)   NOT NULL DEFAULT 0,
  `trending_score` INT          NOT NULL DEFAULT 0,
  `search_tags`    TEXT         NULL,

  -- v2: counters
  `view_count`     INT          NOT NULL DEFAULT 0,
  `like_count`     INT          NOT NULL DEFAULT 0,
  `share_count`    INT          NOT NULL DEFAULT 0,

  `published_at`   DATETIME     NULL,
  `fetched_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_articles_url_hash` (`url_hash`),
  KEY `ix_articles_category_pub` (`category`,`published_at`),
  KEY `ix_articles_breaking`     (`is_breaking`,`published_at`),
  KEY `ix_articles_trending`     (`trending_score`),
  KEY `ix_articles_language`     (`language`),
  KEY `ix_articles_views`        (`view_count`),
  KEY `ix_articles_likes`        (`like_count`),
  FULLTEXT KEY `ft_articles_title_summary` (`title`,`summary`),
  CONSTRAINT `fk_articles_source` FOREIGN KEY (`source_id`) REFERENCES `news_sources`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 6. bookmarks
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bookmarks` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT   NOT NULL,
  `article_id` BIGINT   NOT NULL,
  `note`       VARCHAR(512) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookmark` (`user_id`,`article_id`),
  KEY `ix_bookmarks_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_bm_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_bm_article` FOREIGN KEY (`article_id`) REFERENCES `news_articles`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 7. reading_history
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reading_history` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT   NOT NULL,
  `article_id` BIGINT   NOT NULL,
  `viewed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_history_user_time` (`user_id`,`viewed_at`),
  CONSTRAINT `fk_hist_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_hist_article` FOREIGN KEY (`article_id`) REFERENCES `news_articles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 8. notifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           BIGINT     NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT     NOT NULL,
  `kind`         ENUM('breaking','keyword','digest','quiz','broadcast','weather') NOT NULL,
  `article_id`   BIGINT     NULL,
  `payload`      JSON       NULL,
  `delivered`    TINYINT(1) NOT NULL DEFAULT 0,
  `delivered_at` DATETIME   NULL,
  `created_at`   DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_notif_user_kind` (`user_id`,`kind`),
  KEY `ix_notif_delivered` (`delivered`),
  CONSTRAINT `fk_notif_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_notif_article` FOREIGN KEY (`article_id`) REFERENCES `news_articles`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 9. quizzes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quizzes` (
  `id`         BIGINT       NOT NULL AUTO_INCREMENT,
  `topic`      VARCHAR(128) NOT NULL,
  `cadence`    ENUM('daily','weekly') NOT NULL DEFAULT 'daily',
  `language`   ENUM('en','hi') NOT NULL DEFAULT 'en',
  `questions`  JSON         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_quiz_cadence_lang` (`cadence`,`language`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 10. quiz_results
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz_results` (
  `id`       BIGINT   NOT NULL AUTO_INCREMENT,
  `user_id`  BIGINT   NOT NULL,
  `quiz_id`  BIGINT   NOT NULL,
  `score`    INT      NOT NULL DEFAULT 0,
  `total`    INT      NOT NULL DEFAULT 0,
  `answers`  JSON     NULL,
  `taken_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_qr_user` (`user_id`,`taken_at`),
  KEY `ix_qr_quiz` (`quiz_id`,`score`),
  CONSTRAINT `fk_qr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_qr_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 11. referrals
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referrals` (
  `id`           BIGINT   NOT NULL AUTO_INCREMENT,
  `referrer_id`  BIGINT   NOT NULL,
  `referred_id`  BIGINT   NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referrer_referred` (`referrer_id`,`referred_id`),
  CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 12. premium_users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `premium_users` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT   NOT NULL,
  `plan`       VARCHAR(32) NOT NULL DEFAULT 'monthly',
  `starts_at`  DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_premium_active` (`user_id`,`is_active`),
  CONSTRAINT `fk_prem_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 13. chat_sessions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_sessions` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT   NOT NULL,
  `messages`   JSON     NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_user` (`user_id`),
  CONSTRAINT `fk_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 14. pincode_directory
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pincode_directory` (
  `pincode`  VARCHAR(10)  NOT NULL,
  `city`     VARCHAR(128) NOT NULL,
  `district` VARCHAR(128) NOT NULL,
  `state`    VARCHAR(128) NOT NULL,
  `lat`      DECIMAL(9,6) NULL,
  `lng`      DECIMAL(9,6) NULL,
  PRIMARY KEY (`pincode`),
  KEY `ix_pin_state` (`state`),
  KEY `ix_pin_district` (`district`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- v2 TABLES — web app integration
-- =====================================================================

-- ---------------------------------------------------------------------
-- 15. web_auth_tokens — Telegram-OTP login flow
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `web_auth_tokens` (
  `id`            BIGINT      NOT NULL AUTO_INCREMENT,
  `telegram_id`   BIGINT      NOT NULL,
  `mobile_number` VARCHAR(15) NOT NULL,
  `code`          VARCHAR(8)  NOT NULL,
  `expires_at`    DATETIME    NOT NULL,
  `used`          TINYINT(1)  NOT NULL DEFAULT 0,
  `attempts`      INT         NOT NULL DEFAULT 0,
  `ip`            VARCHAR(64) NULL,
  `created_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_wat_mobile_used` (`mobile_number`, `used`),
  KEY `ix_wat_expires`     (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 16. web_sessions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `web_sessions` (
  `id`           BIGINT       NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT       NOT NULL,
  `token`        CHAR(64)     NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `ip`           VARCHAR(64)  NULL,
  `user_agent`   VARCHAR(256) NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_token` (`token`),
  KEY `ix_session_user`    (`user_id`),
  KEY `ix_session_expires` (`expires_at`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 17. article_views
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_views` (
  `id`         BIGINT      NOT NULL AUTO_INCREMENT,
  `article_id` BIGINT      NOT NULL,
  `user_id`    BIGINT      NULL,
  `ip_hash`    CHAR(64)    NULL,
  `viewed_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_views_article` (`article_id`,`viewed_at`),
  KEY `ix_views_user`    (`user_id`),
  CONSTRAINT `fk_views_article` FOREIGN KEY (`article_id`) REFERENCES `news_articles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_views_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 18. article_likes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_likes` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `article_id` BIGINT   NOT NULL,
  `user_id`    BIGINT   NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_like` (`article_id`,`user_id`),
  KEY `ix_likes_user` (`user_id`),
  CONSTRAINT `fk_like_article` FOREIGN KEY (`article_id`) REFERENCES `news_articles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_like_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 19. article_shares
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `article_shares` (
  `id`         BIGINT   NOT NULL AUTO_INCREMENT,
  `article_id` BIGINT   NOT NULL,
  `user_id`    BIGINT   NULL,
  `platform`   ENUM('whatsapp','telegram','copy','x','facebook','other') NOT NULL DEFAULT 'other',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_shares_article` (`article_id`),
  CONSTRAINT `fk_shares_article` FOREIGN KEY (`article_id`) REFERENCES `news_articles`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shares_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 20. feedback
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `feedback` (
  `id`            BIGINT        NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT        NULL,
  `display_name`  VARCHAR(128)  NOT NULL,
  `mobile_number` VARCHAR(15)   NULL,
  `rating`        TINYINT       NOT NULL DEFAULT 5,
  `message`       VARCHAR(1024) NOT NULL,
  `is_approved`   TINYINT(1)    NOT NULL DEFAULT 0,
  `approved_by`   BIGINT        NULL,
  `approved_at`   DATETIME      NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_fb_approved` (`is_approved`,`created_at`),
  CONSTRAINT `fk_fb_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fb_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 21. severe_alerts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `severe_alerts` (
  `id`              BIGINT       NOT NULL AUTO_INCREMENT,
  `kind`            ENUM('weather','disease','disaster','security','other') NOT NULL DEFAULT 'other',
  `severity`        TINYINT      NOT NULL DEFAULT 3,
  `headline`        VARCHAR(256) NOT NULL,
  `summary_en`      TEXT         NOT NULL,
  `summary_hi`      TEXT         NULL,
  `regions`         JSON         NULL,
  `source_articles` JSON         NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `pushed_count`    INT          NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_alerts_active_severity` (`is_active`,`severity`,`created_at`),
  KEY `ix_alerts_expires`         (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 22. alert_deliveries
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alert_deliveries` (
  `id`       BIGINT   NOT NULL AUTO_INCREMENT,
  `alert_id` BIGINT   NOT NULL,
  `user_id`  BIGINT   NOT NULL,
  `sent_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert_user` (`alert_id`,`user_id`),
  CONSTRAINT `fk_ad_alert` FOREIGN KEY (`alert_id`) REFERENCES `severe_alerts`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
-- Seed: super-admin row (mobile 9540739137)
-- =====================================================================
-- (No placeholder row needed: the bot's /setmobile handler auto-promotes
--  mobile 9540739137 to admin when that user links their number.)
UPDATE `users` SET is_admin = 1, mobile_verified = 1 WHERE mobile_number = '9540739137';


-- =====================================================================
-- Helpful views
-- =====================================================================
CREATE OR REPLACE VIEW `v_top_liked_24h` AS
  SELECT a.id, a.title, a.url, a.image_url, a.category, a.like_count, a.view_count
  FROM news_articles a
  WHERE a.published_at >= (NOW() - INTERVAL 24 HOUR)
  ORDER BY a.like_count DESC, a.view_count DESC
  LIMIT 50;

CREATE OR REPLACE VIEW `v_active_alerts` AS
  SELECT *
  FROM severe_alerts
  WHERE is_active = 1
    AND (expires_at IS NULL OR expires_at > NOW())
  ORDER BY severity DESC, created_at DESC;
