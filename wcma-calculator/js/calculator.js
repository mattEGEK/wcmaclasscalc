/**
 * Calculator Module
 * Handles all calculation logic for weight/horsepower ratios and class determination
 */

import {
    chassisModifierTable,
    bodyModifierTable,
    transModifierTable,
    dtModifierTable,
    tireModifierTable,
    brakeModifierTable,
    getModifierValue,
    getClassIndex
} from './modifiers.js';

/**
 * Calculate weight factor based on competition weight and target class
 * Based on WCMA calculator: https://www.wcma.ca/classing/car-classing.php
 * @param {number} weight - Competition weight in lbs
 * @param {string} targetClass - Target class (GTU, GT1, GT2, GT3, GT4, IT1, IT2)
 * @returns {number} Weight factor modifier
 */
export function calculateWeightFactor(weight, targetClass) {
    if (!weight || weight <= 0 || !targetClass) {
        return 0;
    }

    let weightModFactor = 0;

    switch (targetClass) {
        case "GTU":
        case "GT1":
        case "GT2":
        case "GT3":
        case "GT4":
            if (weight < 2200) {
                weightModFactor = -0.3;
            } else if (weight < 2600) {
                weightModFactor = -0.2;
            } else if (weight < 3000) {
                weightModFactor = -0.1;
            } else if (weight > 4050) {
                weightModFactor = +0.7;
            } else if (weight > 3900) {
                weightModFactor = +0.6;
            } else if (weight > 3750) {
                weightModFactor = +0.5;
            } else if (weight > 3600) {
                weightModFactor = +0.4;
            } else if (weight > 3500) {
                weightModFactor = +0.3;
            } else if (weight > 3400) {
                weightModFactor = +0.2;
            } else if (weight > 3300) {
                weightModFactor = +0.1;
            }
            break;
        case "IT1":
        case "IT2":
            if (weight < 2150) {
                weightModFactor = -0.6;
            } else if (weight < 2250) {
                weightModFactor = -0.5;
            } else if (weight < 2450) {
                weightModFactor = -0.4;
            } else if (weight < 2550) {
                weightModFactor = -0.3;
            } else if (weight < 2650) {
                weightModFactor = -0.2;
            } else if (weight < 2850) {
                weightModFactor = -0.1;
            } else if (weight > 3600) {
                weightModFactor = +0.4;
            } else if (weight > 3500) {
                weightModFactor = +0.3;
            } else if (weight > 3400) {
                weightModFactor = +0.2;
            } else if (weight > 3300) {
                weightModFactor = +0.1;
            }
            break;
        default:
            weightModFactor = 0;
    }

    return weightModFactor;
}

/**
 * Calculate base weight/horsepower ratio
 * @param {number} weight - Competition weight in lbs
 * @param {number} hp - Declared maximum average horsepower
 * @returns {number} Base ratio (weight/hp)
 */
export function calculateBaseRatio(weight, hp) {
    if (!weight || weight <= 0 || !hp || hp <= 0) {
        return 0;
    }
    return Math.round((weight / hp) * 100) / 100;
}

/**
 * Calculate total modification factor from all modification selections
 * @param {Object} modifiers - Object containing all modification factor values
 * @returns {number} Total modification factor
 */
export function calculateModificationFactor(modifiers) {
    if (!modifiers) {
        return 0;
    }

    let totalModFactor = 0;

    // Sum all modification factors
    Object.values(modifiers).forEach(value => {
        const numValue = parseFloat(value) || 0;
        totalModFactor += numValue;
    });

    return totalModFactor;
}

/**
 * Calculate modified weight/horsepower ratio
 * @param {number} baseRatio - Base weight/hp ratio
 * @param {number} modFactor - Total modification factor
 * @returns {number} Modified ratio
 */
export function calculateModifiedRatio(baseRatio, modFactor) {
    if (!baseRatio || baseRatio <= 0) {
        return 0;
    }
    // Apply modification factor to base ratio
    return baseRatio + modFactor;
}

/**
 * Determine class based on adjusted weight/horsepower ratio
 * @param {number} ratio - Adjusted weight/hp ratio
 * @returns {string} Class name (GTU, GT1, GT2, GT3, GT4, IT1, IT2)
 */
export function determineClass(ratio) {
    if (!ratio || ratio <= 0) {
        return '';
    }

    if (ratio < 6.00) {
        return 'GTU';
    } else if (ratio >= 6.00 && ratio < 8.00) {
        return 'GT1';
    } else if (ratio >= 8.00 && ratio < 10.00) {
        return 'GT2';
    } else if (ratio >= 10.00 && ratio < 12.00) {
        return 'GT3';
    } else if (ratio >= 12.00 && ratio < 14.00) {
        return 'GT4';
    } else if (ratio >= 14.00 && ratio < 18.00) {
        return 'IT1';
    } else {
        return 'IT2';
    }
}

/**
 * Format number to specified decimal places
 * @param {number} value - Number to format
 * @param {number} decimals - Number of decimal places (default: 2)
 * @returns {string} Formatted number string
 */
export function formatNumber(value, decimals = 2) {
    if (value === null || value === undefined || isNaN(value)) {
        return '--';
    }
    return parseFloat(value).toFixed(decimals);
}

/**
 * Main calculation orchestrator
 * Performs all calculations and returns results object
 * @param {Object} formData - Object containing all form input values
 * @returns {Object} Calculation results
 */
export function updateCalculations(formData) {
    const {
        competitionWeight,
        declaredHp,
        targetClass,
        chassis,
        bodyMods,
        transmission,
        drivetrain,
        tires,
        brakeSuspension
    } = formData;

    const results = {
        weightFactor: 0,
        baseRatio: 0,
        modificationFactor: 0,
        modifiedRatio: 0,
        calculatedClass: ''
    };

    // Check if we have minimum required data
    const weightNum = parseFloat(competitionWeight);
    const hpNum = parseFloat(declaredHp);
    const hasBaseData = !isNaN(weightNum) && !isNaN(hpNum) && weightNum > 0 && hpNum > 0;

    if (!hasBaseData) {
        return results;
    }

    // Calculate base ratio first
    results.baseRatio = calculateBaseRatio(weightNum, hpNum);
    
    // Determine class from base ratio to determine which modifier values apply
    let classForModifiers = determineClass(results.baseRatio);
    
    // Collect modification factors using modifier tables
    // Modifiers are based on the base class (before modifiers are applied)
    let modifierSum = 0;
    
    // Look up chassis modifier
    if (chassis && classForModifiers) {
        const chassisValue = getModifierValue(chassisModifierTable, chassis, classForModifiers);
        if (chassisValue !== null && !isNaN(chassisValue)) {
            modifierSum += chassisValue;
        }
    }
    
    // Look up body modifier
    if (bodyMods && classForModifiers) {
        const bodyValue = getModifierValue(bodyModifierTable, bodyMods, classForModifiers);
        if (bodyValue !== null && !isNaN(bodyValue)) {
            modifierSum += bodyValue;
        }
    }
    
    // Look up transmission modifier
    if (transmission && classForModifiers) {
        const transValue = getModifierValue(transModifierTable, transmission, classForModifiers);
        if (transValue !== null && !isNaN(transValue)) {
            modifierSum += transValue;
        }
    }
    
    // Look up drivetrain modifier
    if (drivetrain && classForModifiers) {
        const dtValue = getModifierValue(dtModifierTable, drivetrain, classForModifiers);
        if (dtValue !== null && !isNaN(dtValue)) {
            modifierSum += dtValue;
        }
    }
    
    // Look up tire modifier
    if (tires && classForModifiers) {
        const tireValue = getModifierValue(tireModifierTable, tires, classForModifiers);
        if (tireValue !== null && !isNaN(tireValue)) {
            modifierSum += tireValue;
        }
    }
    
    // Look up brake/suspension modifiers (can be multiple selections)
    if (brakeSuspension && Array.isArray(brakeSuspension) && brakeSuspension.length > 0 && classForModifiers) {
        brakeSuspension.forEach(optionId => {
            const brakeValue = getModifierValue(brakeModifierTable, optionId, classForModifiers);
            if (brakeValue !== null && !isNaN(brakeValue)) {
                modifierSum += brakeValue;
            }
        });
    } else if (brakeSuspension && typeof brakeSuspension === 'string' && brakeSuspension && classForModifiers) {
        // Handle single value for backward compatibility
        const brakeValue = getModifierValue(brakeModifierTable, brakeSuspension, classForModifiers);
        if (brakeValue !== null && !isNaN(brakeValue)) {
            modifierSum += brakeValue;
        }
    }
    
    results.modificationFactor = modifierSum;

    // Iteratively calculate weight factor based on the final calculated class
    // Start with class from base ratio + modifiers (without weight factor)
    let classForWeightFactor = determineClass(results.baseRatio + results.modificationFactor);
    let previousWeightFactor = null;
    let iterations = 0;
    const maxIterations = 10;
    
    // Iterate until weight factor stabilizes (class used matches resulting class)
    while (iterations < maxIterations) {
        // Calculate weight factor based on current class
        results.weightFactor = calculateWeightFactor(weightNum, classForWeightFactor);
        
        // Calculate final modified ratio with this weight factor
        results.modifiedRatio = results.baseRatio + results.weightFactor + results.modificationFactor;
        
        // Determine what class results from this modified ratio
        const resultingClass = determineClass(results.modifiedRatio);
        
        // If weight factor hasn't changed and class matches, we're stable
        if (previousWeightFactor !== null && 
            Math.abs(results.weightFactor - previousWeightFactor) < 0.001 &&
            resultingClass === classForWeightFactor) {
            break;
        }
        
        // If resulting class is different, use it for next iteration
        if (resultingClass !== classForWeightFactor) {
            classForWeightFactor = resultingClass;
            previousWeightFactor = results.weightFactor;
            iterations++;
        } else {
            // Class matches, we're done
            break;
        }
    }
    
    // Final class determination
    results.calculatedClass = determineClass(results.modifiedRatio);

    return results;
}

