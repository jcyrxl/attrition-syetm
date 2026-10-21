<!-- the window that opens when a graph is tapped: the data behind it. A row can be a student (opens the preview panel), plain text, or another window -->
<div class="modal-overlay" id="dpop">
    <div class="modal-box" style="width:min(720px,calc(100vw - 24px));">
        <div style="display:flex;align-items:center;gap:10px;"><button type="button" id="dpopBack" class="btn-secondary" style="padding:6px 12px;font-size:12.5px;" hidden>← Back</button><h2 id="dpopTitle" style="margin:0;">Data</h2></div>
        <p id="dpopSub" style="font-size:13px;color:var(--ink-3);margin:6px 0 10px;"></p>
        <div id="dpopBody" style="max-height:58vh;overflow:auto;"></div>
        <div class="modal-actions"><button type="button" class="btn-secondary" id="dpopClose">Close</button></div>
    </div>
</div>
<script>
(function () {
    var ov = document.getElementById('dpop'), body = document.getElementById('dpopBody'), back = document.getElementById('dpopBack');
    var stack = [];
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
    var current = null;
    function render(d) {
        current = d;
        document.getElementById('dpopTitle').textContent = d.t;
        document.getElementById('dpopSub').textContent = d.s || '';
        back.hidden = stack.length === 0;
        var html = '<table style="width:100%;border-collapse:collapse;font-size:13.5px;"><tbody>';
        (d.r || []).forEach(function (r, idx) {
            if (r.p) {
                html += '<tr class="dp-nest" data-i="' + idx + '" style="border-top:1px solid #eef1f3;cursor:pointer;"><td style="padding:10px 6px;"><strong style="color:#075f73;">' + esc(r.n) + '</strong></td><td style="padding:10px 6px;text-align:right;color:#33475a;">' + esc(r.d) + ' <i class="fa-solid fa-chevron-right" style="color:#8aa4ae;font-size:11px;margin-left:6px;"></i></td></tr>';
            } else if (r.i || r.h) {
                html += '<tr style="border-top:1px solid #eef1f3;"><td style="padding:9px 6px;"><a href="' + esc(r.h || ('student.php?id=' + encodeURIComponent(r.i))) + '" style="color:#075f73;font-weight:600;">' + esc(r.n) + '</a>' + (r.i ? '<div style="font-size:11.5px;color:#8a959b;">' + esc(r.i) + '</div>' : '') + '</td><td style="padding:9px 6px;text-align:right;color:#33475a;">' + esc(r.d) + '</td></tr>';
            } else {
                html += '<tr style="border-top:1px solid #eef1f3;"><td style="padding:9px 6px;font-weight:600;">' + esc(r.n) + '</td><td style="padding:9px 6px;text-align:right;color:#33475a;">' + esc(r.d) + '</td></tr>';
            }
        });
        if (!(d.r || []).length) html += '<tr><td style="padding:16px;color:#8a959b;text-align:center;">Nobody here.</td></tr>';
        body.innerHTML = html + '</tbody></table>';
        body.scrollTop = 0;
    }
    body.addEventListener('click', function (e) {
        var tr = e.target.closest('.dp-nest'); if (!tr) return;
        stack.push(current); render(current.r[parseInt(tr.dataset.i, 10)].p);
    });
    back.addEventListener('click', function () { if (stack.length) render(stack.pop()); });
    function close() { ov.classList.remove('show'); stack = []; }
    document.getElementById('dpopClose').addEventListener('click', close);
    ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ov.classList.contains('show') && !document.getElementById('pvDrawer').classList.contains('show')) close(); });
    // tap a point, a slice, a "See all" button ... = the data behind it, in a window (no page change)
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-pop]');
        if (!el) return;
        var d; try { d = JSON.parse(el.getAttribute('data-pop')); } catch (x) { return; }
        e.preventDefault(); stack = []; render(d); ov.classList.add('show');
    });
    // "See more graphs"
    var tg = document.getElementById('moreToggle'), mb = document.getElementById('moreBlock');
    if (tg && mb) {
        function set(open) { mb.hidden = !open; tg.classList.toggle('open', open); tg.querySelector('span').textContent = open ? 'Show fewer graphs' : 'See more graphs'; try { localStorage.setItem('dashMore', open ? '1' : '0'); } catch (x) {} }
        tg.addEventListener('click', function () { set(mb.hidden); });
        try { if (localStorage.getItem('dashMore') === '1') set(true); } catch (x) {}
    }
})();
</script>
