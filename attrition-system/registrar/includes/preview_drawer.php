<!-- a student opens here, in a panel at the side: the page you are on stays exactly where it was -->
<div class="pv-overlay" id="pvOverlay"></div>
<aside class="pv-drawer" id="pvDrawer" aria-hidden="true">
    <div class="pv-top"><strong><i class="fa-solid fa-user"></i> Student</strong><button type="button" id="pvClose" aria-label="Close">✕</button></div>
    <div class="pv-body" id="pvBody"></div>
</aside>
<script>
(function () {
    var drawer = document.getElementById('pvDrawer'), overlay = document.getElementById('pvOverlay'), body = document.getElementById('pvBody');
    function close() { drawer.classList.remove('show'); overlay.classList.remove('show'); drawer.setAttribute('aria-hidden', 'true'); }
    function open(query) {
        body.innerHTML = '<div class="pv-loading"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</div>';
        drawer.classList.add('show'); overlay.classList.add('show'); drawer.setAttribute('aria-hidden', 'false'); body.scrollTop = 0;
        fetch('student_preview.php?' + query, { credentials: 'same-origin' }).then(function (r) { return r.text(); })
            .then(function (h) { body.innerHTML = h; }).catch(function () { body.innerHTML = '<p style="padding:20px;">Could not load the student.</p>'; });
    }
    // any link to a student (a list, a bar, the window of a graph, the bell) opens the panel instead of another page
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('data-full') || e.ctrlKey || e.metaKey || e.shiftKey) return;
        var m = /^student\.php\?(id=[^#]*)/.exec(a.getAttribute('href'));
        if (!m || /student\.php$/.test(location.pathname)) return;
        e.preventDefault(); open(m[1]);
    });
    document.getElementById('pvClose').addEventListener('click', close);
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('show')) close(); });
})();
</script>
