// wcma-calculator/js/media-kit.js — "Copy text" buttons on the media kit.
(function () {
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const src = btn.closest('.media-entry').querySelector('.media-copy-src');
            const text = src.value;
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                src.hidden = false; src.select(); document.execCommand('copy'); src.hidden = true;
            }
            const label = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = label; }, 1500);
        });
    });
})();
