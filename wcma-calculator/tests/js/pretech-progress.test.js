const test = require('node:test');
const assert = require('node:assert');
const { computeMissing } = require('../../js/pretech-progress.js');

const requirements = [
    { key: 'a', tier: 'required' },
    { key: 'b', tier: 'required' },
    { key: 'c', tier: 'conditional' },
    { key: 'd', tier: 'recommended' },
];

test('everything required is missing at first; recommended and unselected conditionals never count', () => {
    assert.deepStrictEqual(computeMissing(requirements, [], []), ['a', 'b']);
});

test('present photos are not missing', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a'], []), ['b']);
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b'], []), []);
});

test('an applicable conditional photo is required until it is present', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b'], ['c']), ['c']);
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b', 'c'], ['c']), []);
});

test('a conditional photo that was added but is switched off no longer counts', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b', 'c'], []), []);
});

test('unknown applicable keys are ignored', () => {
    assert.deepStrictEqual(computeMissing(requirements, ['a', 'b'], ['zzz']), []);
});
