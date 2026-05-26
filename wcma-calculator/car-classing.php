<?php
/**
 * WCMA Classing Calculator - Form Submission Handler
 * Handles form submission and sends email with attachments via PHPMailer + IONOS SMTP
 *
 * SETUP REQUIRED:
 *   1. In your IONOS control panel, create an email address (e.g. noreply@221racing.com)
 *   2. Download PHPMailer: https://github.com/PHPMailer/PHPMailer/releases/latest
 *      Extract and upload the src/ folder to your server as phpmailer/src/
 *   3. Fill in the SMTP credentials below
 */

// ── PHPMailer autoload ────────────────────────────────────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

header('Content-Type: application/json');

// ── Configuration ─────────────────────────────────────────────────────────────
$smtp_host     = 'smtp.ionos.com';   // IONOS SMTP server
$smtp_port     = 587;                 // 587 = STARTTLS  |  465 = SSL
$smtp_user     = 'noreply@yourdomain.com';   // ← your IONOS email address
$smtp_pass     = 'YOUR_SMTP_PASSWORD';        // ← that email's password
$from_email    = 'noreply@yourdomain.com';    // ← must match $smtp_user
$from_name     = 'WCMA Calculator';

$to_email = 'matt.sinfield@gmail.com';
$to_name  = 'Matt Sinfield';

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
$subject = 'WCMA Classing Calculator Submission - ' . htmlspecialchars($name) . ' - ' . date('M j, Y');

// Calculation results (from hidden fields)
$calculated_class = isset($_POST['calculated_class']) ? trim($_POST['calculated_class']) : '';
$base_ratio = isset($_POST['base_ratio']) ? trim($_POST['base_ratio']) : '';
$modified_ratio = isset($_POST['modified_ratio']) ? trim($_POST['modified_ratio']) : '';
$modification_factor = isset($_POST['modification_factor']) ? trim($_POST['modification_factor']) : '';
$weight_factor = isset($_POST['weight_factor']) ? trim($_POST['weight_factor']) : '';

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

        // Sanitize filename and create a unique path
        $file_name = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES[$input_name]['name']));
        $destination = $upload_dir . uniqid() . '-' . $file_name;

        if (move_uploaded_file($_FILES[$input_name]['tmp_name'], $destination)) {
            $attachments[] = [
                'path' => $destination,
                'name' => $file_name
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

// Email body (HTML format for better readability)
$email_body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">';
$email_body .= '<h2 style="color: #1a5490;">WCMA Classing Calculator Submission</h2>';
$email_body .= '<p><strong>Submitted:</strong> ' . date('F j, Y \a\t g:i A') . '</p>';

$email_body .= '<h3 style="color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: 5px;">Contact Information</h3>';
$email_body .= '<table cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
$email_body .= '<tr><td style="width: 200px;"><strong>Name:</strong></td><td>' . htmlspecialchars($name) . '</td></tr>';
$email_body .= '<tr><td><strong>Email:</strong></td><td>' . htmlspecialchars($email) . '</td></tr>';
$email_body .= '<tr><td><strong>Vehicle:</strong></td><td>' . htmlspecialchars($year . ' ' . $make . ' ' . $model) . '</td></tr>';
if (!empty($comments)) {
    $email_body .= '<tr><td><strong>Comments:</strong></td><td>' . nl2br(htmlspecialchars($comments)) . '</td></tr>';
}
$email_body .= '</table>';

$email_body .= '<h3 style="color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: 5px;">Vehicle Factors</h3>';
$email_body .= '<table cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
$email_body .= '<tr><td style="width: 200px;"><strong>Competition Weight (lbs):</strong></td><td>' . htmlspecialchars($competition_weight) . '</td></tr>';
$email_body .= '<tr><td><strong>Declared HP:</strong></td><td>' . htmlspecialchars($declared_hp) . '</td></tr>';
if (!empty($dyno_hp)) {
    $email_body .= '<tr><td><strong>Dyno HP:</strong></td><td>' . htmlspecialchars($dyno_hp) . '</td></tr>';
}
if (!empty($chassis_display)) {
    $email_body .= '<tr><td><strong>Chassis:</strong></td><td>' . htmlspecialchars($chassis_display) . '</td></tr>';
}
if (!empty($body_mods_display)) {
    $email_body .= '<tr><td><strong>Body Mods:</strong></td><td>' . htmlspecialchars($body_mods_display) . '</td></tr>';
}
if (!empty($transmission_display)) {
    $email_body .= '<tr><td><strong>Transmission:</strong></td><td>' . htmlspecialchars($transmission_display) . '</td></tr>';
}
if (!empty($drivetrain_display)) {
    $email_body .= '<tr><td><strong>Drivetrain:</strong></td><td>' . htmlspecialchars($drivetrain_display) . '</td></tr>';
}
if (!empty($tires_display)) {
    $email_body .= '<tr><td><strong>Tires:</strong></td><td>' . htmlspecialchars($tires_display) . '</td></tr>';
}
if (!empty($brake_suspension)) {
    $brake_list = is_array($brake_suspension) ? implode(', ', array_map('htmlspecialchars', $brake_suspension)) : htmlspecialchars($brake_suspension);
    $email_body .= '<tr><td><strong>Brake & Suspension:</strong></td><td>' . $brake_list . '</td></tr>';
}
$email_body .= '</table>';

$email_body .= '<h3 style="color: #1a5490; border-bottom: 2px solid #1a5490; padding-bottom: 5px;">Calculation Results</h3>';
$email_body .= '<table cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px; background-color: #f9f9f9; border: 1px solid #ddd;">';
if (!empty($weight_factor)) {
    $email_body .= '<tr><td style="width: 200px;"><strong>Weight Factor:</strong></td><td>' . htmlspecialchars($weight_factor) . '</td></tr>';
}
if (!empty($base_ratio)) {
    $email_body .= '<tr><td><strong>Base Ratio:</strong></td><td>' . htmlspecialchars($base_ratio) . '</td></tr>';
}
if (!empty($modification_factor)) {
    $email_body .= '<tr><td><strong>Additional Mod Factors:</strong></td><td>' . htmlspecialchars($modification_factor) . '</td></tr>';
}
if (!empty($modified_ratio)) {
    $email_body .= '<tr><td><strong>Modified Ratio:</strong></td><td>' . htmlspecialchars($modified_ratio) . '</td></tr>';
}
if (!empty($calculated_class)) {
    $email_body .= '<tr><td style="font-size: 1.2em; padding-top: 10px;"><strong>Calculated Class:</strong></td><td style="font-size: 1.2em; font-weight: bold; color: #1a5490; padding-top: 10px;">' . htmlspecialchars($calculated_class) . '</td></tr>';
}
$email_body .= '</table>';

$email_body .= '</body></html>';

// Plain text version for email clients that don't support HTML
$email_body_text = "WCMA Classing Calculator Submission\n";
$email_body_text .= "Submitted: " . date('F j, Y \a\t g:i A') . "\n\n";
$email_body_text .= "CONTACT INFORMATION\n";
$email_body_text .= "Name: $name\n";
$email_body_text .= "Email: $email\n";
$email_body_text .= "Vehicle: $year $make $model\n";
if (!empty($comments)) {
    $email_body_text .= "Comments: $comments\n";
}
$email_body_text .= "\nVEHICLE FACTORS\n";
$email_body_text .= "Competition Weight (lbs): $competition_weight\n";
$email_body_text .= "Declared HP: $declared_hp\n";
if (!empty($dyno_hp)) $email_body_text .= "Dyno HP: $dyno_hp\n";
if (!empty($chassis_display)) $email_body_text .= "Chassis: $chassis_display\n";
if (!empty($body_mods_display)) $email_body_text .= "Body Mods: $body_mods_display\n";
if (!empty($transmission_display)) $email_body_text .= "Transmission: $transmission_display\n";
if (!empty($drivetrain_display)) $email_body_text .= "Drivetrain: $drivetrain_display\n";
if (!empty($tires_display)) $email_body_text .= "Tires: $tires_display\n";
if (!empty($brake_suspension)) {
    $brake_list = is_array($brake_suspension) ? implode(', ', $brake_suspension) : $brake_suspension;
    $email_body_text .= "Brake & Suspension: $brake_list\n";
}
$email_body_text .= "\nCALCULATION RESULTS\n";
if (!empty($weight_factor)) $email_body_text .= "Weight Factor: $weight_factor\n";
if (!empty($base_ratio)) $email_body_text .= "Base Ratio: $base_ratio\n";
if (!empty($modification_factor)) $email_body_text .= "Additional Mod Factors: $modification_factor\n";
if (!empty($modified_ratio)) $email_body_text .= "Modified Ratio: $modified_ratio\n";
if (!empty($calculated_class)) $email_body_text .= "Calculated Class: $calculated_class\n";

// ── Send via PHPMailer (IONOS SMTP) ──────────────────────────────────────────
$last_error = '';

function buildMailer($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $from_email, $from_name) {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtp_host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtp_user;
    $mail->Password   = $smtp_pass;
    $mail->SMTPSecure = ($smtp_port === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $smtp_port;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($from_email, $from_name);
    return $mail;
}

try {
    // ── Email to admin ────────────────────────────────────────────────────────
    $mail = buildMailer($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $from_email, $from_name);
    $mail->addAddress($to_email, $to_name);
    $mail->addReplyTo($email, $name);
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $email_body;
    $mail->AltBody = $email_body_text;
    foreach ($attachments as $att) {
        $mail->addAttachment($att['path'], $att['name']);
    }
    $mail->send();

    // ── Confirmation email to submitter ───────────────────────────────────────
    $mail2 = buildMailer($smtp_host, $smtp_port, $smtp_user, $smtp_pass, $from_email, $from_name);
    $mail2->addAddress($email, $name);
    $mail2->Subject = 'Your WCMA Classing Calculator Submission';
    $mail2->isHTML(true);
    $mail2->Body    = $email_body;
    $mail2->AltBody = $email_body_text;
    foreach ($attachments as $att) {
        $mail2->addAttachment($att['path'], $att['name']);
    }
    $mail2->send();

    $mail_sent = true;

} catch (Exception $e) {
    $last_error = $e->getMessage();
    error_log('PHPMailer error: ' . $last_error);
    $mail_sent = false;
}

// Clean up uploaded files
foreach ($attachments as $attachment) {
    if (file_exists($attachment['path'])) {
        unlink($attachment['path']);
    }
}

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

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

