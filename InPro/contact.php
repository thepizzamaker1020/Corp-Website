<?php
/**
 * InPro — Contact Form Mailer
 */
define('CLAIMS_EMAIL',        'claims@inproclaims.com');
define('FROM_EMAIL',          'noreply@inproclaims.com');
define('TURNSTILE_SECRET',    '0x4AAAAAACwyWlJxSE3IC1aduw2e7o_3tjk');

function verify_turnstile(string $token): bool {
    $resp = file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['secret' => TURNSTILE_SECRET, 'response' => $token]),
        ],
    ]));
    if ($resp === false) return false;
    $data = json_decode($resp, true);
    return !empty($data['success']);
}

function sanitize(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pages/contact.html');
    exit;
}

$turnstile_token = $_POST['cf-turnstile-response'] ?? '';
if (empty($turnstile_token) || !verify_turnstile($turnstile_token)) {
    header('Location: pages/contact.html?error=bot');
    exit;
}

$name    = sanitize($_POST['name']    ?? '');
$company = sanitize($_POST['company'] ?? '');
$email   = sanitize($_POST['email']   ?? '');
$phone   = sanitize($_POST['phone']   ?? '');
$subject = sanitize($_POST['subject'] ?? '');
$message = sanitize($_POST['message'] ?? '');

if (empty($name) || empty($email) || empty($subject) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: pages/contact.html?error=1');
    exit;
}

$submitted = date('F j, Y \a\t g:i A T');
$body = "New contact form submission from InPro website\nSubmitted: {$submitted}\n\n" .
        "Name:    {$name}\n" .
        "Company: {$company}\n" .
        "Email:   {$email}\n" .
        "Phone:   {$phone}\n" .
        "Subject: {$subject}\n\n" .
        "Message:\n{$message}\n";

$headers  = "From: InPro Contact <" . FROM_EMAIL . ">\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = mail(CLAIMS_EMAIL, "InPro Contact: {$subject} — {$name}", $body, $headers);

// Auto-reply to sender
$reply = "Dear {$name},\n\nThank you for contacting InPro. We have received your message and will follow up within one business day.\n\nYour message:\n---\nSubject: {$subject}\n{$message}\n---\n\nInPro — Contents Inventory & Valuation Specialists\nclaims@inproclaims.com | Canada-wide | Since 2006\n";
$reply_headers  = "From: InPro <" . FROM_EMAIL . ">\r\n";
$reply_headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
mail($email, "InPro — Message Received", $reply, $reply_headers);

header($sent ? 'Location: pages/contact.html?success=1' : 'Location: pages/contact.html?error=1');
exit;
