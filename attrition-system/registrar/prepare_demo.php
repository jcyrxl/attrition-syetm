<?php
// ONE-TIME helper for the demo data: after importing dummy_data.sql and demo_all_classes.sql, open this page once (as the Program Head or
// the Admin). It copies the attendance and scores of the ~150 demo classes into the students' records and predicts everybody's risk, with a
// progress line, so the first Dashboard does not have to. Safe to run again. You can delete this file when you are done.
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/roles.php';
require_once __DIR__ . '/../config/academic.php';
require_once __DIR__ . '/../config/analytics.php';
require_once __DIR__ . '/../config/grades.php';
if (!isProgramHead() && !isAdmin()) { header('Location: dashboard.php'); exit; }
@set_time_limit(0);
@ini_set('zlib.output_compression', '0');
while (ob_get_level()) { ob_end_flush(); }
function say(string $m): void { echo '<p>' . $m . '</p>' . str_repeat(' ', 1024) . "\n"; flush(); }
$unsynced = function () use ($pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM classes c
        WHERE (EXISTS (SELECT 1 FROM class_meetings m WHERE m.class_id = c.id) OR EXISTS (SELECT 1 FROM class_activities a INNER JOIN class_scores sc ON sc.activity_id = a.id WHERE a.class_id = c.id))
          AND NOT EXISTS (SELECT 1 FROM subject_records sr WHERE sr.class_id = c.id)")->fetchColumn();
};
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Prepare the demo data</title>
<style>body{font-family:Poppins,Arial,sans-serif;max-width:720px;margin:40px auto;padding:0 18px;color:#17323f}h1{color:#064a5e}p{margin:6px 0}.ok{color:#1b6b3a;font-weight:600}a.b{display:inline-block;margin-top:16px;background:#0b8fa3;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:600}</style></head><body>
<h1>Preparing the demo data</h1>
<?php
$start = microtime(true);
$left = $unsynced();
say("Classes with recorded attendance or scores that are not in the students' records yet: <strong>$left</strong>.");
$round = 0;
while ($left > 0 && $round < 60) {
    $round++;
    syncUnsyncedClasses($pdo, null, 12);
    $now = $unsynced();
    say("Round $round — copied results of up to 12 classes; <strong>$now</strong> left.");
    if ($now >= $left) { say('No progress — stopping (check that the Classes tables are up to date: migration_classes.sql).'); break; }
    $left = $now;
}
say('Predicting everybody’s risk again…');
$pdo->prepare("DELETE FROM system_settings WHERE setting_key = 'predictions_model'")->execute();
ensurePredictionsCurrent($pdo);
$n = (int) $pdo->query("SELECT COUNT(DISTINCT student_id) FROM predictions")->fetchColumn();
say('<span class="ok">Done in ' . round(microtime(true) - $start) . ' seconds — ' . $n . ' students have a risk now.</span>');
?>
<a class="b" href="dashboard.php">Go to the Dashboard</a>
</body></html>
