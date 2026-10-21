/**
 * Import preview: every PEF that is missing something is a red card with a "!".
 * As the missing parts are typed in, the card turns normal. The Import button stays OFF until every card is complete
 * (the server checks again and refuses an incomplete upload as well).
 * A card whose problem only the system can judge (e.g. a clash with a record already saved) turns amber
 * ("will be re-checked") once something in it has been changed.
 */
(function () {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.attn-row[data-kind="err"]'));
    var btn = document.getElementById('commitBtn');
    var hint = document.getElementById('blockedHint');

    function validId(v) { return /^[A-Z0-9][A-Z0-9-]{2,19}$/.test(v) && /\d/.test(v); }

    function stateOf(card) {
        var ok = true;
        var idEl = card.querySelector('.fix-id');
        var nameEl = card.querySelector('.fix-name');
        if (idEl && !validId(idEl.value.replace(/\s+/g, '').toUpperCase())) ok = false;
        if (nameEl && nameEl.value.trim() === '') ok = false;
        card.querySelectorAll('select.fix-sel').forEach(function (s) { if (s.value === '') ok = false; });
        card.querySelectorAll('input.fix-grade').forEach(function (g) {
            var v = parseFloat(g.value);
            if (g.value.trim() === '' || isNaN(v) || v < 0 || (v > 4 && v !== 5)) ok = false;
        });
        if (!ok) return 'bad';
        if (card.dataset.server === '1') {
            var changed = false;
            card.querySelectorAll('input,select').forEach(function (el) { if (el.dataset.orig !== undefined && el.value !== el.dataset.orig) changed = true; });
            return changed ? 'recheck' : 'bad';
        }
        return 'fixed';
    }

    function refresh() {
        var bad = 0;
        cards.forEach(function (card) {
            var s = stateOf(card);
            if (s === 'bad') bad++;
            card.classList.toggle('err', s === 'bad');
            card.classList.toggle('fixed', s === 'fixed');
            card.classList.toggle('recheck', s === 'recheck');
            var bang = card.querySelector('.bang');
            if (bang) bang.textContent = s === 'bad' ? '!' : (s === 'fixed' ? '✓' : '↻');
            var st = card.querySelector('.fix-state');
            if (st) st.textContent = s === 'bad' ? 'Needs completing' : (s === 'fixed' ? 'Complete' : 'Changed — will be re-checked when you import');
        });
        if (btn) btn.disabled = bad > 0;
        if (hint) hint.style.display = bad > 0 ? 'block' : 'none';
        var left = document.getElementById('badLeft');
        if (left) left.textContent = bad;
    }

    cards.forEach(function (card) {
        card.querySelectorAll('input,select').forEach(function (el) {
            el.addEventListener('input', refresh);
            el.addEventListener('change', refresh);
        });
    });
    refresh();
})();
