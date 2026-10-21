-- Run this ONCE on a database that already exists (XAMPP / MariaDB). Safe to run again.
-- 1) User Management: who created / last updated each account, and when.
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_by VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_by VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;
-- 2) The Admin adds SUBJECTS; the Program Head makes classes from them (subject code, section, professor).
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(100) NOT NULL UNIQUE,
    created_by VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- the subjects that already exist in the classes and in the students' records become the starting list
INSERT IGNORE INTO subjects (subject_name) SELECT DISTINCT subject_name FROM classes WHERE subject_name <> '';
INSERT IGNORE INTO subjects (subject_name) SELECT DISTINCT subject_name FROM subject_records WHERE subject_name <> '';
