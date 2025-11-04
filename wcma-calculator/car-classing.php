<?php
/**
 * WCMA Classing Calculator - Form Submission Handler
 * Handles form submission and sends email with attachments
 */

header('Content-Type: application/json');

// Recipient email
$to_email = 'matt.sinfield@gmail.com';
$to_name = 'Matt Sinfield';

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

if (!empty($errors)) {
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

// Simple plain text email - HTML was causing issues
// Build simple headers
$headers = "From: WCMA Calculator <noreply@nascc.ab.ca>\r\n";
$headers .= "Reply-To: noreply@nascc.ab.ca\r\n";

// Use plain text message only
$message = $email_body_text;

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON output, but log them
ini_set('log_errors', 1);

// Check if mail() function is available
if (!function_exists('mail')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Mail function is not available on this server. Please contact support.'
    ]);
    exit;
}

// Check mail configuration
$mail_config = [
    'sendmail_path' => ini_get('sendmail_path'),
    'smtp' => ini_get('SMTP'),
    'smtp_port' => ini_get('smtp_port'),
    'sendmail_from' => ini_get('sendmail_from')
];

// Log configuration for debugging (remove in production)
error_log('Mail configuration: ' . json_encode($mail_config));

// Send email with better error handling
$mail_sent = false;
$last_error = '';

// Capture any PHP errors
$error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$last_error) {
    $last_error = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($last_error);
    return false;
});

try {
    // Try to send email
    $mail_sent = mail($to_email, $subject, $message, $headers);
    
    // Capture last error even if mail() returns true
    $last_php_error = error_get_last();
    if ($last_php_error && $last_php_error['type'] === E_WARNING) {
        $last_error = $last_php_error['message'];
        error_log('Mail warning: ' . $last_error);
    }
    
} catch (Exception $e) {
    $last_error = 'Exception: ' . $e->getMessage();
    error_log('Email sending exception: ' . $last_error);
} catch (Error $e) {
    $last_error = 'Fatal error: ' . $e->getMessage();
    error_log('Email sending fatal error: ' . $last_error);
}

// Restore error handler
restore_error_handler();

// Verify mail was actually attempted
// Note: mail() can return true even if email isn't delivered
// Check for common issues
$debug_info = [
    'mail_function_exists' => function_exists('mail'),
    'mail_returned' => $mail_sent,
    'last_error' => $last_error ?: 'None',
    'to_email' => $to_email,
    'headers' => $headers,
    'message_size' => strlen($message),
    'attachment_count' => 0,
    'server_environment' => [
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
    ]
];

// Log debug info
error_log('Email send attempt: ' . json_encode($debug_info));

// Log the actual mail() call details
error_log('=== MAIL() CALL DETAILS ===');
error_log('To: ' . $to_email);
error_log('Subject: ' . $subject);
error_log('Headers length: ' . strlen($headers));
error_log('Message length: ' . strlen($message));
error_log('Headers: ' . $headers);
error_log('Message (first 500 chars): ' . substr($message, 0, 500));
error_log('Plain text email - no multipart, no HTML');

if ($mail_sent) {
    // Even if mail() returns true, email might not be delivered
    // Add a note about checking spam folder and server logs
    $debug_note = '';
    if (empty($mail_config['sendmail_path']) && empty($mail_config['smtp'])) {
        $debug_note = ' WARNING: Mail server configuration may be missing. Check server logs.';
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Form submitted successfully. Plain text email sent with all form data.' . $debug_note,
        'debug' => $debug_info // Remove this in production
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send email. ' . ($last_error ? 'Error: ' . $last_error : 'Please check server mail configuration.'),
        'debug' => $debug_info // Remove this in production
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

