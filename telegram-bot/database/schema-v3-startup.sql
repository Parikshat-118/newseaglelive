CREATE TABLE daily_startup_generations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generation_id BIGINT NOT NULL,
    generation_date DATETIME NOT NULL,
    language ENUM('en', 'hi') DEFAULT 'en',
    search_tags TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_startup_date_lang UNIQUE (generation_date, language),
    CONSTRAINT fk_startup_generation FOREIGN KEY (generation_id) REFERENCES ai_generations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_startup_generation_date ON daily_startup_generations(generation_date);

CREATE TABLE startup_ideas (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    generation_id BIGINT NOT NULL,
    title VARCHAR(256) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(128),
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_startup_ideas_gen FOREIGN KEY (generation_id) REFERENCES daily_startup_generations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_startup_ideas_gen_id ON startup_ideas(generation_id);
