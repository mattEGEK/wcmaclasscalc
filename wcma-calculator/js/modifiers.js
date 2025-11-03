/**
 * Modifier Tables Module
 * Contains all modification factor tables with class-specific values
 * Based on WCMA calculator at https://www.wcma.ca/classing/car-classing.php
 * 
 * Structure: [id, description, GTU, GT1, GT2, GT3, GT4, IT1, IT2]
 * Values of 99 mean "not applicable" for that class
 */

// Chassis modifier table (radio button - single selection)
export const chassisModifierTable = [
    ["chassis1", "Sports Racer, Prototypes, Monocoque race cars (GTU,GT1,GT2)", -2.5, -2.5, -3.4, 99, 99, 99, 99],
    ["chassis2", "Non-Production Vehicle (excluding GT4,IT1,IT2)", -0.4, -0.4, -0.4, -0.4, 99, 99, 99],
    ["chassis3", "Roll cage bars that penetrate the front firewall/bulkhead (IT1,IT2 only)", 99, 99, 99, 99, 99, 0.4, 0.4],
    ["chassis4", "Not applicable", 0, 0, 0, 0, 0, 0, 0]
];

// Body modifier table (checkboxes - multiple selections allowed)
export const bodyModifierTable = [
    ["body1", "Modification of OEM roof line, shape, or windshield/frame removal", -0.3, -0.3, -0.3, -0.3, -0.3, -0.3, -0.3],
    ["body2", "Modification of floor pan for exhaust clearance only and/or the rocker panel for side exiting exhaust", -0.2, -0.2, -0.2, -0.2, -0.2, -0.2, -0.2],
    ["body3", "Factory stock aero option (GT3,GT4,IT1,IT2 only)", 99, 99, 99, 0.4, 0.4, 0.4, 0.4],
    ["body4", "IT1 only Front splitter", 99, 99, 99, 99, 99, -0.5, 99],
    ["body5", "IT2 only Single rear wing or spoiler", 99, 99, 99, 99, 99, 99, -1.0],
    ["body6", "Not applicable", 0, 0, 0, 0, 0, 0, 0]
];

// Transmission modifier table (radio button - single selection)
export const transModifierTable = [
    ["trans1", "Stock to vehicle auto/semi-automatic gearbox (IT1/IT2)", 99, 99, 99, 99, 99, -0.3, -0.3],
    ["trans2", "Modified or swapped OEM sourced auto/sequential/semi-auto gearbox", -0.2, -0.2, -0.5, -0.5, -0.5, 99, 99],
    ["trans3", "Purpose built racing gearbox", -1.0, -1.0, -1.0, -1.0, -1.0, 99, 99],
    ["trans4", "Sequential/semi-auto/dog-ring (GTU,GT1,GT2,GT3,GT4)", -0.2, -0.2, -0.2, -0.2, -0.2, 99, 99],
    ["trans5", "Not Applicable/standard gearbox", 0, 0, 0, 0, 0, 0, 0]
];

// Drivetrain modifier table (radio button - single selection)
export const dtModifierTable = [
    ["dt1", "All wheel drive", -0.5, -0.5, -0.5, -0.5, -0.5, -0.5, -0.5],
    ["dt2", "Front wheel drive", 0.6, 0.6, 0.6, 0.6, 0.6, 0.6, 0.6],
    ["dt3", "MR/RR (IT1,IT2)", 99, 99, 99, 99, 99, -0.4, -0.4],
    ["dt4", "Rear wheel drive", 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0]
];

// Tire modifier table (radio button - single selection)
export const tireModifierTable = [
    ["tire1", "DOT-approved, Section Width 267mm to 282mm, UTQG Treadwear rating 100 or greater", 0.6, 0.6, 0.6, 0.6, 0.6, 0.5, 0.5],
    ["tire2", "DOT-approved, Section Width 267mm to 282mm, UTQG Treadwear rating 41 to 99", 0.3, 0.3, 0.3, 0.3, 0.3, 0.0, 0.0],
    ["tire3", "DOT-approved, Section Width 267mm to 282mm, UTQG Treadwear rating 40 and less", 0.3, 0.3, 0.3, 0.3, -1.0, 99, 99],
    ["tire4", "DOT-approved, Section Width 266mm or smaller, UTQG Treadwear rating 100 or greater", 0.9, 0.9, 0.9, 0.9, 0.9, 0.5, 0.5],
    ["tire5", "DOT-approved, Section Width 266mm or smaller, UTQG Treadwear rating 41 to 99", 0.6, 0.6, 0.6, 0.6, 0.3, 0.0, 0.0],
    ["tire6", "DOT-approved, Section Width 266mm or smaller, UTQG Treadwear rating 40 and less", 0.6, 0.6, 0.6, 0.6, -0.7, 99, 99],
    ["tire9", "Non-DOT Approved 10.6in (269mm) or greater", -0.5, -0.5, -0.5, -0.5, 99, 99, 99],
    ["tire10", "Non-DOT Tire Size 10.5in (267mm) to 9.6 (244mm)", -0.2, -0.2, -0.2, -0.2, 99, 99, 99],
    ["tire11", "Non-DOT Tire Size 9.5in (241mm) or smaller", 0.1, 0.1, 0.1, 0.1, 99, 99, 99]
];

// Brake/suspension modifier table (checkboxes - multiple selections allowed, IT1/IT2 only)
export const brakeModifierTable = [
    ["brake1", "Non-OEM, modified or relocated brake calipers/brackets or rotor diameter", 99, 99, 99, 99, 99, -0.2, -0.2],
    ["brake2", "Suspension design utilizing upper \"A-arm\" or \"wishbone\" type control arms (front or rear)", 99, 99, 99, 99, 99, -0.7, -0.7],
    ["brake3", "Replace, modify, or remove control arms, camber arms/links, toe arms/links", 99, 99, 99, 99, 99, -0.5, -0.5],
    ["brake4", "Add, replace, or modify a Watts link, Panhard Rod, or Torque Arm", 99, 99, 99, 99, 99, -0.5, -0.5],
    ["brake5", "Non-OEM metallic and/or spherical design replacement suspension bushing modifications on control/camber/toe arms/links, panhard rods, watts links, and torque arms", 99, 99, 99, 99, 99, -0.2, -0.2],
    ["brake6", "Non-OEM shocks/struts with an external reservoir (or piggyback) OR with shaft diameter 40mm or greater", 99, 99, 99, 99, 99, -0.7, -0.7],
    ["brake7", "Increase in track width greater than four (4) inches", 99, 99, 99, 99, 99, -0.7, -0.7]
];

/**
 * Get class index for modifier table lookup
 * @param {string} targetClass - Target class (GTU, GT1, GT2, GT3, GT4, IT1, IT2)
 * @returns {number} Column index (2-8 for GTU-IT2)
 */
export function getClassIndex(targetClass) {
    const classMap = {
        'GTU': 2,
        'GT1': 3,
        'GT2': 4,
        'GT3': 5,
        'GT4': 6,
        'IT1': 7,
        'IT2': 8
    };
    return classMap[targetClass] || 8; // Default to IT2
}

/**
 * Get modifier value for a specific option and class
 * @param {Array} modifierTable - The modifier table array
 * @param {string} optionId - The option ID (e.g., "chassis1", "trans2")
 * @param {string} targetClass - Target class
 * @returns {number|null} Modifier value or null if not applicable
 */
export function getModifierValue(modifierTable, optionId, targetClass) {
    const classIndex = getClassIndex(targetClass);
    
    for (let i = 0; i < modifierTable.length; i++) {
        if (modifierTable[i][0] === optionId) {
            const value = modifierTable[i][classIndex];
            // Values >= 10 mean not applicable
            if (value >= 10 || value === 99 || value === 44 || value === 33) {
                return null;
            }
            return value;
        }
    }
    return null;
}

/**
 * Check if an option is available for a given class
 * @param {Array} modifierTable - The modifier table array
 * @param {string} optionId - The option ID
 * @param {string} targetClass - Target class
 * @returns {boolean} True if available
 */
export function isOptionAvailable(modifierTable, optionId, targetClass) {
    const value = getModifierValue(modifierTable, optionId, targetClass);
    return value !== null;
}

