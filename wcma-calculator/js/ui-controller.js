/**
 * UI Controller Module
 * Handles DOM manipulation, event handling, and real-time updates
 */

import { updateCalculations, formatNumber, getBoundaryDistances, getScoringClass, getItTireMaxWidth, CLASS_RANGES } from './calculator.js';
import { handleFormSubmit, clearFieldError, showFieldError, validateFileSize, validateFileType } from './form-handler.js';
import {
    chassisModifierTable,
    bodyModifierTable,
    transModifierTable,
    dtModifierTable,
    tireModifierTable,
    brakeModifierTable,
    getModifierValue,
    isOptionAvailable
} from './modifiers.js';

// Form data state
let formData = {
    competitionWeight: '',
    declaredHp: '',
    dynoHp: '',
    classChoice: '',
    chassis: '',
    bodyMods: [],
    transmission: '',
    drivetrain: '',
    tires: '',
    brakeSuspension: ''
};

// ── Unsaved-changes tracking & guest "sign in to save" nudge ────────────────
const MEANINGFUL_FIELD_IDS = ['name', 'email', 'year', 'make', 'model', 'competition-weight', 'declared-hp'];
const NUDGE_DISMISS_KEY = 'wcma-save-nudge-dismissed';
let formDirty = false;
let loginStatusPromise = null;
let nudgeCheckTimer = null;

function isFormMeaningfullyFilled() {
    return MEANINGFUL_FIELD_IDS.some(id => {
        const el = document.getElementById(id);
        return el && el.value.trim() !== '';
    });
}

function markFormDirty() {
    formDirty = true;
}

function markFormClean() {
    formDirty = false;
}

function getLoginStatus() {
    if (!loginStatusPromise) {
        loginStatusPromise = fetch('session-status.php', { credentials: 'same-origin' })
            .then(res => res.json())
            .catch(() => ({ loggedIn: false }));
    }
    return loginStatusPromise;
}

function hideSaveNudge() {
    const container = document.getElementById('account-nudge');
    const box = container && container.querySelector('.pre-submit-nudge');
    if (box) box.remove();
}

async function showSaveNudgeIfNeeded() {
    if (!isFormMeaningfullyFilled()) return;
    if (sessionStorage.getItem(NUDGE_DISMISS_KEY) === '1') return;

    const container = document.getElementById('account-nudge');
    if (!container || container.querySelector('.pre-submit-nudge') || container.querySelector('.post-submit-nudge')) return;

    const status = await getLoginStatus();
    if (status.loggedIn) return;
    // Re-check in case the user dismissed it or submitted while the fetch was in flight
    if (sessionStorage.getItem(NUDGE_DISMISS_KEY) === '1') return;
    if (container.querySelector('.pre-submit-nudge') || container.querySelector('.post-submit-nudge')) return;

    const redirect = encodeURIComponent('calculator.php');
    const box = document.createElement('div');
    box.className = 'pre-submit-nudge account-nudge-box';

    const text = document.createElement('span');
    text.textContent = 'Sign in to save your progress as you go. ';
    box.appendChild(text);

    const signInLink = document.createElement('a');
    signInLink.href = `auth.php?action=login&redirect=${redirect}`;
    signInLink.textContent = 'Sign In';
    box.appendChild(signInLink);

    box.appendChild(document.createTextNode(' · '));

    const registerLink = document.createElement('a');
    registerLink.href = `auth.php?action=register&redirect=${redirect}`;
    registerLink.textContent = 'Register';
    box.appendChild(registerLink);

    const dismissBtn = document.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.className = 'nudge-dismiss';
    dismissBtn.setAttribute('aria-label', 'Dismiss');
    dismissBtn.textContent = '×';
    dismissBtn.addEventListener('click', () => {
        sessionStorage.setItem(NUDGE_DISMISS_KEY, '1');
        box.remove();
    });
    box.appendChild(dismissBtn);

    container.appendChild(box);
}

function scheduleNudgeCheck() {
    if (nudgeCheckTimer) clearTimeout(nudgeCheckTimer);
    nudgeCheckTimer = setTimeout(showSaveNudgeIfNeeded, 600);
}

window.addEventListener('beforeunload', (event) => {
    if (formDirty && isFormMeaningfullyFilled()) {
        event.preventDefault();
        event.returnValue = '';
    }
});

/**
 * Show the full qualifying-criteria text for each selected modifier
 * dropdown option, since the <option> label itself gets visually
 * truncated and the full description only appears once selected.
 */
function updateModifierExplainers() {
    const explainerFields = [
        { selectId: 'chassis', explainerId: 'chassis-explainer', table: chassisModifierTable },
        { selectId: 'transmission', explainerId: 'transmission-explainer', table: transModifierTable },
        { selectId: 'drivetrain', explainerId: 'drivetrain-explainer', table: dtModifierTable },
        { selectId: 'tires', explainerId: 'tires-explainer', table: tireModifierTable }
    ];

    explainerFields.forEach(field => {
        const select = document.getElementById(field.selectId);
        const explainer = document.getElementById(field.explainerId);
        if (!select || !explainer) return;

        const optionId = select.value;
        if (!optionId) {
            explainer.textContent = '';
            return;
        }

        const row = field.table.find(r => r[0] === optionId);
        explainer.textContent = row ? row[1] : '';
    });
}

/**
 * Render the class-boundary proximity gauge: where the modified ratio
 * sits within the current class band, and how far to the nearer edge.
 * @param {Object} results - Calculation results from updateCalculations
 */
function updateBoundaryGauge(results) {
    const gauge = document.getElementById('boundary-gauge');
    const fill = document.getElementById('boundary-gauge-fill');
    const label = document.getElementById('boundary-gauge-label');
    if (!gauge || !fill || !label) return;

    const ratio = results.modifiedRatio > 0 ? results.modifiedRatio : results.baseRatio;
    const range = ratio > 0 && results.calculatedClass
        ? CLASS_RANGES.find(r => r.name === results.calculatedClass)
        : null;

    if (!range) {
        gauge.hidden = true;
        return;
    }
    gauge.hidden = false;

    // GTU/IT2 are open-ended on one side; use a nominal 2.0-wide band
    // purely for the fill bar's proportions, not for the distance text.
    const nominalWidth = 2.0;
    const lowerBound = isFinite(range.min) ? range.min : range.max - nominalWidth;
    const upperBound = isFinite(range.max) ? range.max : range.min + nominalWidth;
    const clampedRatio = Math.min(Math.max(ratio, lowerBound), upperBound);
    const pct = ((clampedRatio - lowerBound) / (upperBound - lowerBound)) * 100;
    fill.style.width = `${pct.toFixed(1)}%`;

    const distances = getBoundaryDistances(ratio, results.calculatedClass);
    const parts = [];
    if (distances.up) {
        parts.push(`${formatNumber(distances.up.amount)} more and you're in ${distances.up.className}`);
    }
    if (distances.down) {
        parts.push(`${formatNumber(distances.down.amount)} less and you're in ${distances.down.className}`);
    }
    label.textContent = parts.join(' · ');

    const minTick = document.getElementById('boundary-gauge-min');
    const maxTick = document.getElementById('boundary-gauge-max');
    if (minTick) minTick.textContent = isFinite(range.min) ? formatNumber(range.min) : '';
    if (maxTick) maxTick.textContent = isFinite(range.max) ? formatNumber(range.max) : '';
}

/**
 * Get selected brake/suspension values (multiple checkboxes)
 * @returns {Array} Array of selected option IDs
 */
function getBrakeSuspensionValues() {
    const checkboxes = document.querySelectorAll('#brake-suspension-options input[type="checkbox"]:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

/**
 * Get selected body mod values (multiple checkboxes)
 * @returns {Array} Array of selected option IDs
 */
function getBodyModValues() {
    const checkboxes = document.querySelectorAll('#body-mods-options input[type="checkbox"]:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

/**
 * Get current form data from DOM
 * @returns {Object} Form data object
 */
function getFormData() {
    return {
        competitionWeight: document.getElementById('competition-weight')?.value || '',
        declaredHp: document.getElementById('declared-hp')?.value || '',
        dynoHp: document.getElementById('dyno-hp')?.value || '',
        classChoice: document.getElementById('class-choice')?.value || '',
        chassis: document.getElementById('chassis')?.value || '',
        bodyMods: getBodyModValues(),
        transmission: document.getElementById('transmission')?.value || '',
        drivetrain: document.getElementById('drivetrain')?.value || '',
        tires: document.getElementById('tires')?.value || '',
        brakeSuspension: getBrakeSuspensionValues()
    };
}

// Read by feedback.js so bug reports made on this page carry the current inputs.
window.wcmaGetCalcInputs = getFormData;

/**
 * Update form data state
 */
function updateFormData() {
    formData = getFormData();
}

/**
 * Check if base information is entered (weight and HP)
 * @returns {boolean} True if base info is available
 */
function hasBaseInfo() {
    const weight = parseFloat(formData.competitionWeight);
    const hp = parseFloat(formData.declaredHp);
    return !isNaN(weight) && !isNaN(hp) && weight > 0 && hp > 0;
}

/**
 * The class whose column the modifier values are read from: the chosen
 * class, or the class the weight/HP ratio falls in when left on Auto.
 * @returns {string} Class name, or '' without weight and HP
 */
function currentScoringClass() {
    if (!hasBaseInfo()) return '';
    return getScoringClass(parseFloat(formData.competitionWeight), parseFloat(formData.declaredHp), formData.classChoice);
}

/**
 * Populate modifier options for the scoring class
 */
function populateModifierOptions() {
    const scoringClass = currentScoringClass();
    if (!scoringClass) {
        return;
    }

    // Map of field IDs to modifier tables (checkbox groups are handled below)
    const modifierMap = {
        'chassis': chassisModifierTable,
        'transmission': transModifierTable,
        'drivetrain': dtModifierTable,
        'tires': tireModifierTable
    };

    // Populate dropdown selects
    Object.keys(modifierMap).forEach(fieldId => {
        const select = document.getElementById(fieldId);
        const table = modifierMap[fieldId];

        if (!select || !table) return;

        // Save current selection
        const currentValue = select.value;

        // Clear options
        select.innerHTML = '<option value="">-- Select --</option>';

        // Populate with available options for this class
        table.forEach(row => {
            const optionId = row[0];
            const description = row[1];

            if (isOptionAvailable(table, optionId, scoringClass)) {
                const option = document.createElement('option');
                option.value = optionId;

                // Get modifier value for this option and class
                const modifierValue = getModifierValue(table, optionId, scoringClass);
                if (modifierValue !== null) {
                    const sign = modifierValue >= 0 ? '+' : '';
                    option.textContent = `${description} (${sign}${formatNumber(modifierValue)})`;
                } else {
                    option.textContent = description;
                }

                select.appendChild(option);
            }
        });

        // Restore selection if still valid
        if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
            select.value = currentValue;
        } else {
            select.value = '';
        }
    });

    populateBodyModCheckboxes(scoringClass);

    // Special handling for Chassis restrictions affecting Body Mods
    handleChassisRestrictions();

    // Handle brake-suspension as checkboxes (only for IT1/IT2)
    populateBrakeSuspensionCheckboxes(scoringClass);
}

/**
 * Render one checkbox per modifier row that applies in the given class,
 * ticking those listed in `checkedValues`.
 * @returns {number} How many checkboxes were rendered
 */
function renderModifierCheckboxes(container, table, scoringClass, idPrefix, name, checkedValues) {
    container.innerHTML = '';
    table.forEach(row => {
        const optionId = row[0];
        const modifierValue = getModifierValue(table, optionId, scoringClass);
        if (modifierValue === null) return;

        const checkboxWrapper = document.createElement('div');
        checkboxWrapper.className = 'checkbox-item';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = `${idPrefix}-${optionId}`;
        checkbox.name = name;
        checkbox.value = optionId;
        checkbox.checked = checkedValues.includes(optionId);

        const label = document.createElement('label');
        label.htmlFor = checkbox.id;
        const sign = modifierValue >= 0 ? '+' : '';
        label.textContent = `${row[1]} (${sign}${formatNumber(modifierValue)})`;

        checkboxWrapper.appendChild(checkbox);
        checkboxWrapper.appendChild(label);
        container.appendChild(checkboxWrapper);
    });
    return container.children.length;
}

/**
 * Populate body mod checkboxes for the scoring class. Several may be ticked.
 */
function populateBodyModCheckboxes(scoringClass) {
    const container = document.getElementById('body-mods-options');
    if (!container) return;
    renderModifierCheckboxes(container, bodyModifierTable, scoringClass, 'body', 'body_mods[]', getBodyModValues());
}

/**
 * Handle Chassis-based restrictions on Body Mods
 */
function handleChassisRestrictions() {
    const chassisSelect = document.getElementById('chassis');
    const bodyContainer = document.getElementById('body-mods-options');

    if (!chassisSelect || !bodyContainer) return;

    const selectedChassis = chassisSelect.value;
    const bodyCheckboxes = bodyContainer.querySelectorAll('input[type="checkbox"]');

    // Body mods apply to production vehicles only, so they are off for
    // chassis1 (sports racer/prototype/monocoque) and chassis2 (non-production).
    const isRestricted = (selectedChassis === 'chassis1' || selectedChassis === 'chassis2');

    if (isRestricted) {
        let cleared = false;
        bodyCheckboxes.forEach(cb => {
            if (cb.checked) cleared = true;
            cb.checked = false;
            cb.disabled = true;
        });

        // Show tooltip/message
        let tooltip = document.getElementById('body-mods-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'body-mods-tooltip';
            tooltip.className = 'field-tooltip';
            tooltip.style.color = '#e74c3c';
            tooltip.style.fontSize = '0.85rem';
            tooltip.style.marginTop = '4px';
            tooltip.style.fontStyle = 'italic';
            tooltip.textContent = 'Not applicable for this Chassis type';
            bodyContainer.parentElement.parentElement.appendChild(tooltip);
        }
        tooltip.style.display = 'block';

        // Let the standard listeners recalculate once the ticks are gone
        if (cleared) {
            bodyContainer.dispatchEvent(new Event('change', { bubbles: true }));
        }
    } else {
        const enabled = hasBaseInfo();
        bodyCheckboxes.forEach(cb => { cb.disabled = !enabled; });

        // Hide tooltip
        const tooltip = document.getElementById('body-mods-tooltip');
        if (tooltip) {
            tooltip.style.display = 'none';
        }
    }
}

/**
 * Populate brake/suspension checkboxes for the scoring class.
 * Cars not scoring in IT1/IT2 lose the section and any ticked mods.
 */
function populateBrakeSuspensionCheckboxes(scoringClass) {
    const container = document.getElementById('brake-suspension-options');
    if (!container) return;

    if (scoringClass !== 'IT1' && scoringClass !== 'IT2') {
        const hadSelections = container.querySelector('input[type="checkbox"]:checked') !== null;
        container.innerHTML = '';
        if (hadSelections) {
            const notice = document.createElement('span');
            notice.className = 'field-note brake-removed-note';
            notice.textContent = 'Brake & suspension selections were removed because your car is no longer scoring in IT1/IT2.';
            container.appendChild(notice);
        }
        const note = document.createElement('span');
        note.className = 'field-note';
        note.textContent = 'Available when scoring in IT1 or IT2.';
        container.appendChild(note);
        return;
    }

    if (renderModifierCheckboxes(container, brakeModifierTable, scoringClass, 'brake', 'brake_suspension[]', getBrakeSuspensionValues()) === 0) {
        container.innerHTML = '<span class="field-note">No brake/suspension mods available for this class</span>';
    }
}

/**
 * Show the IT maximum tyre width for the car's weight, and warn when the
 * chosen tyre is in a width band above it.
 */
function updateTireWidthNote() {
    const note = document.getElementById('tire-width-note');
    if (!note) return;

    const scoringClass = currentScoringClass();
    if (scoringClass !== 'IT1' && scoringClass !== 'IT2') {
        note.textContent = 'IT1/IT2: maximum tire width depends on competition weight';
        note.classList.remove('field-warning');
        return;
    }

    const maxWidth = getItTireMaxWidth(parseFloat(formData.competitionWeight));
    const tooWide = formData.tires === 'tire1' || formData.tires === 'tire2';   // 267mm to 282mm
    note.textContent = tooWide
        ? `This tire is wider than the ${maxWidth}mm maximum for IT cars at your competition weight.`
        : `IT maximum tire section width at your competition weight: ${maxWidth}mm.`;
    note.classList.toggle('field-warning', tooWide);
}

/**
 * Enable/disable modification factor fields based on base info availability
 */
function updateModificationFieldsState() {
    const hasBase = hasBaseInfo();
    const modificationFields = [
        'class-choice',
        'chassis',
        'transmission',
        'drivetrain',
        'tires'
    ];

    modificationFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        const note = field?.parentElement.querySelector('.field-note');

        if (field) {
            // Enable if we have base info
            field.disabled = !hasBase;

            if (note) {
                if (hasBase) {
                    note.style.display = 'none';
                } else {
                    note.style.display = 'block';
                }
            }
        }
    });

    // Checkbox groups
    document.querySelectorAll('#brake-suspension-options input[type="checkbox"], #body-mods-options input[type="checkbox"]').forEach(cb => {
        cb.disabled = !hasBase;
    });

    // Populate modifier options when base info is available
    if (hasBase) {
        populateModifierOptions();
    }
}

/**
 * Update calculation results display
 * @param {Object} results - Optional pre-calculated results to avoid redundant calculation
 */
function updateResultsDisplay(results = null) {
    if (!results) {
        results = updateCalculations(formData);
    }

    // Update weight factor display
    const weightFactorDisplay = document.getElementById('weight-factor-display');
    if (weightFactorDisplay) {
        if (results.weightFactor !== undefined && results.weightFactor !== null && results.weightFactor !== 0) {
            const sign = results.weightFactor >= 0 ? '+' : '';
            weightFactorDisplay.textContent = `${sign}${formatNumber(results.weightFactor)}`;
        } else {
            weightFactorDisplay.textContent = '0.00';
        }
    }

    // Update additional mod factors display
    const additionalModsDisplay = document.getElementById('additional-mods-display');
    if (additionalModsDisplay) {
        // Show modification factor if it exists, otherwise show 0.00
        if (results.modificationFactor !== undefined && results.modificationFactor !== null) {
            additionalModsDisplay.textContent = formatNumber(results.modificationFactor);
        } else {
            additionalModsDisplay.textContent = '0.00';
        }
    }

    // Update inline results in base information section
    updateInlineResults(results);
    
    // Update modifier values
    updateModifierValues();
}

/**
 * Update inline results next to input fields
 */
function updateInlineResults(results) {
    // Update inline base ratio
    const inlineBaseRatio = document.getElementById('inline-base-ratio');
    if (inlineBaseRatio) {
        inlineBaseRatio.textContent = results.baseRatio > 0 ? formatNumber(results.baseRatio) : '--';
    }

    // Update inline modified ratio
    const inlineModifiedRatio = document.getElementById('inline-modified-ratio');
    if (inlineModifiedRatio) {
        const ratioToShow = results.modifiedRatio > 0 ? results.modifiedRatio : results.baseRatio;
        inlineModifiedRatio.textContent = ratioToShow > 0 ? formatNumber(ratioToShow) : '--';
    }

    // Update inline calculated class
    const inlineCalculatedClass = document.getElementById('inline-calculated-class');
    if (inlineCalculatedClass) {
        const hasRatio = results.modifiedRatio > 0 || results.baseRatio > 0;
        if (hasRatio && results.calculatedClass) {
            inlineCalculatedClass.textContent = results.movedUp
                ? `${results.competingClass} (up from ${results.calculatedClass})`
                : results.calculatedClass;
        } else {
            inlineCalculatedClass.textContent = '--';
        }
    }
    
    // Highlight the calculated class in the class ranges box
    highlightCalculatedClass(results.calculatedClass);

    // Show where the modified ratio sits within its class band
    updateBoundaryGauge(results);
}

/**
 * Highlight the calculated class in the class ranges box
 * @param {string} calculatedClass - The calculated class name (GTU, GT1, etc.)
 */
function highlightCalculatedClass(calculatedClass) {
    // Remove highlight from all class range items
    const allItems = document.querySelectorAll('.class-range-item');
    allItems.forEach(item => {
        item.classList.remove('active-class');
    });
    
    // Add highlight to the calculated class if it exists
    if (calculatedClass) {
        // Map class names to their display names (they should match)
        const classItem = Array.from(allItems).find(item => {
            const classNameEl = item.querySelector('.class-name');
            return classNameEl && classNameEl.textContent.trim() === calculatedClass;
        });
        
        if (classItem) {
            classItem.classList.add('active-class');
        }
    }
}

/**
 * Update modifier values displayed next to each select
 */
function updateModifierValues() {
    const scoringClass = currentScoringClass();
    if (!scoringClass) {
        // Clear all modifier displays if no base info
        ['chassis-modifier', 'body-mods-modifier', 'transmission-modifier', 
         'drivetrain-modifier', 'tires-modifier', 'brake-suspension-modifier'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = '+0.00';
                el.style.color = '#999';
            }
        });
        return;
    }

    const modifierFields = [
        { id: 'chassis', modifierId: 'chassis-modifier', table: chassisModifierTable },
        { id: 'transmission', modifierId: 'transmission-modifier', table: transModifierTable },
        { id: 'drivetrain', modifierId: 'drivetrain-modifier', table: dtModifierTable },
        { id: 'tires', modifierId: 'tires-modifier', table: tireModifierTable }
    ];

    modifierFields.forEach(field => {
        const selectEl = document.getElementById(field.id);
        const modifierEl = document.getElementById(field.modifierId);
        
        if (selectEl && modifierEl && field.table && scoringClass) {
            const optionId = selectEl.value;
            if (optionId) {
                const value = getModifierValue(field.table, optionId, scoringClass);
                if (value !== null) {
                    const sign = value >= 0 ? '+' : '';
                    modifierEl.textContent = `${sign}${formatNumber(value)}`;
                    modifierEl.style.color = value !== 0 ? 'var(--secondary-color)' : '#999';
                } else {
                    modifierEl.textContent = 'N/A';
                    modifierEl.style.color = '#999';
                }
            } else {
                modifierEl.textContent = '+0.00';
                modifierEl.style.color = '#999';
            }
        }
    });
    
    // Checkbox groups show the sum of everything ticked
    [
        { containerId: 'body-mods-options', modifierId: 'body-mods-modifier', table: bodyModifierTable },
        { containerId: 'brake-suspension-options', modifierId: 'brake-suspension-modifier', table: brakeModifierTable }
    ].forEach(group => {
        const modifierEl = document.getElementById(group.modifierId);
        if (!modifierEl) return;
        let totalValue = 0;
        document.querySelectorAll(`#${group.containerId} input[type="checkbox"]:checked`).forEach(checkbox => {
            const value = getModifierValue(group.table, checkbox.value, scoringClass);
            if (value !== null) {
                totalValue += value;
            }
        });

        if (totalValue !== 0) {
            const sign = totalValue >= 0 ? '+' : '';
            modifierEl.textContent = `${sign}${formatNumber(totalValue)}`;
            modifierEl.style.color = 'var(--secondary-color)';
        } else {
            modifierEl.textContent = '+0.00';
            modifierEl.style.color = '#999';
        }
    });
}

/**
 * Handle real-time calculation updates
 */
function handleCalculationUpdate() {
    updateFormData();
    updateModificationFieldsState();
    updateModifierValues();
    updateModifierExplainers();
    updateTireWidthNote();
    const results = updateCalculations(formData);
    updateResultsDisplay(results);
}

/**
 * Handle file input change
 * @param {Event} event - File input change event
 * @param {string} fieldName - Name of the file field
 */
function handleFileChange(event, fieldName) {
    const fileInput = event.target;
    const file = fileInput.files[0];
    const fileInfoId = `${fileInput.id}-info`;
    const fileInfoEl = document.getElementById(fileInfoId);

    // Clear previous errors
    clearFieldError(fileInput.id);

    if (file) {
        // Validate file type
        const typeValidation = validateFileType(file, fieldName);
        if (!typeValidation.isValid) {
            showFieldError(fileInput.id, typeValidation.error);
            fileInput.value = ''; // Clear invalid file
            if (fileInfoEl) fileInfoEl.textContent = '';
            return;
        }

        // Validate file size
        const sizeValidation = validateFileSize(file);
        if (!sizeValidation.isValid) {
            showFieldError(fileInput.id, sizeValidation.error);
            fileInput.value = ''; // Clear invalid file
            if (fileInfoEl) fileInfoEl.textContent = '';
            return;
        }

        // Show file info
        if (fileInfoEl) {
            const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
            fileInfoEl.textContent = `${file.name} (${fileSizeMB} MB)`;
        }
    } else {
        if (fileInfoEl) fileInfoEl.textContent = '';
    }
}

/**
 * Handle print button click - creates a printable document with all data
 */
function handlePrint() {
    // Update form data and calculations to ensure everything is current
    updateFormData();
    const results = updateCalculations(formData);
    
    const dateGenerated = new Date().toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Get all form values
    const formValues = {
        name: document.getElementById('name')?.value || '',
        email: document.getElementById('email')?.value || '',
        year: document.getElementById('year')?.value || '',
        make: document.getElementById('make')?.value || '',
        model: document.getElementById('model')?.value || '',
        comments: document.getElementById('comments')?.value || '',
        competitionWeight: document.getElementById('competition-weight')?.value || '',
        declaredHp: document.getElementById('declared-hp')?.value || '',
        dynoHp: document.getElementById('dyno-hp')?.value || '',
        classChoice: document.getElementById('class-choice')?.value || '',
        chassis: getSelectedOptionText('chassis'),
        bodyMods: getBodyModSelections(),
        transmission: getSelectedOptionText('transmission'),
        drivetrain: getSelectedOptionText('drivetrain'),
        tires: getSelectedOptionText('tires'),
        brakeSuspension: getBrakeSuspensionSelections()
    };
    
    // Build HTML content optimized for single page
    const printContent = `<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>WCMA Classing Calculator - Print</title>
    <style>
        @page {
            margin: 0.5in;
            size: letter;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.3;
            color: #000;
            background: #fff;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header h1 {
            margin: 0;
            font-size: 16pt;
        }
        .date-generated {
            font-size: 8pt;
            color: #666;
            margin-top: 3px;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 12px;
        }
        .section {
            margin-bottom: 10px;
        }
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            border-bottom: 1px solid #333;
            padding-bottom: 3px;
            margin-bottom: 6px;
        }
        .form-row {
            display: flex;
            margin-bottom: 4px;
            font-size: 9pt;
        }
        .form-label {
            font-weight: bold;
            width: 140px;
            flex-shrink: 0;
        }
        .form-value {
            flex: 1;
        }
        .results-section {
            grid-column: 1 / -1;
        }
        .results-box {
            border: 2px solid #000;
            padding: 10px;
            margin: 10px 0;
            background: #f9f9f9;
        }
        .results-title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 8px;
            text-align: center;
        }
        .result-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 9pt;
            border-bottom: 1px solid #ddd;
        }
        .result-item:last-child {
            border-bottom: none;
        }
        .result-label {
            font-weight: bold;
        }
        .result-value {
            font-weight: bold;
        }
        .result-item.highlight {
            background: #e8e8e8;
            padding: 5px;
            margin-top: 3px;
            font-size: 10pt;
        }
        .class-ranges {
            margin-top: 10px;
            border-top: 1px solid #333;
            padding-top: 8px;
        }
        .class-ranges-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            font-size: 8pt;
        }
        .class-range-item {
            display: flex;
            justify-content: space-between;
            padding: 2px 4px;
        }
        .class-range-item.active {
            font-weight: bold;
            background: #d4edda;
        }
        .empty {
            color: #999;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>WCMA Classing Calculator - 2026</h1>
        <div class="date-generated">Date Generated: ${dateGenerated}</div>
    </div>
    
    <div class="content-grid">
        <div class="section">
            <div class="section-title">Contact Information</div>
            <div class="form-row">
                <div class="form-label">Name:</div>
                <div class="form-value">${formValues.name || '<span class="empty">Not provided</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Email:</div>
                <div class="form-value">${formValues.email || '<span class="empty">Not provided</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Vehicle:</div>
                <div class="form-value">${formValues.year || ''} ${formValues.make || ''} ${formValues.model || ''}</div>
            </div>
            ${formValues.comments ? `<div class="form-row"><div class="form-label">Comments:</div><div class="form-value">${formValues.comments}</div></div>` : ''}
        </div>
        
        <div class="section">
            <div class="section-title">Vehicle Factors</div>
            <div class="form-row">
                <div class="form-label">Competition Weight:</div>
                <div class="form-value">${formValues.competitionWeight || '<span class="empty">Not provided</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Declared HP:</div>
                <div class="form-value">${formValues.declaredHp || '<span class="empty">Not provided</span>'}</div>
            </div>
            ${formValues.dynoHp ? `<div class="form-row"><div class="form-label">Dyno HP:</div><div class="form-value">${formValues.dynoHp}</div></div>` : ''}
            <div class="form-row">
                <div class="form-label">Scored In:</div>
                <div class="form-value">${results.scoringClass || '--'}${formValues.classChoice ? '' : ' (auto)'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Chassis:</div>
                <div class="form-value">${formValues.chassis || '<span class="empty">Not selected</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Body Mods:</div>
                <div class="form-value">${formValues.bodyMods || '<span class="empty">Not selected</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Transmission:</div>
                <div class="form-value">${formValues.transmission || '<span class="empty">Not selected</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Drivetrain:</div>
                <div class="form-value">${formValues.drivetrain || '<span class="empty">Not selected</span>'}</div>
            </div>
            <div class="form-row">
                <div class="form-label">Tires:</div>
                <div class="form-value">${formValues.tires || '<span class="empty">Not selected</span>'}</div>
            </div>
            ${formValues.brakeSuspension ? `<div class="form-row"><div class="form-label">Brake & Susp:</div><div class="form-value">${formValues.brakeSuspension}</div></div>` : ''}
        </div>
        
        <div class="section results-section">
            <div class="results-box">
                <div class="results-title">Calculation Results</div>
                <div class="result-item">
                    <span class="result-label">Weight Factor:</span>
                    <span class="result-value">${results.weightFactor !== undefined && results.weightFactor !== null ? (results.weightFactor >= 0 ? '+' : '') + formatNumber(results.weightFactor) : '--'}</span>
                </div>
                <div class="result-item">
                    <span class="result-label">Base Ratio:</span>
                    <span class="result-value">${results.baseRatio > 0 ? formatNumber(results.baseRatio) : '--'}</span>
                </div>
                <div class="result-item">
                    <span class="result-label">Additional Mod Factors:</span>
                    <span class="result-value">${formatNumber(results.modificationFactor)}</span>
                </div>
                <div class="result-item">
                    <span class="result-label">Modified Ratio:</span>
                    <span class="result-value">${results.modifiedRatio > 0 ? formatNumber(results.modifiedRatio) : formatNumber(results.baseRatio)}</span>
                </div>
                <div class="result-item highlight">
                    <span class="result-label">Calculated Class:</span>
                    <span class="result-value">${results.movedUp ? `${results.competingClass} (up from ${results.calculatedClass})` : (results.calculatedClass || '--')}</span>
                </div>
            </div>
            
            <div class="class-ranges">
                <div class="section-title">Class Ranges</div>
                <div class="class-ranges-grid">
                    <div class="class-range-item ${results.calculatedClass === 'GTU' ? 'active' : ''}">
                        <span>GTU</span>
                        <span>&lt; 6.00</span>
                    </div>
                    <div class="class-range-item ${results.calculatedClass === 'GT1' ? 'active' : ''}">
                        <span>GT1</span>
                        <span>6.00-7.99</span>
                    </div>
                    <div class="class-range-item ${results.calculatedClass === 'GT2' ? 'active' : ''}">
                        <span>GT2</span>
                        <span>8.00-9.99</span>
                    </div>
                    <div class="class-range-item ${results.calculatedClass === 'GT3' ? 'active' : ''}">
                        <span>GT3</span>
                        <span>10.00-11.99</span>
                    </div>
                    <div class="class-range-item ${results.calculatedClass === 'GT4' ? 'active' : ''}">
                        <span>GT4</span>
                        <span>12.00-13.99</span>
                    </div>
                    <div class="class-range-item ${results.calculatedClass === 'IT1' ? 'active' : ''}">
                        <span>IT1</span>
                        <span>14.00-17.99</span>
                    </div>
                    <div class="class-range-item ${results.calculatedClass === 'IT2' ? 'active' : ''}">
                        <span>IT2</span>
                        <span>&gt;= 18.00</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>`;
    
    // Try to open new window
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    
    if (!printWindow || printWindow.closed || typeof printWindow.closed === 'undefined') {
        // Popup blocked - fallback: create hidden iframe and print from there
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = 'none';
        document.body.appendChild(iframe);
        
        iframe.onload = () => {
            setTimeout(() => {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                // Remove iframe after printing
                setTimeout(() => {
                    document.body.removeChild(iframe);
                }, 1000);
            }, 500);
        };
        
        // Write content directly to iframe
        iframe.contentDocument.open();
        iframe.contentDocument.write(printContent);
        iframe.contentDocument.close();
    } else {
        // Window opened successfully - write content directly
        printWindow.document.open();
        printWindow.document.write(printContent);
        printWindow.document.close();
        
        // Flag to ensure print is only called once
        let printCalled = false;
        const triggerPrint = () => {
            if (!printCalled) {
                printCalled = true;
                printWindow.focus();
                printWindow.print();
            }
        };
        
        // Wait for content to load, then trigger print
        printWindow.addEventListener('load', () => {
            setTimeout(triggerPrint, 500);
        }, { once: true });
        
        // Fallback if load event doesn't fire
        setTimeout(() => {
            if (printWindow.document.readyState === 'complete') {
                triggerPrint();
            }
        }, 1000);
    }
}

/**
 * Get the display text for a selected option
 */
function getSelectedOptionText(selectId) {
    const select = document.getElementById(selectId);
    if (!select || !select.value) return '';
    const selectedOption = select.options[select.selectedIndex];
    return selectedOption ? selectedOption.textContent.replace(/\s*\([^)]*\)\s*$/, '') : '';
}

/**
 * Get brake/suspension selections as formatted text
 */
function getBrakeSuspensionSelections() {
    return getCheckedLabelsText('brake-suspension-options');
}

/**
 * Get body mod selections as formatted text
 */
function getBodyModSelections() {
    return getCheckedLabelsText('body-mods-options');
}

/**
 * Labels of the ticked checkboxes in a container, without their "(+0.00)" values
 */
function getCheckedLabelsText(containerId) {
    const checkboxes = document.querySelectorAll(`#${containerId} input[type="checkbox"]:checked`);
    if (checkboxes.length === 0) return '';
    const selections = Array.from(checkboxes).map(cb => {
        const label = document.querySelector(`label[for="${cb.id}"]`);
        if (label) {
            return label.textContent.replace(/\s*\([^)]*\)\s*$/, '');
        }
        return '';
    }).filter(text => text);
    return selections.join('; ');
}

/**
 * Get all form data for saving (excluding file inputs)
 */
function getAllFormDataForSave() {
    return {
        name: document.getElementById('name')?.value || '',
        email: document.getElementById('email')?.value || '',
        year: document.getElementById('year')?.value || '',
        make: document.getElementById('make')?.value || '',
        model: document.getElementById('model')?.value || '',
        comments: document.getElementById('comments')?.value || '',
        competitionWeight: document.getElementById('competition-weight')?.value || '',
        declaredHp: document.getElementById('declared-hp')?.value || '',
        dynoHp: document.getElementById('dyno-hp')?.value || '',
        classChoice: document.getElementById('class-choice')?.value || '',
        chassis: document.getElementById('chassis')?.value || '',
        bodyMods: getBodyModValues(),
        transmission: document.getElementById('transmission')?.value || '',
        drivetrain: document.getElementById('drivetrain')?.value || '',
        tires: document.getElementById('tires')?.value || '',
        brakeSuspension: getBrakeSuspensionValues(),
        savedAt: new Date().toISOString()
    };
}

/**
 * Get a CSRF token for account.php POST actions (draft-save, draft-delete).
 * account.php's list page always emits a `<meta name="csrf-token">` tag in
 * its <head>, regardless of whether the user has any drafts/submissions yet
 * (an earlier version of this scraped the token out of a per-row delete
 * form instead, which silently broke for a first-time user with zero rows —
 * the meta tag is unconditional, so it works even on an empty My Cars page).
 * Also doubles as the "is the user logged in" check: a logged-out request
 * to account.php redirects to auth.php?action=login, whose rendered page has
 * no such meta tag, so a missing match means "not logged in."
 */
async function getAccountCsrfToken() {
    const res = await fetch('account.php', { credentials: 'same-origin' });
    const html = await res.text();
    const match = html.match(/name="csrf-token" content="([^"]+)"/);
    return match ? match[1] : null;
}

/**
 * Save the current form as a draft to the logged-in user's My Cars.
 * Redirects to login if the user isn't authenticated.
 */
async function saveConfiguration() {
    const saveButton = document.getElementById('save-config-button');
    const originalLabel = saveButton ? saveButton.textContent : '';
    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = 'Saving…';
    }

    try {
        const configData = getAllFormDataForSave();
        const label = `${configData.year} ${configData.make} ${configData.model}`.trim() || 'Untitled Draft';

        const token = await getAccountCsrfToken();
        if (!token) {
            window.location.href = 'auth.php?action=login&redirect=' + encodeURIComponent('calculator.php');
            return;
        }

        const body = new URLSearchParams();
        body.set('csrf_token', token);
        body.set('label', label);
        body.set('form_data', JSON.stringify(configData));

        try {
            const res = await fetch('account.php?action=draft-save', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const result = await res.json();
            if (result.success) {
                showMessage('Saved to My Cars!', 'success');
                markFormClean();
                hideSaveNudge();
            } else {
                showMessage(result.error || 'Failed to save.', 'error');
            }
        } catch (error) {
            console.error('Error saving draft:', error);
            showMessage('An error occurred while saving.', 'error');
        }
    } finally {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = originalLabel;
        }
    }
}

/**
 * Fetch the current user's drafts from the server.
 */
async function getSavedConfigurations() {
    try {
        const res = await fetch('account.php?action=draft-list', { credentials: 'same-origin' });
        const result = await res.json();
        return result.drafts || [];
    } catch (e) {
        console.error('Error loading drafts:', e);
        return [];
    }
}

/**
 * Load a saved draft into the form.
 */
async function loadConfiguration(draftId) {
    let data;
    try {
        const res = await fetch(`account.php?action=draft-load&id=${encodeURIComponent(draftId)}`, { credentials: 'same-origin' });
        const result = await res.json();
        if (!result.success) {
            showMessage('Draft not found', 'error');
            return;
        }
        data = result.form_data;
    } catch (e) {
        console.error('Error loading draft:', e);
        showMessage('Failed to load draft', 'error');
        return;
    }

    applyFormData(data, 'Configuration loaded successfully!');
}

/**
 * Populate all form fields from a saved/loaded/declared form-data object, recalculate, and
 * show a message. Shared by "Load Saved", pre-fill from a car's declaration, and restoring a
 * signed-out visitor's stashed entries after sign-in.
 */
/**
 * Sets a form field's value unless it lives in a hidden `.form-group` — those fields are
 * account/car-bound (e.g. name, email) and restored/prefilled data must not overwrite them.
 */
function setRestorableFieldValue(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    const group = el.closest('.form-group');
    if (group && group.hidden) return;
    el.value = value;
}

function applyFormData(data, message) {
    // Populate all form fields (basic fields first)
    setRestorableFieldValue('name', data.name || '');
    setRestorableFieldValue('email', data.email || '');
    setRestorableFieldValue('year', data.year || '');
    setRestorableFieldValue('make', data.make || '');
    setRestorableFieldValue('model', data.model || '');
    setRestorableFieldValue('comments', data.comments || '');
    setRestorableFieldValue('competition-weight', data.competitionWeight || '');
    setRestorableFieldValue('declared-hp', data.declaredHp || '');
    setRestorableFieldValue('dyno-hp', data.dynoHp || '');
    setRestorableFieldValue('class-choice', data.classChoice || '');

    // Update form data first to populate modifier options
    updateFormData();
    updateModificationFieldsState();

    // Wait a moment for modifier options to populate, then set values
    setTimeout(() => {
        setRestorableFieldValue('chassis', data.chassis || '');
        setRestorableFieldValue('transmission', data.transmission || '');
        setRestorableFieldValue('drivetrain', data.drivetrain || '');
        setRestorableFieldValue('tires', data.tires || '');

        // Checkbox groups - clear all first, then tick the saved ones.
        // Older drafts saved body mods as a single option id.
        [
            { containerId: 'brake-suspension-options', idPrefix: 'brake', saved: data.brakeSuspension },
            { containerId: 'body-mods-options', idPrefix: 'body', saved: data.bodyMods }
        ].forEach(group => {
            const container = document.getElementById(group.containerId);
            if (!container) return;
            container.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            const saved = Array.isArray(group.saved) ? group.saved : (group.saved ? [group.saved] : []);
            saved.forEach(optionId => {
                const checkbox = document.getElementById(`${group.idPrefix}-${optionId}`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
        });

        updateFormData();
        handleCalculationUpdate();
        closeLoadModal();
        showMessage(message, 'success');
        markFormClean();
        hideSaveNudge();
    }, 100);
}

/**
 * Delete a saved draft.
 */
async function deleteConfiguration(draftId) {
    const token = await getAccountCsrfToken();
    if (!token) return;

    const body = new URLSearchParams();
    body.set('csrf_token', token);
    body.set('id', draftId);

    try {
        await fetch('account.php?action=draft-delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
    } catch (e) {
        console.error('Error deleting draft:', e);
    }
    showLoadModal(); // Refresh the modal
    showMessage('Configuration deleted', 'success');
}

/**
 * Show load configuration modal
 */
async function showLoadModal() {
    const token = await getAccountCsrfToken();
    if (!token) {
        window.location.href = 'auth.php?action=login&redirect=' + encodeURIComponent('calculator.php');
        return;
    }

    let modal = document.getElementById('load-config-modal');

    if (!modal) {
        // Create modal if it doesn't exist
        modal = document.createElement('div');
        modal.id = 'load-config-modal';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Saved Configurations</h2>
                    <button class="modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body" id="saved-configs-list">
                    <!-- Populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="close-load-modal">Close</button>
                </div>
            </div>
        `;
        // Append to body to ensure it's on top
        document.body.appendChild(modal);
        
        // Close handlers
        modal.querySelector('.modal-close').addEventListener('click', closeLoadModal);
        modal.querySelector('#close-load-modal').addEventListener('click', closeLoadModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeLoadModal();
        });
    }
    
    // Populate with saved configurations
    const configs = await getSavedConfigurations();
    const listContainer = modal.querySelector('#saved-configs-list');
    
    if (configs.length === 0) {
        listContainer.innerHTML = '<p style="text-align: center; color: #666; padding: 2rem;">No saved configurations found.</p>';
    } else {
        listContainer.innerHTML = configs.map(config => {
            const date = new Date(config.updated_at);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            return `
                <div class="saved-config-item">
                    <div class="saved-config-info">
                        <div class="saved-config-name">${escapeHtml(config.label || 'Untitled Draft')}</div>
                        <div class="saved-config-date">Saved: ${dateStr}</div>
                    </div>
                    <div class="saved-config-actions">
                        <button type="button" class="btn btn-small btn-primary" data-load-id="${config.id}">Load</button>
                        <button type="button" class="btn btn-small btn-danger" data-delete-id="${config.id}">Delete</button>
                    </div>
                </div>
            `;
        }).join('');
        
        // Add event listeners for load/delete buttons
        listContainer.querySelectorAll('[data-load-id]').forEach(btn => {
            btn.addEventListener('click', () => loadConfiguration(btn.getAttribute('data-load-id')));
        });
        
        listContainer.querySelectorAll('[data-delete-id]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Are you sure you want to delete this configuration?')) {
                    deleteConfiguration(btn.getAttribute('data-delete-id'));
                }
            });
        });
    }
    
    // Show modal with proper display - use flexbox for centering
    document.body.style.overflow = 'hidden';
    modal.style.display = 'flex';
    modal.style.flexDirection = 'column';
    modal.style.justifyContent = 'center';
    modal.style.alignItems = 'center';
}

/**
 * Close load configuration modal
 */
function closeLoadModal() {
    const modal = document.getElementById('load-config-modal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Show message to user
 */
function showMessage(message, type = 'info') {
    try {
        const messageEl = document.getElementById('form-messages');
        if (messageEl) {
            messageEl.textContent = message;
            messageEl.className = `form-messages ${type}`;
            messageEl.style.display = 'block';
            messageEl.setAttribute('role', 'alert');
            
            // Scroll to message if needed
            messageEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            
            setTimeout(() => {
                messageEl.style.display = 'none';
            }, 3000);
        } else {
            // Fallback to console and alert if element not found
            console.warn('form-messages element not found, displaying alert instead:', message);
            alert(message);
        }
    } catch (error) {
        console.error('Error showing message:', error);
        alert(message); // Fallback to alert
    }
}

/**
 * Initialize event listeners
 */
function initializeEventListeners() {
    const form = document.getElementById('classing-form');
    if (!form) return;

    // Track unsaved changes and (for guests) nudge them to sign in once
    // there's something worth protecting.
    form.addEventListener('input', () => {
        markFormDirty();
        scheduleNudgeCheck();
    });

    // Form submission handler
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        
        // Update form data and calculate results
        updateFormData();
        const results = updateCalculations(formData);
        
        // Add all calculation results as hidden fields for email
        const fieldsToAdd = {
            'calculated_class': results.competingClass || '--',
            'base_ratio': results.baseRatio > 0 ? results.baseRatio.toFixed(2) : '--',
            'modified_ratio': results.modifiedRatio > 0 ? results.modifiedRatio.toFixed(2) : '--',
            'modification_factor': results.modificationFactor.toFixed(2),
            'weight_factor': results.weightFactor.toFixed(2)
        };
        
        // Remove any existing hidden calculation fields
        Object.keys(fieldsToAdd).forEach(name => {
            const existing = form.querySelector(`input[name="${name}"]`);
            if (existing) existing.remove();
        });
        
        // Add calculation results as hidden fields
        Object.keys(fieldsToAdd).forEach(name => {
            const hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = name;
            hiddenField.value = fieldsToAdd[name];
            form.appendChild(hiddenField);
        });

        // Individual modifier values for DB persistence
        const modifierValueFields = {
            'chassis_value':          results.chassisValue.toFixed(2),
            'body_mods_value':        results.bodyModsValue.toFixed(2),
            'transmission_value':     results.transmissionValue.toFixed(2),
            'drivetrain_value':       results.drivetrainValue.toFixed(2),
            'tires_value':            results.tiresValue.toFixed(2),
            'brake_suspension_value': results.brakeSuspensionValue.toFixed(2),
        };

        Object.keys(modifierValueFields).forEach(name => {
            const existing = form.querySelector(`input[name="${name}"]`);
            if (existing) existing.remove();
        });

        Object.keys(modifierValueFields).forEach(name => {
            const hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = name;
            hiddenField.value = modifierValueFields[name];
            form.appendChild(hiddenField);
        });

        // Add display text for modifier selections (not just option IDs)
        const modifierDisplayFields = {
            'chassis_display': getSelectedOptionText('chassis'),
            'body_mods_display': getBodyModSelections(),
            'transmission_display': getSelectedOptionText('transmission'),
            'drivetrain_display': getSelectedOptionText('drivetrain'),
            'tires_display': getSelectedOptionText('tires')
        };
        
        Object.keys(modifierDisplayFields).forEach(name => {
            const hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = name;
            hiddenField.value = modifierDisplayFields[name];
            form.appendChild(hiddenField);
        });
        
        // Also add as JSON for convenience
        const hiddenResults = document.createElement('input');
        hiddenResults.type = 'hidden';
        hiddenResults.name = 'calculated_results';
        hiddenResults.value = JSON.stringify(results);
        form.appendChild(hiddenResults);

        // The full form state, so a signed-out visitor's entries can be stashed and restored
        // after sign-in, and so a signed-in re-declaration can pre-fill next time.
        const allFormData = getAllFormDataForSave();
        const existingFormData = form.querySelector('input[name="form_data"]');
        if (existingFormData) existingFormData.remove();
        const hiddenFormData = document.createElement('input');
        hiddenFormData.type = 'hidden';
        hiddenFormData.name = 'form_data';
        hiddenFormData.value = JSON.stringify(allFormData);
        form.appendChild(hiddenFormData);

        try {
            console.log('Calling handleFormSubmit...');
            await handleFormSubmit(form, null, allFormData);
            console.log('Form submission completed successfully');
            markFormClean();
            hideSaveNudge();
        } catch (error) {
            console.error('=== UI Controller: Submission error ===');
            console.error('Error:', error);
            // Error messages are already handled in handleFormSubmit,
            // but we'll log here for debugging
            if (!document.getElementById('form-messages')?.textContent.trim()) {
                showMessage('An unexpected error occurred. Please check the console for details.', 'error');
            }
        }
    });

    // Real-time calculation triggers
    const calculationFields = [
        'competition-weight',
        'declared-hp',
        'dyno-hp',
        'class-choice',
        'chassis',
        'transmission',
        'drivetrain',
        'tires'
    ];

    calculationFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', handleCalculationUpdate);
            field.addEventListener('change', handleCalculationUpdate);
        }
    });
    
    // Checkbox groups (event delegation for dynamically created checkboxes).
    // Clearing body mods for a restricted chassis dispatches a change on the
    // container itself, so that counts too.
    ['brake-suspension-options', 'body-mods-options'].forEach(containerId => {
        const container = document.getElementById(containerId);
        if (container) {
            container.addEventListener('change', (event) => {
                if (event.target.type === 'checkbox' || event.target === container) {
                    handleCalculationUpdate();
                }
            });
        }
    });


    // File upload handlers
    const fileFields = [
        { id: 'dyno-chart', name: 'dyno_chart' },
        { id: 'dyno-table', name: 'dyno_table' },
        { id: 'car-image', name: 'car_image' }
    ];

    fileFields.forEach(field => {
        const fileInput = document.getElementById(field.id);
        if (fileInput) {
            fileInput.addEventListener('change', (event) => {
                handleFileChange(event, field.name);
            });
        }
    });

    // Print button handler
    const printButton = document.getElementById('print-button');
    if (printButton) {
        printButton.addEventListener('click', (e) => {
            e.preventDefault();
            handlePrint();
        });
    }

    // Save configuration button handler
    const saveButton = document.getElementById('save-config-button');
    if (saveButton) {
        saveButton.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            console.log('Save configuration button clicked');
            try {
                saveConfiguration();
            } catch (error) {
                console.error('Error in save button handler:', error);
                alert('An error occurred: ' + error.message);
            }
        });
    } else {
        console.error('Save configuration button not found!');
    }

    // Load configuration button handler
    const loadButton = document.getElementById('load-config-button');
    if (loadButton) {
        loadButton.addEventListener('click', (e) => {
            e.preventDefault();
            showLoadModal();
        });
    }

    // Real-time validation for required fields
    const requiredFields = ['name', 'email', 'year', 'make', 'model', 'competition-weight', 'declared-hp'];
    requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('blur', () => {
                const value = field.value.trim();
                if (!value && field.required) {
                    showFieldError(fieldId, `${field.labels[0]?.textContent.replace(' *', '')} is required`);
                } else {
                    clearFieldError(fieldId);
                }
            });

            field.addEventListener('input', () => {
                if (field.value.trim()) {
                    clearFieldError(fieldId);
                }
            });
        }
    });

    // Email validation
    const emailField = document.getElementById('email');
    if (emailField) {
        emailField.addEventListener('blur', () => {
            const email = emailField.value.trim();
            if (email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    showFieldError('email', 'Please enter a valid email address');
                } else {
                    clearFieldError('email');
                }
            }
        });
    }

    // Year validation
    const yearField = document.getElementById('year');
    if (yearField) {
        yearField.addEventListener('blur', () => {
            const year = yearField.value.trim();
            if (year) {
                const yearRegex = /^\d{4}$/;
                if (!yearRegex.test(year)) {
                    showFieldError('year', 'Year must be 4 digits');
                } else {
                    clearFieldError('year');
                }
            }
        });
    }

    // Prevent decimals in weight and HP fields
    const integerFields = ['competition-weight', 'declared-hp', 'dyno-hp'];
    integerFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            // Prevent decimal point and comma keypress
            field.addEventListener('keydown', (e) => {
                if (e.key === '.' || e.key === ',' || e.key === 'Decimal') {
                    e.preventDefault();
                }
            });

            // Prevent pasting content with decimals
            field.addEventListener('paste', (e) => {
                const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                if (pastedData.includes('.') || pastedData.includes(',')) {
                    e.preventDefault();
                }
            });

            // Clean up if somehow a decimal gets in (e.g. browser autofill)
            field.addEventListener('input', () => {
                if (field.value.includes('.') || field.value.includes(',')) {
                    field.value = field.value.replace(/[.,]/g, '');
                    handleCalculationUpdate(); // Recalculate if value changed
                }
            });
        }
    });
}

/**
 * Initialize the UI controller
 */
function initialize() {
    // Ensure DOM is fully ready
    const initFunction = () => {
        const form = document.getElementById('classing-form');
        if (!form) {
            setTimeout(initFunction, 100);
            return;
        }

        initializeEventListeners();
        updateFormData();
        updateModificationFieldsState();
        updateModifierExplainers();
        updateResultsDisplay();

        // If arriving from a "My Cars" draft Edit link, load that draft
        const params = new URLSearchParams(window.location.search);
        const draftIdParam = params.get('draft');
        if (draftIdParam) {
            getAccountCsrfToken().then(token => {
                if (!token) {
                    const back = encodeURIComponent('calculator.php?draft=' + draftIdParam);
                    window.location.href = 'auth.php?action=login&redirect=' + back;
                    return;
                }
                loadConfiguration(draftIdParam);
            });
        }

        // Re-declaring for a known car: pre-fill from that car's current declaration.
        const carIdParam = params.get('car');
        if (carIdParam) {
            fetch(`cars.php?action=declaration&car_id=${encodeURIComponent(carIdParam)}`, { credentials: 'same-origin' })
                .then(res => res.json())
                .then(result => {
                    if (result && result.form_data) {
                        applyFormData(result.form_data, 'Loaded your current class declaration for this car. Change anything that is different and submit.');
                    }
                })
                .catch(e => console.error('Error loading car declaration:', e));
        }

        // Coming back from sign-in after a guest's submission was stashed: restore their entries.
        if (params.get('restore') === '1' && window.WcmaDeclarationState) {
            const data = window.WcmaDeclarationState.takeStashedForm(sessionStorage);
            if (data) {
                applyFormData(data, 'Your entries are back. Check them and submit.');
            }
        }
    };
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFunction);
    } else {
        // Small delay to ensure all elements are in DOM
        setTimeout(initFunction, 0);
    }
}

// Initialize on load
initialize();


