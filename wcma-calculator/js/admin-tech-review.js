// wcma-calculator/js/admin-tech-review.js
// Signature pad for the in-person tech review form (admin.php?action=tech-sheet).
(function () {
    const canvas = document.getElementById('tech-sig-canvas');
    if (!canvas) return; // accepted sheets have no form

    const pad = WcmaSignaturePad.attach(canvas);
    const form = document.getElementById('tech-accept-form');
    const field = document.getElementById('tech_signature');
    const wrap = canvas.closest('.sig-pad-wrap');

    document.querySelector('[data-clear-sig="tech"]').addEventListener('click', function () {
        pad.clear();
    });

    form.addEventListener('submit', function (event) {
        if (pad.isEmpty()) {
            event.preventDefault();
            wrap.classList.add('field-error');
            return;
        }
        wrap.classList.remove('field-error');
        field.value = pad.toPNGDataURL();
    });
})();
