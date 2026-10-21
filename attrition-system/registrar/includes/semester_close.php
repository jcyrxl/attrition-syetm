<?php
// History → "Close the semester": which professors have encoded their grades (by term), clearing a class, and archiving the semester.
// Program Head only; every action asks for the PIN in a pop-up, and archiving also asks to type ARCHIVE — the data is delicate.
// Expects: $pdo
$curP = currentSchoolYear($pdo);
$sem = (int) $curP['sem'];
$st = ['profs' => [], 'total' => 0, 'complete' => 0, 'cleared' => 0, 'ready' => true];
try { $st = semesterStatus($pdo, $curP['ay'], $sem); } catch (PDOException $e) {}
[$nAyP, $nSemP] = nextPeriod($curP['ay'], $sem);
$pinSet = false;
try { $pq = $pdo->prepare("SELECT student_data_pin_hash FROM users WHERE user_id = ?"); $pq->execute([$_SESSION['user_id']]); $pinSet = (bool) $pq->fetchColumn(); } catch (PDOException $e) {}
$fourth = 0;
try { $fq = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.status = 'enrolled' AND EXISTS (SELECT 1 FROM student_records r WHERE r.student_id = s.student_id AND r.academic_year = ? AND r.year_level = 4)"); $fq->execute([$curP['ay']]); $fourth = (int) $fq->fetchColumn(); } catch (PDOException $e) {}
$chipFor = ['complete' => ['All grades in', 'ok'], 'in_progress' => ['In progress', 'warn'], 'none_yet' => ['Not yet encoded', 'bad']];
$cell = function (int $done, int $n): string {
    $cls = $done >= $n ? 'ok' : ($done > 0 ? 'warn' : 'zero');
    return '<span class="cmp ' . $cls . '">' . ($done >= $n ? '<i class="fa-solid fa-check"></i> ' : '') . $done . '/' . $n . '</span>';
};
$pct = $st['total'] ? round($st['complete'] / $st['total'] * 100) : 0;
?>
<section class="close-card" id="close">
    <div class="close-head">
        <div>
            <h2><i class="fa-solid fa-box-archive"></i> Close the semester <small class="term-small"><?= htmlspecialchars($curP['ay']) ?> · <?= $sem === 1 ? '1st' : '2nd' ?> Semester</small></h2>
            <p>See who has encoded their grades — by term — then <strong>clear</strong> each class whose grades are complete. When every class is complete, archive the semester: the system moves on to <strong><?= htmlspecialchars($nAyP) ?> · <?= $nSemP === 1 ? '1st' : '2nd' ?> Semester</strong>. Everything here asks for your PIN.</p>
        </div>
        <div class="close-progress">
            <div class="ring" style="--p:<?= $pct ?>"><b><?= $pct ?>%</b></div>
            <div><strong><?= $st['complete'] ?> of <?= $st['total'] ?></strong> classes complete<br><span class="cls-hint"><?= $st['cleared'] ?> cleared</span></div>
        </div>
    </div>

    <?php if (!$pinSet): ?><div class="alert-banner" style="background:#fff4e0;color:#6b4600;margin:0 0 12px;"><i class="fa-solid fa-key"></i> You have not set your PIN yet. Set it in <a href="privacy_settings.php" style="color:#6b4600;font-weight:700;">Privacy Settings</a> — it is asked every time you record a student request, clear a class or archive a semester.</div><?php endif; ?>

    <?php if (!$st['profs']): ?><div class="chart-empty" style="padding:22px;text-align:center;">No class with students in this semester yet.</div><?php endif; ?>

    <?php foreach ($st['profs'] as $uid => $p): [$lbl, $cls] = $chipFor[$p['status']]; ?>
    <details class="prof-card" <?= $p['status'] !== 'complete' ? 'open' : '' ?>>
        <summary>
            <span class="pname"><?= htmlspecialchars($p['name']) ?></span>
            <span class="pchip <?= $cls ?>"><?= $lbl ?></span>
            <span class="pcount"><?= $p['done'] ?> of <?= $p['total'] ?> class<?= $p['total'] === 1 ? '' : 'es' ?> complete</span>
        </summary>
        <div class="table-wrap" style="margin:0;border:none;box-shadow:none;border-radius:0;">
        <table>
            <thead><tr><th>Class</th><th>Students</th><th>Prelim</th><th>Midterm</th><th>Pre-Finals</th><th>Final Grade</th><th>Status</th><th style="width:130px;"></th></tr></thead>
            <tbody>
            <?php foreach ($p['classes'] as $c): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($c['subject']) ?></strong><span class="small-ay" style="display:block;"><?= htmlspecialchars($c['section']) ?> · #<?= $c['id'] ?></span></td>
                    <td><?= $c['n'] ?></td>
                    <td><?= $cell($c['terms'][1], $c['n']) ?></td><td><?= $cell($c['terms'][2], $c['n']) ?></td><td><?= $cell($c['terms'][3], $c['n']) ?></td><td><?= $cell($c['final'], $c['n']) ?></td>
                    <td><?php if ($c['status'] === 'cleared'): ?><span class="pchip ok"><i class="fa-solid fa-lock"></i> Cleared</span>
                        <?php elseif ($c['status'] === 'complete'): ?><span class="pchip ok">Complete</span>
                        <?php elseif ($c['status'] === 'in_progress'): ?><span class="pchip warn">In progress</span>
                        <?php else: ?><span class="pchip bad">Not started</span><?php endif; ?></td>
                    <td style="text-align:right;">
                        <?php if ($c['status'] === 'complete'): ?><button type="button" class="btn-primary" style="padding:6px 12px;font-size:12.5px;" data-clear="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['subject'] . ' · ' . $c['section']) ?>">Clear class</button>
                        <?php elseif ($c['status'] === 'cleared'): ?><button type="button" class="btn-secondary" style="padding:6px 12px;font-size:12.5px;" data-reopen="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['subject'] . ' · ' . $c['section']) ?>">Reopen</button><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </details>
    <?php endforeach; ?>

    <div class="archive-bar">
        <div>
            <strong><?= $st['ready'] && $st['total'] ? 'Every grade is complete.' : 'Not ready to archive yet.' ?></strong>
            <span class="cls-hint"><?= $st['ready'] && $st['total'] ? 'You can archive ' . htmlspecialchars($curP['ay']) . ' · ' . ($sem === 1 ? '1st' : '2nd') . ' Semester now.' : ($st['total'] - $st['complete']) . ' class' . (($st['total'] - $st['complete']) === 1 ? ' is' : 'es are') . ' still missing grades.' ?></span>
            <?php if ($sem === 2 && $fourth > 0): ?><label class="grad-check"><input type="checkbox" id="gradCheck"> Mark the <strong><?= $fourth ?></strong> enrolled 4th-year student<?= $fourth === 1 ? '' : 's' ?> as <strong>Graduated</strong></label><?php endif; ?>
        </div>
        <button type="button" class="btn-primary" id="archiveBtn" <?= ($st['ready'] && $st['total']) ? '' : 'disabled title="Complete the grades first"' ?>><i class="fa-solid fa-box-archive"></i> Archive the semester</button>
    </div>
</section>

<script>
(function () {
    function post(fields) {
        var fd = new FormData(); Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch('history.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(function (r) { return r.json(); });
    }
    function done(res) {
        if (res.logout) { location.href = '../index.php?error=locked'; return; }
        toast(res.msg, res.ok ? 'ok' : 'bad', 5200);
        if (res.ok) setTimeout(function () { location.href = 'history.php#close'; location.reload(); }, 900);
    }
    function esc(t) { return String(t).replace(/</g, '&lt;'); }
    document.querySelectorAll('[data-clear]').forEach(function (b) { b.addEventListener('click', function () {
        askSecret({ type: 'pin', title: 'Clear this class?', message: '<strong>' + esc(b.dataset.name) + '</strong> has complete grades. Clearing makes them final and archives the class — the professor can no longer record in it. (You can reopen it.)', confirmText: 'Clear class' }).then(function (pin) {
            if (pin === null) return; post({ action: 'clear_class', credential: pin, id: b.dataset.clear }).then(done).catch(function () { toast('Could not reach the server.', 'bad'); });
        }); }); });
    document.querySelectorAll('[data-reopen]').forEach(function (b) { b.addEventListener('click', function () {
        askSecret({ type: 'pin', title: 'Reopen this class?', message: '<strong>' + esc(b.dataset.name) + '</strong> will be open again, so the professor can change its attendance and scores.', confirmText: 'Reopen class' }).then(function (pin) {
            if (pin === null) return; post({ action: 'reopen_class', credential: pin, id: b.dataset.reopen }).then(done).catch(function () { toast('Could not reach the server.', 'bad'); });
        }); }); });
    var ab = document.getElementById('archiveBtn');
    if (ab) ab.addEventListener('click', function () {
        var grad = document.getElementById('gradCheck');
        askSecret({ type: 'pin', danger: true, requireText: 'ARCHIVE', title: 'Archive <?= htmlspecialchars($curP['ay']) ?> · <?= $sem === 1 ? '1st' : '2nd' ?> Semester?',
            message: 'This moves <strong>all <?= $st['total'] ?> classes</strong> to the archive (view only) and starts <strong><?= htmlspecialchars($nAyP) ?> · <?= $nSemP === 1 ? '1st' : '2nd' ?> Semester</strong>. The Dashboard, Reports and Prediction will show the new semester. <strong>It cannot be undone from here.</strong>' + (grad && grad.checked ? ' The 4th-year students will be marked Graduated.' : ''),
            confirmText: 'Archive the semester' }).then(function (pin) {
            if (pin === null) return;
            post({ action: 'archive_semester', credential: pin, graduate: grad && grad.checked ? 1 : 0 }).then(done).catch(function () { toast('Could not reach the server.', 'bad'); });
        });
    });
    if (location.hash === '#close') { var c = document.getElementById('close'); if (c) c.scrollIntoView(); }
})();
</script>
