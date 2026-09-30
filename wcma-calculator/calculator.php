<?php
// wcma-calculator/calculator.php — the Class Calculator inside the hub layout.
//
// Page order (UX review 2026-09-30 §M2): which car, then the numbers the class comes from, then the
// optional supporting documents. On a phone the result column sits below the form, so a small dock
// at the bottom of the screen (js/calc-dock.js) keeps the class in view while the modifiers change.
require __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view_helpers.php';
require __DIR__ . '/cars-lib.php';

$pdo = db_connect();
db_init($pdo);
$user = current_user();
$account = $user !== null ? db_find_user_by_id($pdo, (int)$user['id']) : null;
$boundCar = null;
if ($user !== null && isset($_GET['car']) && ctype_digit((string)$_GET['car'])) {
    $c = db_get_user_car($pdo, (int)$user['id'], (int)$_GET['car']);
    if ($c !== null && $c['archived_at'] === null) $boundCar = $c;
}
$v = fn(?string $s): string => h((string)$s);
// Signed in with a car chosen: every "Your car" field is already known, so the section is not shown.
$carSectionHidden = $boundCar !== null && $account !== null;

renderPageStart('Class Calculator', 'calculator', ['bodyClass' => 'calculator-page']);
?>
<h1 class="hub-page-title">Class calculator</h1>
<div class="two-step-panel">
    <p class="two-step-panel-title">Every competitor needs to do two things:</p>
    <ol class="two-step-list">
        <li><strong>Declare your class</strong> &mdash; once a season, or whenever your car changes. <span class="two-step-current">(You're on this step now.)</span></li>
        <li><strong>Submit a tech sheet</strong> &mdash; every event, no matter what. <span class="two-step-note">(You'll do this after your class is calculated and saved.)</span></li>
    </ol>
</div>

<details class="calc-explainer-details">
    <summary>How is my class calculated?</summary>
    <div class="instructions-box">
        <ol class="how-it-works-steps">
            <li><strong>Base Ratio.</strong> Competition Weight &divide; Declared HP gives your starting ratio and Base Class (e.g. a 2800&nbsp;lb / 350&nbsp;hp car &rarr; ratio 8.00 &rarr; GT2).</li>
            <li><strong>Modifiers shift the ratio.</strong> Each chassis, body, transmission, drivetrain, tire, and brake/suspension option you select adds or subtracts from that ratio &mdash; the value next to each dropdown shows exactly how much.</li>
            <li><strong>Final class.</strong> Base Ratio + Weight Factor + Modification Factor = Modified Ratio, and the Modified Ratio determines your Calculated Class shown on the right.</li>
        </ol>
        <p class="rulebook-disclaimer">Per the Sporting Regulations, this Calculator &ldquo;substitutes for the class rules and vehicle specification sheet&rdquo; &mdash; what you submit is your class declaration to WCMA, and it needs to be <strong>kept up to date and accurate at all times</strong>. See the official <a href="https://www.wcma.ca/racing/racing-regulations/" target="_blank" rel="noopener">Sporting &amp; Technical Regulations</a> (updated annually) for the full criteria.</p>
    </div>
</details>

<?php if ($boundCar !== null): ?>
<div class="hub-card calc-car-banner">Declaring class for <strong><?= h(carDisplayName($boundCar)) ?></strong></div>
<?php endif; ?>
<div id="account-nudge"></div>

<div class="main-layout">
    <div class="form-column">
    <form id="classing-form" action="car-classing.php" method="POST" enctype="multipart/form-data" novalidate>
    <!-- Your car (and, for a guest, who you are) -->
    <section class="form-section compact-section" aria-labelledby="contact-heading"<?= $carSectionHidden ? ' hidden' : '' ?>>
        <h2 id="contact-heading"><?= $account !== null ? 'Your car' : 'You and your car' ?></h2>
        <div class="form-grid compact-grid">
            <?php if ($boundCar !== null): ?>
            <input type="hidden" name="car_id" value="<?= (int)$boundCar['id'] ?>">
            <?php else: ?>
            <div class="form-group" id="car-picker-group" hidden>
                <label for="car-id">Car (required)</label>
                <select id="car-id" name="car_id"></select>
                <p class="field-help">Every class declaration is for one of your cars.</p>
                <span class="error-message" id="car-id-error"></span>
            </div>

            <div class="form-group" id="car-number-group" hidden>
                <label for="car-number">Car number (required)</label>
                <input type="text" id="car-number" name="car_number" maxlength="10">
                <p class="field-help">The number you race with. Numbers are reserved on MotorsportReg.</p>
                <span class="error-message" id="car-number-error"></span>
            </div>
            <?php endif; ?>

            <div class="form-group"<?= $account !== null ? ' hidden' : '' ?>>
                <label for="name">Name (required)</label>
                <input type="text" id="name" name="name" required aria-required="true"<?= $account !== null ? ' value="' . $v($account['name']) . '"' : '' ?>>
                <span class="error-message" id="name-error"></span>
            </div>

            <div class="form-group"<?= $account !== null ? ' hidden' : '' ?>>
                <label for="email">Email (required)</label>
                <input type="email" id="email" name="email" required aria-required="true"<?= $account !== null ? ' value="' . $v($account['email']) . '"' : '' ?>>
                <span class="error-message" id="email-error"></span>
            </div>

            <div class="form-group"<?= $boundCar !== null ? ' hidden' : '' ?>>
                <label for="year">Year (required)</label>
                <input type="text" id="year" name="year" required aria-required="true" pattern="[0-9]{4}" inputmode="numeric"<?= $boundCar !== null ? ' value="' . $v((string)($boundCar['year'] ?? '')) . '"' : '' ?>>
                <span class="error-message" id="year-error"></span>
            </div>

            <div class="form-group"<?= $boundCar !== null ? ' hidden' : '' ?>>
                <label for="make">Make (required)</label>
                <input type="text" id="make" name="make" required aria-required="true"<?= $boundCar !== null ? ' value="' . $v($boundCar['make']) . '"' : '' ?>>
                <span class="error-message" id="make-error"></span>
            </div>

            <div class="form-group"<?= $boundCar !== null ? ' hidden' : '' ?>>
                <label for="model">Model (required)</label>
                <input type="text" id="model" name="model" required aria-required="true"<?= $boundCar !== null ? ' value="' . $v($boundCar['model']) . '"' : '' ?>>
                <span class="error-message" id="model-error"></span>
            </div>
        </div>
    </section>

    <!-- Vehicle factors: what the class is calculated from -->
    <section class="form-section compact-section" aria-labelledby="vehicle-factors-heading">
        <h2 id="vehicle-factors-heading">Vehicle factors</h2>
        <div class="form-grid compact-grid">
            <div class="form-group">
                <label for="competition-weight">Competition weight (required)</label>
                <input type="number" id="competition-weight" name="competition_weight" required aria-required="true" min="0" step="1" placeholder="Weight in lbs">
                <p class="field-help">Per WCMA regs, this is the minimum weight your car competes at &mdash; including driver and safety equipment &mdash; not just its static or curb weight.</p>
                <span class="error-message" id="competition-weight-error"></span>
            </div>

            <div class="form-group">
                <label for="declared-hp">Declared HP (required)</label>
                <input type="number" id="declared-hp" name="declared_hp" required aria-required="true" min="0" step="1" placeholder="Enter HP">
                <span class="error-message" id="declared-hp-error"></span>
            </div>

            <div class="form-group">
                <label for="dyno-hp">Dyno HP (optional)</label>
                <input type="number" id="dyno-hp" name="dyno_hp" min="0" step="1" placeholder="Enter dyno HP">
                <p class="field-help">Horsepower measured on a dynamometer. Optional, but if you have a dyno chart, providing this helps verify your Declared HP at tech inspection.</p>
                <span class="error-message" id="dyno-hp-error"></span>
            </div>

            <div class="form-group">
                <label for="class-choice">Class to score in</label>
                <select id="class-choice" name="class_choice" disabled>
                    <option value="">Auto (from weight &divide; HP)</option>
                    <option value="GTU">GTU</option>
                    <option value="GT1">GT1</option>
                    <option value="GT2">GT2</option>
                    <option value="GT3">GT3</option>
                    <option value="GT4">GT4</option>
                    <option value="IT1">IT1</option>
                    <option value="IT2">IT2</option>
                </select>
                <p class="field-help">Some mod factors differ by class. Auto scores your mods in the class your weight &divide; HP falls in. Pick a class to score them in that class instead, or to run in a faster class than your result &mdash; the regs let you move up a class, never down.</p>
            </div>
            <p class="field-note full-width calc-locked-note" id="calc-locked-note">Enter the weight and HP above to open the choices below.</p>
            <div class="form-group">
                <label for="chassis">Chassis</label>
                <div class="select-with-modifier">
                    <select id="chassis" name="chassis" disabled data-modifier-type="chassis">
                        <option value="">-- Select --</option>
                    </select>
                    <span class="modifier-value" id="chassis-modifier">+0.00</span>
                </div>
                <p class="modifier-explainer" id="chassis-explainer"></p>
            </div>

            <div class="form-group full-width">
                <label>Body mods (production vehicles)</label>
                <div class="checkbox-group">
                    <div id="body-mods-options" class="checkbox-options">
                        <span class="field-note">Enter weight and HP first.</span>
                    </div>
                    <div class="modifier-value-container">
                        <span class="modifier-value" id="body-mods-modifier">+0.00</span>
                    </div>
                </div>
                <p class="field-help">Tick every one that applies. &ldquo;BTM Aero&rdquo; means the car keeps its base trim model body lines with no non-BTM aero &mdash; see the <a href="https://www.wcma.ca/racing/racing-regulations/" target="_blank" rel="noopener">Technical Regulations</a>, 3.2 D.2.f (GT) and 3.3 D.4 (IT), for what is allowed.</p>
            </div>

            <div class="form-group">
                <label for="transmission">Transmission</label>
                <div class="select-with-modifier">
                    <select id="transmission" name="transmission" disabled data-modifier-type="trans">
                        <option value="">-- Select --</option>
                    </select>
                    <span class="modifier-value" id="transmission-modifier">+0.00</span>
                </div>
                <p class="modifier-explainer" id="transmission-explainer"></p>
            </div>

            <div class="form-group">
                <label for="drivetrain">Drivetrain</label>
                <div class="select-with-modifier">
                    <select id="drivetrain" name="drivetrain" disabled data-modifier-type="dt">
                        <option value="">-- Select --</option>
                    </select>
                    <span class="modifier-value" id="drivetrain-modifier">+0.00</span>
                </div>
                <p class="modifier-explainer" id="drivetrain-explainer"></p>
            </div>

            <div class="form-group">
                <label for="tires">Tires</label>
                <div class="select-with-modifier">
                    <select id="tires" name="tires" disabled data-modifier-type="tire">
                        <option value="">-- Select --</option>
                    </select>
                    <span class="modifier-value" id="tires-modifier">+0.00</span>
                </div>
                <span class="field-note" id="tire-width-note">IT1/IT2: maximum tire width depends on competition weight</span>
                <p class="modifier-explainer" id="tires-explainer"></p>
            </div>

            <div class="form-group full-width">
                <label>Brake and suspension (IT1/IT2)</label>
                <div class="checkbox-group">
                    <div id="brake-suspension-options" class="checkbox-options">
                        <span class="field-note">Available when scoring in IT1 or IT2.</span>
                    </div>
                    <div class="modifier-value-container">
                        <span class="modifier-value" id="brake-suspension-modifier">+0.00</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Supporting documents: all optional -->
    <section class="form-section compact-section" aria-labelledby="documents-heading">
        <h2 id="documents-heading">Supporting documents (optional)</h2>
        <p class="file-note">A dyno sheet or a photo helps the inspector accept your class. Each file can be up to 2 MB: PDF, DOC, JPG or PNG.</p>
        <div class="form-grid compact-grid">
            <div class="form-group">
                <label for="dyno-chart">Dyno chart</label>
                <input type="file" id="dyno-chart" name="dyno_chart" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                <span class="file-info" id="dyno-chart-info"></span>
                <span class="error-message" id="dyno-chart-error"></span>
            </div>

            <div class="form-group">
                <label for="dyno-table">Exported dyno table</label>
                <input type="file" id="dyno-table" name="dyno_table" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt">
                <span class="file-info" id="dyno-table-info"></span>
                <span class="error-message" id="dyno-table-error"></span>
            </div>

            <div class="form-group">
                <label for="car-image">Photo of the car</label>
                <input type="file" id="car-image" name="car_image" accept=".jpg,.jpeg,.png">
                <span class="file-info" id="car-image-info"></span>
                <span class="error-message" id="car-image-error"></span>
            </div>

            <div class="form-group full-width">
                <label for="comments">Comments for the inspector</label>
                <textarea id="comments" name="comments" rows="2"></textarea>
            </div>
        </div>
    </section>

    <!-- Success/Error Messages: beside the button that was pressed -->
    <div id="form-messages" class="form-messages" role="alert" aria-live="polite"></div>

    <!-- Form Actions -->
    <div class="form-actions">
        <button type="submit" id="submit-button" class="btn btn-primary">Submit class declaration</button>
        <button type="button" id="save-config-button" class="btn btn-secondary">Save as a draft</button>
        <button type="button" id="load-config-button" class="btn btn-secondary">Open a saved draft</button>
        <button type="button" id="print-button" class="btn btn-secondary">Print</button>
    </div>

    <p class="submit-note">Submitting sends this as your class declaration to WCMA, and you get a copy by email. Keep it accurate and up to date, per the official <a href="https://www.wcma.ca/racing/racing-regulations/" target="_blank" rel="noopener">Sporting &amp; Technical Regulations</a>.</p>
    </form>
    </div>

    <!-- Calculation Results Column -->
    <div class="results-column" id="results">
        <div class="sticky-results-container">
            <div class="calculation-results-box">
                <h2>Calculation results</h2>
                <div class="inline-calculation-summary">
                    <div class="summary-item highlight">
                        <span class="summary-label">Calculated Class:</span>
                        <span class="summary-value" id="inline-calculated-class">--</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Weight Factor:
                            <span class="summary-help">An adjustment based on how light or heavy your car is compared to the typical range for its class.</span>
                        </span>
                        <span class="summary-value" id="weight-factor-display">--</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Base Ratio:
                            <span class="summary-help">Competition Weight &divide; Declared HP, rounded to 2 decimals.</span>
                        </span>
                        <span class="summary-value" id="inline-base-ratio">--</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Additional Mod Factors:
                            <span class="summary-help">The sum of your selected chassis, body, transmission, drivetrain, tire, and brake/suspension modifiers.</span>
                        </span>
                        <span class="summary-value" id="additional-mods-display">--</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Modified Ratio:
                            <span class="summary-help">Base Ratio + Weight Factor + Modification Factor &mdash; determines your Calculated Class.</span>
                        </span>
                        <span class="summary-value" id="inline-modified-ratio">--</span>
                    </div>
                </div>
                <div class="boundary-gauge" id="boundary-gauge" hidden>
                    <div class="boundary-gauge-track">
                        <div class="boundary-gauge-fill" id="boundary-gauge-fill"></div>
                    </div>
                    <div class="boundary-gauge-ticks" aria-hidden="true">
                        <span id="boundary-gauge-min"></span>
                        <span id="boundary-gauge-max"></span>
                    </div>
                    <p class="boundary-gauge-label" id="boundary-gauge-label"></p>
                </div>
                <p class="higher-class-note">You may always choose to compete in a higher class than your calculated one.</p>
            </div>

            <!-- Class Ranges Box -->
            <div class="class-ranges-box">
                <h3>Class ranges</h3>
                <div class="class-ranges-list">
                    <div class="class-range-item">
                        <span class="class-name">GTU</span>
                        <span class="class-range">&lt; 6.00</span>
                    </div>
                    <div class="class-range-item">
                        <span class="class-name">GT1</span>
                        <span class="class-range">6.00 - 7.99</span>
                    </div>
                    <div class="class-range-item">
                        <span class="class-name">GT2</span>
                        <span class="class-range">8.00 - 9.99</span>
                    </div>
                    <div class="class-range-item">
                        <span class="class-name">GT3</span>
                        <span class="class-range">10.00 - 11.99</span>
                    </div>
                    <div class="class-range-item">
                        <span class="class-name">GT4</span>
                        <span class="class-range">12.00 - 13.99</span>
                    </div>
                    <div class="class-range-item">
                        <span class="class-name">IT1</span>
                        <span class="class-range">14.00 - 17.99</span>
                    </div>
                    <div class="class-range-item">
                        <span class="class-name">IT2</span>
                        <span class="class-range">&gt;= 18.00</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- The class stays in view on a phone while the form is scrolled (js/calc-dock.js). -->
<a href="#results" class="class-dock" id="class-dock" hidden>
    <span class="class-dock-label">Class so far</span>
    <strong class="class-dock-class" id="class-dock-class"></strong>
    <span class="class-dock-ratio" id="class-dock-ratio"></span>
    <span class="class-dock-more">Details &darr;</span>
</a>
<?php
renderPageEnd(['scripts' =>
    '<script src="js/declaration-state.js?v=1"></script>'
    . '<script type="module" src="js/calculator.js?v=1.4"></script>'
    . '<script type="module" src="js/form-handler.js?v=1.6"></script>'
    . '<script type="module" src="js/ui-controller.js?v=1.7"></script>'
    . '<script src="js/car-picker.js"></script>'
    . '<script src="js/calc-dock.js"></script>'
]);
