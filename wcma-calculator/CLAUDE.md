# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A static HTML/CSS/JS single-page calculator replacing the legacy PHP calculator at wcma.ca/classing/car-classing.php. It computes WCMA motorsport car classes from weight/horsepower ratios with modification factors. The form posts to `car-classing.php` (server-side PHP, not in this repo) which handles email delivery.

## Running the App

No build step. Open `car-classing.html` directly in a browser, or serve it with any static file server:

```
npx serve .
# or
python -m http.server 8080
```

ES6 modules (`type="module"`) require a server — opening the HTML via `file://` will cause CORS errors on the JS imports.

## Architecture

Four vanilla JS ES6 modules loaded from `car-classing.html`:

- **`js/modifiers.js`** — Pure data. Contains the six modifier tables (chassis, body, transmission, drivetrain, tires, brake/suspension) as arrays with structure `[id, description, GTU, GT1, GT2, GT3, GT4, IT1, IT2]`. Value `99` means "not applicable for this class." Exports `getModifierValue()` and `isOptionAvailable()` for table lookups.

- **`js/calculator.js`** — Pure calculation logic, no DOM. Exports `updateCalculations(formData)` which orchestrates the full calculation pipeline. Key subtlety: weight factor depends on the final class, which itself depends on the weight factor — resolved by an iterative loop (max 10 iterations) until the class stabilizes. Imports from `modifiers.js`.

- **`js/form-handler.js`** — Validation and form submission via `fetch()`. Returns JSON from the PHP backend. Exports validation helpers (`validateEmail`, `validateNumeric`, etc.) used by both itself and `ui-controller.js`.

- **`js/ui-controller.js`** — All DOM manipulation and event wiring. Imports from all three other modules. Handles: real-time recalculation on input, populating modifier dropdowns filtered by calculated class, chassis→body-mods restriction logic, brake/suspension checkboxes (IT1/IT2 only), print window generation, and localStorage save/load for configurations.

## Calculation Formula

1. **Base Ratio** = `competitionWeight / declaredHp` (rounded to 2 decimals)
2. **Base Class** = determined from base ratio using fixed ranges (GTU < 6.00, GT1 6-7.99, GT2 8-9.99, GT3 10-11.99, GT4 12-13.99, IT1 14-17.99, IT2 ≥ 18)
3. **Modifier lookups** use the base class as the column index into modifier tables
4. **Weight Factor** is iteratively resolved — it depends on the final class, so the loop recalculates until class stabilizes
5. **Modified Ratio** = `baseRatio + weightFactor + modificationFactor`
6. **Calculated Class** = determined from modified ratio using same ranges

## Key Constraints

- Weight and HP inputs must be whole integers — decimal entry is blocked via `keydown` and `paste` handlers in `ui-controller.js:1469-1496`
- Modifier dropdowns are disabled until both weight and HP are entered
- Selecting `chassis1` or `chassis2` disables the Body Mods dropdown (chassis restriction logic at `ui-controller.js:176-230`)
- Brake/suspension section only renders checkboxes for IT1/IT2; shows an informational note for other classes
- Saved configurations stored in `localStorage` under key `wcma-saved-configs`, capped at 10 entries
