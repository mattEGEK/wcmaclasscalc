// wcma-calculator/js/tech-sheet-form.js
(function () {
    let checklistWidget = WcmaTechChecklist.render(
        document.getElementById('checklist-container'), TECH_CHECKLIST_SECTIONS, window.TECH_SHEET_EXISTING_CHECKLIST || {}
    );

    // Problems are shown in words next to their field, and the page takes you there (spec §C3).
    const form = document.getElementById('tech-sheet-form');
    WcmaFormProblems.wire(form);
    function firstOf(selector, fallback) { return document.querySelector(selector) || fallback; }

    // Ice form: whether the chosen class requires the head & neck restraint (labels new driver rows too).
    let headNeckRequired = false;

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
            row.setAttribute('data-equipment-key', key);
            rowRefs[key] = row;
            const label = document.createElement('div');
            label.className = 'checklist-item-label';
            label.textContent = (key === 'head_neck_restraints' && headNeckRequired)
                ? WcmaIceClass.equipmentLabel(def.label, true) : def.label;
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
        function updateHeadNeckLabel(code) {
            const hnRequired = WcmaIceClass.fhrRequired(window.ICE_FHR_BY_CLASS, code);
            headNeckRequired = hnRequired;
            document.querySelectorAll('[data-equipment-key="head_neck_restraints"] .checklist-item-label').forEach(function (el) {
                el.textContent = WcmaIceClass.equipmentLabel(TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.label, hnRequired);
            });
        }
        function onIceClassChange(seed) {
            const code = iceClassSelect.value;
            const sections = WcmaIceClass.sectionsFor(window.ICE_SECTIONS_BY_CLASS, code);
            const container = document.getElementById('checklist-container');
            // seed: answers from a restored draft (tech-sheet-draft.js), used on the first render only.
            const carried = WcmaIceClass.carryChecklistState(Object.assign({}, seed || {}, checklistWidget.getState()), sections);
            container.innerHTML = '';
            checklistWidget = WcmaTechChecklist.render(container, sections, carried);
            TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.optional = !WcmaIceClass.fhrRequired(window.ICE_FHR_BY_CLASS, code);
            updateHeadNeckLabel(code);
            document.getElementById('ice-class-note').textContent = (window.ICE_CLASS_NOTES || {})[code] || '';
            document.getElementById('ice-helmet-note').textContent = (window.ICE_HELMET_NOTES || {})[code] || '';
        }
        iceClassSelect.addEventListener('change', function () { onIceClassChange(); });
        // The browser can restore a <select> value on reload/back-navigation without firing
        // `change`, leaving the checklist rendered for the page's original class (or empty, for a
        // fresh form) while the select itself shows something else. Bring the checklist back in
        // sync once on load when that happens.
        if (iceClassSelect.value !== (window.ICE_RENDERED_CLASS || '')) onIceClassChange(window.TECH_SHEET_EXISTING_CHECKLIST);
        // For a server-rendered selected class on a fresh form, update the label once.
        if (iceClassSelect.value !== '') updateHeadNeckLabel(iceClassSelect.value);
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

    // One signature when Driver 1 is the signed-in user (spec §C4): the pad counts as both.
    const driverSigBlock = document.getElementById('driver-sig-block');
    const entrantSigLabel = document.getElementById('entrant-sig-label');
    const sigError = document.getElementById('sig-error');
    function oneSigner() { return WcmaDriverChoice.isSelfChoice(window.TECH_SHEET_DRIVERS || [], driver1Choice.value); }
    function syncSigners() {
        const one = oneSigner();
        const appearing = !one && driverSigBlock.hidden;
        driverSigBlock.hidden = one;
        entrantSigLabel.textContent = one ? 'Your signature (entrant and driver)' : 'Entrant\'s signature';
        if (appearing) driverPad.resize();   // the canvas had no size while hidden; this also clears it
    }
    driver1Choice.addEventListener('change', syncSigners);
    syncSigners();

    const entrantSigWrap = document.getElementById('entrant-sig-canvas').closest('.sig-pad-wrap');
    const driverSigWrap = document.getElementById('driver-sig-canvas').closest('.sig-pad-wrap');
    // The signature message goes as soon as the member starts signing (spec 2026-09-29 §2.6).
    [['entrant-sig-canvas', entrantSigWrap], ['driver-sig-canvas', driverSigWrap]].forEach(function (pair) {
        document.getElementById(pair[0]).addEventListener('pointerdown', function () {
            sigError.hidden = true;
            pair[1].classList.remove('field-error');
        });
    });

    const sheetTypeSelect = document.getElementById('sheet_type');
    const enduranceCard = document.getElementById('endurance-drivers-card');
    const additionalDriversContainer = document.getElementById('additional-drivers-container');
    const additionalDrivers = []; // [{number, picker, state}]

    // Toggling endurance -> standard hides #endurance-drivers-card, but the
    // co-driver name inputs stay in the DOM with `required` set, which fails
    // HTML5 constraint validation silently (a required-but-hidden field
    // blocks submit with no visible error). Disabling excludes them from
    // constraint validation entirely; re-enable on toggle back.
    function syncSheetType() {
        const isEndurance = sheetTypeSelect.value === 'endurance';
        enduranceCard.hidden = !isEndurance;
        additionalDrivers.forEach(function (d) {
            d.picker.select.disabled = !isEndurance;
            d.picker.sync();
        });
    }
    if (sheetTypeSelect) {
        sheetTypeSelect.addEventListener('change', syncSheetType);
        syncSheetType();
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
        sigError.hidden = true;
    }

    document.getElementById('tech-sheet-form').addEventListener('submit', function (e) {
        const errorEl = document.getElementById('tech-sheet-error');
        errorEl.hidden = true;
        clearAllHighlights();
        WcmaFormProblems.clearAll(form);

        if (!checklistWidget.isComplete()) {
            e.preventDefault();
            checklistWidget.highlightIncomplete();
            errorEl.textContent = 'Please mark every checklist item OK or N/A before submitting — the missing items are highlighted.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            WcmaFormProblems.show(firstOf('#checklist-container .checklist-item-row.field-error', document.getElementById('checklist-container')),
                'Mark this item OK or N/A.');
            return;
        }
        if (!WcmaDriverChoice.driverChoiceComplete(driver1Choice.value, driver1NewName.value)) {
            e.preventDefault();
            const driverField = driver1Choice.value === WcmaDriverChoice.NEW ? driver1NewName : driver1Choice;
            driverField.classList.add('error');
            errorEl.textContent = 'Choose Driver 1, or pick "+ Add a co-driver" and type their name.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            WcmaFormProblems.show(driverField, errorEl.textContent);
            return;
        }
        if (!isEquipmentComplete(driver1State)) {
            e.preventDefault();
            driver1Equipment.highlightIncomplete();
            errorEl.textContent = 'Please confirm all of Driver 1\'s safety equipment (including helmet and suit ratings) before submitting — the missing items are highlighted.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            WcmaFormProblems.show(firstOf('#equipment-container .field-error', document.getElementById('equipment-container')),
                'Confirm this item, or enter its rating.');
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
                errorEl.textContent = 'Please choose a driver and confirm all safety equipment for every added driver (Driver ' + incompleteDriver.number + ') before submitting — the missing fields are highlighted.';
                errorEl.hidden = false;
                errorEl.classList.add('show');
                WcmaFormProblems.show(incompleteDriver.wrap.querySelector('.error, .field-error') || incompleteDriver.wrap, errorEl.textContent);
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
                WcmaFormProblems.show(dup.choice === WcmaDriverChoice.NEW ? dup.nameInput : dup.select, errorEl.textContent);
                return;
            }
        }
        const one = oneSigner();
        const entrantSignatureMissing = entrantPad.isEmpty() && !window.TECH_SHEET_HAS_ENTRANT_SIGNATURE;
        const driverSignatureMissing = !one && driverPad.isEmpty() && !window.TECH_SHEET_HAS_DRIVER_SIGNATURE;
        if (entrantSignatureMissing || driverSignatureMissing) {
            e.preventDefault();
            if (entrantSignatureMissing) entrantSigWrap.classList.add('field-error');
            if (driverSignatureMissing) driverSigWrap.classList.add('field-error');
            const box = one ? 'the signature box' : (entrantSignatureMissing && driverSignatureMissing ? 'both signature boxes'
                : (entrantSignatureMissing ? 'the Entrant\'s signature box' : 'the Driver\'s signature box'));
            sigError.textContent = 'Please sign in ' + box + '.';
            sigError.hidden = false;
            errorEl.textContent = sigError.textContent;
            errorEl.hidden = false;
            errorEl.classList.add('show');
            sigError.scrollIntoView({ block: 'center' });
            return;
        }

        document.getElementById('checklist_json').value = JSON.stringify(checklistWidget.getState());
        document.getElementById('driver1_equipment_json').value = JSON.stringify(driver1State);
        // Leave the hidden field blank when the pad wasn't (re)drawn, so an edit save
        // without re-signing doesn't clobber the previously-saved signature file.
        const entrantData = entrantPad.isEmpty() ? '' : entrantPad.toPNGDataURL();
        document.getElementById('entrant_signature').value = entrantData;
        // One signer: the same signature is the driver's. A blank pad leaves both blank, keeping signatures on file.
        document.getElementById('driver_signature').value = one ? entrantData : (driverPad.isEmpty() ? '' : driverPad.toPNGDataURL());
        document.getElementById('drivers_json').value = JSON.stringify(additionalDrivers.map(function (d) {
            return { driver_number: d.number, driver_choice: d.picker.select.value, new_name: d.picker.nameInput.value, equipment: d.state };
        }));
    });
    // What tech-sheet-draft.js keeps between visits (spec §C1).
    window.WcmaTechSheetForm = { state: function () { return { checklist: checklistWidget.getState(), equipment: driver1State }; } };
})();
