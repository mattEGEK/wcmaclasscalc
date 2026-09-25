const test = require('node:test');
const assert = require('node:assert');
const { carIdFromQuery, pickerOptions, initialCarValue } = require('../../js/car-picker.js');

const cars = [
    { id: 3, label: '#42 2004 Honda S2000', current_class: 'GT3' },
    { id: 8, label: '#17 1999 Mazda Miata', current_class: null },
];

test('reads the car id from the query string', () => {
    assert.strictEqual(carIdFromQuery('?car=8'), '8');
    assert.strictEqual(carIdFromQuery('?draft=2&car=3'), '3');
    assert.strictEqual(carIdFromQuery('?car=abc'), '');
    assert.strictEqual(carIdFromQuery(''), '');
});

test('lists cars with their class, then a new-car option', () => {
    assert.deepStrictEqual(pickerOptions(cars), [
        { value: '3', label: '#42 2004 Honda S2000 (GT3)' },
        { value: '8', label: '#17 1999 Mazda Miata' },
        { value: 'new', label: 'A new car' },
    ]);
});

test('initial choice: the linked car, else ask, else a new car', () => {
    assert.strictEqual(initialCarValue(cars, '?car=8'), '8');
    assert.strictEqual(initialCarValue(cars, '?car=99'), '');
    assert.strictEqual(initialCarValue(cars, ''), '');
    assert.strictEqual(initialCarValue([], '?car=8'), 'new');
});
