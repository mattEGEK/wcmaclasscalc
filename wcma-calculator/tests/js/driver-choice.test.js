const test = require('node:test');
const assert = require('node:assert');
const { NEW, driverChoiceOptions, driverChoiceComplete, duplicateDriverChoice, isSelfChoice, coDriverNameMessage } = require('../../js/driver-choice.js');

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

test('duplicateDriverChoice finds a repeated profile id, or -1 when all differ', () => {
    const entries = [{ choice: '6', newName: '' }, { choice: '5', newName: '' }, { choice: '6', newName: '' }];
    assert.strictEqual(duplicateDriverChoice(entries, drivers), 2);
    assert.strictEqual(duplicateDriverChoice([{ choice: '5', newName: '' }, { choice: '6', newName: '' }], drivers), -1);
});

test('duplicateDriverChoice treats matching NEW names (collapsed whitespace, case-insensitive) as the same person', () => {
    const entries = [{ choice: NEW, newName: 'Alex Rivera' }, { choice: NEW, newName: '  alex   rivera ' }];
    assert.strictEqual(duplicateDriverChoice(entries, drivers), 1);
});

test('duplicateDriverChoice matches a NEW name against an existing profile name', () => {
    const entries = [{ choice: '6', newName: '' }, { choice: NEW, newName: ' sam patel ' }];
    assert.strictEqual(duplicateDriverChoice(entries, drivers), 1);
});

test('duplicateDriverChoice with no drivers list still catches repeated NEW names', () => {
    const entries = [{ choice: NEW, newName: 'Alex' }, { choice: NEW, newName: 'Alex' }];
    assert.strictEqual(duplicateDriverChoice(entries, undefined), 1);
});

test('isSelfChoice is true only for the signed-in users own profile', () => {
    assert.strictEqual(isSelfChoice(drivers, '5'), true);
    assert.strictEqual(isSelfChoice(drivers, 5), true);
    assert.strictEqual(isSelfChoice(drivers, '6'), false);
    assert.strictEqual(isSelfChoice(drivers, NEW), false);
    assert.strictEqual(isSelfChoice([], '5'), false);
});

test('co-driver name messages name the driver row', () => {
    assert.strictEqual(coDriverNameMessage(1), "Enter the co-driver's name.");
    assert.strictEqual(coDriverNameMessage(3), "Enter Driver 3's name.");
});
