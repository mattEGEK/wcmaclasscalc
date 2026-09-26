const test = require('node:test');
const assert = require('node:assert');

const load = () => import('../../js/calculator.js');
const round2 = n => Math.round(n * 100) / 100;

test('auto: factors come from the weight/HP class, like the WCMA calculator default', async () => {
    const { updateCalculations } = await load();
    // 3000 / 252 = 11.90 -> GT3; R-compound 267-282mm tyre is +0.3 in GT3
    const r = updateCalculations({ competitionWeight: '3000', declaredHp: '252', tires: 'tire3' });
    assert.strictEqual(r.scoringClass, 'GT3');
    assert.strictEqual(r.tiresValue, 0.3);
    assert.strictEqual(round2(r.modifiedRatio), 12.2);
    assert.strictEqual(r.calculatedClass, 'GT4');
    assert.strictEqual(r.competingClass, 'GT4');
});

test('chosen class: factors and weight factor come from the chosen class', async () => {
    const { updateCalculations } = await load();
    // 2800 / 240 = 11.67; FWD +0.6; tire5 is +0.6 in GT3 but +0.3 in GT4; weight factor -0.1
    const auto = updateCalculations({ competitionWeight: '2800', declaredHp: '240', drivetrain: 'dt2', tires: 'tire5' });
    assert.strictEqual(round2(auto.modifiedRatio), 12.77);
    const gt4 = updateCalculations({ competitionWeight: '2800', declaredHp: '240', drivetrain: 'dt2', tires: 'tire5', classChoice: 'GT4' });
    assert.strictEqual(gt4.scoringClass, 'GT4');
    assert.strictEqual(gt4.tiresValue, 0.3);
    assert.strictEqual(gt4.weightFactor, -0.1);
    assert.strictEqual(round2(gt4.modifiedRatio), 12.47);
    assert.strictEqual(gt4.calculatedClass, 'GT4');
    assert.strictEqual(gt4.competingClass, 'GT4');
    assert.strictEqual(gt4.movedUp, false);
});

test('choosing a faster class than the result means competing in the chosen class', async () => {
    const { updateCalculations } = await load();
    const r = updateCalculations({ competitionWeight: '3000', declaredHp: '240', classChoice: 'GT2' });
    assert.strictEqual(r.calculatedClass, 'GT4');   // 12.50
    assert.strictEqual(r.competingClass, 'GT2');
    assert.strictEqual(r.movedUp, true);
});

test('choosing a slower class than the result cannot move the car down', async () => {
    const { updateCalculations } = await load();
    const r = updateCalculations({ competitionWeight: '2500', declaredHp: '250', classChoice: 'GT4' });
    assert.strictEqual(r.calculatedClass, 'GT2');   // 10.00 - 0.2 weight factor = 9.80
    assert.strictEqual(r.competingClass, 'GT2');
    assert.strictEqual(r.movedUp, false);
});

test('an unknown class choice is treated as auto', async () => {
    const { updateCalculations } = await load();
    const r = updateCalculations({ competitionWeight: '3000', declaredHp: '252', tires: 'tire3', classChoice: 'XYZ' });
    assert.strictEqual(r.scoringClass, 'GT3');
});

test('body mods can be combined and are summed', async () => {
    const { updateCalculations } = await load();
    const r = updateCalculations({ competitionWeight: '3000', declaredHp: '250', bodyMods: ['body1', 'body2', 'body3'] });
    // GT4: -0.3 + -0.2 + 0.4
    assert.strictEqual(round2(r.bodyModsValue), -0.1);
    assert.strictEqual(round2(r.modificationFactor), -0.1);
});

test('a single legacy body-mod string still works', async () => {
    const { updateCalculations } = await load();
    const r = updateCalculations({ competitionWeight: '3000', declaredHp: '250', bodyMods: 'body1' });
    assert.strictEqual(r.bodyModsValue, -0.3);
});

test('class from weight/HP uses the same rounded ratio everywhere', async () => {
    const { updateCalculations, getScoringClass } = await load();
    // 2999 / 250 = 11.996 -> displayed and classed as 12.00 -> GT4
    assert.strictEqual(getScoringClass(2999, 250, ''), 'GT4');
    const r = updateCalculations({ competitionWeight: '2999', declaredHp: '250' });
    assert.strictEqual(r.baseRatio, 12);
    assert.strictEqual(r.scoringClass, 'GT4');
});

test('IT maximum tyre width follows minimum competition weight', async () => {
    const { getItTireMaxWidth } = await load();
    assert.strictEqual(getItTireMaxWidth(2751), 265);
    assert.strictEqual(getItTireMaxWidth(2750), 255);
    assert.strictEqual(getItTireMaxWidth(2400), 255);
    assert.strictEqual(getItTireMaxWidth(2399), 225);
});
