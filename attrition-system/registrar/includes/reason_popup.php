<?php // The data of the pop-up sits in <div id="popupData"> (printed with the graphs, so it follows the school year being shown). ?>
<?php if (empty($popupDataRendered)): ?><div id="popupData" hidden data-cases="<?= htmlspecialchars(json_encode($popupCases ?? [], JSON_UNESCAPED_UNICODE)) ?>"></div><?php endif; ?>
<div class="modal-overlay" id="reasonModal">
    <div class="modal-box" style="width:820px;">
        <h2 id="reasonTitle">Reason</h2>
        <p id="reasonSub" style="font-size:13px;color:#64727a;margin-bottom:12px;"></p>
        <div id="reasonBody" style="max-height:60vh;overflow:auto;"></div>
        <div class="modal-actions"><button type="button" class="btn-secondary" onclick="closeReasonModal()">Close</button></div>
    </div>
</div>
<script>
    const CASE_SHORT = <?= jsonAttr(array_map(function ($t) { return $t['short']; }, CASE_TYPES)) ?>;
    const CASE_COLOR = <?= jsonAttr(array_map(function ($t) { return $t['color']; }, CASE_TYPES)) ?>;
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function popupCases() {
        const el = document.getElementById('popupData');
        try { return JSON.parse(el.dataset.cases || '[]'); } catch (e) { return []; }
    }
    function openReasonModal(reason) {
        const rows = popupCases().filter(function (c) { return c.reason === reason; });
        const others = reason === 'Others';
        document.getElementById('reasonTitle').textContent = others ? 'Others — what students wrote' : reason;
        document.getElementById('reasonSub').textContent = rows.length + ' student' + (rows.length === 1 ? '' : 's') +
            (others ? ' chose "Others". Their own words are shown below.' : ' gave this reason in their letter.');
        let html = '<table style="width:100%;border-collapse:collapse;font-size:13.5px;"><thead><tr style="text-align:left;color:#64727a;">' +
            '<th style="padding:8px 6px;">Student</th><th style="padding:8px 6px;">Request</th><th style="padding:8px 6px;">School Year</th><th style="padding:8px 6px;">Semester</th>' +
            '<th style="padding:8px 6px;">' + (others ? 'What they wrote' : 'Remarks') + '</th><th style="padding:8px 6px;">Filed</th></tr></thead><tbody>';
        rows.forEach(function (c) {
            html += '<tr style="border-top:1px solid #eef1f3;">' +
                '<td style="padding:9px 6px;"><strong>' + esc(c.full_name) + '</strong><br><span style="color:#8a959b;font-size:12px;">' + esc(c.student_id) + '</span></td>' +
                '<td style="padding:9px 6px;"><span class="status-pill" style="--c:' + esc(CASE_COLOR[c.case_type]) + ';">' + esc(CASE_SHORT[c.case_type]) + '</span></td>' +
                '<td style="padding:9px 6px;">' + esc(c.academic_year) + '</td><td style="padding:9px 6px;">' + esc(c.semester) + '</td>' +
                '<td style="padding:9px 6px;">' + (others ? '<span class="others-text">“' + esc(c.reason_other) + '”</span>' : esc(c.details || '—')) + '</td>' +
                '<td style="padding:9px 6px;">' + esc(c.filed_on) + '</td></tr>';
        });
        document.getElementById('reasonBody').innerHTML = html + '</tbody></table>';
        document.getElementById('reasonModal').classList.add('show');
    }
    function closeReasonModal() { document.getElementById('reasonModal').classList.remove('show'); }
    document.getElementById('reasonModal').addEventListener('click', function (e) { if (e.target === this) closeReasonModal(); });
    <?php if (!empty($_GET['open'])): ?>openReasonModal(<?= jsonAttr($_GET['open']) ?>);<?php endif; ?>
</script>
