// wcma-calculator/js/admin-tech-review.js
// Signature pad for the in-person tech review form (admin.php?action=tech-sheet).
(function () {
    const canvas = document.getElementById('tech-sig-canvas');
    if (!canvas) return; // accepted sheets have no form

    const pad = WcmaSignaturePad.attach(canvas);
    const form = document.getElementById('tech-accept-form');
    const field = document.getElementById('tech_signature');
    const errorBox = document.getElementById('tech-accept-error');
    const wrap =canvas.closest('.sig-pad-wrap');

    document.querySelector('[data-clear-sig="tech"]').addEventListener('click', function () {
        pad.clear();
    });

    form.addEventListener('submit', function (event) {
        if (pad.isEmpty()) {
            event.preventDefault();
            wrap.classList.add('field-error');
            // .form-messages is display:none until it has .show/.error, and
            // those classes use display:block !important, which would also
            // defeat the hidden attribute. So toggle both together.
            errorBox.textContent = 'A tech representative signature is required. Please sign in the box below.';
            errorBox.hidden = false;
            errorBox.classList.add('show', 'error');
            wrap.scrollIntoView({ block: 'center' });
            return;
        }
        wrap.classList.remove('field-error');
        errorBox.textContent = '';
        errorBox.hidden = true;
        errorBox.classList.remove('show', 'error');
        field.value = pad.toPNGDataURL();
    });
})();
