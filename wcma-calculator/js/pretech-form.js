// wcma-calculator/js/pretech-form.js
// Behaviour of the competitor pre-tech page: upload each photo as soon as it is chosen (one request
// per photo), edit typed details, toggle conditional photos, and keep the progress bar and the
// submit button in step. State arrives in window.PRETECH_STATE (see pretech-page.php).
(function () {
    'use strict';

    const state = window.PRETECH_STATE;
    if (!state || state.locked) return;

    const client = WcmaPhotoUpload.browserClient(state.csrf);
    const present = new Set(Object.keys(state.photos));
    const applicable = new Set(state.applicable);

    const progressLabel = document.getElementById('pretech-progress');
    const progressFill = document.getElementById('pretech-fill');
    const submitBtn = document.getElementById('pretech-submit-btn');
    const submitHint = document.getElementById('pretech-submit-hint');

    function cards() { return Array.prototype.slice.call(document.querySelectorAll('.pretech-card')); }
    function cardFor(key) { return document.querySelector('.pretech-card[data-key="' + key + '"]'); }

    function requiredTotal() {
        return state.requirements.filter(function (r) {
            return r.tier === 'required' || (r.tier === 'conditional' && applicable.has(r.key));
        }).length;
    }

    function refresh() {
        const missing = WcmaPretechProgress.computeMissing(state.requirements, Array.from(present), Array.from(applicable));
        const total = requiredTotal();
        const done = total - missing.length;
        progressLabel.textContent = done + ' of ' + total + ' required photos';
        progressFill.style.width = (total > 0 ? Math.round(done / total * 100) : 0) + '%';
        if (submitBtn) {
            submitBtn.disabled = missing.length > 0;
            submitHint.textContent = missing.length > 0
                ? 'Add every required photo to enable submitting.'
                : 'Everything required is in. Submit when you are ready.';
        }
    }

    function setError(card, message) {
        const el = card.querySelector('[data-error]');
        el.textContent = message || '';
        el.hidden = !message;
    }

    function setStatus(card, text, cls) {
        const el = card.querySelector('[data-status]');
        el.textContent = text;
        el.className = 'pretech-status ' + cls;
    }

    function typedValues(card) {
        const values = {};
        card.querySelectorAll('[data-typed]').forEach(function (input) {
            values[input.getAttribute('data-typed')] = input.value;
        });
        return values;
    }

    function showPhoto(card, photo) {
        const img = card.querySelector('[data-thumb]');
        img.src = photo.url + '&v=' + Date.now();   // same URL after a retake: bust the cache
        img.hidden = false;
        const note = card.querySelector('.pretech-note');
        if (note) note.remove();
        setStatus(card, 'Added', 'badge-ok');
        const label = card.querySelector('.pretech-upload');
        if (label && label.firstChild) label.firstChild.textContent = 'Retake photo';
    }

    cards().forEach(function (card) {
        const key = card.getAttribute('data-key');

        const fileInput = card.querySelector('[data-photo-input]');
        if (fileInput) {
            fileInput.addEventListener('change', async function () {
                const file = fileInput.files && fileInput.files[0];
                if (!file) return;
                setError(card, '');
                setStatus(card, 'Uploading…', 'badge-pending');
                try {
                    const photo = await client.upload({
                        file: file, subjectType: 'tech_sheet', subjectId: state.sheetId,
                        requirementKey: key, typed: typedValues(card),
                    });
                    state.photos[key] = photo;
                    present.add(key);
                    showPhoto(card, photo);
                } catch (e) {
                    setError(card, e.message);
                    setStatus(card, present.has(key) ? 'Added' : 'Photo needed', present.has(key) ? 'badge-ok' : 'badge-pending');
                } finally {
                    fileInput.value = '';
                    refresh();
                }
            });
        }

        card.querySelectorAll('[data-typed]').forEach(function (input) {
            input.addEventListener('change', async function () {
                const photo = state.photos[key];
                if (!photo) return;   // details are sent with the photo when it is added
                setError(card, '');
                try {
                    state.photos[key] = await client.typed({ id: photo.id, typed: typedValues(card) });
                    setStatus(card, 'Added', 'badge-ok');
                } catch (e) {
                    setError(card, e.message);
                }
            });
        });

        const toggle = card.querySelector('[data-applies-toggle]');
        if (toggle) {
            toggle.addEventListener('change', async function () {
                setError(card, '');
                try {
                    await client.applies({ subjectType: 'tech_sheet', subjectId: state.sheetId, requirementKey: key, applies: toggle.checked });
                    if (toggle.checked) {
                        applicable.add(key);
                    } else {
                        applicable.delete(key);
                        present.delete(key);            // turning it off removes any photo already added
                        delete state.photos[key];
                        card.querySelector('[data-thumb]').hidden = true;
                        setStatus(card, 'No photo yet', 'badge-pending');
                    }
                } catch (e) {
                    toggle.checked = !toggle.checked;   // the server refused: put the switch back
                    setError(card, e.message);
                } finally {
                    refresh();
                }
            });
        }
    });

    refresh();
})();
