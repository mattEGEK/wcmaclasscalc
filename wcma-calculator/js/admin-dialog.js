// wcma-calculator/js/admin-dialog.js
// Admin edit modals (admin desktop UX spec 2026-09-29 §2). [data-dialog-open="<id>"] opens that
// <dialog class="admin-dialog">; [data-dialog-close] closes the dialog it sits in; a dialog marked
// data-open-on-load (an error sent back from it) opens as the page loads. Esc closes natively; a
// backdrop click does not, so a stray click never throws away edits. Loadable as a classic script
// (window.WcmaAdminDialog) or via require() for tests.
(function (root) {
    'use strict';

    function openDialog(dialog, opener) {
        if (dialog.open) return;
        dialog.showModal();
        const field = dialog.querySelector('input:not([type="hidden"]), select, textarea');
        if (field) field.focus();
        dialog.addEventListener('close', function () {
            // Cancel/Esc throws away unsaved edits, so reopening never shows them as if they were saved.
            Array.prototype.forEach.call(dialog.querySelectorAll('form'), function (form) { form.reset(); });
            if (opener) opener.focus();
        }, { once: true });
    }

    function onClick(doc, event) {
        const target = event.target;
        if (!target || typeof target.closest !== 'function') return;
        const opener = target.closest('[data-dialog-open]');
        if (opener) {
            const dialog = doc.getElementById(opener.getAttribute('data-dialog-open'));
            if (dialog) openDialog(dialog, opener);
            return;
        }
        if (target.closest('[data-dialog-close]')) {
            const dialog = target.closest('dialog');
            if (dialog) dialog.close();
        }
    }

    function init(doc) {
        doc.addEventListener('click', function (event) { onClick(doc, event); });
        const pending = doc.querySelector('dialog.admin-dialog[data-open-on-load]');
        if (pending) openDialog(pending, null);
    }

    const api = { openDialog: openDialog, onClick: onClick, init: init };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.WcmaAdminDialog = api;
        init(root.document);
    }
})(typeof window !== 'undefined' ? window : globalThis);
