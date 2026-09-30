// wcma-calculator/js/tech-sheet-form.js
(function () {
    let checklistWidget = WcmaTechChecklist.render(
        document.getElementById('checklist-container'), TECH_CHECKLIST_SECTIONS, window.TECH_SHEET_EXISTING_CHECKLIST || {}
    );

    // Problems are shown in words next to their field, and the page takes you there (spec §C3).
    // Every problem is reported on one submit (UX review 2026-09-30 §H2): the browser's own
    // stop-at-the-first check is off, and the submit handler below runs every check itself.
    const form = document.getElementById('tech-sheet-form');
    form.noValidate = true;
    WcmaFormProblems.wire(form, { noFocus: true });
    function firstOf(selector, fallback) { return document.querySelector(selector) || fallback; }
    // What to type in a rating box, by item (UX review §M14): a helmet standard is not a suit standard.
    const RATING_EXAMPLES = { helmet: 'SA2020', suit: 'SFI 3.2A/5' };

    // Ice form: whether the chosen class requires the head & neck restraint (labels new driver rows too).
    let headNeckRequired = false;
    // Why it is required, for the label: the ice class (default) or, on the TA/Drift form, the cage.
    let headNeckReason;

    // A fixed row is no longer a problem: drop its highlight and any message under it.
    function clearRowProblem(row) {
        row.classList.remove('field-error');
        const next = row.nextElementSibling;
        if (next && next.hasAttribute && next.hasAttribute('data-problem')) next.remove();
    }

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
                ? WcmaIceClass.equipmentLabel(def.label, true, headNeckReason) : def.label;
            row.appendChild(label);

            if (def.has_rating) {
                label.textContent = def.label + ' rating';
                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = RATING_EXAMPLES[key] ? 'e.g. ' + RATING_EXAMPLES[key] : 'Rating on the label';
                input.setAttribute('aria-label', def.label + ' rating, from its label');
                input.style.marginRight = '0.5rem';
                if (state[key].value) input.value = state[key].value;
                input.addEventListener('input', function () {
                    state[key].value = input.value;
                    state[key].competitor_confirmed = input.value.trim() !== '';
                    if (input.value.trim() !== '') { input.classList.remove('error'); clearRowProblem(row); }
                });
                ratingInputRefs[key] = input;
                row.appendChild(input);
            } else {
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'checklist-chip';
                confirmBtn.textContent = 'I have this';
                confirmBtn.setAttribute('aria-pressed', state[key].competitor_confirmed ? 'true' : 'false');
                if (state[key].competitor_confirmed) confirmBtn.classList.add('checklist-chip-selected-ok');
                confirmBtn.addEventListener('click', function () {
                    state[key].competitor_confirmed = !state[key].competitor_confirmed;
                    confirmBtn.classList.toggle('checklist-chip-selected-ok', state[key].competitor_confirmed);
                    confirmBtn.setAttribute('aria-pressed', state[key].competitor_confirmed ? 'true' : 'false');
                    if (state[key].competitor_confirmed) clearRowProblem(row);
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

        function missingCount() {
            return Object.keys(TECH_DRIVER_EQUIPMENT_ITEMS).filter(function (key) {
                const def = TECH_DRIVER_EQUIPMENT_ITEMS[key];
                const item = state[key];
                return (!def.optional && !item.competitor_confirmed) || (def.has_rating && (item.value == null || String(item.value).trim() === ''));
            }).length;
        }

        return { state: state, highlightIncomplete: highlightIncomplete, clearHighlights: clearHighlights, missingCount: missingCount };
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

    // TA/Drift form: a roll bar or cage adds the cage checks and makes the head & neck restraint required.
    const cagedBox = document.getElementById('ta_drift_caged');
    if (cagedBox && window.TA_DRIFT_SECTIONS && window.WcmaIceClass) {
        headNeckReason = 'in a caged car';
        function syncHeadNeck() {
            headNeckRequired = cagedBox.checked;
            TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.optional = !cagedBox.checked;
            document.querySelectorAll('[data-equipment-key="head_neck_restraints"] .checklist-item-label').forEach(function (el) {
                el.textContent = WcmaIceClass.equipmentLabel(TECH_DRIVER_EQUIPMENT_ITEMS.head_neck_restraints.label, cagedBox.checked, headNeckReason);
            });
            document.getElementById('ta-drift-helmet-note').textContent = (window.TA_DRIFT_HELMET_NOTES || {})[cagedBox.checked ? 'on' : 'off'] || '';
        }
        function onCagedChange(seed) {
            const sections = window.TA_DRIFT_SECTIONS[cagedBox.checked ? 'on' : 'off'];
            const container = document.getElementById('checklist-container');
            // seed: answers from a restored draft (tech-sheet-draft.js), used on the first render only.
            const carried = WcmaIceClass.carryChecklistState(Object.assign({}, seed || {}, checklistWidget.getState()), sections);
            container.innerHTML = '';
            checklistWidget = WcmaTechChecklist.render(container, sections, carried);
            syncHeadNeck();
        }
        cagedBox.addEventListener('change', function () { onCagedChange(); });
        // A browser can restore the box on reload without firing `change` (as with the ice class above).
        if (cagedBox.checked !== !!window.TA_DRIFT_RENDERED_CAGED) onCagedChange(window.TECH_SHEET_EXISTING_CHECKLIST);
        else syncHeadNeck();
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
    // The signature message goes once the member starts signing the pad(s) it names (spec 2026-09-29 §2.6).
    const sigMissing = { entrant: false, driver: false };
    [['entrant', 'entrant-sig-canvas', entrantSigWrap], ['driver', 'driver-sig-canvas', driverSigWrap]].forEach(function (pad) {
        document.getElementById(pad[1]).addEventListener('pointerdown', function () {
            if (!sigMissing[pad[0]]) return;
            sigMissing[pad[0]] = false;
            pad[2].classList.remove('field-error');
            if (sigMissing.entrant || sigMissing.driver) {
                sigError.textContent = 'Please sign in the ' + (sigMissing.entrant ? 'Entrant' : 'Driver') + '\'s signature box.';
                currentProblems.forEach(function (p) { if (p.key === 'sig') p.text = sigError.textContent; });
            } else {
                sigError.hidden = true;
                currentProblems = currentProblems.filter(function (p) { return p.key !== 'sig'; });
            }
            renderSummary();
        });
    });

    const sheetTypeSelect = document.getElementById('sheet_type');
    const enduranceCard = document.getElementById('endurance-drivers-card');
    const additionalDriversContainer = document.getElementById('additional-drivers-container');
    const additionalDrivers = []; // [{number, picker, state}]

    // The list of everything still to finish, above the Submit button. Each line takes you to its problem.
    let currentProblems = [];
    function renderSummary() {
        const errorEl = document.getElementById('tech-sheet-error');
        errorEl.textContent = '';
        document.querySelectorAll('.field-message-count').forEach(function (n) { n.remove(); });
        if (currentProblems.length === 0) { errorEl.hidden = true; return; }
        const n = currentProblems.length;
        const lead = document.createElement('p');
        lead.className = 'problem-summary-lead';
        lead.textContent = n === 1 ? 'One thing to finish before you can submit:' : n + ' things to finish before you can submit:';
        errorEl.appendChild(lead);
        const list = document.createElement('ul');
        list.className = 'problem-summary';
        currentProblems.forEach(function (p) {
            const li = document.createElement('li');
            const go = document.createElement('button');
            go.type = 'button';
            go.className = 'link-button';
            go.textContent = p.text;
            go.addEventListener('click', function () {
                // A checklist item in a section that has been folded shut: open it so there is somewhere to go.
                const section = p.el.closest ? p.el.closest('.checklist-section') : null;
                if (section) section.classList.add('checklist-section-open');
                WcmaFormProblems.focus(p.el);
            });
            li.appendChild(go);
            list.appendChild(li);
        });
        errorEl.appendChild(list);
        errorEl.hidden = false;
        errorEl.classList.add('show');
    }

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
        clearAllHighlights();
        WcmaFormProblems.clearAll(form);
        const problems = [];   // {el, text, key}: every problem, found in one pass

        // 1. Plain required fields and radio groups. checkValidity() fires `invalid`, which wire() turns
        //    into the words next to the field.
        const seenAnchors = [];
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.willValidate || el.checkValidity()) return;
            const anchor = (el.type === 'radio' && el.closest('[data-radio-group]')) || el;
            if (seenAnchors.indexOf(anchor) !== -1) return;
            seenAnchors.push(anchor);
            const msg = anchor.nextElementSibling;
            problems.push({ el: el, text: msg && msg.classList.contains('field-message') ? msg.textContent : 'Fill in a required field.' });
        });

        // 2. The vehicle checklist.
        if (!checklistWidget.isComplete()) {
            checklistWidget.highlightIncomplete();
            const firstRow = document.querySelector('#checklist-container .checklist-item-row.field-error');
            const missing = checklistWidget.missingCount();
            if (firstRow) {
                WcmaFormProblems.mark(firstRow, 'Mark this item OK or N/A.');
                problems.push({ el: firstRow, text: missing === 1 ? 'Vehicle checklist: 1 item to mark OK or N/A.' : 'Vehicle checklist: ' + missing + ' items to mark OK or N/A.' });
            }
        }

        // 3. Driver 1.
        if (!WcmaDriverChoice.driverChoiceComplete(driver1Choice.value, driver1NewName.value)) {
            const driverField = driver1Choice.value === WcmaDriverChoice.NEW ? driver1NewName : driver1Choice;
            driverField.classList.add('error');
            if (!problems.some(function (p) { return p.el === driverField; })) {
                WcmaFormProblems.mark(driverField, 'Choose Driver 1, or pick "+ Add a co-driver" and type their name.');
                problems.push({ el: driverField, text: 'Choose Driver 1, or pick "+ Add a co-driver" and type their name.' });
            }
        }

        // 4. Driver 1's safety equipment.
        if (!isEquipmentComplete(driver1State)) {
            driver1Equipment.highlightIncomplete();
            const firstItem = firstOf('#equipment-container .checklist-item-row.field-error', document.getElementById('equipment-container'));
            const missing = driver1Equipment.missingCount();
            WcmaFormProblems.mark(firstItem, 'Tick "I have this", or enter the rating.');
            problems.push({ el: firstItem, text: 'Driver safety equipment: ' + missing + (missing === 1 ? ' item' : ' items') + ' to confirm.' });
        }

        // 5. Added drivers on an endurance sheet.
        if (sheetTypeSelect && sheetTypeSelect.value === 'endurance') {
            additionalDrivers.forEach(function (d) {
                const choiceOk = WcmaDriverChoice.driverChoiceComplete(d.picker.select.value, d.picker.nameInput.value);
                if (choiceOk && isEquipmentComplete(d.state)) return;
                if (!choiceOk) (d.picker.select.value === WcmaDriverChoice.NEW ? d.picker.nameInput : d.picker.select).classList.add('error');
                d.highlightIncomplete();
                const target = d.wrap.querySelector('.error, .field-error') || d.wrap;
                const text = 'Driver ' + d.number + ': choose the driver and confirm all their safety equipment.';
                if (!problems.some(function (p) { return p.el === target; })) {
                    WcmaFormProblems.mark(target, text);
                    problems.push({ el: target, text: text });
                }
            });
            const rows = [{ choice: driver1Choice.value, newName: driver1NewName.value, select: driver1Choice, nameInput: driver1NewName }]
                .concat(additionalDrivers.map(function (d) {
                    return { choice: d.picker.select.value, newName: d.picker.nameInput.value, select: d.picker.select, nameInput: d.picker.nameInput };
                }));
            const dupIndex = WcmaDriverChoice.duplicateDriverChoice(rows, window.TECH_SHEET_DRIVERS || []);
            if (dupIndex !== -1) {
                const dup = rows[dupIndex];
                const dupField = dup.choice === WcmaDriverChoice.NEW ? dup.nameInput : dup.select;
                dupField.classList.add('error');
                let name = dup.newName.trim();
                if (dup.choice !== WcmaDriverChoice.NEW) {
                    const match = (window.TECH_SHEET_DRIVERS || []).find(function (d) { return String(d.id) === String(dup.choice); });
                    name = match ? match.name : 'This driver';
                }
                if (name !== '' && !problems.some(function (p) { return p.el === dupField; })) {
                    WcmaFormProblems.mark(dupField, name + ' is on this sheet twice.');
                    problems.push({ el: dupField, text: name + ' is on this sheet twice.' });
                }
            }
        }

        // 6. Signatures.
        const one = oneSigner();
        const entrantSignatureMissing = entrantPad.isEmpty() && !window.TECH_SHEET_HAS_ENTRANT_SIGNATURE;
        const driverSignatureMissing = !one && driverPad.isEmpty() && !window.TECH_SHEET_HAS_DRIVER_SIGNATURE;
        sigMissing.entrant = entrantSignatureMissing;
        sigMissing.driver = driverSignatureMissing;
        if (entrantSignatureMissing || driverSignatureMissing) {
            if (entrantSignatureMissing) entrantSigWrap.classList.add('field-error');
            if (driverSignatureMissing) driverSigWrap.classList.add('field-error');
            const box = one ? 'the signature box' : (entrantSignatureMissing && driverSignatureMissing ? 'both signature boxes'
                : (entrantSignatureMissing ? 'the Entrant\'s signature box' : 'the Driver\'s signature box'));
            sigError.textContent = 'Please sign in ' + box + '.';
            sigError.hidden = false;
            problems.push({ el: entrantSignatureMissing ? entrantSigWrap : driverSigWrap, text: sigError.textContent, key: 'sig' });
        }

        if (problems.length > 0) {
            e.preventDefault();
            // Page order, so the list reads top to bottom and the first problem is the highest on the page.
            problems.sort(function (a, b) { return a.el === b.el ? 0 : (a.el.compareDocumentPosition(b.el) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1); });
            currentProblems = problems;
            renderSummary();
            const first = problems[0];
            if (problems.length > 1) {
                const count = document.createElement('p');
                count.className = 'field-message-count';
                count.textContent = problems.length + ' things to finish. Each one is marked, and they are all listed above the Submit button.';
                const anchor = first.key === 'sig' ? sigError : ((first.el.type === 'radio' && first.el.closest('[data-radio-group]')) || first.el);
                const after = anchor.nextElementSibling && anchor.nextElementSibling.classList.contains('field-message') ? anchor.nextElementSibling : anchor;
                after.insertAdjacentElement('afterend', count);
            }
            if (first.key === 'sig') sigError.scrollIntoView({ block: 'center' });
            else WcmaFormProblems.focus(first.el);
            return;
        }
        currentProblems = [];
        renderSummary();

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
