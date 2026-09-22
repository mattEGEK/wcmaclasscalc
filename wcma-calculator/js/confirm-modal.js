/**
 * Replaces native confirm() for forms marked with data-confirm="message".
 * Intercepts submit, shows a styled modal, and re-submits (bypassing this
 * same listener) if the user confirms.
 */
(function () {
    let modalEl = null;

    function buildModal() {
        const overlay = document.createElement('div');
        overlay.className = 'confirm-modal-overlay';
        overlay.hidden = true;

        overlay.innerHTML =
            '<div class="confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="confirm-modal-message">' +
            '  <p id="confirm-modal-message"></p>' +
            '  <div class="confirm-modal-actions">' +
            '    <button type="button" class="btn btn-secondary" data-role="cancel">Cancel</button>' +
            '    <button type="button" class="btn btn-danger" data-role="confirm">Confirm</button>' +
            '  </div>' +
            '</div>';

        document.body.appendChild(overlay);
        return overlay;
    }

    function askConfirm(message) {
        if (!modalEl) modalEl = buildModal();
        const messageEl = modalEl.querySelector('#confirm-modal-message');
        const confirmBtn = modalEl.querySelector('[data-role="confirm"]');
        const cancelBtn = modalEl.querySelector('[data-role="cancel"]');

        messageEl.textContent = message;
        modalEl.hidden = false;
        confirmBtn.focus();

        return new Promise(function (resolve) {
            function cleanup(result) {
                modalEl.hidden = true;
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click', onCancel);
                modalEl.removeEventListener('click', onOverlayClick);
                document.removeEventListener('keydown', onKeydown);
                resolve(result);
            }
            function onConfirm() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onOverlayClick(e) { if (e.target === modalEl) cleanup(false); }
            function onKeydown(e) { if (e.key === 'Escape') cleanup(false); }

            confirmBtn.addEventListener('click', onConfirm);
            cancelBtn.addEventListener('click', onCancel);
            modalEl.addEventListener('click', onOverlayClick);
            document.addEventListener('keydown', onKeydown);
        });
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const message = form.dataset.confirm;
        if (!message || form.dataset.confirmed === '1') return;

        event.preventDefault();
        askConfirm(message).then(function (ok) {
            if (!ok) return;
            form.dataset.confirmed = '1';
            form.requestSubmit ? form.requestSubmit() : form.submit();
            delete form.dataset.confirmed;
        });
    });
})();
