-- Run this ONCE on a database that already exists (XAMPP / MariaDB). Safe to run again (also after an earlier version of this file).
-- It adds Classes (attendance and grades recorded in the system), the per-student class lists, and lets a year exist before it has grades.

ALTER TABLE student_records MODIFY gwa DECIMAL(5,2) NULL, MODIFY attendance_rate DECIMAL(5,2) NULL;
ALTER TABLE subject_records MODIFY grade DECIMAL(5,2) NULL, MODIFY attendance_rate DECIMAL(5,2) NULL DEFAULT NULL;
ALTER TABLE subject_records ADD COLUMN IF NOT EXISTS class_id INT NULL;
DROP TABLE IF EXISTS import_pending;

-- ------------------------------------------------------------
-- CLASSES — attendance and grades recorded in the system itself
-- One class = one subject, taught to one section, in one semester. Its class list is filled in AUTOMATICALLY, student by student:
-- a student whose enrolled subjects are known (from the PEF) is in the class of every subject he or she takes; a student with no
-- subject list follows the classes of his or her section.
-- Attendance is marked every meeting (/ present, A absent = 1, L late = 1/2, E excused = 1/2; 3 or more = Dropped (Unofficially)).
-- Scores are entered per column (quiz, activity, recitation, reporting ...) and per exam in three terms:
-- Prelim 30%, Midterm 30%, Pre-Finals 40% make the FINAL GRADE; each term = class standing 60% + exam 40%.
-- Everything is calculated and copied into subject_records / student_records, so Students, History, Reports and Prediction use it automatically.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_subjects (               -- the subjects a student is enrolled in each semester (from the PEF)
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    academic_year VARCHAR(20) NOT NULL,
    semester TINYINT NOT NULL,
    subject_code VARCHAR(20) DEFAULT NULL,
    subject_name VARCHAR(100) NOT NULL,
    KEY idx_student_subjects (student_id, academic_year, semester),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id VARCHAR(50) DEFAULT NULL,      -- the professor (users.user_id); NULL = not claimed yet, any Faculty member may record
    subject_code VARCHAR(20) DEFAULT NULL,
    subject_name VARCHAR(100) NOT NULL,
    section VARCHAR(20) NOT NULL,                -- BSIT-32
    academic_year VARCHAR(20) NOT NULL,
    semester TINYINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class (subject_name, section, academic_year, semester)
);
CREATE TABLE IF NOT EXISTS class_students (
    class_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    excluded TINYINT(1) NOT NULL DEFAULT 0,      -- 1 = taken out of this class by hand (so the automatic class list does not put the student back)
    PRIMARY KEY (class_id, student_id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS class_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    meeting_date DATE NOT NULL,
    term TINYINT NOT NULL DEFAULT 1,             -- 1 Prelim, 2 Midterm, 3 Pre-Finals (where the meeting is shown; absences add up over the whole semester)
    UNIQUE KEY uq_meeting (class_id, meeting_date),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS class_attendance (
    meeting_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    status CHAR(1) NOT NULL DEFAULT 'P',         -- P present (/), A absent, L late, E excused
    PRIMARY KEY (meeting_id, student_id),
    FOREIGN KEY (meeting_id) REFERENCES class_meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS class_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    term TINYINT NOT NULL,                       -- 1 Prelim, 2 Midterm, 3 Pre-Finals
    component VARCHAR(40) NOT NULL,              -- Quiz, Activity, Recitation, Reporting ... (each professor decides) or 'Exam'
    title VARCHAR(80) NOT NULL,
    items DECIMAL(6,2) NOT NULL,                 -- the highest possible score ("out of 10")
    is_exam TINYINT(1) NOT NULL DEFAULT 0,       -- the term exam (40% of the term); everything else is class standing (60%)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS class_scores (
    activity_id INT NOT NULL,
    student_id VARCHAR(20) NOT NULL,
    score DECIMAL(6,2) NOT NULL,                 -- no row = not entered yet
    PRIMARY KEY (activity_id, student_id),
    FOREIGN KEY (activity_id) REFERENCES class_activities(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS class_weights (                  -- optional: how much each kind of activity counts in the class standing (per professor)
    class_id INT NOT NULL,
    component VARCHAR(40) NOT NULL,
    weight DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (class_id, component),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- columns added after the first version of the Classes tables (do nothing when they are already there)
ALTER TABLE classes ADD COLUMN IF NOT EXISTS subject_code VARCHAR(20) NULL AFTER owner_user_id;
ALTER TABLE class_students ADD COLUMN IF NOT EXISTS excluded TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE class_meetings ADD COLUMN IF NOT EXISTS term TINYINT NOT NULL DEFAULT 1;
ALTER TABLE class_attendance ADD COLUMN IF NOT EXISTS status CHAR(1) NOT NULL DEFAULT 'P';
ALTER TABLE class_attendance ADD COLUMN IF NOT EXISTS present TINYINT(1) NOT NULL DEFAULT 1;
UPDATE class_attendance SET status = IF(present = 1, 'P', 'A') WHERE status = 'P' AND present = 0;

-- three roles: Admin, Program Head (views everything, changes nothing) and Faculty (professors: only their assigned classes)
ALTER TABLE users MODIFY role ENUM('faculty','program_head','admin') NOT NULL DEFAULT 'faculty';

-- confirmation codes sent by e-mail (forgot password / changing a password or PIN)
CREATE TABLE IF NOT EXISTS email_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    tries TINYINT NOT NULL DEFAULT 0,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_codes (user_id, purpose)
);

-- a class cleared by the Program Head (grades complete) is archived: view only
ALTER TABLE classes ADD COLUMN IF NOT EXISTS cleared_at DATETIME NULL;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS cleared_by VARCHAR(50) NULL;
