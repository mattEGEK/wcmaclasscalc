// wcma-calculator/js/media-profile.js
//
// Media profile form: shrink the picked photo in the browser (the server takes 2 MB at most),
// preview it, count blurb characters, show the guardian field only for minors, and confirm
// withdraw/delete. Without JS the form still works; big photos are then refused by the server.
(function () {
    const form = document.getElementById('media-form');
    if (!form) return;

    const photo = document.getElementById('mp-photo');
    const preview = document.getElementById('mp-preview');
    photo.addEventListener('change', async function () {
        const file = photo.files && photo.files[0];
        if (!file) return;
        try {
            const blob = await window.WcmaPhotoResize.resizeToJpeg(file, { maxEdge: 1600, quality: 0.85 });
            const resized = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(resized);
            photo.files = dt.files;
            preview.src = URL.createObjectURL(resized);
            preview.hidden = false;
        } catch (e) {
            // Keep the original file; the server will say if it is too large.
        }
    });

    const blurb = document.getElementById('mp-blurb');
    const count = document.getElementById('mp-blurb-count');
    const updateCount = function () { count.textContent = blurb.value.length + ' of 500 characters'; };
    blurb.addEventListener('input', updateCount);
    updateCount();

    const minor = form.querySelector('input[name="is_minor"]');
    const guardian = document.getElementById('mp-guardian');
    const syncMinor = function () { guardian.hidden = !minor.checked; };
    minor.addEventListener('change', syncMinor);
    syncMinor();

    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
        });
    });
})();
