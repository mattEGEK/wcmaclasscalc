/**
 * Ice tech sheet: the checklist follows the chosen class's group. Pure helpers, exported for
 * node --test; js/tech-sheet-form.js does the DOM work.
 */
(function () {
    function sectionsFor(sectionsByClass, code) {
        return (sectionsByClass && sectionsByClass[code]) || {};
    }

    function carryChecklistState(oldState, sections) {
        const out = {};
        Object.keys(sections).forEach(function (s) {
            Object.keys(sections[s].items).forEach(function (key) {
                const prev = oldState && oldState[key];
                out[key] = { status: prev && prev.status ? prev.status : null };
            });
        });
        return out;
    }

    function fhrRequired(fhrByClass, code) {
        return !!(fhrByClass && fhrByClass[code]);
    }

    function equipmentLabel(baseLabel, required) {
        return required ? baseLabel + ' (required for this class)' : baseLabel;
    }

    const api = { sectionsFor, carryChecklistState, fhrRequired, equipmentLabel };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else window.WcmaIceClass = api;
})();
