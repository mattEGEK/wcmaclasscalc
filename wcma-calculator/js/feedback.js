/**
 * Feedback modal. Any element with [data-feedback-open] opens it.
 * Posts to feedback.php; the server stores it, files a GitHub issue and emails admins.
 */
(function () {
    var overlay = null;
    var session = { loggedIn: false, email: '', csrfToken: '' };

    function build() {
        overlay = document.createElement('div');
        overlay.className = 'confirm-modal-overlay';
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="confirm-modal feedback-modal" role="dialog" aria-modal="true" aria-labelledby="feedback-title">' +
            '  <h2 id="feedback-title">Send feedback</h2>' +
            '  <form id="feedback-form" novalidate>' +
            '    <div class="form-group"><label for="feedback-type">Type</label>' +
            '      <select id="feedback-type" name="type">' +
            '        <option value="bug">Bug report</option>' +
            '        <option value="feedback">General feedback</option>' +
            '        <option value="idea">Idea</option>' +
            '      </select></div>' +
            '    <div class="form-group"><label for="feedback-message">Message</label>' +
            '      <textarea id="feedback-message" name="message" rows="5" maxlength="2000" required></textarea></div>' +
            '    <div class="form-group"><label for="feedback-email">Email <span class="file-note">(optional, so we can reply)</span></label>' +
            '      <input type="email" id="feedback-email" name="email"></div>' +
            '    <div class="feedback-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>' +
            '    <div class="form-messages" id="feedback-messages" role="alert"></div>' +
            '    <div class="confirm-modal-actions">' +
            '      <button type="button" class="btn btn-secondary" data-role="cancel">Cancel</button>' +
            '      <button type="submit" class="btn btn-primary" data-role="send">Send</button>' +
            '    </div>' +
            '  </form>' +
            '</div>';
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        overlay.querySelector('[data-role="cancel"]').addEventListener('click', close);
        overlay.querySelector('#feedback-form').addEventListener('submit', submit);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay && !overlay.hidden) close();
        });
    }

    function showMessage(text, type) {
        var box = overlay.querySelector('#feedback-messages');
        box.textContent = text;
        box.className = 'form-messages show ' + type;
    }

    function close() {
        if (overlay) overlay.hidden = true;
    }

    function open() {
        if (!overlay) build();
        var form = overlay.querySelector('#feedback-form');
        form.hidden = false;
        form.reset();
        overlay.querySelector('#feedback-messages').className = 'form-messages';
        overlay.querySelector('[data-role="send"]').disabled = false;
        overlay.querySelector('[data-role="send"]').textContent = 'Send';
        overlay.hidden = false;

        fetch('session-status.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                session = data;
                var emailInput = overlay.querySelector('#feedback-email');
                if (data.loggedIn && data.email && !emailInput.value) emailInput.value = data.email;
            })
            .catch(function () { /* submit will report a missing token */ });

        overlay.querySelector('#feedback-message').focus();
    }

    function submit(event) {
        event.preventDefault();
        var form = event.target;
        var sendBtn = overlay.querySelector('[data-role="send"]');
        var type = form.elements.type.value;

        var body = new FormData();
        body.append('csrf_token', session.csrfToken || '');
        body.append('type', type);
        body.append('message', form.elements.message.value);
        body.append('email', form.elements.email.value);
        body.append('website', form.elements.website.value);
        body.append('page_url', window.location.href);
        body.append('viewport', window.innerWidth + 'x' + window.innerHeight);
        if (type === 'bug' && typeof window.wcmaGetCalcInputs === 'function') {
            try { body.append('calc_inputs', JSON.stringify(window.wcmaGetCalcInputs())); } catch (e) { /* omit */ }
        }

        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending…';

        fetch('feedback.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (r) {
                if (r.ok && r.data.ok) {
                    form.hidden = true;
                    var done = document.createElement('p');
                    done.textContent = 'Thanks — we got it.';
                    var closeBtn = document.createElement('button');
                    closeBtn.type = 'button';
                    closeBtn.className = 'btn btn-primary';
                    closeBtn.textContent = 'Close';
                    closeBtn.addEventListener('click', function () { done.remove(); closeBtn.remove(); close(); });
                    form.parentNode.appendChild(done);
                    form.parentNode.appendChild(closeBtn);
                    closeBtn.focus();
                } else {
                    showMessage((r.data.errors || ['Something went wrong. Please try again.']).join(' '), 'error');
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send';
                }
            })
            .catch(function () {
                showMessage('Could not reach the server. Please try again.', 'error');
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
            });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-feedback-open]');
        if (!trigger) return;
        event.preventDefault();
        open();
    });
})();
