const test = require('node:test');
const assert = require('node:assert');
const { openDialog, onClick, init } = require('../../js/admin-dialog.js');

// Minimal stand-ins for the DOM pieces admin-dialog.js touches.
function fakeEl(attrs, dialog) {
    return {
        attrs, focusCount: 0,
        focus() { this.focusCount++; },
        getAttribute(name) { return this.attrs[name]; },
        closest(sel) {
            const m = sel.match(/^\[(.+)\]$/);
            if (m && m[1] in this.attrs) return this;
            if (sel === 'dialog') return dialog || null;
            return null;
        },
    };
}
function fakeDialog(fields, forms) {
    const listeners = {};
    return {
        open: false, fields: fields || [], forms: forms || [],
        querySelectorAll() { return this.forms; },
        showModal() { this.open = true; },
        close() { this.open = false; (listeners.close || []).forEach(fn => fn()); listeners.close = []; },
        querySelector() { return this.fields[0] || null; },
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    };
}
function fakeDoc(dialogs, pending) {
    return {
        getElementById: id => dialogs[id] || null,
        querySelector: () => pending || null,
        addEventListener() {},
    };
}

test('opening shows the modal and puts focus in its first field', () => {
    const field = fakeEl({});
    const dialog = fakeDialog([field]);
    openDialog(dialog, null);
    assert.strictEqual(dialog.open, true);
    assert.strictEqual(field.focusCount, 1);
});

test('closing returns focus to the Edit button that opened it', () => {
    const dialog = fakeDialog([fakeEl({})]);
    const opener = fakeEl({ 'data-dialog-open': 'd' });
    openDialog(dialog, opener);
    dialog.close();
    assert.strictEqual(opener.focusCount, 1);
});

test('an Edit button opens the dialog it names', () => {
    const dialog = fakeDialog([]);
    onClick(fakeDoc({ 'user-dialog-3': dialog }), { target: fakeEl({ 'data-dialog-open': 'user-dialog-3' }) });
    assert.strictEqual(dialog.open, true);
});

test('Cancel closes the dialog it sits in', () => {
    const dialog = fakeDialog([]);
    dialog.showModal();
    onClick(fakeDoc({}), { target: fakeEl({ 'data-dialog-close': '' }, dialog) });
    assert.strictEqual(dialog.open, false);
});

test('a click anywhere else, including the backdrop, leaves the dialog open', () => {
    const dialog = fakeDialog([]);
    dialog.showModal();
    onClick(fakeDoc({}), { target: fakeEl({}, dialog) });
    assert.strictEqual(dialog.open, true);
});

test('a dialog sent back with an error opens as the page loads', () => {
    const dialog = fakeDialog([]);
    init(fakeDoc({}, dialog));
    assert.strictEqual(dialog.open, true);
});

test('closing without saving puts the form back as the page showed it', () => {
    const form = { resets: 0, reset() { this.resets++; } };
    const dialog = fakeDialog([], [form]);
    openDialog(dialog, null);
    dialog.close();
    assert.strictEqual(form.resets, 1);
});
