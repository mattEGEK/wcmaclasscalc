/**
 * Click-to-enlarge overlay for any <img data-lightbox> on the page.
 */
(function () {
    let overlay = null;

    function buildOverlay() {
        const el = document.createElement('div');
        el.className = 'lightbox-overlay';
        el.hidden = true;
        el.innerHTML = '<img class="lightbox-image" alt="">';
        document.body.appendChild(el);
        el.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
        return el;
    }

    function open(src, alt) {
        if (!overlay) overlay = buildOverlay();
        const img = overlay.querySelector('.lightbox-image');
        img.src = src;
        img.alt = alt || '';
        overlay.hidden = false;
    }

    function close() {
        if (overlay) overlay.hidden = true;
    }

    document.addEventListener('click', function (e) {
        const target = e.target.closest('img[data-lightbox]');
        if (!target) return;
        open(target.src, target.alt);
    });
})();
