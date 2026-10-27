<?php
/**
 * BIG4DIGITAL — HOSTINGER PRODUCTION CONTACT FORM HANDLER
 * 
 * Works reliably on standard Hostinger shared hosting (LiteSpeed/Apache + PHP).
 * Validates inputs, blocks spam via honeypot, and securely transmits emails.
 * Supports both modern asynchronous fetch/AJAX and standard POST submissions.
 */

declare(strict_types=1);

// Configuration
const RECIPIENT_EMAIL = 'big4digitaladvisory@gmail.com';
const SENDER_EMAIL    = 'no-reply@big4digital.com';
const SITE_NAME       = 'Big4Digital';

// Determine if request is AJAX
$isAjax = false;
if (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
    (isset($_POST['ajax']) && $_POST['ajax'] === '1')
) {
    $isAjax = true;
}

// Function to respond with JSON
function sendResponse(bool $success, string $message, int $statusCode = 200, bool $isAjax = false): void {
    if ($isAjax) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message
        ]);
        exit;
    }

    if ($success) {
        header('Location: contact-us.html?status=success#contactFormSection');
    } else {
        header('Location: contact-us.html?status=error&msg=' . urlencode($message) . '#contactFormSection');
    }
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method. Only POST is allowed.', 405, $isAjax);
}

// 1. Spam Protection: Honeypot check
if (!empty($_POST['website_hp'])) {
    // Bot filled the hidden honeypot. Pretend success so bot doesn't retry.
    sendResponse(true, 'Your message has been sent successfully.', 200, $isAjax);
}

// 2. Extract and sanitize inputs
$name    = isset($_POST['name']) ? trim(strip_tags((string)$_POST['name'])) : '';
$email   = isset($_POST['email']) ? trim(filter_var((string)$_POST['email'], FILTER_SANITIZE_EMAIL)) : '';
$phone   = isset($_POST['phone']) ? trim(strip_tags((string)$_POST['phone'])) : '';
$message = isset($_POST['message']) ? trim(strip_tags((string)$_POST['message'])) : '';

// 3. Validation
$errors = [];

if (empty($name) || mb_strlen($name) < 2) {
    $errors[] = 'Please enter your full name.';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if (empty($message) || mb_strlen($message) < 5) {
    $errors[] = 'Please enter a message (at least 5 characters).';
}

if (!empty($errors)) {
    sendResponse(false, implode(' ', $errors), 422, $isAjax);
}

// 4. Sanitize headers to prevent header injection
$cleanName  = preg_replace('/[\r\n]+/', ' ', $name);
$cleanEmail = preg_replace('/[\r\n]+/', ' ', $email);

// 5. Construct Email
$subject = "New Inquiry from {$cleanName} — " . SITE_NAME;

$bodyText = "You have received a new contact inquiry from the Big4Digital website:\n\n";
$bodyText .= "Name:    {$cleanName}\n";
$bodyText .= "Email:   {$cleanEmail}\n";
$bodyText .= "Phone:   " . (!empty($phone) ? $phone : 'Not provided') . "\n";
$bodyText .= "Date:    " . date('Y-m-d H:i:s T') . "\n";
$bodyText .= "IP:      " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n\n";
$bodyText .= "Message:\n";
$bodyText .= "--------------------------------------------------\n";
$bodyText .= "{$message}\n";
$bodyText .= "--------------------------------------------------\n";

$headers  = "From: " . SITE_NAME . " <" . SENDER_EMAIL . ">\r\n";
$headers .= "Reply-To: {$cleanName} <{$cleanEmail}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

// 6. Send Mail via PHP mail()
$mailSent = @mail(RECIPIENT_EMAIL, $subject, $bodyText, $headers);

if ($mailSent) {
    sendResponse(true, 'Thank you! Your message has been received. Our team will contact you shortly.', 200, $isAjax);
} else {
    // If local mail agent isn't configured, still log safely and return a polite error or success
    error_log("Big4Digital Contact Form: mail() failed for inquiry from {$cleanEmail}");
    // Return friendly error
    sendResponse(false, 'Unable to send message at this moment. Please contact us directly at big4digitaladvisory@gmail.com or +92 3463579955.', 500, $isAjax);
}
