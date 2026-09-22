// wcma-calculator/js/tech-sheet-checklist.js
// Renders the Mockup-A accordion: collapsible sections, sticky progress bar,
// "Mark all OK" per section, two-button OK/N/A chips per item.
window.WcmaTechChecklist = (function () {
    function countComplete(sections, state) {
        let total = 0, done = 0;
        Object.keys(sections).forEach(function (sectionKey) {
            Object.keys(sections[sectionKey].items).forEach(function (itemKey) {
                total++;
                if (state[itemKey] && state[itemKey].status) done++;
            });
        });
        return { total: total, done: done };
    }

    function sectionCount(items, state) {
        let total = 0, done = 0;
        Object.keys(items).forEach(function (itemKey) {
            total++;
            if (state[itemKey] && state[itemKey].status) done++;
        });
        return { total: total, done: done };
    }

    function render(container, sections, initialState) {
        const state = JSON.parse(JSON.stringify(initialState || {}));
        Object.keys(sections).forEach(function (sectionKey) {
            Object.keys(sections[sectionKey].items).forEach(function (itemKey) {
                if (!state[itemKey]) state[itemKey] = { status: null };
            });
        });

        const progressWrap = document.createElement('div');
        progressWrap.className = 'checklist-progress-wrap';
        const progressLabel = document.createElement('div');
        progressLabel.className = 'checklist-progress-label';
        const progressBarOuter = document.createElement('div');
        progressBarOuter.className = 'checklist-progress-bar';
        const progressFill = document.createElement('div');
        progressFill.className = 'checklist-progress-fill';
        progressBarOuter.appendChild(progressFill);
        progressWrap.appendChild(progressLabel);
        progressWrap.appendChild(progressBarOuter);
        container.appendChild(progressWrap);

        function updateProgress() {
            const c = countComplete(sections, state);
            const pct = c.total === 0 ? 0 : Math.round((c.done / c.total) * 100);
            progressLabel.textContent = c.done + ' of ' + c.total + ' items checked (' + pct + '%)';
            progressFill.style.width = pct + '%';
        }

        Object.keys(sections).forEach(function (sectionKey) {
            const section = sections[sectionKey];
            const sectionEl = document.createElement('div');
            sectionEl.className = 'checklist-section';

            const header = document.createElement('div');
            header.className = 'checklist-section-header';
            const nameWrap = document.createElement('div');
            const nameEl = document.createElement('div');
            nameEl.className = 'checklist-section-name';
            nameEl.textContent = section.label;
            const countEl = document.createElement('div');
            countEl.className = 'checklist-section-count';
            nameWrap.appendChild(nameEl);
            nameWrap.appendChild(countEl);
            const chevron = document.createElement('div');
            chevron.className = 'checklist-chevron';
            chevron.textContent = '▾';
            header.appendChild(nameWrap);
            header.appendChild(chevron);

            const body = document.createElement('div');
            body.className = 'checklist-section-body';

            const markAll = document.createElement('div');
            markAll.className = 'checklist-mark-all';
            markAll.textContent = 'Mark all OK';
            markAll.addEventListener('click', function () {
                Object.keys(section.items).forEach(function (itemKey) {
                    state[itemKey].status = 'ok';
                });
                refreshAllChips();
                updateSectionCount();
                updateProgress();
            });
            body.appendChild(markAll);

            const chipRefs = {};

            function refreshAllChips() {
                Object.keys(chipRefs).forEach(function (itemKey) {
                    setChipVisual(itemKey);
                });
            }

            function setChipVisual(itemKey) {
                const refs = chipRefs[itemKey];
                refs.ok.classList.toggle('checklist-chip-selected-ok', state[itemKey].status === 'ok');
                refs.na.classList.toggle('checklist-chip-selected-na', state[itemKey].status === 'na');
            }

            function updateSectionCount() {
                const c = sectionCount(section.items, state);
                countEl.textContent = c.done + ' of ' + c.total + ' complete';
            }

            Object.keys(section.items).forEach(function (itemKey) {
                const row = document.createElement('div');
                row.className = 'checklist-item-row';
                const label = document.createElement('div');
                label.className = 'checklist-item-label';
                label.textContent = section.items[itemKey];
                const group = document.createElement('div');
                group.className = 'checklist-toggle-group';
                const okBtn = document.createElement('button');
                okBtn.type = 'button';
                okBtn.className = 'checklist-chip';
                okBtn.textContent = 'OK';
                const naBtn = document.createElement('button');
                naBtn.type = 'button';
                naBtn.className = 'checklist-chip';
                naBtn.textContent = 'N/A';

                okBtn.addEventListener('click', function () {
                    state[itemKey].status = 'ok';
                    setChipVisual(itemKey);
                    updateSectionCount();
                    updateProgress();
                });
                naBtn.addEventListener('click', function () {
                    state[itemKey].status = 'na';
                    setChipVisual(itemKey);
                    updateSectionCount();
                    updateProgress();
                });

                chipRefs[itemKey] = { ok: okBtn, na: naBtn };
                group.appendChild(okBtn);
                group.appendChild(naBtn);
                row.appendChild(label);
                row.appendChild(group);
                body.appendChild(row);
            });

            header.addEventListener('click', function () {
                sectionEl.classList.toggle('checklist-section-open');
            });

            sectionEl.appendChild(header);
            sectionEl.appendChild(body);
            container.appendChild(sectionEl);

            refreshAllChips();
            updateSectionCount();
        });

        updateProgress();

        return {
            getState: function () { return JSON.parse(JSON.stringify(state)); },
            isComplete: function () { return countComplete(sections, state).done === countComplete(sections, state).total; },
        };
    }

    return { render: render };
})();
