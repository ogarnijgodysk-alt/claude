-- Platforma kursów: schemat bazy (MySQL 5.7+ / MariaDB 10.3+), utf8mb4
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  first_name VARCHAR(120) NOT NULL DEFAULT '',
  last_name VARCHAR(160) NOT NULL DEFAULT '',
  full_name VARCHAR(300) NOT NULL DEFAULT '',
  role ENUM('admin','client') NOT NULL DEFAULT 'client',
  password_hash VARCHAR(255) NULL,
  legacy_hash VARCHAR(255) NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  publigo_id VARCHAR(40) NULL,
  publigo_role VARCHAR(60) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_email (email),
  KEY k_publigo (publigo_id),
  KEY k_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(300) NOT NULL,
  short_title VARCHAR(80) NOT NULL DEFAULT '',
  description TEXT NULL,
  access_amount SMALLINT UNSIGNED NULL,
  access_unit ENUM('months','years') NOT NULL DEFAULT 'months',
  sort_order INT NOT NULL DEFAULT 0,
  publigo_course_id VARCHAR(40) NULL,
  publigo_product_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_pub (publigo_course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  title VARCHAR(300) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  publigo_id VARCHAR(40) NULL,
  KEY k_course (course_id, sort_order),
  CONSTRAINT fk_mod_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_id INT UNSIGNED NOT NULL,
  title VARCHAR(300) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  publigo_id VARCHAR(40) NULL,
  KEY k_module (module_id, sort_order),
  KEY k_pub (publigo_id),
  CONSTRAINT fk_les_mod FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- type: text | vimeo | youtube | image | file | quiz | embed
-- content: html (text) / url (video, embed); data: JSON (alt, caption, media_id, items, quiz_id ...)
CREATE TABLE IF NOT EXISTS blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  content MEDIUMTEXT NULL,
  data MEDIUMTEXT NULL,
  KEY k_lesson (lesson_id, sort_order),
  KEY k_type (type),
  CONSTRAINT fk_blk_les FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_name VARCHAR(300) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  ext VARCHAR(10) NOT NULL,
  size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NULL,
  source_url VARCHAR(700) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_stored (stored_name),
  KEY k_src (source_url(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quizzes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(300) NOT NULL,
  pass_percent TINYINT UNSIGNED NOT NULL DEFAULT 70,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  publigo_quiz_id VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_pub (publigo_quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- type: single | multi | text | file
CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT UNSIGNED NOT NULL,
  type VARCHAR(10) NOT NULL,
  text TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY k_quiz (quiz_id, sort_order),
  CONSTRAINT fk_qq_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_answers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question_id INT UNSIGNED NOT NULL,
  text TEXT NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  KEY k_q (question_id, sort_order),
  CONSTRAINT fk_qa_q FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  score_percent TINYINT UNSIGNED NULL,
  passed TINYINT(1) NOT NULL DEFAULT 0,
  needs_review TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_at DATETIME NULL,
  answers MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  KEY k_user_quiz (user_id, quiz_id),
  KEY k_review (needs_review, reviewed_at),
  CONSTRAINT fk_att_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  expires_at DATE NULL,
  legacy_progress TINYINT UNSIGNED NULL,
  legacy_done SMALLINT UNSIGNED NULL,
  legacy_total SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_user_course (user_id, course_id),
  KEY k_course (course_id),
  KEY k_exp (expires_at),
  CONSTRAINT fk_en_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_en_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_progress (
  user_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  completed_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, lesson_id),
  CONSTRAINT fk_lp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lp_les FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY u_token (token_hash),
  KEY k_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(40) NOT NULL,
  subject CHAR(64) NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  KEY k_lookup (bucket, subject, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(60) NOT NULL,
  user_id INT UNSIGNED NULL,
  email VARCHAR(190) NULL,
  ip VARCHAR(45) NULL,
  detail VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY k_ev (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  to_email VARCHAR(190) NOT NULL,
  kind VARCHAR(30) NOT NULL,
  subject VARCHAR(300) NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  body_html MEDIUMTEXT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  send_after DATETIME NOT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY k_status (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zadania w tle (import klientów CSV/XLSX, migracja Publigo)
CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(30) NOT NULL,
  status ENUM('new','running','done','failed') NOT NULL DEFAULT 'new',
  payload MEDIUMTEXT NULL,
  cursor_pos INT UNSIGNED NOT NULL DEFAULT 0,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  result MEDIUMTEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY k_status (status, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Przekierowania 301 ze starych adresów Publigo
CREATE TABLE IF NOT EXISTS redirects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  old_path VARCHAR(400) NOT NULL,
  target_type ENUM('course','lesson') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  UNIQUE KEY u_path (old_path(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
