const test = require('node:test');
const assert = require('node:assert');
const { MAX_AGE_MS, encode, decode, hasStorage } = require('../../js/tech-sheet-draft.js');

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
