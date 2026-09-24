/**
 * Disables the submit button and swaps its label while a plain (non-fetch)
 * form POST is in flight, so slow actions (delete, resend, promote/demote)
 * give some feedback and can't be double-clicked. Waits for confirm-modal.js
 * to actually confirm on data-confirm forms (see its dataset.confirmed flag).
 */
(function () {
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (event.defaultPrevented) return;
        if (form.dataset.confirm && form.dataset.confirmed !== '1') return;

        const btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.disabled) return;

        btn.dataset.originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = btn.dataset.loadingText || 'Please wait…';
    });
})();
