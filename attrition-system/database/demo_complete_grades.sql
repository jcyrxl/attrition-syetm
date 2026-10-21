-- OPTIONAL — run AFTER demo_all_classes.sql, only to try "Archive this semester" (Program Head → History → Close the semester).
-- (After this, open registrar/prepare_demo.php once so the records catch up.) It finishes the semester: the Midterm exam, a Pre-Finals column set and the Pre-Finals exam get scores for every student, so every
-- Final Grade is complete. (Then the Program Head can clear the classes and archive it, and the system moves to the next semester.) Safe to run again.

SET @ay  := COALESCE((SELECT setting_value FROM system_settings WHERE setting_key = 'current_school_year'),
                     (SELECT MAX(sr.academic_year) FROM student_records sr INNER JOIN students s ON s.student_id = sr.student_id WHERE s.status = 'enrolled'));
SET @sem := COALESCE((SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = 'current_semester'), 1);

-- the Pre-Finals columns (classes that do not have them yet)
INSERT INTO class_activities (class_id, term, component, title, items, is_exam)
SELECT c.id, a.term, a.component, a.title, a.items, 0
FROM classes c
CROSS JOIN (SELECT 3 AS term, 'Quiz' AS component, 'Quiz 4' AS title, 10 AS items
            UNION ALL SELECT 3, 'Activity', 'Activity 2', 15
            UNION ALL SELECT 3, 'Recitation', 'Recitation 2', 10) a
WHERE c.academic_year = @ay AND c.semester = @sem
  AND NOT EXISTS (SELECT 1 FROM class_activities x WHERE x.class_id = c.id AND x.term = 3 AND x.is_exam = 0);

-- a score for everybody in every column that has none yet (whole numbers or .5, like the real ones)
INSERT IGNORE INTO class_scores (activity_id, student_id, score)
SELECT a.id, cs.student_id,
       ROUND(a.items * 2 * LEAST(1, GREATEST(0, 0.55 + 0.45 * (CAST(MOD(CRC32(CONCAT(cs.student_id, 'ability')), 100) AS SIGNED) / 100)
                                            - 0.03 * CAST(MOD(CRC32(c.subject_name), 3) AS SIGNED)
                                            + (CAST(MOD(CRC32(CONCAT(cs.student_id, a.id)), 40) AS SIGNED) - 18) / 100))) / 2
FROM class_activities a
INNER JOIN classes c ON c.id = a.class_id AND c.academic_year = @ay AND c.semester = @sem
INNER JOIN class_students cs ON cs.class_id = c.id AND cs.excluded = 0
WHERE NOT EXISTS (SELECT 1 FROM class_scores sc WHERE sc.activity_id = a.id AND sc.student_id = cs.student_id);

-- the students' records are calculated again the next time a Dashboard opens
DELETE FROM subject_records WHERE class_id IN (SELECT id FROM classes WHERE academic_year = @ay AND semester = @sem);
