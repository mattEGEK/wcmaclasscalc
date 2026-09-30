const test = require('node:test');
const assert = require('node:assert');
const { sectionsFor, carryChecklistState, fhrRequired, equipmentLabel } = require('../../js/ice-class-picker.js');

const map = {
    SS: { a: { label: 'A', items: { airbags: 'Airbags', tow: 'Tow hooks' } } },
    LS: { b: { label: 'B', items: { cage: 'Cage', tow: 'Tow hooks' } } },
};

test('sectionsFor returns the class sections, or an empty object', () => {
    assert.deepStrictEqual(sectionsFor(map, 'LS'), map.LS);
    assert.deepStrictEqual(sectionsFor(map, ''), {});
    assert.deepStrictEqual(sectionsFor(null, 'LS'), {});
});

test('carryChecklistState keeps answers for items still on the list and starts new ones blank', () => {
    const old = { airbags: { status: 'ok' }, tow: { status: 'na' } };
    assert.deepStrictEqual(carryChecklistState(old, map.LS), { cage: { status: null }, tow: { status: 'na' } });
    assert.deepStrictEqual(carryChecklistState(null, map.SS), { airbags: { status: null }, tow: { status: null } });
});

test('fhrRequired reads the class flag', () => {
    assert.strictEqual(fhrRequired({ LS: true, NS: false }, 'LS'), true);
    assert.strictEqual(fhrRequired({ LS: true }, 'NS'), false);
    assert.strictEqual(fhrRequired(undefined, 'LS'), false);
});

test('equipmentLabel marks a required item', () => {
    assert.strictEqual(equipmentLabel('Head & Neck Restraints', true), 'Head & Neck Restraints (required for this class)');
    assert.strictEqual(equipmentLabel('Head & Neck Restraints', false), 'Head & Neck Restraints');
});

test('equipmentLabel can say why the item is required', () => {
    assert.strictEqual(equipmentLabel('Head & Neck Restraint', true, 'in a caged car'), 'Head & Neck Restraint (required in a caged car)');
    assert.strictEqual(equipmentLabel('Head & Neck Restraint', false, 'in a caged car'), 'Head & Neck Restraint');
});
