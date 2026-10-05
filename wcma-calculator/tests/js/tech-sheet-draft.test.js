const test = require('node:test');
const assert = require('node:assert');
const { MAX_AGE_MS, encode, decode, hasStorage } = require('../../js/tech-sheet-draft.js');
const draft = require('../../js/tech-sheet-draft.js');

const now = Date.UTC(2026, 8, 28);
const data = { fields: { car_weight: '2700' }, checklist: { brakes: { status: 'ok' } }, equipment: {}, logBook: '1' };

test('a draft round-trips', () => {
    assert.deepStrictEqual(decode(encode(data, now), now + 1000), data);
});

test('drafts older than 14 days, from the future, or unreadable are ignored', () => {
    assert.strictEqual(MAX_AGE_MS, 14 * 24 * 3600 * 1000);
    assert.strictEqual(decode(encode(data, now), now + MAX_AGE_MS + 1), null);
    assert.deepStrictEqual(decode(encode(data, now), now + MAX_AGE_MS), data);
    assert.strictEqual(decode(encode(data, now + 3600 * 1000), now), null);
    assert.strictEqual(decode('not json', now), null);
    assert.strictEqual(decode(null, now), null);
    assert.strictEqual(decode(JSON.stringify({ savedAt: now }), now), null);
});

test('hasStorage returns the store only when it can write', () => {
    const ok = { setItem() {}, removeItem() {} };
    assert.strictEqual(hasStorage(ok), ok);
    assert.strictEqual(hasStorage({ setItem() { throw new Error('private mode'); }, removeItem() {} }), null);
    assert.strictEqual(hasStorage(undefined), null);
});

test('a draft never carries its own event: the key already names it', () => {
    const { FIELDS } = require('../../js/tech-sheet-draft.js');
    assert.strictEqual(FIELDS.includes('event_id'), false);
});

test('a menu only takes back one of its own choices (bug list 2026-10-02 #10)', () => {
    const select = { tagName: 'SELECT', options: [{ value: '5' }, { value: '__new__' }] };
    assert.equal(draft.canRestore(select, '5'), true);
    assert.equal(draft.canRestore(select, 9), false);      // a driver deleted since the draft was saved
    assert.equal(draft.canRestore(select, ''), false);
    assert.equal(draft.canRestore({ tagName: 'INPUT' }, 'Red'), true);
    assert.equal(draft.canRestore({ tagName: 'INPUT' }, null), false);
});

test('an odd saved log book value finds no radio instead of throwing (bug list 2026-10-02 #11)', () => {
    const radios = [{ value: '1' }, { value: '0' }];
    assert.equal(draft.findRadio(radios, '1'), radios[0]);
    assert.equal(draft.findRadio(radios, 0), radios[1]);
    assert.equal(draft.findRadio(radios, '"]broken'), null);
    assert.equal(draft.findRadio(radios, { x: 1 }), null);
});
