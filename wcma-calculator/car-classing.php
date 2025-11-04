<?php
/**
 * WCMA Classing Calculator - Form Submission Handler
 * Handles form submission and sends email with attachments
 */

header('Content-Type: application/json');

// Recipient email
$to_email = 'matt.sinfield@gmail.com';
$to_name = 'Matt Sinfield';

// Subject
$subject = 'WCMA Classing Calculator Submission - ' . date('Y-m-d H:i:s');

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
$brake_suspension = isset($_POST['brake_suspension']) ? $_POST['brake_suspension'] : [];

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
if (!empty($chassis)) {
    $email_body .= '<tr><td><strong>Chassis:</strong></td><td>' . htmlspecialchars($chassis) . '</td></tr>';
}
if (!empty($body_mods)) {
    $email_body .= '<tr><td><strong>Body Mods:</strong></td><td>' . htmlspecialchars($body_mods) . '</td></tr>';
}
if (!empty($transmission)) {
    $email_body .= '<tr><td><strong>Transmission:</strong></td><td>' . htmlspecialchars($transmission) . '</td></tr>';
}
if (!empty($drivetrain)) {
    $email_body .= '<tr><td><strong>Drivetrain:</strong></td><td>' . htmlspecialchars($drivetrain) . '</td></tr>';
}
if (!empty($tires)) {
    $email_body .= '<tr><td><strong>Tires:</strong></td><td>' . htmlspecialchars($tires) . '</td></tr>';
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
if (!empty($chassis)) $email_body_text .= "Chassis: $chassis\n";
if (!empty($body_mods)) $email_body_text .= "Body Mods: $body_mods\n";
if (!empty($transmission)) $email_body_text .= "Transmission: $transmission\n";
if (!empty($drivetrain)) $email_body_text .= "Drivetrain: $drivetrain\n";
if (!empty($tires)) $email_body_text .= "Tires: $tires\n";
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

// Email headers
$boundary = md5(time());
$headers = "From: WCMA Calculator <noreply@nascc.ab.ca>\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

// Initialize message body
$message = "--$boundary\r\n";
$message .= "Content-Type: multipart/alternative; boundary=\"alt-$boundary\"\r\n\r\n";

// Plain text part
$message .= "--alt-$boundary\r\n";
$message .= "Content-Type: text/plain; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
$message .= $email_body_text . "\r\n\r\n";

// HTML part
$message .= "--alt-$boundary\r\n";
$message .= "Content-Type: text/html; charset=UTF-8\r\n";
$message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
$message .= $email_body . "\r\n\r\n";
$message .= "--alt-$boundary--\r\n";

// Handle file attachments
$attachments = [];
$file_fields = [
    'dyno_chart' => 'Dyno Chart',
    'dyno_table' => 'Exported Dyno Table',
    'car_image' => 'Car Image'
];

foreach ($file_fields as $field_name => $display_name) {
    if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$field_name];
        $file_path = $file['tmp_name'];
        $file_name = $file['name'];
        $file_type = $file['type'];
        $file_size = $file['size'];
        
        // Validate file size (2MB max)
        if ($file_size > 2 * 1024 * 1024) {
            continue; // Skip files that are too large
        }
        
        // Read file content
        $file_content = file_get_contents($file_path);
        $file_content_encoded = chunk_split(base64_encode($file_content));
        
        // Add attachment to message
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: $file_type; name=\"$file_name\"\r\n";
        $message .= "Content-Disposition: attachment; filename=\"$file_name\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= $file_content_encoded . "\r\n";
        
        $attachments[] = $display_name . ': ' . $file_name . ' (' . formatBytes($file_size) . ')';
    }
}

// Close message
$message .= "--$boundary--\r\n";

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
    'attachment_count' => count($attachments),
    'server_environment' => [
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
    ]
];

// Log debug info
error_log('Email send attempt: ' . json_encode($debug_info));

if ($mail_sent) {
    // Even if mail() returns true, email might not be delivered
    // Add a note about checking spam folder and server logs
    $attachment_info = !empty($attachments) ? ' Attachments: ' . implode(', ', $attachments) : ' No attachments.';
    
    // Warning message for debugging (can be removed in production)
    $debug_note = '';
    if (empty($mail_config['sendmail_path']) && empty($mail_config['smtp'])) {
        $debug_note = ' WARNING: Mail server configuration may be missing. Check server logs.';
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Form submitted successfully. Email sent with all form data.' . $attachment_info . $debug_note,
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

