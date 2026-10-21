/**
 * Shared PIN / password prompts (Faculty: Student Data PIN, Admin: password).
 * Wrong answers show an inline message and let the person retry without losing anything.
 */
(function () {
    const modal = document.getElementById('credModal');
    let pendingForm = null;

    async function verify(value) {
        const fd = new FormData();
        fd.append('credential', value);
        // accounts and class assignments use the Account-Management Password; everything else the Student Data PIN
        fd.append('type', /(users|assignments)\.php/.test(location.pathname) ? 'action_password' : 'pin');
        const res = await fetch('verify.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        return res.json();
    }
    function showErr(el, msg) { el.textContent = msg; el.style.display = 'block'; }
    function handleLogout() {
        alert('Too many incorrect attempts. For security you have been logged out.');
        window.location.href = '../index.php?error=locked';
    }

    // For forms that already contain their own PIN/password field (Add/Edit modals).
    window.guardForm = function (form, input, errEl) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            errEl.style.display = 'none';
            if (!input.value) { showErr(errEl, 'Please enter it to continue.'); input.focus(); return; }
            let res;
            try { res = await verify(input.value); }
            catch (x) { showErr(errEl, 'Could not verify right now. Please try again.'); return; }
            if (res.ok) { form.submit(); return; }
            showErr(errEl, res.error || 'Verification failed.');
            input.value = '';
            input.focus();
            if (res.logout) handleLogout();
        });
    };

    // For one-click actions (Delete / Reset) — opens a small prompt, then submits the form.
    window.confirmWithCredential = function (form, opts) {
        if (!modal) return true;
        opts = opts || {};
        pendingForm = form;
        document.getElementById('credTitle').textContent = opts.title || 'Please confirm';
        document.getElementById('credMessage').textContent = opts.message || '';
        const inp = document.getElementById('credInput');
        inp.value = '';
        document.getElementById('credError').style.display = 'none';
        modal.classList.add('show');
        setTimeout(function () { inp.focus(); }, 50);
        return false;
    };

    window.closeCredModal = function () {
        if (modal) modal.classList.remove('show');
        pendingForm = null;
    };

    if (modal) {
        const inp = document.getElementById('credInput');
        const err = document.getElementById('credError');
        if (modal.dataset.type === 'pin') {
            inp.setAttribute('inputmode', 'numeric');
            inp.setAttribute('maxlength', '6');
            inp.addEventListener('input', function () { inp.value = inp.value.replace(/\D/g, '').slice(0, 6); });
        }
        async function confirmClick() {
            if (!inp.value) { showErr(err, 'Please enter it to continue.'); return; }
            let res;
            try { res = await verify(inp.value); }
            catch (x) { showErr(err, 'Could not verify right now. Please try again.'); return; }
            if (res.ok && pendingForm) {
                pendingForm.querySelector('.cred-field').value = inp.value;
                pendingForm.submit();
                return;
            }
            showErr(err, res.error || 'Verification failed.');
            inp.value = '';
            inp.focus();
            if (res.logout) handleLogout();
        }
        document.getElementById('credConfirmBtn').addEventListener('click', confirmClick);
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); confirmClick(); } });
        modal.addEventListener('click', function (e) { if (e.target === modal) window.closeCredModal(); });
    }
})();
