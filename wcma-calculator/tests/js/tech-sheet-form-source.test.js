const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

// tech-sheet-form.js is browser glue with no exports; guard the load-time sync at source level.
const src = fs.readFileSync(path.join(__dirname, '../../js/tech-sheet-form.js'), 'utf8');

test('the endurance card is synced with the sheet type on load, not only on change', () => {
    assert.match(src, /function syncSheetType\(\)/);
    assert.match(src, /sheetTypeSelect\.addEventListener\('change', syncSheetType\)/);
    assert.match(src, /\n\s*syncSheetType\(\);/);
});

test('the TA/Drift cage box swaps the checklist and the head & neck rule, and syncs once on load', () => {
    assert.match(src, /document\.getElementById\('ta_drift_caged'\)/);
    assert.match(src, /window\.TA_DRIFT_SECTIONS\[cagedBox\.checked \? 'on' : 'off'\]/);
    assert.match(src, /TECH_DRIVER_EQUIPMENT_ITEMS\.head_neck_restraints\.optional = !cagedBox\.checked/);
    assert.match(src, /cagedBox\.checked !== !!window\.TA_DRIFT_RENDERED_CAGED/);
    assert.match(src, /let headNeckReason;/);
});

test('an edit asks for a new signature when the entrant or Driver 1 changes', () => {
    assert.match(src, /const signedAs = \{ entrant: nameKey\(/);
    assert.match(src, /const driverOnFile = !!window\.TECH_SHEET_HAS_DRIVER_SIGNATURE && !driver1Changed\(\);/);
    assert.match(src, /const entrantOnFile = !!window\.TECH_SHEET_HAS_ENTRANT_SIGNATURE && !entrantChanged\(\)/);
    assert.match(src, /Driver 1 has changed, so the new driver needs to sign\. /);
});
