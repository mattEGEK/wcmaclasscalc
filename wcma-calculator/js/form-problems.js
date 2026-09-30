// wcma-calculator/js/form-problems.js
// Says in words what is missing, next to the field, and takes you to it (mobile UX spec 2026-09-28 §C3).
(function (root) {
    'use strict';

    function cleanLabel(label) {
        return String(label || '').replace(/\(required\)|\(optional\)/gi, '').replace(/\s+/g, ' ').trim();
    }

    /** The words for a missing field. Keeps acronyms like NASCC; lower-cases a leading capital word. */
    function messageFor(field) {
        if (field.dataMessage) return field.dataMessage;
        const label = cleanLabel(field.label);
        if (!label) return 'Please fill this in.';
        if (field.kind === 'radio') return 'Choose an answer for "' + label + '".';
        const words = label.split(' ');
        if (/^[A-Z][a-z]/.test(words[0])) words[0] = words[0].toLowerCase();
        return (field.kind === 'select' ? 'Choose the ' : 'Enter the ') + words.join(' ') + '.';
    }

    const api = { messageFor: messageFor };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }

    function labelText(el) {
        if (el.type === 'radio') {
            const group = el.closest('[data-radio-group]');
            return group ? group.getAttribute('data-radio-group') : '';
        }
        const lab = el.id ? root.document.querySelector('label[for="' + el.id + '"]') : null;
        return lab ? lab.textContent : '';
    }

    function show(target, message) {
        const next = target.nextElementSibling;
        if (next && next.classList.contains('field-message') && next.hasAttribute('data-problem')) { next.textContent = message; return; }
        const msg = root.document.createElement('p');
        msg.className = 'field-message';
        msg.setAttribute('data-problem', '');   // added here, so clearAll may remove it (fixed messages stay)
        msg.setAttribute('role', 'alert');
        msg.textContent = message;
        target.insertAdjacentElement('afterend', msg);
    }

    function focusOn(el) {
        if (!el.matches('input, select, textarea, button, [tabindex]')) el.setAttribute('tabindex', '-1');
        el.scrollIntoView({ block: 'center' });
        el.focus({ preventScroll: true });
    }

    function clearAll(form) {
        form.querySelectorAll('.field-message[data-problem]').forEach(function (m) { m.remove(); });
    }

    function wire(form, opts) {
        let first = !(opts && opts.noFocus);
        form.addEventListener('invalid', function (e) {
            const el = e.target;
            e.preventDefault();
            const group = el.type === 'radio' ? el.closest('[data-radio-group]') : null;
            const anchor = group || el;
            if (anchor.nextElementSibling && anchor.nextElementSibling.classList.contains('field-message')) return;
            show(anchor, messageFor({
                dataMessage: el.getAttribute('data-message') || (group && group.getAttribute('data-message')),
                kind: el.tagName === 'SELECT' ? 'select' : (el.type === 'radio' ? 'radio' : 'text'),
                label: labelText(el),
            }));
            if (first) { first = false; focusOn(el); setTimeout(function () { first = true; }, 0); }
        }, true);
        function clearFor(e) {
            const anchor = e.target.closest('[data-radio-group]') || e.target;
            const next = anchor.nextElementSibling;
            if (next && next.hasAttribute('data-problem')) next.remove();
        }
        form.addEventListener('input', clearFor);
        form.addEventListener('change', clearFor);
    }

    root.WcmaFormProblems = {
        messageFor: messageFor, clearAll: clearAll, wire: wire,
        show: function (target, message) { show(target, message); focusOn(target); },
        mark: show,        // the message only: a form that reports every problem at once focuses the first itself
        focus: focusOn,
    };
})(typeof window !== 'undefined' ? window : globalThis);
