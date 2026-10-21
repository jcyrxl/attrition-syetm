-- ============================================================
-- Web-Based Attrition System With Data Analytics
-- College of Computing Studies - Universidad de Manila
-- Database Schema (v3 — historical per-year records + raw percentage grades)
-- ============================================================

CREATE DATABASE IF NOT EXISTS ccs_attrition_system;
USE ccs_attrition_system;

-- ------------------------------------------------------------
-- USERS (Registrar / Program Head logins)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,                -- editable by Admin only (users.php)
    email VARCHAR(150) DEFAULT NULL,                 -- editable by the user themself (profile.php)
    contact_number VARCHAR(20) DEFAULT NULL,         -- editable by the user themself (profile.php)
    role ENUM('faculty','program_head','admin') NOT NULL DEFAULT 'faculty',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0, -- forced after an Admin-triggered reset
    student_data_pin_hash VARCHAR(255) DEFAULT NULL,     -- Faculty member's own 6-digit PIN, required before editing student data
    action_password_hash VARCHAR(255) DEFAULT NULL,      -- Admin's Account-Management Password (different from the login password)
    profile_picture VARCHAR(100) DEFAULT NULL,           -- file name in uploads/avatars/ (NULL = show first letter of name)
    verify_fail_count TINYINT NOT NULL DEFAULT 0,        -- wrong PIN/password tries in the current lockout stage
    verify_stage TINYINT NOT NULL DEFAULT 0,             -- 0: 6 tries -> 15 min, 1: 3 tries -> 30 min, 2: 3 tries -> 24 h + logout
    verify_locked_until DATETIME DEFAULT NULL,           -- while in the future, PIN/password confirmations are refused
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(50) DEFAULT NULL,                 -- users.user_id of the Admin who created the account (NULL = created by the setup page)
    updated_by VARCHAR(50) DEFAULT NULL,                 -- users.user_id of the Admin who last edited or reset it
    updated_at DATETIME DEFAULT NULL
);
-- No default user is inserted here on purpose (a hand-typed bcrypt hash
-- can be wrong). After importing this file, open create_admin.php once in
-- your browser to generate accounts safely, then delete create_admin.php.
-- Create the Admin account with create_admin.php, then create Faculty accounts from User Management.

-- ------------------------------------------------------------
-- ACADEMIC YEARS / DATASETS uploaded by Registrar
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS datasets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(20) NOT NULL,
    uploaded_by VARCHAR(150) NOT NULL,
    record_count INT DEFAULT 0,
    status ENUM('pending','validated','error') DEFAULT 'pending',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- STUDENTS (core / current info only — one row per student)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    program VARCHAR(20) NOT NULL DEFAULT 'BSIT',
    gender ENUM('Male','Female','Other') DEFAULT 'Other',
    age TINYINT DEFAULT NULL,
    year_level TINYINT NOT NULL DEFAULT 1,       -- current/highest year level reached (1-4)
    academic_year VARCHAR(20) NOT NULL,           -- current academic year, e.g. 2025-2026
    status ENUM('enrolled','loa','hd','shifted','dropped','graduated') DEFAULT 'enrolled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- STATUS REQUESTS — the paper forms, online: Leave of Absence, Honorable Dismissal,
-- Dropped (Unofficial) and Shifted. Filed on the Status Records page by the Registrar.
-- A typed "Others" reason keeps exactly what the student wrote (reason_other).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    case_type ENUM('loa','hd','du','shifted') NOT NULL,   -- LOA / Honorable Dismissal / Dropped (Unofficial) / Shifted
    academic_year VARCHAR(20) NOT NULL,
    semester VARCHAR(20) NOT NULL,                        -- '1st Semester' / '2nd Semester' / 'Whole Year'
    reason VARCHAR(80) NOT NULL,                          -- one of the listed reasons, or 'Others'
    reason_other VARCHAR(255) DEFAULT NULL,               -- what the student wrote when 'Others' is chosen
    details VARCHAR(500) DEFAULT NULL,                    -- optional remarks
    shifted_to VARCHAR(100) DEFAULT NULL,                 -- only for Shifted
    filed_on DATE NOT NULL,                               -- date of the request
    recorded_by VARCHAR(150) DEFAULT NULL,                -- name of the Program Head (LOA, HD) or of the professor (DU) who recorded it
    recorded_by_user VARCHAR(50) DEFAULT NULL,            -- users.user_id of the one who recorded it (a professor may undo only his or her own DU)
    returned_on DATE DEFAULT NULL,                        -- set when a student on LOA / DU is re-enrolled
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_case (student_id, case_type, academic_year, semester),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- STUDENT RECORDS (historical, one row PER YEAR LEVEL per student)
-- Lets a 3rd year student still have Year 1 and Year 2 data on file.
-- Grade scale: raw numeric grades, 0-100 (e.g. 87, 89) — not a converted GPA scale.
-- gwa / attendance_rate / units_failed here are now AUTO-COMPUTED by
-- students.php from that year's subject_records rows (see below) —
-- the Registrar enters grades per subject, not a single GWA number.
-- section is entered per year too, since a student's section can change
-- every year (e.g. BSIT-11 in Year 1, BSIT-22 in Year 2).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    year_level TINYINT NOT NULL,                  -- 1, 2, 3, or 4
    academic_year VARCHAR(20) NOT NULL,            -- the AY that year level was taken
    section VARCHAR(20) DEFAULT NULL,              -- e.g. "BSIT-11" — can differ every year
    gwa DECIMAL(5,2) DEFAULT NULL,                 -- 0-100 (100 = highest) — average of that year's subjects; NULL = enrolled, no grades yet
    attendance_rate DECIMAL(5,2) DEFAULT NULL,     -- percentage 0-100 — average of that year's subjects (NULL = no grades yet)
    units_failed TINYINT DEFAULT 0,                -- count of that year's subjects below the failing threshold
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_year (student_id, year_level),
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- SUBJECT RECORDS (one row PER SUBJECT, per year level, per student)
-- This is the actual data the Registrar enters. Subjects can be freely
-- added or removed per year, so the system can show exactly which
-- subject a student is struggling in — not just an overall average —
-- which is what actually explains WHY someone is flagged at-risk.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subject_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    year_level TINYINT NOT NULL,
    semester TINYINT NOT NULL DEFAULT 1,           -- 1 or 2 (two semesters per year)
    subject_name VARCHAR(100) NOT NULL,
    grade DECIMAL(5,2) DEFAULT NULL,               -- raw grade, 0-100 (e.g. 87, 89), same scale as GWA (NULL = attendance recorded, no grade yet)
    attendance_rate DECIMAL(5,2) DEFAULT NULL,
    class_id INT DEFAULT NULL,                     -- set when the row is calculated from a class in Classes (grades + attendance recorded in the system)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);
CREATE INDEX idx_subject_records_student_year ON subject_records (student_id, year_level, semester);

-- ------------------------------------------------------------
-- PREDICTIONS (output of the Logistic Regression model)
-- ------------------------------------------------------------
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
-- The subjects of the curriculum. The ADMIN adds a subject (its name only). The PROGRAM HEAD then makes a class from it: gives it a subject
-- code, chooses the section and assigns the professor (Class Assignments).
CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(100) NOT NULL UNIQUE,
    created_by VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id VARCHAR(50) DEFAULT NULL,      -- the professor (users.user_id); NULL = not claimed yet, any Faculty member may record
    subject_code VARCHAR(20) DEFAULT NULL,
    subject_name VARCHAR(100) NOT NULL,
    section VARCHAR(20) NOT NULL,                -- BSIT-32
    academic_year VARCHAR(20) NOT NULL,
    semester TINYINT NOT NULL,
    cleared_at DATETIME DEFAULT NULL,            -- the Program Head cleared (archived) this class: its grades are complete and final
    cleared_by VARCHAR(50) DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS predictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    probability DECIMAL(5,2) NOT NULL,      -- 0-100
    risk_level ENUM('Low','Moderate','High') NOT NULL,
    model_version VARCHAR(20) DEFAULT 'v1.0',
    predicted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- NOTIFICATIONS (one row per High-risk prediction event)
-- Created automatically by config/predict.php whenever a student is scored
-- High risk. Marked read the moment someone clicks it in the bell dropdown,
-- so it disappears from the unread list — and clicking it opens that
-- student's Subject Breakdown on the Students page.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL,
    prediction_id INT NULL,                           -- NULL for a 'du' notice (a professor marked the student Dropped (Unofficially))
    probability DECIMAL(5,2) NOT NULL DEFAULT 0,
    kind VARCHAR(10) NOT NULL DEFAULT 'risk',         -- 'risk' (flagged High Risk) or 'du' (for the Program Head)
    message VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (prediction_id) REFERENCES predictions(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- MODEL SETTINGS (coefficients + accuracy, updated by python/train_model.py)
-- Calibrated for RAW grades on a 0-100 scale (100 = highest):
-- higher grade => lower risk, so coef_gwa is NEGATIVE.
-- coef_trend applies to (latest_gwa - earliest_gwa on record): an improving
-- trend lowers risk, so it is also negative. These are heuristic starting
-- values — replace them by running python/train_model.py on real historical
-- data once enough of it exists.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
-- Starting password for new / reset accounts. Admin can change it in Privacy Settings.
INSERT INTO system_settings (setting_key, setting_value) VALUES ('default_password', 'Default!2026')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

CREATE TABLE IF NOT EXISTS model_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO model_settings (setting_key, setting_value) VALUES
    ('intercept', '12.50'),
    ('coef_gwa', '-0.12'),
    ('coef_attendance', '-0.05'),
    ('coef_units_failed', '0.60'),
    ('coef_trend', '-0.04'),
    ('accuracy', '91.0')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ------------------------------------------------------------
-- Sample data (BSIT only). Grades are RAW numbers 0-100. Each year has its own school year,
-- section, and up to 2 semesters of subjects.
-- ------------------------------------------------------------
INSERT INTO students (student_id, full_name, program, gender, age, year_level, academic_year, status) VALUES
('22-22-001','Dela Cruz, Juan A.','BSIT','Male',20,3,'2025-2026','enrolled'),
('22-22-005','Santos, Maria B.','BSIT','Female',19,2,'2025-2026','enrolled'),
('22-22-007','Reyes, Pedro C.','BSIT','Male',21,4,'2025-2026','enrolled'),
('22-22-012','Garcia, Ana D.','BSIT','Female',18,1,'2025-2026','enrolled')
ON DUPLICATE KEY UPDATE student_id = student_id;

-- Juan: 3rd year now. His section changes every year (BSIT-11 -> BSIT-22 -> BSIT-33).
INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed) VALUES
('22-22-001', 1, '2023-2024', 'BSIT-11', 78.00, 82.00, 1),
('22-22-001', 2, '2024-2025', 'BSIT-22', 80.00, 80.00, 1),
('22-22-001', 3, '2025-2026', 'BSIT-33', 80.00, 78.50, 2)
ON DUPLICATE KEY UPDATE gwa = VALUES(gwa);

INSERT INTO subject_records (student_id, year_level, semester, subject_name, grade, attendance_rate) VALUES
('22-22-001', 3, 1, 'Data Structures and Algorithms', 72, 75.00),
('22-22-001', 3, 1, 'Database Management Systems', 74, 77.00),
('22-22-001', 3, 1, 'Web Systems and Technologies', 85, 80.00),
('22-22-001', 3, 2, 'Networking 1', 84, 82.00),
('22-22-001', 3, 2, 'Software Engineering', 85, 78.50);

-- Maria: 2nd year
INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed) VALUES
('22-22-005', 1, '2024-2025', 'BSIT-11', 88.00, 90.00, 0),
('22-22-005', 2, '2025-2026', 'BSIT-22', 91.00, 92.00, 0)
ON DUPLICATE KEY UPDATE gwa = VALUES(gwa);

-- Pedro: 4th year, strong all the way (his Year 1 was 2022-2023)
INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed) VALUES
('22-22-007', 1, '2022-2023', 'BSIT-11', 92.00, 95.00, 0),
('22-22-007', 2, '2023-2024', 'BSIT-22', 93.00, 96.00, 0),
('22-22-007', 3, '2024-2025', 'BSIT-33', 94.00, 96.50, 0),
('22-22-007', 4, '2025-2026', 'BSIT-44', 95.00, 96.50, 0)
ON DUPLICATE KEY UPDATE gwa = VALUES(gwa);

-- Ana: 1st year, struggling in specific subjects
INSERT INTO student_records (student_id, year_level, academic_year, section, gwa, attendance_rate, units_failed) VALUES
('22-22-012', 1, '2025-2026', 'BSIT-11', 73.00, 65.00, 3)
ON DUPLICATE KEY UPDATE gwa = VALUES(gwa);

INSERT INTO subject_records (student_id, year_level, semester, subject_name, grade, attendance_rate) VALUES
('22-22-012', 1, 1, 'Programming Fundamentals', 68, 60.00),
('22-22-012', 1, 1, 'Mathematics in the Modern World', 70, 65.00),
('22-22-012', 1, 2, 'Purposive Communication', 72, 62.00),
('22-22-012', 1, 2, 'Physical Education 1', 82, 73.00);

INSERT INTO datasets (academic_year, uploaded_by, record_count, status) VALUES
('2025-2026','Registrar', 4, 'validated')
ON DUPLICATE KEY UPDATE academic_year = academic_year;

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
