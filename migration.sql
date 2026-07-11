-- Database Migration: News Eagle Live — Student Hub Hindi Support

-- 1. Update ai_generations
ALTER TABLE ai_generations 
ADD COLUMN language ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER content_date;
ALTER TABLE ai_generations DROP INDEX uq_gen_type_date;
ALTER TABLE ai_generations ADD UNIQUE KEY uq_gen_type_date_lang (content_type, content_date, language);

-- 2. Update daily_quizzes
ALTER TABLE daily_quizzes
ADD COLUMN language ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER quiz_date;
ALTER TABLE daily_quizzes DROP INDEX uq_quiz_exam_date;
ALTER TABLE daily_quizzes ADD UNIQUE KEY uq_quiz_exam_date_lang (exam_type, quiz_date, language);

-- 3. Update daily_editorials
ALTER TABLE daily_editorials
ADD COLUMN language ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER editorial_date;
ALTER TABLE daily_editorials DROP INDEX uq_editorial_date;
ALTER TABLE daily_editorials ADD UNIQUE KEY uq_editorial_date_lang (editorial_date, language);

-- 4. Update daily_mains_questions
ALTER TABLE daily_mains_questions
ADD COLUMN language ENUM('en','hi') NOT NULL DEFAULT 'en' AFTER mains_date;
-- Dropping potential old constraints (if any existed, otherwise this can be skipped)
-- ALTER TABLE daily_mains_questions DROP INDEX uq_mains_date;
ALTER TABLE daily_mains_questions ADD UNIQUE KEY uq_mains_date_lang_order (mains_date, language, order_no);
