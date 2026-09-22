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

    sheetTypeSelect.addEventListener('change', function () {
        enduranceCard.hidden = sheetTypeSelect.value !== 'endurance';
    });

    function addDriverRow(existingDriver) {
        if (additionalDrivers.length >= 6) return; // drivers 2-7
        const number = additionalDrivers.length + 2;
        const wrap = document.createElement('div');
        wrap.style.marginBottom = '1rem';
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = 'Driver ' + number + ' Name';
        nameInput.required = true;
        if (existingDriver && existingDriver.driver_name) nameInput.value = existingDriver.driver_name;
        wrap.appendChild(nameInput);
        const equipContainer = document.createElement('div');
        wrap.appendChild(equipContainer);
        additionalDriversContainer.appendChild(wrap);
        const state = renderEquipmentInto(equipContainer, 'driver' + number, existingDriver ? existingDriver.equipment : null);
        additionalDrivers.push({ number: number, nameInput: nameInput, state: state });
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
