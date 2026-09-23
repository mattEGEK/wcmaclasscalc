<?php
/**
 * WCMA Classing Calculator - Form Submission Handler
 * Handles form submission and sends email with attachments via PHPMailer + IONOS SMTP
 *
 * SETUP REQUIRED: see config.php for SMTP credential setup instructions.
 */

// ── PHPMailer autoload ────────────────────────────────────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';
require __DIR__ . '/db.php';
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/config.php';
require __DIR__ . '/email-helpers.php';
require __DIR__ . '/submission-email-render.php';

$current_user = current_user();

header('Content-Type: application/json');

// ── Configuration ─────────────────────────────────────────────────────────────
$pdo = db_connect();
db_init($pdo);
$to_email = db_get_setting($pdo, 'classing_recipient_email', config_default('CLASSING_RECIPIENT_EMAIL', 'classing@wcma.ca'));
$to_name  = db_get_setting($pdo, 'classing_recipient_name', config_default('CLASSING_RECIPIENT_NAME', 'WCMA Classing'));

// Set timezone to Mountain Standard Time
date_default_timezone_set('America/Denver');

// Collect form data
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$year = isset($_POST['year']) ? trim($_POST['year']) : '';
$make = isset($_POST['make']) ? trim($_POST['make']) : '';
$model = isset($_POST['model']) ? trim($_POST['model']) : '';
$comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
$competition_weight = isset($_POST['competition_weight']) ? trim($_POST['competition_weight']) : '';
$declared_hp = isset($_POST['declared_hp']) ? trim($_POST['declared_hp']) : '';
$dyno_hp = isset($_POST['dyno_hp']) ? trim($_POST['dyno_hp']) : '';
$chassis = isset($_POST['chassis']) ? trim($_POST['chassis']) : '';
$body_mods = isset($_POST['body_mods']) ? trim($_POST['body_mods']) : '';
$transmission = isset($_POST['transmission']) ? trim($_POST['transmission']) : '';
$drivetrain = isset($_POST['drivetrain']) ? trim($_POST['drivetrain']) : '';
$tires = isset($_POST['tires']) ? trim($_POST['tires']) : '';

// Get display text for modifiers (preferred over option IDs)
$chassis_display = isset($_POST['chassis_display']) ? trim($_POST['chassis_display']) : $chassis;
$body_mods_display = isset($_POST['body_mods_display']) ? trim($_POST['body_mods_display']) : $body_mods;
$transmission_display = isset($_POST['transmission_display']) ? trim($_POST['transmission_display']) : $transmission;
$drivetrain_display = isset($_POST['drivetrain_display']) ? trim($_POST['drivetrain_display']) : $drivetrain;
$tires_display = isset($_POST['tires_display']) ? trim($_POST['tires_display']) : $tires;
$brake_suspension = isset($_POST['brake_suspension']) ? $_POST['brake_suspension'] : [];

// Subject with submitter name and date
$subject = 'WCMA Class Declaration - ' . htmlspecialchars($name) . ' - ' . date('M j, Y');

// Calculation results (from hidden fields)
$calculated_class = isset($_POST['calculated_class']) ? trim($_POST['calculated_class']) : '';
$base_ratio = isset($_POST['base_ratio']) ? trim($_POST['base_ratio']) : '';
$modified_ratio = isset($_POST['modified_ratio']) ? trim($_POST['modified_ratio']) : '';
$modification_factor = isset($_POST['modification_factor']) ? trim($_POST['modification_factor']) : '';
$weight_factor = isset($_POST['weight_factor']) ? trim($_POST['weight_factor']) : '';

$chassis_value          = isset($_POST['chassis_value'])          ? (float)$_POST['chassis_value']          : 0.0;
$body_mods_value        = isset($_POST['body_mods_value'])        ? (float)$_POST['body_mods_value']        : 0.0;
$transmission_value     = isset($_POST['transmission_value'])     ? (float)$_POST['transmission_value']     : 0.0;
$drivetrain_value       = isset($_POST['drivetrain_value'])       ? (float)$_POST['drivetrain_value']       : 0.0;
$tires_value            = isset($_POST['tires_value'])            ? (float)$_POST['tires_value']            : 0.0;
$brake_suspension_value = isset($_POST['brake_suspension_value']) ? (float)$_POST['brake_suspension_value'] : 0.0;

// Handle brake_suspension as array
if (is_string($brake_suspension)) {
    $brake_suspension = [$brake_suspension];
}

// Validate required fields
$errors = [];
if (empty($name)) $errors[] = 'Name is required';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
if (empty($year)) $errors[] = 'Year is required';
if (empty($make)) $errors[] = 'Make is required';
if (empty($model)) $errors[] = 'Model is required';
if (empty($competition_weight)) $errors[] = 'Competition weight is required';
if (empty($declared_hp)) $errors[] = 'Declared HP is required';

// Validate integers for weight and HP
if (!empty($competition_weight) && (!ctype_digit((string)$competition_weight) && !is_int($competition_weight + 0))) {
    $errors[] = 'Competition weight must be a whole number (no decimals)';
}
if (!empty($declared_hp) && (!ctype_digit((string)$declared_hp) && !is_int($declared_hp + 0))) {
    $errors[] = 'Declared HP must be a whole number (no decimals)';
}
if (!empty($dyno_hp) && (!ctype_digit((string)$dyno_hp) && !is_int($dyno_hp + 0))) {
    $errors[] = 'Dyno HP must be a whole number (no decimals)';
}

// Handle file uploads
$attachments = [];
$upload_dir = __DIR__ . '/uploads/';

// Create uploads directory if it doesn't exist
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        $errors[] = 'Failed to create uploads directory.';
    }
}

$allowed_mimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
    'text/plain'
];
$allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
$max_file_size = 2 * 1024 * 1024; // 2 MB

$file_inputs = ['dyno_chart', 'dyno_table', 'car_image'];

foreach ($file_inputs as $input_name) {
    if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == UPLOAD_ERR_OK) {
        // Check file size
        if ($_FILES[$input_name]['size'] > $max_file_size) {
            $errors[] = "File '{$_FILES[$input_name]['name']}' is too large. Maximum size is 2 MB.";
            continue;
        }

        // Check file type and extension
        $file_info = pathinfo($_FILES[$input_name]['name']);
        $extension = isset($file_info['extension']) ? strtolower($file_info['extension']) : '';
        $file_type = mime_content_type($_FILES[$input_name]['tmp_name']);

        if (!in_array($file_type, $allowed_mimes) || !in_array($extension, $allowed_extensions)) {
            $errors[] = "Invalid file type for '{$_FILES[$input_name]['name']}'. Allowed types: PDF, DOC, DOCX, JPG, PNG, TXT.";
            continue;
        }

        // Sanitize filename and create a unique temp path
        $file_name   = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES[$input_name]['name']));
        $temp_name   = uniqid() . '-' . $file_name;
        $destination = $upload_dir . $temp_name;

        if (move_uploaded_file($_FILES[$input_name]['tmp_name'], $destination)) {
            $attachments[] = [
                'path'  => $destination,
                'name'  => $file_name,
                'input' => $input_name,  // 'dyno_chart', 'dyno_table', or 'car_image'
            ];
        } else {
            $errors[] = "Failed to move uploaded file: '{$file_name}'.";
        }
    } elseif (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] != UPLOAD_ERR_NO_FILE) {
        $errors[] = "Error uploading file '{$_FILES[$input_name]['name']}'. Error code: {$_FILES[$input_name]['error']}";
    }
}


if (!empty($errors)) {
    // Clean up any files that were successfully uploaded before the error
    foreach ($attachments as $attachment) {
        if (file_exists($attachment['path'])) {
            unlink($attachment['path']);
        }
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errors' => $errors
    ]);
    exit;
}

// ── Persist to database ───────────────────────────────────────────────────────
$submission_id = db_insert_submission($pdo, [
    ':submitted_at'           => date('Y-m-d H:i:s'),
    ':name'                   => $name,
    ':email'                  => $email,
    ':year'                   => $year,
    ':make'                   => $make,
    ':model'                  => $model,
    ':comments'               => $comments ?: null,
    ':competition_weight'     => (int)$competition_weight,
    ':declared_hp'            => (int)$declared_hp,
    ':dyno_hp'                => $dyno_hp !== '' ? (int)$dyno_hp : null,
    ':chassis_display'        => $chassis_display ?: null,
    ':body_mods_display'      => $body_mods_display ?: null,
    ':transmission_display'   => $transmission_display ?: null,
    ':drivetrain_display'     => $drivetrain_display ?: null,
    ':tires_display'          => $tires_display ?: null,
    ':brake_suspension'       => json_encode($brake_suspension),
    ':chassis_value'          => $chassis_value,
    ':body_mods_value'        => $body_mods_value,
    ':transmission_value'     => $transmission_value,
    ':drivetrain_value'       => $drivetrain_value,
    ':tires_value'            => $tires_value,
    ':brake_suspension_value' => $brake_suspension_value,
    ':weight_factor'          => (float)$weight_factor,
    ':modification_factor'    => (float)$modification_factor,
    ':base_ratio'             => (float)$base_ratio,
    ':modified_ratio'         => (float)$modified_ratio,
    ':calculated_class'       => $calculated_class ?: null,
    ':user_id'                => $current_user['id'] ?? null,
]);

// Move uploaded files to uploads/{submission_id}/
$sub_upload_dir = $upload_dir . $submission_id . '/';
if (!is_dir($sub_upload_dir)) {
    mkdir($sub_upload_dir, 0755, true);
}

$file_paths = ['dyno_chart' => null, 'dyno_table' => null, 'car_image' => null];

foreach ($attachments as &$att) {
    $new_path = $sub_upload_dir . $att['name'];
    if (rename($att['path'], $new_path)) {
        $att['path'] = $new_path;
        $file_paths[$att['input']] = 'uploads/' . $submission_id . '/' . $att['name'];
    } else {
        error_log("Failed to move file to: $new_path");
    }
}
unset($att);

db_update_submission_files($pdo, $submission_id, $file_paths['dyno_chart'], $file_paths['dyno_table'], $file_paths['car_image']);

// Email body: same branded layout as the admin resend (see submission-email-render.php)
$submission_for_email = [
    'submitted_at'        => date('Y-m-d H:i:s'),
    'name'                => $name,
    'email'               => $email,
    'year'                => $year,
    'make'                => $make,
    'model'               => $model,
    'comments'            => $comments,
    'competition_weight'  => $competition_weight,
    'declared_hp'         => $declared_hp,
    'dyno_hp'             => $dyno_hp,
    'chassis_display'     => $chassis_display,
    'body_mods_display'   => $body_mods_display,
    'transmission_display'=> $transmission_display,
    'drivetrain_display'  => $drivetrain_display,
    'tires_display'       => $tires_display,
    'brake_suspension'    => $brake_suspension,
    'weight_factor'       => $weight_factor,
    'base_ratio'          => $base_ratio,
    'modification_factor' => $modification_factor,
    'modified_ratio'      => $modified_ratio,
    'calculated_class'    => $calculated_class,
];
$email_body_text = renderSubmissionEmailText($submission_for_email);

// ── Send via PHPMailer (IONOS SMTP) ──────────────────────────────────────────
$last_error = '';

function buildMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = (SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    return $mail;
}

try {
    // ── Email to admin ────────────────────────────────────────────────────────
    $mail = buildMailer();
    $mail->addAddress($to_email, $to_name);
    $mail->addReplyTo($email, $name);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = renderSubmissionEmailHtml($submission_for_email, emailLogoSrc($mail));
    $mail->AltBody = $email_body_text;
    foreach ($attachments as $att) {
        $mail->addAttachment($att['path'], $att['name']);
    }
    $mail->send();

    // ── Confirmation email to submitter ───────────────────────────────────────
    $mail2 = buildMailer();
    $mail2->addAddress($email, $name);
    $mail2->Subject = 'Your WCMA Classing Calculator Submission';
    $mail2->isHTML(true);
    $mail2->Body    = renderSubmissionEmailHtml($submission_for_email, emailLogoSrc($mail2));
    $mail2->AltBody = $email_body_text;
    foreach ($attachments as $att) {
        $mail2->addAttachment($att['path'], $att['name']);
    }
    $mail2->send();

    $mail_sent = true;
    db_update_email_sent($pdo, $submission_id, 1);

} catch (Exception $e) {
    $last_error = $e->getMessage();
    error_log('PHPMailer error: ' . $last_error);
    $mail_sent = false;
    db_update_email_sent($pdo, $submission_id, 0);
}

// Files now persist under uploads/{submission_id}/ — no cleanup needed.

if ($mail_sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Form submitted successfully! A confirmation has been sent to ' . htmlspecialchars($email) . '.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email. Error: ' . htmlspecialchars($last_error)
    ]);
}


