// Password show/hide toggle for auth pages (login/register/reset).
document.addEventListener('click', function (event) {
    const btn = event.target.closest('.password-toggle');
    if (!btn) return;

    const input = document.getElementById(btn.dataset.target);
    if (!input) return;

    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    btn.classList.toggle('is-showing', !showing);
});
