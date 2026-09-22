// wcma-calculator/js/tech-sheet-form.js
(function () {
    const checklistWidget = WcmaTechChecklist.render(
        document.getElementById('checklist-container'), TECH_CHECKLIST_SECTIONS, {}
    );

    function renderEquipmentInto(container, prefix) {
        const state = {};
        Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).forEach(function (key) {
            state[key] = { competitor_confirmed: false, value: null, tech_approved: null };
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

    const driver1State = renderEquipmentInto(document.getElementById('equipment-container'), 'driver1');

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

    document.getElementById('add-driver-btn').addEventListener('click', function () {
        if (additionalDrivers.length >= 6) return; // drivers 2-7
        const number = additionalDrivers.length + 2;
        const wrap = document.createElement('div');
        wrap.style.marginBottom = '1rem';
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = 'Driver ' + number + ' Name';
        nameInput.required = true;
        wrap.appendChild(nameInput);
        const equipContainer = document.createElement('div');
        wrap.appendChild(equipContainer);
        additionalDriversContainer.appendChild(wrap);
        const state = renderEquipmentInto(equipContainer, 'driver' + number);
        additionalDrivers.push({ number: number, nameInput: nameInput, state: state });
    });

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
        if (entrantPad.isEmpty() || driverPad.isEmpty()) {
            e.preventDefault();
            errorEl.textContent = 'Both the entrant and driver signatures are required.';
            errorEl.hidden = false;
            errorEl.classList.add('show');
            return;
        }

        document.getElementById('checklist_json').value = JSON.stringify(checklistWidget.getState());
        document.getElementById('driver1_equipment_json').value = JSON.stringify(driver1State);
        document.getElementById('entrant_signature').value = entrantPad.toPNGDataURL();
        document.getElementById('driver_signature').value = driverPad.toPNGDataURL();
        document.getElementById('drivers_json').value = JSON.stringify(additionalDrivers.map(function (d) {
            return { driver_number: d.number, driver_name: d.nameInput.value, equipment: d.state };
        }));
    });
})();
