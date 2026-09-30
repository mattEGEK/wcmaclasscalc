// wcma-calculator/js/calc-dock.js
// Class Calculator helpers that only read what js/ui-controller.js renders (UX review 2026-09-30 §M2):
//   - a dock at the bottom of a phone screen that keeps the class in view while the form is
//     scrolled, since the result column sits below the form on narrow screens;
//   - the "enter weight and HP first" note, shown only while the modifier choices are locked.
(function () {
    'use strict';

    const dock = document.getElementById('class-dock');
    const classEl = document.getElementById('inline-calculated-class');
    const ratioEl = document.getElementById('inline-modified-ratio');
    const results = document.getElementById('results');
    const lockedNote = document.getElementById('calc-locked-note');
    const chassis = document.getElementById('chassis');

    function hasValue(el) {
        const text = el ? el.textContent.trim() : '';
        return text !== '' && text !== '--';
    }

    let resultsInView = false;
    function refreshDock() {
        if (!dock || !classEl) return;
        const show = hasValue(classEl) && !resultsInView;
        dock.hidden = !show;
        document.body.classList.toggle('has-class-dock', show);
        if (!show) return;
        document.getElementById('class-dock-class').textContent = classEl.textContent.trim();
        document.getElementById('class-dock-ratio').textContent = hasValue(ratioEl) ? 'ratio ' + ratioEl.textContent.trim() : '';
    }

    function refreshLockedNote() {
        if (lockedNote && chassis) lockedNote.hidden = !chassis.disabled;
    }

    if (dock && classEl && typeof MutationObserver !== 'undefined') {
        const watch = new MutationObserver(refreshDock);
        [classEl, ratioEl].forEach(function (el) {
            if (el) watch.observe(el, { childList: true, characterData: true, subtree: true });
        });
        if (results && typeof IntersectionObserver !== 'undefined') {
            new IntersectionObserver(function (entries) {
                resultsInView = entries[0].isIntersecting;
                refreshDock();
            }, { threshold: 0.15 }).observe(results);
        }
        refreshDock();
    }

    if (lockedNote && chassis && typeof MutationObserver !== 'undefined') {
        new MutationObserver(refreshLockedNote).observe(chassis, { attributes: true, attributeFilter: ['disabled'] });
        refreshLockedNote();
    }
})();
