// wcma-calculator/js/tech-sheet-form.js
(function () {
    let checklistWidget = WcmaTechChecklist.render(
        document.getElementById('checklist-container'), TECH_CHECKLIST_SECTIONS, window.TECH_SHEET_EXISTING_CHECKLIST || {}
    );

    function renderEquipmentInto(container, prefix, existingState) {
        existingState = existingState || {};
        const state = {};
        const rowRefs = {};
        const ratingInputRefs = {};
        Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).forEach(function (key) {
            const existing = existingState[key] || {};
            state[key] = {
                competitor_confirmed: !!existing.competitor_confirmed,
                value: existing.value != null ? existing.value : null,
            };
            const def = TECH_DRIVER_EQUIPMENT_ITEMS[key];
            const row = document.createElement('div');
            row.className = 'checklist-item-row';
            rowRefs[key] = row;
            const label = document.createElement('div');
            label.className = 'checklist-item-label';
            label.textContent = def.label;
            row.appendChild(label);

            if (def.has_rating) {
                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'Rating (e.g. SA2020)';
                input.style.marginRight = '0.5rem';
                if (state[key].value) input.value = state[key].value;
                input.addEventListener('input', function () {
                    state[key].value = input.value;
                    state[key].competitor_confirmed = input.value.trim() !== '';
                    if (input.value.trim() !== '') { input.classList.remove('error'); row.classList.remove('field-error'); }
                });
                ratingInputRefs[key] = input;
                row.appendChild(input);
            } else {
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'checklist-chip';
                confirmBtn.textContent = 'Confirm';
                if (state[key].competitor_confirmed) confirmBtn.classList.add('checklist-chip-selected-ok');
                confirmBtn.addEventListener('click', function () {
                    state[key].competitor_confirmed = !state[key].competitor_confirmed;
                    confirmBtn.classList.toggle('checklist-chip-selected-ok', state[key].competitor_confirmed);
                    if (state[key].competitor_confirmed) row.classList.remove('field-error');
                });
                row.appendChild(confirmBtn);
            }
            container.appendChild(row);
        });

        function highlightIncomplete() {
            Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).forEach(function (key) {
                const def = TECH_DRIVER_EQUIPMENT_ITEMS[key];
                const item = state[key];
                const incomplete = (!def.optional && !item.competitor_confirmed)
                    || (def.has_rating && (item.value == null || String(item.value).trim() === ''));
                rowRefs[key].classList.toggle('field-error', incomplete);
                if (ratingInputRefs[key]) ratingInputRefs[key].classList.toggle('error', incomplete);
            });
        }

        function clearHighlights() {
            Object.keys(rowRefs).forEach(function (key) {
                rowRefs[key].classList.remove('field-error');
                if (ratingInputRefs[key]) ratingInputRefs[key].classList.remove('error');
            });
        }

        return { state: state, highlightIncomplete: highlightIncomplete, clearHighlights: clearHighlights };
    }

    // Mirrors tech-sheet-data.php's validateDriverEquipment(): every
    // non-optional item must be competitor_confirmed, and helmet/suit
    // (has_rating items) additionally need a non-blank rating value.
    function isEquipmentComplete(state) {
        return Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).every(function (key) {
            const def = TECH_DRIVER_EQUIPMENT_ITEMS[key];
            const item = state[key] || {};
            if (!def.optional && !item.competitor_confirmed) return false;
            if (def.has_rating && (item.value == null || String(item.value).trim() === '')) return false;
            return true;
        });
    }

    const driver1Equipment = renderEquipmentInto(document.getElementById('equipment-container'), 'driver1', window.TECH_SHEET_EXISTING_EQUIPMENT);
    const driver1State = driver1Equipment.state;

    // Ice form: the checklist and the head & neck rule follow the chosen class.
    const iceClassSelect = document.getElementById('ice_class');
    if (iceClassSelect && window.ICE_SECTIONS_BY_CLASS && window.WcmaIceClass) {
        function onIceClassChange() {
            const code = iceClassSelect.value;
            const sections = WcmaIceClass.sectionsFor(window.ICE_SECTIONS_BY_CLASS, code);
            const container = document.getElementById('checklist-container');
            const carried = WcmaIceClass.carryChecklistState(checklistWidget.getState(), sections);
            container.innerHTML = '';
            checklistWidget = WcmaTechChecklist.render(container, sections, carried);
            TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.optional = !WcmaIceClass.fhrRequired(window.ICE_FHR_BY_CLASS, code);
            document.getElementById('ice-class-note').textContent = (window.ICE_CLASS_NOTES || {})[code] || '';
            document.getElementById('ice-helmet-note').textContent = (window.ICE_HELMET_NOTES || {})[code] || '';
        }
        iceClassSelect.addEventListener('change', onIceClassChange);
        // The browser can restore a <select> value on reload/back-navigation without firing
        // `change`, leaving the checklist rendered for the page's original class (or empty, for a
        // fresh form) while the select itself shows something else. Bring the checklist back in
        // sync once on load when that happens.
        if (iceClassSelect.value !== (window.ICE_RENDERED_CLASS || '')) onIceClassChange();
    }

    const entrantPad = WcmaSignaturePad.attach(document.getElementById('entrant-sig-canvas'));
    const driverPad = WcmaSignaturePad.attach(document.getElementById('driver-sig-canvas'));
    document.querySelectorAll('[data-clear-sig]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            (btn.getAttribute('data-clear-sig') === 'entrant' ? entrantPad : driverPad).clear();
        });
    });

    const driver1Choice = document.getElementById('driver1_choice');
    const driver1NewName = document.getElementById('driver1_new_name');
    WcmaDriverChoice.wire(driver1Choice, driver1NewName);

    const sheetTypeSelect = document.getElementById('sheet_type');
    const enduranceCard = document.getElementById('endurance-drivers-card');
    const additionalDriversContainer = document.getElementById('additional-drivers-container');
    const additionalDrivers = []; // [{number, picker, state}]

    // Toggling endurance -> standard hides #endurance-drivers-card, but the
    // co-driver name inputs stay in the DOM with `required` set, which fails
    // HTML5 constraint validation silently (a required-but-hidden field
    // blocks submit with no visible error). Disabling excludes them from
    // constraint validation entirely; re-enable on toggle back.
    if (sheetTypeSelect) {
        sheetTypeSelect.addEventListener('change', function () {
            const isEndurance = sheetTypeSelect.value === 'endurance';
            enduranceCard.hidden = !isEndurance;
            additionalDrivers.forEach(function (d) {
                d.picker.select.disabled = !isEndurance;
                d.picker.sync();
            });
        });
    }

    function renumberDriverRows() {
        additionalDrivers.forEach(function (d, i) {
            const number = i + 2;
            d.number = number;
            d.picker.select.setAttribute('aria-label', 'Driver ' + number);
        });
    }

    function addDriverRow(existingDriver) {
        if (additionalDrivers.length >= 6) return; // drivers 2-7
        const number = additionalDrivers.length + 2;
        const wrap = document.createElement('div');
        wrap.style.marginBottom = '1rem';
        wrap.style.display = 'flex';
        wrap.style.alignItems = 'flex-start';
        wrap.style.gap = '0.5rem';

        const fieldsWrap = document.createElement('div');
        fieldsWrap.style.flex = '1';

        const drivers = window.TECH_SHEET_DRIVERS || [];
        // Default to a co-driver profile nobody else on the sheet has picked yet, so adding a row
        // doesn't start the new driver as a duplicate of Driver 1 or another row.
        const chosen = [driver1Choice.value].concat(additionalDrivers.map(function (d) { return d.picker.select.value; }));
        const availableCoDriver = drivers.find(function (d) { return !d.self && chosen.indexOf(String(d.id)) === -1; });
        const picker = WcmaDriverChoice.build(document, drivers,
            existingDriver ? existingDriver.driver_choice : (availableCoDriver ? availableCoDriver.id : WcmaDriverChoice.NEW),
            existingDriver ? existingDriver.new_name : '', number);
        fieldsWrap.appendChild(picker.select);
        fieldsWrap.appendChild(picker.nameInput);
        const equipContainer = document.createElement('div');
        fieldsWrap.appendChild(equipContainer);
        wrap.appendChild(fieldsWrap);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'link-button';
        removeBtn.textContent = 'Remove';
        removeBtn.addEventListener('click', function () {
            const idx = additionalDrivers.findIndex(function (d) { return d.wrap === wrap; });
            if (idx !== -1) additionalDrivers.splice(idx, 1);
            wrap.remove();
            renumberDriverRows();
        });
        wrap.appendChild(removeBtn);

        additionalDriversContainer.appendChild(wrap);
        const equip = renderEquipmentInto(equipContainer, 'driver' + number, existingDriver ? existingDriver.equipment : null);
        additionalDrivers.push({
            number: number, picker: picker, state: equip.state, wrap: wrap,
            highlightIncomplete: equip.highlightIncomplete, clearHighlights: equip.clearHighlights,
        });
        picker.nameInput.addEventListener('input', function () {
            if (picker.nameInput.value.trim() !== '') picker.nameInput.classList.remove('error');
        });
        picker.select.addEventListener('change', function () {
            picker.select.classList.remove('error');
            picker.nameInput.classList.remove('error');
        });
    }

    const addDriverBtn = document.getElementById('add-driver-btn');
    if (addDriverBtn) {
        addDriverBtn.addEventListener('click', function () {
            addDriverRow(null);
        });
    }

    const existingDrivers = window.TECH_SHEET_EXISTING_DRIVERS || [];
    if (additionalDriversContainer && existingDrivers.length > 0) {
        existingDrivers.forEach(function (d) { addDriverRow(d); });
    }

    const entrantSigWrap = document.getElementById('entrant-sig-canvas').closest('.sig-pad-wrap');
    const driverSigWrap = document.getElementById('driver-sig-canvas').closest('.sig-pad-wrap');

    function clearAllHighlights() {
        checklistWidget.clearHighlights();
        driver1Equipment.clearHighlights();
        additionalDrivers.forEach(function (d) {
            d.clearHighlights();
            d.picker.select.classList.remove('error');
            d.picker.nameInput.classList.remove('error');
        });
        driver1Choice.classList.remove('error');
        driver1NewName.classList.remove('error');
        entrantSigWrap.classList.remove('field-error');
        driverSigWrap.classList.remove('field-error');
    }

    document.getElementById('tech-sheet-form').addEventListener('submit', function (e) {
        const errorEl = document.getElementById('tech-sheet-error');
        errorEl.hidden = true;
        clearAllHighlights();

        if (!checklistWidget.isComplete()) {
            e.preventDefault();
            checklistWidget.highlightIncomplete();
            errorEl.textContent = 'Please mark every checklist item OK or N/A before submitting — the missing items are highlighted below.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
        if (!WcmaDriverChoice.driverChoiceComplete(driver1Choice.value, driver1NewName.value)) {
            e.preventDefault();
            (driver1Choice.value === WcmaDriverChoice.NEW ? driver1NewName : driver1Choice).classList.add('error');
            errorEl.textContent = 'Choose Driver 1, or pick "+ Add a co-driver" and type their name.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
        if (!isEquipmentComplete(driver1State)) {
            e.preventDefault();
            driver1Equipment.highlightIncomplete();
            errorEl.textContent = 'Please confirm all of Driver 1\'s safety equipment (including helmet and suit ratings) before submitting — the missing items are highlighted below.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
        if (sheetTypeSelect && sheetTypeSelect.value === 'endurance') {
            const incompleteDriver = additionalDrivers.find(function (d) {
                return !WcmaDriverChoice.driverChoiceComplete(d.picker.select.value, d.picker.nameInput.value) || !isEquipmentComplete(d.state);
            });
            if (incompleteDriver) {
                e.preventDefault();
                if (!WcmaDriverChoice.driverChoiceComplete(incompleteDriver.picker.select.value, incompleteDriver.picker.nameInput.value)) {
                    (incompleteDriver.picker.select.value === WcmaDriverChoice.NEW ? incompleteDriver.picker.nameInput : incompleteDriver.picker.select).classList.add('error');
                }
                incompleteDriver.highlightIncomplete();
                errorEl.textContent = 'Please choose a driver and confirm all safety equipment for every added driver (Driver ' + incompleteDriver.number + ') before submitting — the missing fields are highlighted below.';
                errorEl.hidden = false;
                errorEl.classList.add('show');
                return;
            }
            const rows = [{ choice: driver1Choice.value, newName: driver1NewName.value, select: driver1Choice, nameInput: driver1NewName }]
                .concat(additionalDrivers.map(function (d) {
                    return { choice: d.picker.select.value, newName: d.picker.nameInput.value, select: d.picker.select, nameInput: d.picker.nameInput };
                }));
            const dupIndex = WcmaDriverChoice.duplicateDriverChoice(rows, window.TECH_SHEET_DRIVERS || []);
            if (dupIndex !== -1) {
                e.preventDefault();
                const dup = rows[dupIndex];
                (dup.choice === WcmaDriverChoice.NEW ? dup.nameInput : dup.select).classList.add('error');
                let name = dup.newName.trim();
                if (dup.choice !== WcmaDriverChoice.NEW) {
                    const match = (window.TECH_SHEET_DRIVERS || []).find(function (d) { return String(d.id) === String(dup.choice); });
                    name = match ? match.name : 'This driver';
                }
                errorEl.textContent = name + ' is on this sheet twice.';
                errorEl.hidden = false;
                errorEl.classList.add('show');
                return;
            }
        }
        const entrantSignatureMissing = entrantPad.isEmpty() && !window.TECH_SHEET_HAS_ENTRANT_SIGNATURE;
        const driverSignatureMissing = driverPad.isEmpty() && !window.TECH_SHEET_HAS_DRIVER_SIGNATURE;
        if (entrantSignatureMissing || driverSignatureMissing) {
            e.preventDefault();
            if (entrantSignatureMissing) entrantSigWrap.classList.add('field-error');
            if (driverSignatureMissing) driverSigWrap.classList.add('field-error');
            errorEl.textContent = 'Both the entrant and driver signatures are required — the missing signature pad(s) are highlighted below.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }

        document.getElementById('checklist_json').value = JSON.stringify(checklistWidget.getState());
        document.getElementById('driver1_equipment_json').value = JSON.stringify(driver1State);
        // Leave the hidden field blank when the pad wasn't (re)drawn, so an edit save
        // without re-signing doesn't clobber the previously-saved signature file.
        document.getElementById('entrant_signature').value = entrantPad.isEmpty() ? '' : entrantPad.toPNGDataURL();
        document.getElementById('driver_signature').value = driverPad.isEmpty() ? '' : driverPad.toPNGDataURL();
        document.getElementById('drivers_json').value = JSON.stringify(additionalDrivers.map(function (d) {
            return { driver_number: d.number, driver_choice: d.picker.select.value, new_name: d.picker.nameInput.value, equipment: d.state };
        }));
    });
})();
