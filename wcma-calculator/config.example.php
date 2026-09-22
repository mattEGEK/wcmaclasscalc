<?php
/**
 * Shared configuration for the WCMA Classing Calculator.
 *
 * SMTP + Google OAuth credentials used by car-classing.php, admin.php,
 * auth.php, and account.php. Edit the placeholder values below once here
 * instead of in each file.
 *
 * SMTP SETUP:
 *   1. In your IONOS control panel, create an email address (e.g. noreply@221racing.com)
 *   2. Download PHPMailer: https://github.com/PHPMailer/PHPMailer/releases/latest
 *      Extract and upload the src/ folder to your server as phpmailer/src/
 *   3. Fill in the SMTP credentials below
 *
 * GOOGLE OAUTH SETUP:
 *   Generate a client at https://console.cloud.google.com/apis/credentials
 *   (OAuth client ID → Web application). Add this file's callback URL as an
 *   "Authorized redirect URI", e.g. https://yourdomain.com/auth.php?action=google-callback
 */

// ── SMTP ───────────────────────────────────────────────────────────────────
define('SMTP_HOST',  'smtp.ionos.com');   // IONOS SMTP server
define('SMTP_PORT',  587);                 // 587 = STARTTLS  |  465 = SSL
define('SMTP_USER',  'noreply@yourdomain.com');   // ← your IONOS email address
define('SMTP_PASS',  'YOUR_SMTP_PASSWORD');        // ← that email's password
define('FROM_EMAIL', 'noreply@yourdomain.com');    // ← must match SMTP_USER
define('FROM_NAME',  'WCMA Calculator');

// ── Google OAuth ───────────────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
define('GOOGLE_REDIRECT_URI',  'https://yourdomain.com/auth.php?action=google-callback');
