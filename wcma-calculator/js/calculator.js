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
 * Class boundaries, in the same order/values used by determineClass().
 * Shared so the UI can render "how close to the next class" without
 * duplicating the boundary numbers a third time.
 */
export const CLASS_RANGES = [
    { name: 'GTU', min: -Infinity, max: 6.00 },
    { name: 'GT1', min: 6.00, max: 8.00 },
    { name: 'GT2', min: 8.00, max: 10.00 },
    { name: 'GT3', min: 10.00, max: 12.00 },
    { name: 'GT4', min: 12.00, max: 14.00 },
    { name: 'IT1', min: 14.00, max: 18.00 },
    { name: 'IT2', min: 18.00, max: Infinity }
];

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
 * Describe how far a ratio is from each edge of its class band.
 * Ratios are 2-decimal, and a lower band's max is exclusive, so dropping
 * into the class below takes 0.01 more than the gap to the band minimum.
 * @param {number} ratio - Current (modified) weight/hp ratio
 * @param {string} className - Class the ratio falls in
 * @returns {{min: number, max: number, up: ?{className: string, amount: number}, down: ?{className: string, amount: number}}|null}
 */
export function getBoundaryDistances(ratio, className) {
    const idx = CLASS_RANGES.findIndex(r => r.name === className);
    if (!(ratio > 0) || idx === -1) {
        return null;
    }
    const range = CLASS_RANGES[idx];
    const round2 = n => Math.round(n * 100) / 100;
    const up = isFinite(range.max)
        ? { className: CLASS_RANGES[idx + 1].name, amount: round2(range.max - ratio) }
        : null;
    const down = isFinite(range.min)
        ? { className: CLASS_RANGES[idx - 1].name, amount: round2(ratio - range.min + 0.01) }
        : null;
    return { min: range.min, max: range.max, up, down };
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
 * True when `name` is one of the WCMA classes.
 * @param {string} name
 * @returns {boolean}
 */
export function isValidClass(name) {
    return CLASS_RANGES.some(r => r.name === name);
}

/**
 * True when class `a` is faster (a lower weight/hp band) than class `b`.
 * @param {string} a
 * @param {string} b
 * @returns {boolean}
 */
export function isFasterClass(a, b) {
    const ia = CLASS_RANGES.findIndex(r => r.name === a);
    const ib = CLASS_RANGES.findIndex(r => r.name === b);
    return ia !== -1 && ib !== -1 && ia < ib;
}

/**
 * The class whose column the modifier tables are read from. As in the WCMA
 * calculator, that is the class the entrant chose, or by default the class
 * their weight/hp ratio (before any factors) falls in.
 * @param {number} weight - Competition weight in lbs
 * @param {number} hp - Declared maximum average horsepower
 * @param {string} classChoice - Chosen class, or '' for automatic
 * @returns {string} Class name, or '' without weight and HP
 */
export function getScoringClass(weight, hp, classChoice) {
    if (isValidClass(classChoice)) {
        return classChoice;
    }
    return determineClass(calculateBaseRatio(weight, hp));
}

/**
 * Maximum tyre section width (mm) allowed in IT1/IT2 for a given minimum
 * competition weight (WCMA Technical Regulations 3.3 C.2).
 * @param {number} weight - Competition weight in lbs
 * @returns {number} Width in mm
 */
export function getItTireMaxWidth(weight) {
    if (weight > 2750) return 265;
    if (weight >= 2400) return 255;
    return 225;
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
        classChoice,
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
        calculatedClass: '',
        scoringClass: '',
        competingClass: '',
        movedUp: false,
        chassisValue: 0,
        bodyModsValue: 0,
        transmissionValue: 0,
        drivetrainValue: 0,
        tiresValue: 0,
        brakeSuspensionValue: 0,
    };

    // Check if we have minimum required data
    const weightNum = parseFloat(competitionWeight);
    const hpNum = parseFloat(declaredHp);
    const hasBaseData = !isNaN(weightNum) && !isNaN(hpNum) && weightNum > 0 && hpNum > 0;

    if (!hasBaseData) {
        return results;
    }

    results.baseRatio = calculateBaseRatio(weightNum, hpNum);

    const chosenClass = isValidClass(classChoice) ? classChoice : '';
    const scoringClass = getScoringClass(weightNum, hpNum, chosenClass);
    results.scoringClass = scoringClass;

    // Single-choice fields hold one option id; checkbox fields hold a list.
    // A lone string is accepted for checkbox fields so older saved drafts load.
    const sumOf = (table, ids) => {
        const list = Array.isArray(ids) ? ids : (ids ? [ids] : []);
        return list.reduce((sum, id) => {
            const v = getModifierValue(table, id, scoringClass);
            return v !== null && !isNaN(v) ? sum + v : sum;
        }, 0);
    };

    results.chassisValue         = sumOf(chassisModifierTable, chassis);
    results.bodyModsValue        = sumOf(bodyModifierTable, bodyMods);
    results.transmissionValue    = sumOf(transModifierTable, transmission);
    results.drivetrainValue      = sumOf(dtModifierTable, drivetrain);
    results.tiresValue           = sumOf(tireModifierTable, tires);
    results.brakeSuspensionValue = sumOf(brakeModifierTable, brakeSuspension);
    results.modificationFactor = results.chassisValue + results.bodyModsValue
        + results.transmissionValue + results.drivetrainValue
        + results.tiresValue + results.brakeSuspensionValue;

    if (chosenClass) {
        // The WCMA calculator takes the weight factor from the chosen class.
        results.weightFactor = calculateWeightFactor(weightNum, chosenClass);
        results.modifiedRatio = results.baseRatio + results.weightFactor + results.modificationFactor;
    } else {
        // Iteratively calculate weight factor based on the final calculated class
        // Start with class from base ratio + modifiers (without weight factor)
        let classForWeightFactor = determineClass(results.baseRatio + results.modificationFactor);
        let previousWeightFactor = null;
        let iterations = 0;
        const maxIterations = 10;

        // Iterate until weight factor stabilizes (class used matches resulting class)
        while (iterations < maxIterations) {
            results.weightFactor = calculateWeightFactor(weightNum, classForWeightFactor);
            results.modifiedRatio = results.baseRatio + results.weightFactor + results.modificationFactor;
            const resultingClass = determineClass(results.modifiedRatio);

            if (previousWeightFactor !== null &&
                Math.abs(results.weightFactor - previousWeightFactor) < 0.001 &&
                resultingClass === classForWeightFactor) {
                break;
            }

            if (resultingClass !== classForWeightFactor) {
                classForWeightFactor = resultingClass;
                previousWeightFactor = results.weightFactor;
                iterations++;
            } else {
                break;
            }
        }
    }

    results.calculatedClass = determineClass(results.modifiedRatio);

    // A driver may always run in a faster class than their ratio gives
    // (Technical Regulations 3.2 E.1.6 / 3.3 E.1.6), never a slower one.
    results.movedUp = !!chosenClass && isFasterClass(chosenClass, results.calculatedClass);
    results.competingClass = results.movedUp ? chosenClass : results.calculatedClass;

    return results;
}
