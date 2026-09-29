<?php
// wcma-calculator/admin-settings.php
//
// Admin: Settings — where submissions and feedback are emailed (admin desktop UX spec 2026-09-29 §7).
// Loaded by admin.php, which has already checked the admin role; settings-update is CSRF-checked there.

function handleSettings(PDO $pdo): void {
    $feedbackRecipient = feedbackRecipient($pdo);
    $values = [
        'classing_recipient_email'   => db_get_setting($pdo, 'classing_recipient_email', config_default('CLASSING_RECIPIENT_EMAIL', 'classing@wcma.ca')),
        'classing_recipient_name'    => db_get_setting($pdo, 'classing_recipient_name', config_default('CLASSING_RECIPIENT_NAME', 'WCMA Classing')),
        'tech_sheet_recipient_email' => db_get_setting($pdo, 'tech_sheet_recipient_email', config_default('TECH_SHEET_RECIPIENT_EMAIL', 'classing@wcma.ca')),
        'tech_sheet_recipient_name'  => db_get_setting($pdo, 'tech_sheet_recipient_name', config_default('TECH_SHEET_RECIPIENT_NAME', 'WCMA Classing')),
        'feedback_recipient_email'   => $feedbackRecipient['email'],
        'feedback_recipient_name'    => $feedbackRecipient['name'],
    ];
    $csrf = generateCsrfToken();
    $flash = getFlash();
    adminRenderPage('Settings', 'settings', renderSettingsPageHtml($values, $csrf), $flash);
}

function handleSettingsUpdate(PDO $pdo): void {
    $fields = [
        'classing_recipient_email'   => ['name' => 'classing_recipient_name',    'label' => 'Class calculator'],
        'tech_sheet_recipient_email' => ['name' => 'tech_sheet_recipient_name',  'label' => 'Tech sheet'],
        'feedback_recipient_email'   => ['name' => 'feedback_recipient_name',    'label' => 'Feedback'],
    ];

    $clean = [];
    foreach ($fields as $emailKey => $info) {
        $email = trim($_POST[$emailKey] ?? '');
        $name  = trim($_POST[$info['name']] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash($info['label'] . ' recipient requires a valid email address.', 'error');
            adminRedirect('admin.php?action=settings');
        }
        if ($name === '') {
            setFlash($info['label'] . ' recipient requires a name.', 'error');
            adminRedirect('admin.php?action=settings');
        }
        $clean[$emailKey] = $email;
        $clean[$info['name']] = $name;
    }

    foreach ($clean as $key => $value) {
        db_set_setting($pdo, $key, $value);
    }

    setFlash('Notification settings updated.', 'success');
    adminRedirect('admin.php?action=settings');
}

const ADMIN_SETTINGS_GROUPS = [
    ['legend' => 'Class calculator', 'id' => 'classing', 'email' => 'classing_recipient_email', 'name' => 'classing_recipient_name'],
    ['legend' => 'Tech sheets', 'id' => 'tech-sheet', 'email' => 'tech_sheet_recipient_email', 'name' => 'tech_sheet_recipient_name'],
    ['legend' => 'Feedback', 'id' => 'feedback', 'email' => 'feedback_recipient_email', 'name' => 'feedback_recipient_name'],
];

/** The Settings body: one card, a fieldset per recipient with Email and Name side by side. Pure. */
function renderSettingsPageHtml(array $values, string $csrf): string {
    $out = '<p class="admin-intro">Where class calculator submissions, tech sheet submissions and user feedback are emailed. '
        . 'People who submit a class calculation or a tech sheet always get their own copy too.</p>'
        . '<form method="post" action="admin.php?action=settings-update" class="detail-card admin-settings">' . adminCsrfField($csrf);
    foreach (ADMIN_SETTINGS_GROUPS as $g) {
        $out .= '<fieldset><legend>' . h($g['legend']) . '</legend><div class="admin-form">'
            . adminField($g['id'] . '-email', 'Email', '<input type="email" id="' . $g['id'] . '-email" name="' . $g['email'] . '" value="' . h((string)$values[$g['email']]) . '" required>')
            . adminField($g['id'] . '-name', 'Name', '<input type="text" id="' . $g['id'] . '-name" name="' . $g['name'] . '" value="' . h((string)$values[$g['name']]) . '" required>')
            . '</div></fieldset>';
    }
    return $out . '<div class="admin-form-actions"><button type="submit" class="btn btn-primary">Save</button></div></form>';
}
