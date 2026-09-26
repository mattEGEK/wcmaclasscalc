const test = require('node:test');
const assert = require('node:assert');
const { NEW, driverChoiceOptions, driverChoiceComplete } = require('../../js/driver-choice.js');

const drivers = [{ id: 5, name: 'Jordan Lee', self: true }, { id: 6, name: 'Sam Patel', self: false }];

test('options list profiles, mark you, select the chosen one and end with add', () => {
    const opts = driverChoiceOptions(drivers, 6);
    assert.deepStrictEqual(opts.map(o => o.value), ['5', '6', NEW]);
    assert.strictEqual(opts[0].label, 'Jordan Lee (you)');
    assert.strictEqual(opts[2].label, '+ Add a co-driver');
    assert.deepStrictEqual(opts.map(o => o.selected), [false, true, false]);
    assert.strictEqual(driverChoiceOptions(drivers, NEW)[2].selected, true);
});

test('a choice is complete with a profile id, or new with a name', () => {
    assert.strictEqual(driverChoiceComplete('6', ''), true);
    assert.strictEqual(driverChoiceComplete(NEW, '  Alex '), true);
    assert.strictEqual(driverChoiceComplete(NEW, '   '), false);
    assert.strictEqual(driverChoiceComplete('', 'x'), false);
});
