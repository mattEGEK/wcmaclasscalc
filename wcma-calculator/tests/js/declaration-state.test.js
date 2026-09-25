const test = require('node:test');
const assert = require('node:assert');
const { STASH_KEY, stashForm, takeStashedForm, signInUrlForRestore } = require('../../js/declaration-state.js');

function memoryStorage() {
    const m = new Map();
    return { setItem: (k, v) => m.set(k, String(v)), getItem: k => (m.has(k) ? m.get(k) : null), removeItem: k => m.delete(k), _m: m };
}

test('stash then take returns the data once', () => {
    const s = memoryStorage();
    assert.strictEqual(stashForm(s, { competitionWeight: '2860' }), true);
    assert.deepStrictEqual(takeStashedForm(s), { competitionWeight: '2860' });
    assert.strictEqual(takeStashedForm(s), null);
    assert.strictEqual(s._m.has(STASH_KEY), false);
});

test('storage failures never throw', () => {
    const broken = { setItem() { throw new Error('quota'); }, getItem() { throw new Error('denied'); }, removeItem() {} };
    assert.strictEqual(stashForm(broken, { a: 1 }), false);
    assert.strictEqual(takeStashedForm(broken), null);
    const s = memoryStorage();
    s.setItem(STASH_KEY, '{not json');
    assert.strictEqual(takeStashedForm(s), null);
});

test('sign-in URL comes back to the restore page', () => {
    assert.strictEqual(signInUrlForRestore(), 'auth.php?action=login&redirect=calculator.php%3Frestore%3D1');
});
