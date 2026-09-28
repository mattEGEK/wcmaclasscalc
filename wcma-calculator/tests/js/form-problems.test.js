const test = require('node:test');
const assert = require('node:assert');
const { messageFor } = require('../../js/form-problems.js');

test('a field with data-message says exactly that', () => {
    assert.strictEqual(messageFor({ dataMessage: 'Enter the race weight.', kind: 'text', label: 'Race weight' }), 'Enter the race weight.');
});

test('without data-message: text says Enter, select and radio say Choose, label lower-cased without (required)', () => {
    assert.strictEqual(messageFor({ kind: 'text', label: 'Entrant (required)' }), 'Enter the entrant.');
    assert.strictEqual(messageFor({ kind: 'select', label: 'NASCC class (required)' }), 'Choose the NASCC class.');
    assert.strictEqual(messageFor({ kind: 'radio', label: 'Log book turned in? (required)' }), 'Choose an answer for "Log book turned in?".');
    assert.strictEqual(messageFor({ kind: 'text', label: '' }), 'Please fill this in.');
});
