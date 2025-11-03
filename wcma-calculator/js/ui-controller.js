/**
 * UI Controller Module
 * Handles DOM manipulation, event handling, and real-time updates
 */

import { updateCalculations, formatNumber } from './calculator.js';
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
    targetClass: '',
    chassis: '',
    bodyMods: '',
    transmission: '',
    drivetrain: '',
    tires: '',
    brakeSuspension: ''
};

/**
 * Get selected brake/suspension values (multiple checkboxes)
 * @returns {Array} Array of selected option IDs
 */
function getBrakeSuspensionValues() {
    const checkboxes = document.querySelectorAll('#brake-suspension-options input[type="checkbox"]:checked');
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
        targetClass: '', // Not used - class is calculated automatically
        chassis: document.getElementById('chassis')?.value || '',
        bodyMods: document.getElementById('body-mods')?.value || '',
        transmission: document.getElementById('transmission')?.value || '',
        drivetrain: document.getElementById('drivetrain')?.value || '',
        tires: document.getElementById('tires')?.value || '',
        brakeSuspension: getBrakeSuspensionValues()
    };
}

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
 * Populate modifier dropdown options based on calculated class
 */
function populateModifierOptions() {
    // Calculate base ratio to determine initial class
    const weightNum = parseFloat(formData.competitionWeight);
    const hpNum = parseFloat(formData.declaredHp);
    
    if (!weightNum || !hpNum || weightNum <= 0 || hpNum <= 0) {
        return;
    }
    
    const baseRatio = weightNum / hpNum;
    let calculatedClass = '';
    
    // Determine class from base ratio
    if (baseRatio < 6.00) {
        calculatedClass = 'GTU';
    } else if (baseRatio >= 6.00 && baseRatio < 8.00) {
        calculatedClass = 'GT1';
    } else if (baseRatio >= 8.00 && baseRatio < 10.00) {
        calculatedClass = 'GT2';
    } else if (baseRatio >= 10.00 && baseRatio < 12.00) {
        calculatedClass = 'GT3';
    } else if (baseRatio >= 12.00 && baseRatio < 14.00) {
        calculatedClass = 'GT4';
    } else if (baseRatio >= 14.00 && baseRatio < 18.00) {
        calculatedClass = 'IT1';
    } else {
        calculatedClass = 'IT2';
    }
    
    if (!calculatedClass) {
        return;
    }

    // Map of field IDs to modifier tables (excluding brake-suspension)
    const modifierMap = {
        'chassis': chassisModifierTable,
        'body-mods': bodyModifierTable,
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
            
            if (isOptionAvailable(table, optionId, calculatedClass)) {
                const option = document.createElement('option');
                option.value = optionId;
                
                // Get modifier value for this option and class
                const modifierValue = getModifierValue(table, optionId, calculatedClass);
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
    
    // Handle brake-suspension as checkboxes (only for IT1/IT2)
    populateBrakeSuspensionCheckboxes(calculatedClass);
}

/**
 * Populate brake/suspension checkboxes based on calculated class
 */
function populateBrakeSuspensionCheckboxes(calculatedClass) {
    const container = document.getElementById('brake-suspension-options');
    if (!container) return;
    
    // Only show for IT1 and IT2
    if (calculatedClass !== 'IT1' && calculatedClass !== 'IT2') {
        container.innerHTML = '<span class="field-note">Brake and suspension mods are only available for IT1 and IT2 classes</span>';
        return;
    }
    
    // Save currently checked values
    const checkedValues = Array.from(container.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    
    // Clear container
    container.innerHTML = '';
    
    // Populate with available options for this class
    brakeModifierTable.forEach(row => {
        const optionId = row[0];
        const description = row[1];
        
        if (isOptionAvailable(brakeModifierTable, optionId, calculatedClass)) {
            const modifierValue = getModifierValue(brakeModifierTable, optionId, calculatedClass);
            if (modifierValue !== null) {
                const checkboxWrapper = document.createElement('div');
                checkboxWrapper.className = 'checkbox-item';
                
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.id = `brake-${optionId}`;
                checkbox.name = 'brake_suspension[]';
                checkbox.value = optionId;
                checkbox.checked = checkedValues.includes(optionId);
                
                const label = document.createElement('label');
                label.htmlFor = `brake-${optionId}`;
                const sign = modifierValue >= 0 ? '+' : '';
                label.textContent = `${description} (${sign}${formatNumber(modifierValue)})`;
                
                checkboxWrapper.appendChild(checkbox);
                checkboxWrapper.appendChild(label);
                container.appendChild(checkboxWrapper);
            }
        }
    });
    
    if (container.children.length === 0) {
        container.innerHTML = '<span class="field-note">No brake/suspension mods available for this class</span>';
    }
}

/**
 * Enable/disable modification factor fields based on base info availability
 */
function updateModificationFieldsState() {
    const hasBase = hasBaseInfo();
    const modificationFields = [
        'chassis',
        'body-mods',
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
    
    // Handle brake-suspension checkboxes separately
    const brakeContainer = document.getElementById('brake-suspension-options');
    if (brakeContainer) {
        const checkboxes = brakeContainer.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.disabled = !hasBase;
        });
    }
    
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
            inlineCalculatedClass.textContent = results.calculatedClass;
        } else {
            inlineCalculatedClass.textContent = '--';
        }
    }
    
    // Highlight the calculated class in the class ranges box
    highlightCalculatedClass(results.calculatedClass);
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
    // Calculate class from current base ratio for modifier lookup
    const weightNum = parseFloat(formData.competitionWeight);
    const hpNum = parseFloat(formData.declaredHp);
    
    if (!weightNum || !hpNum || weightNum <= 0 || hpNum <= 0) {
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
    
    const baseRatio = weightNum / hpNum;
    let calculatedClass = '';
    
    // Determine class from base ratio
    if (baseRatio < 6.00) {
        calculatedClass = 'GTU';
    } else if (baseRatio >= 6.00 && baseRatio < 8.00) {
        calculatedClass = 'GT1';
    } else if (baseRatio >= 8.00 && baseRatio < 10.00) {
        calculatedClass = 'GT2';
    } else if (baseRatio >= 10.00 && baseRatio < 12.00) {
        calculatedClass = 'GT3';
    } else if (baseRatio >= 12.00 && baseRatio < 14.00) {
        calculatedClass = 'GT4';
    } else if (baseRatio >= 14.00 && baseRatio < 18.00) {
        calculatedClass = 'IT1';
    } else {
        calculatedClass = 'IT2';
    }

    const modifierFields = [
        { id: 'chassis', modifierId: 'chassis-modifier', table: chassisModifierTable },
        { id: 'body-mods', modifierId: 'body-mods-modifier', table: bodyModifierTable },
        { id: 'transmission', modifierId: 'transmission-modifier', table: transModifierTable },
        { id: 'drivetrain', modifierId: 'drivetrain-modifier', table: dtModifierTable },
        { id: 'tires', modifierId: 'tires-modifier', table: tireModifierTable }
    ];

    modifierFields.forEach(field => {
        const selectEl = document.getElementById(field.id);
        const modifierEl = document.getElementById(field.modifierId);
        
        if (selectEl && modifierEl && field.table && calculatedClass) {
            const optionId = selectEl.value;
            if (optionId) {
                const value = getModifierValue(field.table, optionId, calculatedClass);
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
    
    // Handle brake-suspension checkboxes separately (sum of all selected)
    const brakeModifierEl = document.getElementById('brake-suspension-modifier');
    if (brakeModifierEl && calculatedClass) {
        const checkedBoxes = document.querySelectorAll('#brake-suspension-options input[type="checkbox"]:checked');
        let totalValue = 0;
        
        checkedBoxes.forEach(checkbox => {
            const value = getModifierValue(brakeModifierTable, checkbox.value, calculatedClass);
            if (value !== null) {
                totalValue += value;
            }
        });
        
        if (totalValue !== 0) {
            const sign = totalValue >= 0 ? '+' : '';
            brakeModifierEl.textContent = `${sign}${formatNumber(totalValue)}`;
            brakeModifierEl.style.color = 'var(--secondary-color)';
        } else {
            brakeModifierEl.textContent = '+0.00';
            brakeModifierEl.style.color = '#999';
        }
    }
}

/**
 * Handle real-time calculation updates
 */
function handleCalculationUpdate() {
    updateFormData();
    updateModificationFieldsState();
    updateModifierValues();
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
        chassis: getSelectedOptionText('chassis'),
        bodyMods: getSelectedOptionText('body-mods'),
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
                    <span class="result-value">${results.calculatedClass || '--'}</span>
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
    const checkboxes = document.querySelectorAll('#brake-suspension-options input[type="checkbox"]:checked');
    if (checkboxes.length === 0) return '';
    const selections = Array.from(checkboxes).map(cb => {
        const label = document.querySelector(`label[for="${cb.id}"]`);
        if (label) {
            return label.textContent.replace(/\s*\([^)]*\)\s*$/, '');
        }
        return '';
    }).filter(text => text);
    return selections.join(', ');
}

/**
 * Initialize event listeners
 */
function initializeEventListeners() {
    const form = document.getElementById('classing-form');
    if (!form) return;

    // Form submission handler
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        
        // Update form data and calculate results
        updateFormData();
        const results = updateCalculations(formData);
        
        // Add all calculation results as hidden fields for email
        const fieldsToAdd = {
            'calculated_class': results.calculatedClass || '--',
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
        
        // Also add as JSON for convenience
        const hiddenResults = document.createElement('input');
        hiddenResults.type = 'hidden';
        hiddenResults.name = 'calculated_results';
        hiddenResults.value = JSON.stringify(results);
        form.appendChild(hiddenResults);

        try {
            await handleFormSubmit(form);
        } catch (error) {
            console.error('Submission error:', error);
        }
    });

    // Real-time calculation triggers
    const calculationFields = [
        'competition-weight',
        'declared-hp',
        'dyno-hp',
        'chassis',
        'body-mods',
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
    
    // Handle brake-suspension checkboxes
    const brakeContainer = document.getElementById('brake-suspension-options');
    if (brakeContainer) {
        // Use event delegation for dynamically created checkboxes
        brakeContainer.addEventListener('change', (event) => {
            if (event.target.type === 'checkbox') {
                handleCalculationUpdate();
            }
        });
    }


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
        updateResultsDisplay();
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


