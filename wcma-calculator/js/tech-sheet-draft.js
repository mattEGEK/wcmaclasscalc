// wcma-calculator/js/tech-sheet-draft.js
// Keeps a new tech sheet's answers in this browser while it is filled in, so a phone call, a reload
// or a flat battery doesn't lose them (mobile UX spec 2026-09-28 §C1). Signatures are never saved.
// Load it before tech-sheet-form.js: it restores values and the checklist/equipment globals first.
(function (root) {
    'use strict';
    const MAX_AGE_MS = 14 * 24 * 3600 * 1000;
    // No event_id: the key already names the event, so a draft can't move a sheet to another event.
    const FIELDS = ['sheet_type', 'car_colour', 'car_weight', 'ice_class', 'entrant_name', 'driver1_choice', 'driver1_new_name', 'engine_hp'];

    function encode(data, now) { return JSON.stringify({ savedAt: now, data: data }); }

    function decode(raw, now) {
        if (!raw) return null;
        let parsed;
        try { parsed = JSON.parse(raw); } catch (e) { return null; }
        if (!parsed || typeof parsed.savedAt !== 'number' || !parsed.data || typeof parsed.data !== 'object') return null;
        if (now - parsed.savedAt > MAX_AGE_MS || parsed.savedAt - now > 60 * 1000) return null;
        return parsed.data;
    }

    function hasStorage(store) {
        try { store.setItem('wcma-storage-check', '1'); store.removeItem('wcma-storage-check'); return store; } catch (e) { return null; }
    }

    /**
     * Whether a saved value can go back into $el. A menu only takes one of its own choices: a driver
     * deleted since the draft was saved would otherwise leave Driver 1 blank (bug list 2026-10-02 #10).
     */
    function canRestore(el, value) {
        if (value == null || value === '') return false;
        if (el.tagName === 'SELECT') return Array.prototype.some.call(el.options, function (o) { return o.value === String(value); });
        return true;
    }

    /** The radio among $radios whose value is $value, or null. Compared directly, so an odd saved value can't break a selector (#11). */
    function findRadio(radios, value) {
        return Array.prototype.find.call(radios, function (r) { return r.value === String(value); }) || null;
    }

    const api = { MAX_AGE_MS: MAX_AGE_MS, FIELDS: FIELDS, encode: encode, decode: decode, hasStorage: hasStorage, canRestore: canRestore, findRadio: findRadio };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; return; }
    root.WcmaTechSheetDraft = api;

    const key = root.TECH_SHEET_DRAFT_KEY;
    let store = null;
    try { store = key ? hasStorage(root.localStorage) : null; } catch (e) { store = null; }
    if (!store) return;
    const doc = root.document;
    const form = doc.getElementById('tech-sheet-form');
    if (!form) return;

    let pending = null;
    let stopped = false;   // set by Start over: nothing more is saved on this page
    let saved = null;
    try { saved = decode(store.getItem(key), Date.now()); if (saved === null) store.removeItem(key); } catch (e) { saved = null; }

    if (saved) {
        const fields = saved.fields || {};
        let driverGone = false;
        FIELDS.forEach(function (id) {
            const el = doc.getElementById(id);
            if (!el || fields[id] == null || fields[id] === '') return;
            if (canRestore(el, fields[id])) el.value = fields[id];
            else if (id === 'driver1_choice') driverGone = true;
        });
        if (saved.checklist && typeof saved.checklist === 'object') root.TECH_SHEET_EXISTING_CHECKLIST = saved.checklist;
        if (saved.equipment && typeof saved.equipment === 'object') root.TECH_SHEET_EXISTING_EQUIPMENT = saved.equipment;
        if (saved.logBook != null) {
            const radio = findRadio(form.querySelectorAll('input[name="log_book_turned_in"]'), saved.logBook);
            if (radio) radio.checked = true;
        }
        const notice = doc.createElement('div');
        notice.className = 'form-messages show info draft-notice';
        notice.setAttribute('role', 'status');
        notice.textContent = 'We kept your answers from earlier. '
            + (driverGone ? 'The driver you picked is no longer on your list, so check Driver 1. ' : '');
        const again = doc.createElement('button');
        again.type = 'button';
        again.className = 'btn btn-secondary';
        again.id = 'draft-start-over';
        again.textContent = 'Start over';
        again.addEventListener('click', function (e) {
            e.stopPropagation();
            stopped = true;
            clearTimeout(pending);
            try { store.removeItem(key); } catch (err) { /* nothing to clear */ }
            form.reset();
            root.location.assign(root.location.pathname + root.location.search);
        });
        notice.appendChild(again);
        form.insertBefore(notice, form.firstChild);
    }

    function collect() {
        const fields = {};
        FIELDS.forEach(function (id) { const el = doc.getElementById(id); if (el) fields[id] = el.value; });
        const s = root.WcmaTechSheetForm ? root.WcmaTechSheetForm.state() : {};
        const log = form.querySelector('input[name="log_book_turned_in"]:checked');
        return { fields: fields, checklist: s.checklist || {}, equipment: s.equipment || {}, logBook: log ? log.value : null };
    }

    function scheduleSave() {
        if (stopped) return;
        clearTimeout(pending);
        pending = setTimeout(function () {
            try { store.setItem(key, encode(collect(), Date.now())); } catch (e) { /* full or blocked: carry on without a draft */ }
        }, 150);
    }
    ['input', 'change', 'click'].forEach(function (type) { form.addEventListener(type, scheduleSave); });
})(typeof window !== 'undefined' ? window : globalThis);
