const test = require('node:test');
const assert = require('node:assert');
const { computeTargetSize } = require('../../js/photo-resize.js');

test('scales a landscape photo so the long edge is maxEdge', () => {
    assert.deepStrictEqual(computeTargetSize(4000, 3000, 1600), { width: 1600, height: 1200 });
});

test('scales a portrait photo so the long edge is maxEdge', () => {
    assert.deepStrictEqual(computeTargetSize(3000, 4000, 1600), { width: 1200, height: 1600 });
});

test('never scales up', () => {
    assert.deepStrictEqual(computeTargetSize(800, 600, 1600), { width: 800, height: 600 });
    assert.deepStrictEqual(computeTargetSize(1600, 900, 1600), { width: 1600, height: 900 });
});

test('rounds to whole pixels', () => {
    const r = computeTargetSize(4032, 3024, 1600);
    assert.deepStrictEqual(r, { width: 1600, height: 1200 });
    const odd = computeTargetSize(3001, 2000, 1600);
    assert.ok(Number.isInteger(odd.width) && Number.isInteger(odd.height));
});
