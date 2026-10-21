-- OPTIONAL demo — run AFTER dummy_data.sql and migration_classes.sql. Safe to run again.
-- Two classes of the dummy school year 2025-2026 (2nd Semester), already half way through: 8 meetings (with some absences, lates and
-- excused), the Prelim scores and exam, and some Midterm scores — with a few weak students, so the red marks and the
-- attendance alerts (2 absences = alert, 3 = Dropped (Unofficially)) show. The second class uses weights (Quiz 50 / Recitation 20 / Assignment 30).
-- The class page calculates everything by itself. The first time something is changed in a class, its results are copied into the
-- students' records and their risk is predicted again.

DELETE FROM classes WHERE owner_user_id IS NULL AND subject_name LIKE '%(demo)';

INSERT INTO classes (owner_user_id, subject_code, subject_name, section, academic_year, semester) VALUES
    (NULL, NULL, 'Computer Programming 2 (demo)', 'BSIT-11', '2025-2026', 2),
    (NULL, NULL, 'Discrete Mathematics (demo)',   'BSIT-13', '2025-2026', 2);

INSERT IGNORE INTO class_students (class_id, student_id, excluded)
SELECT c.id, s.student_id, 0 FROM classes c
INNER JOIN student_records sr ON sr.academic_year = c.academic_year AND sr.section = c.section
INNER JOIN students s ON s.student_id = sr.student_id AND s.status = 'enrolled'
WHERE c.subject_name LIKE '%(demo)';

-- the exam of each term (the system makes these for every new class)
INSERT INTO class_activities (class_id, term, component, title, items, is_exam)
SELECT c.id, t.term, 'Exam', CASE t.term WHEN 1 THEN 'Prelim Exam' WHEN 2 THEN 'Midterm Exam' ELSE 'Pre-Finals Exam' END, 50, 1
FROM classes c CROSS JOIN (SELECT 1 AS term UNION SELECT 2 UNION SELECT 3) t WHERE c.subject_name LIKE '%(demo)';

-- what this professor grades: quizzes, an activity, recitation, an assignment, a reporting
INSERT INTO class_activities (class_id, term, component, title, items, is_exam)
SELECT c.id, a.term, a.component, a.title, a.items, 0
FROM classes c CROSS JOIN (
    SELECT 1 AS term, 'Quiz' AS component, 'Quiz 1' AS title, 10 AS items
    UNION SELECT 1, 'Quiz', 'Quiz 2', 20
    UNION SELECT 1, 'Activity', 'Activity 1', 15
    UNION SELECT 1, 'Recitation', 'Recitation 1', 10
    UNION SELECT 2, 'Quiz', 'Quiz 3', 10
    UNION SELECT 2, 'Assignment', 'Assignment 1', 15
) a WHERE c.subject_name LIKE '%(demo)';

-- weights for the second class only (the first one uses the total score)
INSERT INTO class_weights (class_id, component, weight)
SELECT c.id, w.component, w.weight FROM classes c CROSS JOIN (
    SELECT 'Quiz' AS component, 50 AS weight UNION SELECT 'Recitation', 20 UNION SELECT 'Assignment', 30 UNION SELECT 'Activity', 0
) w WHERE c.subject_name = 'Discrete Mathematics (demo)' AND w.weight > 0;

-- eight meetings, a week apart (the last one is today): four in Prelim, four in Midterm
INSERT INTO class_meetings (class_id, meeting_date, term)
SELECT c.id, DATE_SUB(CURDATE(), INTERVAL d.n DAY), d.term FROM classes c
CROSS JOIN (SELECT 49 AS n, 1 AS term UNION SELECT 42, 1 UNION SELECT 35, 1 UNION SELECT 28, 1
            UNION SELECT 21, 2 UNION SELECT 14, 2 UNION SELECT 7, 2 UNION SELECT 0, 2) d
WHERE c.subject_name LIKE '%(demo)';

-- attendance: every student has their own chance of being absent (4% to 25%); some are late or excused
INSERT INTO class_attendance (meeting_id, student_id, status)
SELECT t.mid, t.sid, CASE WHEN t.r < t.a THEN 'A' WHEN t.r < t.a + 6 THEN 'L' WHEN t.r < t.a + 10 THEN 'E' ELSE 'P' END
FROM (SELECT m.id AS mid, cs.student_id AS sid,
             MOD(CRC32(CONCAT(cs.student_id, m.meeting_date)), 100) AS r,
             4 + MOD(CRC32(cs.student_id), 22) AS a
      FROM class_meetings m
      INNER JOIN classes c ON c.id = m.class_id
      INNER JOIN class_students cs ON cs.class_id = c.id AND cs.excluded = 0
      WHERE c.subject_name LIKE '%(demo)') t;

-- scores: Prelim (everything incl. the exam) and the Midterm quiz + assignment; the Midterm and Pre-Finals exams are not taken yet.
-- Each student has an ability (45% to 95%) plus some ups and downs per column — a few end up below 50%.
INSERT INTO class_scores (activity_id, student_id, score)
SELECT a.id, cs.student_id,
       ROUND(a.items * LEAST(1, GREATEST(0, 0.45 + 0.50 * (MOD(CRC32(CONCAT(cs.student_id, 'ability')), 100) / 100)
                                            + (MOD(CRC32(CONCAT(cs.student_id, a.id)), 40) - 15) / 100)), 0)
FROM class_activities a
INNER JOIN classes c ON c.id = a.class_id
INNER JOIN class_students cs ON cs.class_id = c.id AND cs.excluded = 0
WHERE c.subject_name LIKE '%(demo)' AND (a.term = 1 OR (a.term = 2 AND a.is_exam = 0));
