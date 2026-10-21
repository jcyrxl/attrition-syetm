-- Run this ONCE on a database that already exists (XAMPP / MariaDB). Safe to run again.
-- Requests: the Program Head records Leave of Absence and Honorable Dismissal (a separate form page) and classifies a student as Enrolled or
-- Shifted (Students page); the professor records Dropped (Unofficially). A professor's drop reaches the Program Head (Reports, Requests, and the bell).
ALTER TABLE student_cases ADD COLUMN IF NOT EXISTS recorded_by_user VARCHAR(50) NULL;
ALTER TABLE notifications MODIFY prediction_id INT NULL;
ALTER TABLE notifications MODIFY probability DECIMAL(5,2) NOT NULL DEFAULT 0;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS kind VARCHAR(10) NOT NULL DEFAULT 'risk';
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS message VARCHAR(255) NULL;
