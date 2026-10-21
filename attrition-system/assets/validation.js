/**
 * Shared front-end validation helpers.
 * These are UX conveniences only — config/validation.php on the server
 * is what actually enforces the rules, so nothing here can be bypassed
 * by disabling JavaScript.
 */

/**
 * Locks a User ID input to digits and dashes (-) only. Any letter/other
 * symbol keystroke is blocked immediately (never appears in the field)
 * and a short "Invalid" message is shown next to the field for a moment.
 */
function lockUserIdField(inputEl, msgEl, opts) {
    opts = opts || {};
    if (opts.noClipboard) {
        // numbers and dashes can only be TYPED: no paste, cut, copy, drag-and-drop or right-click menu in this box
        ['paste', 'cut', 'copy', 'drop', 'dragstart', 'contextmenu'].forEach(function (ev) { inputEl.addEventListener(ev, function (e) { e.preventDefault(); showMsg('Please type the User ID — copy and paste is not allowed here.'); }); });
        inputEl.addEventListener('keydown', function (e) {
            var k = e.key, ctrl = e.ctrlKey || e.metaKey;
            if ((ctrl && /^[vxc]$/i.test(k)) || (e.shiftKey && (k === 'Insert' || k === 'Delete'))) { e.preventDefault(); showMsg('Please type the User ID — copy and paste is not allowed here.'); return; }
            if (k.length === 1 && !ctrl && !/[0-9-]/.test(k)) { e.preventDefault(); showMsg('Invalid User ID — numbers and dashes (-) only. Other characters are not allowed.'); }
        });
        inputEl.addEventListener('beforeinput', function (e) { if (e.inputType && /insertFromPaste|insertFromDrop|insertFromYank/.test(e.inputType)) { e.preventDefault(); } });
        inputEl.setAttribute('autocomplete', 'off'); inputEl.setAttribute('spellcheck', 'false');
        if (!inputEl.getAttribute('maxlength')) { inputEl.setAttribute('maxlength', '20'); }
    }
    function showMsg(text) {
        if (!msgEl) return;
        msgEl.textContent = text; msgEl.style.display = 'block';
        clearTimeout(inputEl._msgTimeout); inputEl._msgTimeout = setTimeout(function () { msgEl.style.display = 'none'; }, 2500);
    }
    inputEl.addEventListener('input', function () {
        const original = inputEl.value;
        const allowedOnly = original.replace(/[^0-9-]/g, '');
        if (original !== allowedOnly) {
            inputEl.value = allowedOnly;
            if (msgEl) {
                msgEl.textContent = 'Invalid User ID — numbers and dashes (-) only. Other characters are not allowed.';
                msgEl.style.display = 'block';
                clearTimeout(inputEl._msgTimeout);
                inputEl._msgTimeout = setTimeout(() => { msgEl.style.display = 'none'; }, 2500);
            }
        } else if (msgEl) {
            msgEl.style.display = 'none';
        }
    });
}

/**
 * Attaches a live strength checklist under a password field.
 * Safe to use more than once on the same page (each checklist keeps its own items).
 */
function attachPasswordStrength(inputEl, checklistEl) {
    const rules = [
        { test: v => v.length >= 8, label: 'At least 8 characters' },
        { test: v => /[A-Z]/.test(v), label: 'One uppercase letter (A-Z)' },
        { test: v => /[a-z]/.test(v), label: 'One lowercase letter (a-z)' },
        { test: v => /[0-9]/.test(v), label: 'One number (0-9)' },
        { test: v => /[^A-Za-z0-9]/.test(v), label: 'One special character (e.g. ! @ # $)' },
    ];

    checklistEl.innerHTML = '';
    const items = rules.map(function (r) {
        const li = document.createElement('li');
        li.style.color = '#c62828';
        li.style.transition = 'color .2s';
        li.textContent = '✕ ' + r.label;
        checklistEl.appendChild(li);
        return li;
    });
    checklistEl.style.listStyle = 'none';
    checklistEl.style.padding = '0';
    checklistEl.style.margin = '8px 0 0';
    checklistEl.style.fontSize = '13px';
    checklistEl.style.lineHeight = '1.9';

    inputEl.addEventListener('input', function () {
        const v = inputEl.value;
        rules.forEach(function (r, i) {
            const passed = r.test(v);
            items[i].textContent = (passed ? '✓ ' : '✕ ') + r.label;
            items[i].style.color = passed ? '#16824a' : '#c62828';
        });
    });

    return function isStrong() {
        return rules.every(r => r.test(inputEl.value));
    };
}
