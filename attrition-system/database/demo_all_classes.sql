-- ============================================================================================================================
-- DEMO: a class for EVERY enrolled student's section, handed out to the Faculty accounts — so every professor's Dashboard is full.
-- Run it AFTER dummy_data.sql and migration_classes.sql (and after the Faculty accounts exist: User Management).
-- It REPLACES demo_classes.sql (run this one instead). Safe to run again.
--
--  * School year / semester: the one the system is in (2026-2027, 1st Semester with the new Dummy data).
--  * 6 subjects for every section of every year level for that semester (e.g. 3rd year, 1st sem: Systems Integration and Architecture, Technopreneurship ...), each section = its own class — 26 sections, about 156 classes.
--  * EVERY Faculty account is used — "Jepoy Dizon", "Hatsune Miku" and any other you add in User Management. The classes are dealt out
--    so each professor handles different subjects in different sections. Add more Faculty accounts and run this file again to
--    spread the classes over everybody. (It re-deals the classes it lists; assignments you made by hand for these subjects are replaced.)
--  * The first Faculty (A–Z) grades with weights (Quiz 35 · Activity 20 · Recitation 15 · Reporting 20 · Seatwork 10); the others use total score.
--  * Each class gets 8 meetings (4 Prelim, 4 Midterm) with absences / lates / excused, the Prelim scores and exam, and Midterm scores
--    (the Midterm exam and the Pre-Finals are not taken yet). Each student has his or her own strength, so the same students show up
--    weak in several subjects — and a few reach 2 or 3 absences.
--  * Nothing is calculated here: the first time a Dashboard is opened the system copies the class results into the students' records
--    and predicts everyone's risk again.
-- ============================================================================================================================

DELETE FROM classes WHERE subject_name LIKE '%(demo)';

-- the semester the system is in (Dummy data sets it: 2026-2027, 1st Semester); the school year of the enrolled students otherwise
SET @ay  := COALESCE((SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'),
                     (SELECT MAX(sr.academic_year) FROM student_records sr INNER JOIN students s ON s.student_id = sr.student_id WHERE s.status = 'enrolled'));
SET @sem := COALESCE((SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = 'current_semester'), 1);

DROP TEMPORARY TABLE IF EXISTS tmp_subj;
DROP TEMPORARY TABLE IF EXISTS tmp_fac;
DROP TEMPORARY TABLE IF EXISTS tmp_new;

-- the subjects of each year level
CREATE TEMPORARY TABLE tmp_subj (idx INT NOT NULL, yr TINYINT NOT NULL, sem TINYINT NOT NULL, subject_name VARCHAR(100) NOT NULL);
INSERT INTO tmp_subj (idx, yr, sem, subject_name) VALUES
    (0, 1, 1, 'Understanding the Self'),
    (1, 1, 1, 'Computer Programming 1'),
    (2, 1, 1, 'Introduction to Computing'),
    (3, 1, 1, 'Mathematics in the Modern World'),
    (4, 1, 1, 'Purposive Communication'),
    (5, 1, 1, 'Physical Education 1'),
    (0, 1, 2, 'Computer Programming 2'),
    (1, 1, 2, 'Discrete Mathematics'),
    (2, 1, 2, 'Introduction to Human Computer Interaction'),
    (3, 1, 2, 'Art Appreciation'),
    (4, 1, 2, 'Ethics'),
    (5, 1, 2, 'Physical Education 2'),
    (0, 2, 1, 'Data Structures and Algorithms'),
    (1, 2, 1, 'Object-Oriented Programming'),
    (2, 2, 1, 'Information Management 1'),
    (3, 2, 1, 'Networking 1'),
    (4, 2, 1, 'Platform Technologies'),
    (5, 2, 1, 'Physical Education 3'),
    (0, 2, 2, 'Information Management 2'),
    (1, 2, 2, 'Networking 2'),
    (2, 2, 2, 'Web Systems and Technologies'),
    (3, 2, 2, 'Quantitative Methods'),
    (4, 2, 2, 'Integrative Programming'),
    (5, 2, 2, 'Physical Education 4'),
    (0, 3, 1, 'Systems Integration and Architecture'),
    (1, 3, 1, 'Information Assurance and Security 1'),
    (2, 3, 1, 'Technopreneurship'),
    (3, 3, 1, 'Application Development'),
    (4, 3, 1, 'Social and Professional Issues'),
    (5, 3, 1, 'IT Elective 1'),
    (0, 3, 2, 'Information Assurance and Security 2'),
    (1, 3, 2, 'Capstone Project 1'),
    (2, 3, 2, 'Systems Administration and Maintenance'),
    (3, 3, 2, 'Mobile Application Development'),
    (4, 3, 2, 'Research Methods'),
    (5, 3, 2, 'IT Elective 2'),
    (0, 4, 1, 'Capstone Project 2'),
    (1, 4, 1, 'Emerging Technologies'),
    (2, 4, 1, 'IT Elective 3'),
    (3, 4, 1, 'Project Management'),
    (4, 4, 1, 'Computer Graphics and Visual Computing'),
    (5, 4, 1, 'Seminars and Field Trips'),
    (0, 4, 2, 'Practicum (On-the-Job Training)'),
    (1, 4, 2, 'Professional Ethics in IT'),
    (2, 4, 2, 'IT Elective 4'),
    (3, 4, 2, 'Technical Writing'),
    (4, 4, 2, 'Current Trends in IT'),
    (5, 4, 2, 'Exit Assessment');

-- the Faculty accounts, numbered 0, 1, 2 ... (A–Z by name)
SET @r := -1;
CREATE TEMPORARY TABLE tmp_fac AS
    SELECT (@r := @r + 1) AS n, u.user_id FROM (SELECT user_id FROM users WHERE role = 'faculty' ORDER BY full_name ASC LIMIT 1000) u;
SET @nfac := (SELECT COUNT(*) FROM tmp_fac);

-- one class per subject per section of the enrolled students (the ones that exist already are left alone)
INSERT IGNORE INTO classes (owner_user_id, subject_code, subject_name, section, academic_year, semester)
SELECT NULL, NULL, sub.subject_name, sec.section, @ay, @sem
FROM (SELECT DISTINCT sr.section FROM student_records sr INNER JOIN students s ON s.student_id = sr.student_id
      WHERE s.status = 'enrolled' AND sr.academic_year = @ay AND sr.section REGEXP '^BSIT-[1-4][1-8]$') sec
INNER JOIN tmp_subj sub ON sub.yr = CAST(SUBSTRING(sec.section, 6, 1) AS UNSIGNED) AND sub.sem = @sem;

-- the classes that are still empty (just made): they get a class list, exams, columns, meetings, attendance and scores
CREATE TEMPORARY TABLE tmp_new AS
    SELECT c.id FROM classes c
    WHERE c.academic_year = @ay AND c.semester = @sem AND NOT EXISTS (SELECT 1 FROM class_activities a WHERE a.class_id = c.id);

-- deal the classes out: for each subject the sections go round the professors, and every subject starts with a different professor
UPDATE classes c
INNER JOIN tmp_subj s ON s.subject_name = c.subject_name AND s.yr = CAST(SUBSTRING(c.section, 6, 1) AS UNSIGNED) AND s.sem = @sem
INNER JOIN tmp_fac f ON f.n = MOD(s.idx + CAST(SUBSTRING(c.section, 7, 1) AS UNSIGNED), GREATEST(@nfac, 1))
SET c.owner_user_id = f.user_id
WHERE c.academic_year = @ay AND c.semester = @sem;

-- the class lists: every enrolled student of the section
INSERT IGNORE INTO class_students (class_id, student_id, excluded)
SELECT c.id, s.student_id, 0
FROM tmp_new t
INNER JOIN classes c ON c.id = t.id
INNER JOIN student_records sr ON sr.academic_year = c.academic_year AND sr.section = c.section
INNER JOIN students s ON s.student_id = sr.student_id AND s.status = 'enrolled';

-- the exam of each term
INSERT INTO class_activities (class_id, term, component, title, items, is_exam)
SELECT t.id, x.term, 'Exam', x.title, 50, 1
FROM tmp_new t
CROSS JOIN (SELECT 1 AS term, 'Prelim Exam' AS title UNION ALL SELECT 2, 'Midterm Exam' UNION ALL SELECT 3, 'Pre-Finals Exam') x;

-- what the professors grade
INSERT INTO class_activities (class_id, term, component, title, items, is_exam)
SELECT t.id, a.term, a.component, a.title, a.items, 0
FROM tmp_new t
CROSS JOIN (SELECT 1 AS term, 'Quiz' AS component, 'Quiz 1' AS title, 10 AS items
            UNION ALL SELECT 1, 'Quiz', 'Quiz 2', 20
            UNION ALL SELECT 1, 'Activity', 'Activity 1', 15
            UNION ALL SELECT 1, 'Recitation', 'Recitation 1', 10
            UNION ALL SELECT 2, 'Quiz', 'Quiz 3', 10
            UNION ALL SELECT 2, 'Reporting', 'Reporting 1', 20
            UNION ALL SELECT 2, 'Seatwork', 'Seatwork 1', 10) a;

-- the first professor grades with weights; the others with the total score
INSERT IGNORE INTO class_weights (class_id, component, weight)
SELECT c.id, w.component, w.weight
FROM classes c
INNER JOIN tmp_fac f ON f.n = 0 AND c.owner_user_id = f.user_id
CROSS JOIN (SELECT 'Quiz' AS component, 35 AS weight UNION ALL SELECT 'Activity', 20 UNION ALL SELECT 'Recitation', 15
            UNION ALL SELECT 'Reporting', 20 UNION ALL SELECT 'Seatwork', 10) w
WHERE c.academic_year = @ay AND c.semester = @sem;

-- eight meetings, a week apart (the last one about today): four in Prelim, four in Midterm; each class meets on a slightly different day
INSERT IGNORE INTO class_meetings (class_id, meeting_date, term)
SELECT t.id, DATE_SUB(CURDATE(), INTERVAL (d.n + MOD(t.id, 3)) DAY), d.term
FROM tmp_new t
CROSS JOIN (SELECT 49 AS n, 1 AS term UNION ALL SELECT 42, 1 UNION ALL SELECT 35, 1 UNION ALL SELECT 28, 1
            UNION ALL SELECT 21, 2 UNION ALL SELECT 14, 2 UNION ALL SELECT 7, 2 UNION ALL SELECT 0, 2) d;

-- attendance: every student has a chance of being absent (4% to 25%) that differs per subject; some are late or excused
INSERT INTO class_attendance (meeting_id, student_id, status)
SELECT x.mid, x.sid, CASE WHEN x.r < x.a THEN 'A' WHEN x.r < x.a + 6 THEN 'L' WHEN x.r < x.a + 10 THEN 'E' ELSE 'P' END
FROM (SELECT m.id AS mid, cs.student_id AS sid,
             CAST(MOD(CRC32(CONCAT(cs.student_id, m.id)), 100) AS SIGNED) AS r,
             4 + CAST(MOD(CRC32(CONCAT(cs.student_id, c.subject_name)), 22) AS SIGNED) AS a
      FROM tmp_new t
      INNER JOIN classes c ON c.id = t.id
      INNER JOIN class_meetings m ON m.class_id = c.id
      INNER JOIN class_students cs ON cs.class_id = c.id AND cs.excluded = 0) x;

-- scores: Prelim (all of it, with the exam) and the Midterm columns. Each student has a strength (the same in every subject),
-- some subjects are a little harder, and every score has its ups and downs — a few end up below 50%.
INSERT INTO class_scores (activity_id, student_id, score)
SELECT a.id, cs.student_id,
       ROUND(a.items * 2 * LEAST(1, GREATEST(0, 0.55 + 0.45 * (CAST(MOD(CRC32(CONCAT(cs.student_id, 'ability')), 100) AS SIGNED) / 100)
                                            - 0.03 * CAST(MOD(CRC32(c.subject_name), 3) AS SIGNED)
                                            + (CAST(MOD(CRC32(CONCAT(cs.student_id, a.id)), 40) AS SIGNED) - 18) / 100))) / 2
FROM tmp_new t
INNER JOIN classes c ON c.id = t.id
INNER JOIN class_activities a ON a.class_id = c.id
INNER JOIN class_students cs ON cs.class_id = c.id AND cs.excluded = 0
WHERE a.term = 1 OR (a.term = 2 AND a.is_exam = 0);

DROP TEMPORARY TABLE IF EXISTS tmp_new;
DROP TEMPORARY TABLE IF EXISTS tmp_fac;
DROP TEMPORARY TABLE IF EXISTS tmp_subj;

-- who handles what (shown when the import finishes)
SELECT u.full_name AS professor, COUNT(*) AS classes, SUM((SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id AND cs.excluded = 0)) AS students_in_them,
       GROUP_CONCAT(DISTINCT c.section ORDER BY c.section SEPARATOR ', ') AS sections
FROM classes c LEFT JOIN users u ON u.user_id = c.owner_user_id
WHERE c.academic_year = @ay AND c.semester = @sem
GROUP BY u.full_name ORDER BY u.full_name;

-- The subjects list (the Admin adds more later): every subject that appears in the records above.
INSERT IGNORE INTO subjects (subject_name) SELECT DISTINCT subject_name FROM subject_records WHERE subject_name <> '';
INSERT IGNORE INTO subjects (subject_name) SELECT DISTINCT subject_name FROM classes WHERE subject_name <> '';
