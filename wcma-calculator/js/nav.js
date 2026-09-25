// wcma-calculator/js/nav.js — phone "Menu" button for the hub header.
(function () {
    const btn = document.querySelector('.hub-menu-btn');
    const list = document.getElementById('hub-nav-list');
    if (!btn || !list) return;
    btn.addEventListener('click', () => {
        const open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        list.classList.toggle('is-open', !open);
    });
})();
