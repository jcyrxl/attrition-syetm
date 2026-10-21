/**
 * The class sheet — a spreadsheet for the class record.
 * Tabs per term + Summary; every mark and score is saved the moment it is entered (the server recalculates the grades, copies them
 * into the student's records and predicts the risk again). Works like Excel where it counts: Enter / arrows go down a column,
 * a column copied from Excel can be pasted, and the first column and the headers stay in place while you scroll.
 */
(function () {
    var sheet = document.getElementById('sheet');
    if (!sheet) return;
    var CID = parseInt(sheet.dataset.class, 10);
    var canEdit = sheet.dataset.edit === '1';
    var LOW = parseFloat(sheet.dataset.low), FAIL = parseFloat(sheet.dataset.fail);
    var state = document.getElementById('saveState');

    function say(text, bad) {
        if (!state) return;
        state.textContent = text;
        state.className = 'cls-saved' + (bad ? ' bad' : '');
        if (!bad) { clearTimeout(say.t); say.t = setTimeout(function () { state.textContent = ''; }, 2500); }
    }
    // every request waits for the one before it, so many quick entries (or a paste) are saved in order
    var chain = Promise.resolve();
    function api(action, data) {
        say('Saving…');
        var p = chain.then(function () {
            return fetch('class_api.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
                body: JSON.stringify(Object.assign({ action: action, class_id: CID }, data || {}))
            }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'No connection to the server.' }; });
        });
        chain = p.catch(function () {});
        return p;
    }
    function fmtG(v) { if (v === null || v === undefined) return '—'; v = Math.round(v * 2) / 2; return v % 1 === 0 ? String(v) : v.toFixed(1); }
    function pctText(v, star) { return v === null || v === undefined ? '—' : fmtG(v) + '%' + (star ? '*' : ''); }
    function pts(v) { return String(Math.round(v * 10) / 10).replace(/\.0$/, ''); }
    function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function all(sel) { return Array.prototype.slice.call(sheet.querySelectorAll(sel)); }

    function setLocked(on) {
        var b = document.getElementById('lockBanner');
        if (b) b.hidden = !on;
        all('.rec').forEach(function (el) { if (!/^colOther/.test(el.id) || !el.hidden) el.disabled = on || !canEdit; });
    }

    // ---------- tabs (remembered in the address, so a reload stays on the same tab) ----------
    var tabBtns = all('[data-tab]');
    function showTab(id) {
        if (!sheet.querySelector('#pane-' + id)) id = sheet.dataset.active || 't1';
        tabBtns.forEach(function (b) { b.classList.toggle('on', b.dataset.tab === id); });
        all('.cls-pane').forEach(function (p) { p.classList.toggle('on', p.id === 'pane-' + id); });
        try { history.replaceState(null, '', '#' + id); } catch (e) {}
        refreshAverages();
    }
    tabBtns.forEach(function (b) { b.addEventListener('click', function () { showTab(b.dataset.tab); }); });
    function currentTab() { var b = sheet.querySelector('[data-tab].on'); return b ? b.dataset.tab : 't1'; }
    var h = /^#(t[1-3]|summary|setup|list)$/.exec(location.hash);
    showTab(h ? h[1] : (sheet.dataset.active || 't1'));
    // the small "+ Meeting" / "+ Column" cards: only one open at a time, closed by a click elsewhere
    document.addEventListener('click', function (e) {
        all('details.pop[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });

    function reloadHere() { location.hash = currentTab(); location.reload(); }
    function handle(res, undo) {
        if (res.redirect) { location.href = res.redirect; return false; }
        if (res.locked) { setLocked(true); if (undo) undo(); say('Locked — enter your PIN again.', true); return false; }
        if (!res.ok) { if (undo) undo(); say(res.error || 'Could not save.', true); return false; }
        if (res.reload) { reloadHere(); return false; }
        say('Saved ✓');
        return true;
    }

    // ---------- put the recalculated numbers on the page ----------
    var badge = { High: 'high-badge', Moderate: 'moderate-badge', Low: 'low-badge' };
    function setCells(name, sid, term, fn) {
        all('[data-cell="' + name + '"][data-sid="' + sid + '"]' + (term ? '[data-term="' + term + '"]' : '')).forEach(fn);
    }
    function apply(p) {
        var sid = p.sid, t;
        for (t = 1; t <= 3; t++) {
            (function (x, t) {
                setCells('cs', sid, t, function (c) { c.textContent = pctText(x.cs); });
                setCells('exam', sid, t, function (c) { c.textContent = pctText(x.exam); });
                setCells('grade', sid, t, function (c) { c.textContent = pctText(x.grade, x.prov); c.classList.toggle('bad', x.grade !== null && x.grade < FAIL); });
                setCells('s-term', sid, t, function (c) { c.textContent = pctText(x.grade, x.prov); c.classList.toggle('bad', x.grade !== null && x.grade < FAIL); });
            })(p.terms[t], t);
        }
        all('input.score[data-sid="' + sid + '"]').forEach(function (inp) {
            var pc = p.pct[inp.dataset.aid];
            inp.classList.toggle('low', pc !== undefined && pc < LOW);
        });
        setCells('running', sid, null, function (c) { c.textContent = pctText(p.running); c.classList.toggle('bad', p.running !== null && p.running < FAIL); });
        setCells('final', sid, null, function (c) { c.innerHTML = p.final === null ? '<span class="notyet">Not yet</span>' : pctText(p.final); });
        setCells('abs', sid, null, function (c) {
            c.textContent = p.att.total ? pts(p.att.points) : '—';
            c.classList.toggle('warn', p.alert === 'warn'); c.classList.toggle('drop', p.alert === 'drop');
            c.title = 'Absent ' + p.att.absent + ' · Late ' + p.att.late + ' · Excused ' + p.att.excused;
        });
        setCells('risk', sid, null, function (c) {
            c.innerHTML = p.risk ? '<span class="badge ' + badge[p.risk.level] + '">' + p.risk.level + ' ' + Math.round(p.risk.prob) + '%</span>'
                                 : '<span class="badge" style="background:#eef2f5;color:#8a959b;">Not scored yet</span>';
        });
        setCells('attn', sid, null, function (c) {
            c.dataset.full = p.attention.join(' · ');
            c.innerHTML = p.attention.length ? '<span class="attn-clamp"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(p.attention.join(' · ')) + '</span>' : '<span style="color:#8a959b;">—</span>';
        });
        setCells('flag', sid, null, function (c) {
            c.innerHTML = p.attention.length ? '<i class="fa-solid fa-triangle-exclamation"></i>' : ''; c.title = p.attention.join(' · ');
        });
        all('tr[data-sid="' + sid + '"]').forEach(function (tr) { tr.classList.toggle('attn', p.attention.length > 0); });
        refreshAbsBox(); refreshChip(); refreshAverages(); applyRowFilter();
    }
    function applyAll(res) { (res.students || []).forEach(apply); }

    function refreshAbsBox() {
        var box = document.getElementById('absBox'), list = document.getElementById('absList');
        if (!box) return;
        var d = [], w = [], seen = {};
        all('#summaryTable td[data-cell="abs"]').forEach(function (td) {
            var sid = td.dataset.sid; if (seen[sid]) return; seen[sid] = 1;
            var name = td.closest('tr').querySelector('.name strong').textContent;
            if (td.classList.contains('drop')) d.push('<b class="d">' + esc(name) + ' (' + td.textContent + ' — limit reached)</b>');
            else if (td.classList.contains('warn')) w.push('<b class="w">' + esc(name) + ' (' + td.textContent + ')</b>');
        });
        list.innerHTML = d.concat(w).join(' · ');
        var n = d.length + w.length, cnt = document.getElementById('absCount');
        if (cnt) { cnt.textContent = n; cnt.parentNode.lastChild.textContent = ' absence alert' + (n === 1 ? '' : 's'); }
        box.hidden = n === 0;
    }
    function refreshChip() {
        var chip = document.getElementById('attnChip');
        if (chip) { var n = all('#summaryTable tr.attn').length; chip.textContent = n; chip.hidden = n === 0; }
    }
    // class average under every column (worked out from what is on the page, so it follows every change)
    function num(t) { var v = parseFloat(String(t).replace('%', '').replace('*', '')); return isNaN(v) ? null : v; }
    function avgOf(vals) { return vals.length ? vals.reduce(function (a, b) { return a + b; }, 0) / vals.length : null; }
    function refreshAverages() {
        all('td[data-avg]').forEach(function (td) {
            var k = td.dataset.avg, vals = [], m;
            if ((m = /^a(\d+)$/.exec(k))) {
                all('input.score[data-aid="' + m[1] + '"]').forEach(function (i) { var v = parseFloat(i.value), mx = parseFloat(i.max); if (!isNaN(v) && mx > 0) vals.push(v / mx * 100); });
            } else if ((m = /^(cs|exam|grade)(\d)$/.exec(k))) {
                all('td[data-cell="' + m[1] + '"][data-term="' + m[2] + '"]').forEach(function (c) { if (c.closest('table').id === 'summaryTable') return; var v = num(c.textContent); if (v !== null) vals.push(v); });
            }
            var a = avgOf(vals);
            td.textContent = a === null ? '' : fmtG(a) + '%';
            td.classList.toggle('bad', a !== null && a < FAIL);
        });
    }
    // find a student / only those who need attention
    var find = document.getElementById('rowFind'), only = document.getElementById('onlyAttn');
    function applyRowFilter() {
        var text = find ? find.value.trim().toLowerCase() : '', on = only && only.checked;
        all('tbody tr[data-sid]').forEach(function (tr) {
            var nm = (tr.querySelector('.name') ? tr.querySelector('.name').textContent : '').toLowerCase();
            tr.hidden = (text && nm.indexOf(text) === -1) || (on && !tr.classList.contains('attn'));
        });
    }
    if (find) find.addEventListener('input', applyRowFilter);
    if (only) only.addEventListener('change', applyRowFilter);

    // ---------- unlock (the PIN is asked in a pop-up in the middle of the screen) ----------
    var unlockBtn = document.getElementById('unlockBtn');
    if (unlockBtn) {
        unlockBtn.addEventListener('click', function () {
            askSecret({ title: 'Unlock to edit', message: 'Enter your PIN to record in this class. It stays unlocked for one hour.', type: 'pin', confirmText: 'Unlock' }).then(function (pin) {
                if (pin === null) return;
                api('unlock', { credential: pin }).then(function (res) {
                    if (res.redirect) { location.href = res.redirect; return; }
                    if (!res.ok) { say(res.error || 'Wrong PIN.', true); return; }
                    setLocked(false); say('Unlocked ✓');
                });
            });
        });
    }

    // ---------- the whole "needs attention" note on hover ----------
    var tip = document.createElement('div'); tip.className = 'attn-tip'; tip.hidden = true; document.body.appendChild(tip);
    sheet.addEventListener('mouseover', function (e) {
        var td = e.target.closest ? e.target.closest('td.attn-text') : null;
        if (!td || !td.dataset.full) { tip.hidden = true; return; }
        tip.innerHTML = '<b>Needs attention</b>' + td.dataset.full.split(' · ').map(function (x) { return '<div>' + esc(x) + '</div>'; }).join('');
        tip.hidden = false;
        var r = td.getBoundingClientRect(), h = tip.offsetHeight, top = r.bottom + 6;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
        tip.style.left = Math.max(8, Math.min(r.left, window.innerWidth - tip.offsetWidth - 8)) + 'px'; tip.style.top = top + 'px';
    });
    sheet.addEventListener('mouseleave', function () { tip.hidden = true; });
    sheet.addEventListener('scroll', function () { tip.hidden = true; }, true);

    // ---------- Scores | Attendance view of a term ----------
    all('.cls-seg button').forEach(function (b) {
        b.addEventListener('click', function () {
            var pane = b.closest('.cls-pane'); pane.dataset.view = b.dataset.view;
            Array.prototype.forEach.call(b.parentNode.children, function (x) { x.classList.toggle('on', x === b); });
        });
    });

    // ---------- Dropped (Unofficially): the professor decides, with the PIN; the Program Head sees it at once ----------
    function statusCall(payload) {
        return fetch('status_api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(payload) })
            .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'No connection to the server.' }; });
    }
    function afterStatus(res) {
        if (res.redirect) { location.href = res.redirect; return; }
        if (!res.ok) { say(res.error || 'Could not save.', true); if (window.toast) toast(res.error || 'Could not save.', 'bad'); return; }
        if (window.toast) toast(res.message, 'ok');
        setTimeout(reloadHere, 700);
    }

    // ---------- saving ----------
    all('.col-type').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var o = document.getElementById('colOther' + sel.dataset.term);
            o.hidden = sel.value !== '__other'; o.disabled = o.hidden || sel.disabled; if (!o.hidden) o.focus();
        });
    });
    sheet.addEventListener('change', function (e) {
        var el = e.target;
        if (el.classList.contains('att-sel')) {
            var want = el.value, prev = el.dataset.prev;
            el.className = 'rec att-sel st-' + want;
            api('set_attendance', { meeting_id: el.dataset.mid, student_id: el.dataset.sid, status: want }).then(function (res) {
                if (handle(res, function () { el.value = prev; el.className = 'rec att-sel st-' + prev; })) { el.dataset.prev = want; applyAll(res); }
            });
        } else if (el.classList.contains('score')) {
            var v = el.value.trim(), max = parseFloat(el.max);
            if (v !== '' && (isNaN(parseFloat(v)) || parseFloat(v) < 0 || parseFloat(v) > max)) {
                say('The score must be from 0 to ' + max + '.', true); el.value = el.dataset.prev; return;
            }
            if (v !== '' && (parseFloat(v) * 2) % 1 !== 0) { say('A score is a whole number or .5 (like 8 or 8.5).', true); el.value = el.dataset.prev; return; }
            api('set_score', { activity_id: el.dataset.aid, student_id: el.dataset.sid, score: v }).then(function (res) {
                if (handle(res, function () { el.value = el.dataset.prev; })) { el.dataset.prev = v; applyAll(res); }
            });
        } else if (el.classList.contains('items-input')) {
            api('set_items', { activity_id: el.dataset.aid, items: el.value }).then(function (res) { handle(res, function () { location.reload(); }); });
        }
    });

    // ---------- spreadsheet keys: Enter / ↓ next student, ↑ / Shift+Enter previous, in the same column ----------
    function moveFrom(el, dir) {
        var td = el.closest('td'), tr = td.parentNode, idx = Array.prototype.indexOf.call(tr.children, td);
        var next = dir > 0 ? tr.nextElementSibling : tr.previousElementSibling;
        while (next && next.hidden) next = dir > 0 ? next.nextElementSibling : next.previousElementSibling;
        if (next && next.children[idx]) { var i = next.children[idx].querySelector('input.score, select.att-sel'); if (i) { i.focus(); if (i.select) i.select(); } }
    }
    sheet.addEventListener('keydown', function (e) {
        var el = e.target;
        if (!(el.classList.contains('score') || el.classList.contains('att-sel'))) return;
        if (e.key === 'Enter') { e.preventDefault(); moveFrom(el, e.shiftKey ? -1 : 1); }
        else if (el.classList.contains('score') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); moveFrom(el, e.key === 'ArrowDown' ? 1 : -1); }
    });
    // ---------- paste a block of scores copied from Excel ----------
    sheet.addEventListener('paste', function (e) {
        var el = e.target;
        if (!el.classList || !el.classList.contains('score') || el.disabled) return;
        var text = (e.clipboardData || window.clipboardData).getData('text');
        if (!/[\n\t]/.test(text.replace(/\s+$/, ''))) return;               // a single value pastes normally
        e.preventDefault();
        var grid = text.replace(/\r/g, '').replace(/\n+$/, '').split('\n').map(function (r) { return r.split('\t'); });
        var tr0 = el.closest('tr'), td0 = el.closest('td'), col0 = Array.prototype.indexOf.call(tr0.children, td0);
        var rows = Array.prototype.slice.call(tr0.parentNode.children).filter(function (r) { return !r.hidden; });
        var start = rows.indexOf(tr0), n = 0;
        grid.forEach(function (cells, ri) {
            var tr = rows[start + ri]; if (!tr) return;
            cells.forEach(function (val, ci) {
                var cell = tr.children[col0 + ci], inp = cell && cell.querySelector('input.score');
                val = val.trim();
                if (!inp || val === '' || inp.disabled) return;
                inp.value = val; inp.dispatchEvent(new Event('change', { bubbles: true })); n++;
            });
        });
        say(n + ' score' + (n === 1 ? '' : 's') + ' pasted…');
    });

    // ---------- the class list (masterlist) ----------
    var mlFile = document.getElementById('mlFile');
    if (mlFile) mlFile.addEventListener('change', function () {
        var f = mlFile.files[0]; if (!f) return;
        var r = new FileReader();
        r.onload = function () { var t = document.getElementById('mlText'); t.value = (t.value ? t.value.replace(/\s+$/, '') + '\n' : '') + r.result; };
        r.readAsText(f); mlFile.value = '';
    });
    function renderPreview(rows) {
        var label = { 'new': ['New — will be created', '#e8f6ec', '#1b6b3a'], existing: ['Already in the system — will be added', '#e8f4fb', '#1f4f9f'], in_class: ['Already in this class', '#eef2f5', '#64727a'], conflict: ['Problem', '#fff1f0', '#b3261e'], error: ['Problem', '#fff1f0', '#b3261e'] };
        var ok = 0, html = '<div class="table-wrap" style="margin:10px 0;max-height:320px;overflow:auto;"><table><thead><tr><th>#</th><th>Student No.</th><th>Name</th><th>Result</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            var l = label[r.status]; if (r.status === 'new' || r.status === 'existing') ok++;
            html += '<tr><td>' + r.line + '</td><td>' + esc(r.id || '—') + '</td><td>' + esc(r.name || '—') + '</td><td><span class="badge" style="background:' + l[1] + ';color:' + l[2] + ';">' + esc(r.issue || l[0]) + '</span></td></tr>';
        });
        html += '</tbody></table></div>';
        html += ok ? '<button type="button" class="btn-primary rec" data-act="ml-import"><i class="fa-solid fa-user-plus"></i> Add ' + ok + ' student' + (ok === 1 ? '' : 's') + ' to this class</button>'
                   : '<p class="cls-hint">Nobody can be added yet — fix the lines marked Problem.</p>';
        document.getElementById('mlResult').innerHTML = html;
    }

    function val(id) { var x = document.getElementById(id); return x ? x.value : ''; }
    sheet.addEventListener('click', function (e) {
        var a = e.target.closest('[data-act]');
        if (!a || a.disabled) return;
        e.preventDefault();
        var act = a.dataset.act, t = a.dataset.term;
        if (act === 'add-meeting') { api('add_meeting', { date: val('mtDate' + t), term: t }).then(function (res) { handle(res); }); }
        else if (act === 'all-present') { api('all_present', { meeting_id: a.dataset.mid }).then(function (res) {
            if (handle(res)) { all('.att-sel[data-mid="' + a.dataset.mid + '"]').forEach(function (s) { s.value = 'P'; s.dataset.prev = 'P'; s.className = 'rec att-sel st-P'; }); applyAll(res); } }); }
        else if (act === 'del-meeting') { if (confirm('Delete this meeting and its attendance?')) api('delete_meeting', { meeting_id: a.dataset.mid }).then(function (res) { handle(res); }); }
        else if (act === 'add-column') {
            var type = val('colType' + t) === '__other' ? val('colOther' + t) : val('colType' + t);
            api('add_activity', { term: t, component: type, title: val('colTitle' + t), items: val('colItems' + t) }).then(function (res) { handle(res); });
        }
        else if (act === 'del-activity') { if (confirm('Remove this column and every score in it?')) api('delete_activity', { activity_id: a.dataset.aid }).then(function (res) { handle(res); }); }
        else if (act === 'save-weights' || act === 'clear-weights') {
            var w = {};
            if (act === 'save-weights') all('.w-input').forEach(function (i) { w[i.dataset.comp] = i.value; });
            api('set_weights', { weights: w }).then(function (res) { handle(res); });
        }
        else if (act === 'ml-preview') { api('preview_students', { text: val('mlText') }).then(function (res) { if (res.ok) { say(''); renderPreview(res.rows); } else handle(res); }); }
        else if (act === 'ml-import') { api('import_students', { text: val('mlText') }).then(function (res) { handle(res); }); }
        else if (act === 'add-student') {
            var raw = val('addStudentInput'), idm = /—\s*([A-Za-z0-9-]+)\s*$/.exec(raw);
            api('add_student', { student_id: idm ? idm[1] : raw.trim() }).then(function (res) { handle(res); });
        }
        else if (act === 'remove-student') { if (confirm('Take this student out of the class? Their scores and attendance here are deleted.')) api('remove_student', { student_id: a.dataset.sid }).then(function (res) { handle(res); }); }
        else if (act === 'mark-du') {
            askSecret({ title: 'Mark as Dropped (Unofficially)', message: a.dataset.name + ' will be recorded as Dropped (Unofficially) — your decision as the professor. The Program Head will see it. Enter your PIN to continue.', type: 'pin', confirmText: 'Mark dropped', danger: true }).then(function (pin) {
                if (pin === null) return;
                statusCall({ action: 'mark_du', class_id: CID, student_id: a.dataset.sid, credential: pin }).then(afterStatus);
            });
        }
        else if (act === 'undo-du') {
            askSecret({ title: 'Take back Dropped (Unofficially)', message: a.dataset.name + ' will be Enrolled again. Enter your PIN to continue.', type: 'pin', confirmText: 'Take back' }).then(function (pin) {
                if (pin === null) return;
                statusCall({ action: 'undo_du', case_id: a.dataset.case, credential: pin }).then(afterStatus);
            });
        }
        else if (act === 'download-csv') downloadCsv();
    });

    function downloadCsv() {
        var rows = [];
        var cellText = function (c) { return '"' + c.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"'; };
        rows.push(all('#summaryTable thead th').map(cellText).join(','));
        all('#summaryTable tbody tr').forEach(function (tr) { rows.push(Array.prototype.slice.call(tr.children).map(cellText).join(',')); });
        var blob = new Blob(['\uFEFF' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = (sheet.dataset.title || 'class').replace(/[^A-Za-z0-9-]+/g, '_') + '.csv';
        document.body.appendChild(link); link.click(); link.remove();
    }

    setLocked(sheet.dataset.unlocked !== '1');
    refreshAverages(); refreshChip();
})();
