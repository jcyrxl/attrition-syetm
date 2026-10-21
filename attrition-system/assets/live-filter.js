/**
 * Filters that never reload the page.
 *   <form id="filterForm" data-base="students.php"> — every named field is a filter
 *   [data-live] (with an id)         -> replaced with the server's fresh version after each change
 *   [data-live-attr="hidden,..."]    -> those attributes are copied from the fresh version (e.g. fields shown only in one mode)
 *   <select data-live-options>       -> its options are refreshed (e.g. sections of the chosen year)
 *   <select data-resets="section">   -> clears that other field when this one changes
 *   <a data-nav>                     -> loads in place as well
 * The address bar is kept up to date, so Back / Refresh / copy-link all keep working.
 */
(function () {
    const form = document.getElementById('filterForm');
    if (!form) return;
    const base = form.dataset.base || location.pathname.split('/').pop();
    let timer = null, controller = null;

    function buildUrl() {
        const p = new URLSearchParams();
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.type === 'submit' || el.type === 'button') return;
            if (el.closest('[hidden]')) return;
            if (el.value !== '') p.set(el.name, el.value);
        });
        const qs = p.toString();
        return base + (qs ? '?' + qs : '');
    }

    function swap(doc) {
        document.querySelectorAll('[data-live]').forEach(function (el) {
            const fresh = doc.getElementById(el.id);
            if (fresh) el.innerHTML = fresh.innerHTML;
        });
        document.querySelectorAll('[data-live-attr]').forEach(function (el) {
            const fresh = doc.getElementById(el.id);
            if (!fresh) return;
            el.dataset.liveAttr.split(',').forEach(function (a) {
                a = a.trim();
                if (a === 'hidden') el.hidden = fresh.hidden; else el.setAttribute(a, fresh.getAttribute(a) || '');
            });
        });
        form.querySelectorAll('select').forEach(function (sel) {
            const fresh = doc.querySelector('#filterForm select[name="' + sel.name + '"]');
            if (!fresh) return;
            if (sel.hasAttribute('data-live-options')) sel.innerHTML = fresh.innerHTML;
            sel.value = fresh.value;
        });
    }

    function load(url, push) {
        if (controller) controller.abort();
        controller = new AbortController();
        const area = document.getElementById('viewArea');
        if (area) area.style.opacity = '.55';
        fetch(url, { credentials: 'same-origin', signal: controller.signal })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                swap(new DOMParser().parseFromString(html, 'text/html'));
                if (push) history.pushState({}, '', url);
                if (area) area.style.opacity = '';
            })
            .catch(function (err) { if (err.name !== 'AbortError') window.location.href = url; });
    }

    window.applyFilters = function (changed) {
        if (changed && changed.dataset && changed.dataset.resets) {
            const t = form.elements[changed.dataset.resets];
            if (t) t.value = '';
        }
        if (changed && changed.tagName === 'INPUT') {
            clearTimeout(timer);
            timer = setTimeout(function () { load(buildUrl(), true); }, 280);
            return;
        }
        load(buildUrl(), true);
    };

    form.addEventListener('submit', function (e) { e.preventDefault(); window.applyFilters(); });
    document.addEventListener('click', function (e) {
        const a = e.target.closest('a[data-nav]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || a.target === '_blank') return;
        e.preventDefault();
        load(a.href, true);
    });
    window.addEventListener('popstate', function () { load(location.href, false); });
})();
