-- For daily_quizzes (skip dropping the index if it was already dropped)
ALTER TABLE `daily_quizzes` ADD COLUMN `language` ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER `quiz_date`;
ALTER TABLE `daily_quizzes` ADD UNIQUE KEY `uq_exam_date_lang` (`exam_type`, `quiz_date`, `language`);

-- For daily_editorials
ALTER TABLE `daily_editorials` ADD COLUMN `language` ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER `editorial_date`;
ALTER TABLE `daily_editorials` DROP INDEX `uq_editorial_date`;
ALTER TABLE `daily_editorials` ADD UNIQUE KEY `uq_editorial_date_lang` (`editorial_date`, `language`);

-- For daily_mains_questions
ALTER TABLE `daily_mains_questions` ADD COLUMN `language` ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER `mains_date`;
ALTER TABLE `daily_mains_questions` ADD UNIQUE KEY `uq_mains_date_lang_order` (`mains_date`, `language`, `order_no`);
