// wcma-calculator/js/tech-sheet-form.js
(function () {
    const checklistWidget = WcmaTechChecklist.render(
        document.getElementById('checklist-container'), TECH_CHECKLIST_SECTIONS, window.TECH_SHEET_EXISTING_CHECKLIST || {}
    );

    function renderEquipmentInto(container, prefix, existingState) {
        existingState = existingState || {};
        const state = {};
        Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).forEach(function (key) {
            const existing = existingState[key] || {};
            state[key] = {
                competitor_confirmed: !!existing.competitor_confirmed,
                value: existing.value != null ? existing.value : null,
                tech_approved: existing.tech_approved != null ? existing.tech_approved : null,
            };
            const def = TECH_DRIVER_EQUIPMENT_ITEMS[key];
            const row = document.createElement('div');
            row.className = 'checklist-item-row';
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
                });
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
                });
                row.appendChild(confirmBtn);
            }
            container.appendChild(row);
        });
        return state;
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

    const driver1State = renderEquipmentInto(document.getElementById('equipment-container'), 'driver1', window.TECH_SHEET_EXISTING_EQUIPMENT);

    const entrantPad = WcmaSignaturePad.attach(document.getElementById('entrant-sig-canvas'));
    const driverPad = WcmaSignaturePad.attach(document.getElementById('driver-sig-canvas'));
    document.querySelectorAll('[data-clear-sig]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            (btn.getAttribute('data-clear-sig') === 'entrant' ? entrantPad : driverPad).clear();
        });
    });

    const sheetTypeSelect = document.getElementById('sheet_type');
    const enduranceCard = document.getElementById('endurance-drivers-card');
    const additionalDriversContainer = document.getElementById('additional-drivers-container');
    const additionalDrivers = []; // [{number, nameInput, state}]

    // Toggling endurance -> standard hides #endurance-drivers-card, but the
    // co-driver name inputs stay in the DOM with `required` set, which fails
    // HTML5 constraint validation silently (a required-but-hidden field
    // blocks submit with no visible error). Disabling excludes them from
    // constraint validation entirely; re-enable on toggle back.
    sheetTypeSelect.addEventListener('change', function () {
        const isEndurance = sheetTypeSelect.value === 'endurance';
        enduranceCard.hidden = !isEndurance;
        additionalDrivers.forEach(function (d) {
            d.nameInput.disabled = !isEndurance;
        });
    });

    function renumberDriverRows() {
        additionalDrivers.forEach(function (d, i) {
            const number = i + 2;
            d.number = number;
            d.nameInput.placeholder = 'Driver ' + number + ' Name';
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

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = 'Driver ' + number + ' Name';
        nameInput.required = true;
        if (existingDriver && existingDriver.driver_name) nameInput.value = existingDriver.driver_name;
        fieldsWrap.appendChild(nameInput);
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
        const state = renderEquipmentInto(equipContainer, 'driver' + number, existingDriver ? existingDriver.equipment : null);
        additionalDrivers.push({ number: number, nameInput: nameInput, state: state, wrap: wrap });
    }

    document.getElementById('add-driver-btn').addEventListener('click', function () {
        addDriverRow(null);
    });

    const existingDrivers = window.TECH_SHEET_EXISTING_DRIVERS || [];
    if (existingDrivers.length > 0) {
        existingDrivers.forEach(function (d) { addDriverRow(d); });
    }

    document.getElementById('tech-sheet-form').addEventListener('submit', function (e) {
        const errorEl = document.getElementById('tech-sheet-error');
        errorEl.hidden = true;

        if (!checklistWidget.isComplete()) {
            e.preventDefault();
            errorEl.textContent = 'Please mark every checklist item OK or N/A before submitting.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
        if (!isEquipmentComplete(driver1State)) {
            e.preventDefault();
            errorEl.textContent = 'Please confirm all of Driver 1\'s safety equipment (including helmet and suit ratings) before submitting.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }
        if (sheetTypeSelect.value === 'endurance') {
            const incompleteDriver = additionalDrivers.find(function (d) {
                return d.nameInput.value.trim() === '' || !isEquipmentComplete(d.state);
            });
            if (incompleteDriver) {
                e.preventDefault();
                errorEl.textContent = 'Please enter a name and confirm all safety equipment for every added driver (Driver ' + incompleteDriver.number + ') before submitting.';
                errorEl.hidden = false;
                errorEl.classList.add('show');
                return;
            }
        }
        const entrantSignatureMissing = entrantPad.isEmpty() && !window.TECH_SHEET_HAS_ENTRANT_SIGNATURE;
        const driverSignatureMissing = driverPad.isEmpty() && !window.TECH_SHEET_HAS_DRIVER_SIGNATURE;
        if (entrantSignatureMissing || driverSignatureMissing) {
            e.preventDefault();
            errorEl.textContent = 'Both the entrant and driver signatures are required.';
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
            return { driver_number: d.number, driver_name: d.nameInput.value, equipment: d.state };
        }));
    });
})();
