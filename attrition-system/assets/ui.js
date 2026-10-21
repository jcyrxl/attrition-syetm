/**
 * Shared interface helpers.
 *   toast(message, 'ok' | 'bad' | 'warn' | '')       a small message that fades by itself
 *   askSecret({title, message, type, confirmText, danger, requireText})  -> Promise<string|null>
 *       A pop-up in the middle of the screen that asks for the Student Data PIN ('pin') or the Account-Management Password
 *       ('action_password'), checks it with the server, and answers with it (null if cancelled). Used by every save / delete,
 *       so nobody has to scroll to the end of a page to type a password.
 */
(function () {
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
    function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

    var wrap;
    window.toast = function (message, type, ms) {
        if (!wrap) { wrap = el('div', 'toast-wrap'); document.body.appendChild(wrap); }
        var icon = { ok: 'fa-circle-check', bad: 'fa-circle-exclamation', warn: 'fa-triangle-exclamation' }[type] || 'fa-circle-info';
        var t = el('div', 'toast ' + (type || ''), '<i class="fa-solid ' + icon + '"></i><span>' + esc(message) + '</span>');
        wrap.appendChild(t);
        setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 300); }, ms || 4200);
    };

    window.askSecret = function (opts) {
        opts = opts || {};
        var type = opts.type || 'pin';
        var label = opts.label || (type === 'pin' ? 'Your Student Data PIN' : 'Your Account-Management Password');
        return new Promise(function (resolve) {
            var ov = el('div', 'sx-overlay');
            var box = el('div', 'sx-box' + (opts.danger ? ' danger' : ''));
            box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
            box.innerHTML = '<div class="sx-icon"><i class="fa-solid ' + (opts.danger ? 'fa-triangle-exclamation' : 'fa-shield-halved') + '"></i></div>' +
                '<h3>' + esc(opts.title || 'Please confirm') + '</h3>' + (opts.message ? '<p>' + opts.message + '</p>' : '') +
                (opts.requireText ? '<label>Type <strong>' + esc(opts.requireText) + '</strong> to continue</label><input type="text" class="sx-text" autocomplete="off" spellcheck="false">' : '') +
                '<label>' + esc(label) + '</label><input type="password" class="sx-secret" autocomplete="off"' + (type === 'pin' ? ' inputmode="numeric" maxlength="6"' : '') + '>' +
                '<div class="sx-err"></div>' +
                '<div class="sx-actions"><button type="button" class="btn-secondary sx-cancel">Cancel</button><button type="button" class="' + (opts.danger ? 'btn-danger-solid' : 'btn-primary') + ' sx-ok">' + esc(opts.confirmText || 'Confirm') + '</button></div>';
            ov.appendChild(box); document.body.appendChild(ov);
            var secret = box.querySelector('.sx-secret'), text = box.querySelector('.sx-text'), err = box.querySelector('.sx-err'), ok = box.querySelector('.sx-ok');
            if (type === 'pin') secret.addEventListener('input', function () { secret.value = secret.value.replace(/\D/g, '').slice(0, 6); });
            (text || secret).focus();
            function close(v) { document.removeEventListener('keydown', onKey); ov.remove(); resolve(v); }
            function onKey(e) { if (e.key === 'Escape') close(null); else if (e.key === 'Enter') submit(); }
            document.addEventListener('keydown', onKey);
            box.querySelector('.sx-cancel').addEventListener('click', function () { close(null); });
            ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(null); });
            var busy = false;
            function submit() {
                if (busy) return;
                if (text && text.value.trim() !== opts.requireText) { err.textContent = 'Type ' + opts.requireText + ' exactly to continue.'; text.focus(); return; }
                if (!secret.value) { err.textContent = 'Please enter it to continue.'; secret.focus(); return; }
                busy = true; ok.disabled = true; err.textContent = '';
                var fd = new FormData(); fd.append('credential', secret.value); fd.append('type', type);
                fetch('verify.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res.ok) { close(secret.value); return; }
                    busy = false; ok.disabled = false; err.textContent = res.error || 'That is not correct.'; secret.value = ''; secret.focus();
                    if (res.logout) { alert('Too many incorrect attempts. For security you have been logged out.'); location.href = '../index.php?error=locked'; }
                }).catch(function () { busy = false; ok.disabled = false; err.textContent = 'Could not check it right now. Please try again.'; });
            }
            ok.addEventListener('click', submit);
        });
    };
})();
