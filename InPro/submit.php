<?php
/**
 * InPro — Claim Submission PHP Mailer
 * Sends confirmation to adjuster + full file to claims@inproclaims.com
 */

// ─── Config ───
define('CLAIMS_EMAIL',     'claims@inproclaims.com');
define('FROM_EMAIL',       'noreply@inproclaims.com');
define('FROM_NAME',        'InPro Claim Submission');
define('MAX_FILE_SIZE',    20971520); // 20 MB per file
define('MAX_TOTAL_SIZE',   15728640); // 15 MB total across all files
define('TURNSTILE_SECRET', '0x4AAAAAACwyWlJxSE3IC1aduw2e7o_3tjk');

// ─── Helpers ───
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

function sanitize(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function fmt_currency(string $val): string {
    if ($val === '') return '';
    return '$' . number_format((float)$val, 2);
}

// ─── Only accept POST ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pages/submit-claim.html');
    exit;
}

// ─── Turnstile bot check ───
$turnstile_token = $_POST['cf-turnstile-response'] ?? '';
if (empty($turnstile_token) || !verify_turnstile($turnstile_token)) {
    header('Location: pages/submit-claim.html?error=bot');
    exit;
}

// ─── Section 1: Adjuster & Claim Information ───
$insurance_company     = sanitize($_POST['insurance_company']     ?? '');
$insurer_claim_number  = sanitize($_POST['insurer_claim_number']  ?? '');
$date_of_loss          = sanitize($_POST['date_of_loss']          ?? '');
$adj_name              = sanitize($_POST['adj_name']              ?? '');
$adj_email             = sanitize($_POST['adj_email']             ?? '');
$adj_phone             = sanitize($_POST['adj_phone']             ?? '');
$ia_company            = sanitize($_POST['ia_company']            ?? '');
$ia_file_number        = sanitize($_POST['ia_file_number']        ?? '');
$claim_type            = sanitize($_POST['claim_type']            ?? '');
$content_loss_estimate = sanitize($_POST['content_loss_estimate'] ?? '');
$policy_limit          = sanitize($_POST['policy_limit']          ?? '');
$deductible            = sanitize($_POST['deductible']            ?? '');
$special_limits        = sanitize($_POST['special_limits']        ?? '');

// ─── Section 2: InPro Services Requested ───
$onsite_required   = sanitize($_POST['onsite_required']   ?? '');
$services_requested = sanitize($_POST['services_requested'] ?? '');
$service_details   = sanitize($_POST['service_details']   ?? '');

// ─── Section 3: Policyholder Information ───
$insured_names      = sanitize($_POST['insured_names']      ?? '');
$insured_type       = sanitize($_POST['insured_type']       ?? '');
$ph_contact_allowed = sanitize($_POST['ph_contact_allowed'] ?? '');
$loss_address       = sanitize($_POST['loss_address']       ?? '');
$loss_city          = sanitize($_POST['loss_city']          ?? '');
$loss_province      = sanitize($_POST['loss_province']      ?? '');
$postal_code        = sanitize($_POST['postal_code']        ?? '');
$ph_email           = sanitize($_POST['ph_email']           ?? '');
$ph_mobile          = sanitize($_POST['ph_mobile']          ?? '');
$ph_home            = sanitize($_POST['ph_home']            ?? '');
$ph_business        = sanitize($_POST['ph_business']        ?? '');

// ─── Section 4: Contractor Information ───
$contractor_company = sanitize($_POST['contractor_company'] ?? '');
$contractor_contact = sanitize($_POST['contractor_contact'] ?? '');
$contractor_email   = sanitize($_POST['contractor_email']   ?? '');
$contractor_phone   = sanitize($_POST['contractor_phone']   ?? '');

// ─── Section 5: Notes / Safety Issues ───
$notes_safety = sanitize($_POST['notes_safety'] ?? '');

// ─── Validate required fields ───
$errors = [];
if (empty($insurance_company))                                    $errors[] = 'Insurance company is required.';
if (empty($insurer_claim_number))                                 $errors[] = 'Insurer claim number is required.';
if (empty($date_of_loss))                                         $errors[] = 'Date of loss is required.';
if (empty($adj_name))                                             $errors[] = 'Adjuster name is required.';
if (empty($adj_email) || !validate_email($adj_email))             $errors[] = 'A valid adjuster email is required.';
if (empty($adj_phone))                                            $errors[] = 'Adjuster phone is required.';
if (empty($claim_type))                                           $errors[] = 'Claim type is required.';
if (empty($onsite_required))                                      $errors[] = 'Please indicate whether onsite attendance is required.';
if (empty($insured_names))                                        $errors[] = 'Insured name(s) is required.';
if (empty($insured_type))                                         $errors[] = 'Insured type is required.';
if (empty($ph_contact_allowed))                                   $errors[] = 'Policyholder contact permission is required.';
if (empty($loss_address))                                         $errors[] = 'Loss address is required.';
if (empty($loss_city))                                            $errors[] = 'City is required.';
if (empty($loss_province))                                        $errors[] = 'Province is required.';

if (!empty($errors)) {
    header('Location: pages/submit-claim.html?error=1');
    exit;
}

// ─── Handle File Uploads ───
$attachments = [];
$upload_dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inpro_uploads_' . uniqid();
mkdir($upload_dir, 0755, true);

// ─── Guard: total file size ───
if (!empty($_FILES['claim_files']['size'])) {
    $total_size = array_sum($_FILES['claim_files']['size']);
    if ($total_size > MAX_TOTAL_SIZE) {
        header('Location: pages/submit-claim.html?error=filesize');
        exit;
    }
}

if (!empty($_FILES['claim_files']['name'][0])) {
    $allowed_types = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv', 'application/zip', 'application/x-zip-compressed',
        'image/jpeg', 'image/png', 'image/heic', 'image/heif',
        'message/rfc822', 'application/vnd.ms-outlook',
    ];
    $allowed_exts = ['pdf','doc','docx','xls','xlsx','csv','zip','jpg','jpeg','png','heic','heif','msg','eml'];

    foreach ($_FILES['claim_files']['tmp_name'] as $i => $tmp) {
        if ($_FILES['claim_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['claim_files']['size'][$i]  > MAX_FILE_SIZE) continue;

        $orig_name = basename($_FILES['claim_files']['name'][$i]);
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $mime      = mime_content_type($tmp);

        if (!in_array($ext, $allowed_exts)) continue;

        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig_name);
        $dest      = $upload_dir . DIRECTORY_SEPARATOR . $safe_name;

        if (move_uploaded_file($tmp, $dest)) {
            $attachments[] = ['path' => $dest, 'name' => $safe_name, 'mime' => $mime];
        }
    }
}

// ─── Build email bodies ───
$sep          = str_repeat('=', 52);
$loss_location = trim("{$loss_address}, {$loss_city}, {$loss_province}" . ($postal_code ? " {$postal_code}" : ''));
$submitted_at = date('F j, Y \a\t g:i A T');
$file_count   = count($attachments);
$file_label   = $file_count === 0 ? 'No files attached' : ($file_count === 1 ? '1 file attached' : "{$file_count} files attached");

// ─── Internal email (full detail to claims@inproclaims.com) ───
$sep = str_repeat('=', 52);

$internal_body  = "NEW CLAIM SUBMISSION — INPRO\n";
$internal_body .= "Submitted: {$submitted_at}\n";
$internal_body .= $sep . "\n\n";

$internal_body .= "ADJUSTER & CLAIM INFORMATION\n";
$internal_body .= "Insurance Company:    {$insurance_company}\n";
$internal_body .= "Insurer Claim Number: {$insurer_claim_number}\n";
$internal_body .= "Date of Loss:         {$date_of_loss}\n";
$internal_body .= "Adjuster Name:        {$adj_name}\n";
$internal_body .= "Adjuster Email:       {$adj_email}\n";
$internal_body .= "Adjuster Phone:       {$adj_phone}\n";
if ($ia_company)     $internal_body .= "IA Company:           {$ia_company}\n";
if ($ia_file_number) $internal_body .= "IA File Number:       {$ia_file_number}\n";
$internal_body .= "Claim Type:           {$claim_type}\n";
if ($content_loss_estimate) $internal_body .= "Content Loss Est:     " . fmt_currency($content_loss_estimate) . "\n";
if ($policy_limit)          $internal_body .= "Policy Limit:         " . fmt_currency($policy_limit) . "\n";
if ($deductible)            $internal_body .= "Deductible:           " . fmt_currency($deductible) . "\n";
if ($special_limits)        $internal_body .= "Special Limits:       {$special_limits}\n";

$internal_body .= "\n" . $sep . "\n\n";
$internal_body .= "INPRO SERVICES REQUESTED\n";
$internal_body .= "Onsite Required:      {$onsite_required}\n";
if ($services_requested) $internal_body .= "Additional Services:  {$services_requested}\n";
if ($service_details)    $internal_body .= "Details:\n{$service_details}\n";

$internal_body .= "\n" . $sep . "\n\n";
$internal_body .= "POLICYHOLDER INFORMATION\n";
$internal_body .= "Insured Name(s):      {$insured_names}\n";
$internal_body .= "Insured Type:         {$insured_type}\n";
$internal_body .= "Contact Allowed:      {$ph_contact_allowed}\n";
$internal_body .= "Loss Address:         {$loss_location}\n";
if ($ph_email)    $internal_body .= "PH Email:             {$ph_email}\n";
if ($ph_mobile)   $internal_body .= "PH Mobile:            {$ph_mobile}\n";
if ($ph_home)     $internal_body .= "PH Home:              {$ph_home}\n";
if ($ph_business) $internal_body .= "PH Business:          {$ph_business}\n";

if ($contractor_company || $contractor_contact || $contractor_email || $contractor_phone) {
    $internal_body .= "\n" . $sep . "\n\n";
    $internal_body .= "CONTRACTOR INFORMATION\n";
    if ($contractor_company) $internal_body .= "Company:              {$contractor_company}\n";
    if ($contractor_contact) $internal_body .= "Contact:              {$contractor_contact}\n";
    if ($contractor_email)   $internal_body .= "Email:                {$contractor_email}\n";
    if ($contractor_phone)   $internal_body .= "Phone:                {$contractor_phone}\n";
}

if ($notes_safety) {
    $internal_body .= "\n" . $sep . "\n\n";
    $internal_body .= "NOTES / SAFETY ISSUES\n";
    $internal_body .= $notes_safety . "\n";
}

$internal_body .= "\n" . $sep . "\n\n";
$internal_body .= "FILES\n";
$internal_body .= $file_label . "\n";
if ($file_count > 0) {
    foreach ($attachments as $f) {
        $internal_body .= "  - {$f['name']}\n";
    }
}

// ─── Adjuster confirmation email ───
$confirm_body  = "Dear {$adj_name},\n\n";
$confirm_body .= "Thank you for submitting your claim file to InPro. We have received your submission and a specialist will review it shortly.\n\n";
$confirm_body .= "SUBMISSION SUMMARY\n";
$confirm_body .= str_repeat('-', 40) . "\n";
$confirm_body .= "Insurance Company:  {$insurance_company}\n";
$confirm_body .= "Claim Number:       {$insurer_claim_number}\n";
$confirm_body .= "Insured:            {$insured_names}\n";
$confirm_body .= "Date of Loss:       {$date_of_loss}\n";
$confirm_body .= "Claim Type:         {$claim_type}\n";
$confirm_body .= "Onsite Required:    {$onsite_required}\n";
$confirm_body .= "Files:              {$file_label}\n";
$confirm_body .= "Submitted:          {$submitted_at}\n\n";
$confirm_body .= "WHAT HAPPENS NEXT\n";
$confirm_body .= str_repeat('-', 40) . "\n";
$confirm_body .= "Our team will review your file and follow up within one business day.\n";
$confirm_body .= "For urgent matters, email claims@inproclaims.com with 'URGENT' in the subject line.\n\n";
$confirm_body .= "---\n";
$confirm_body .= "InPro — Contents Inventory & Valuation Specialists\n";
$confirm_body .= "claims@inproclaims.com | Canada-wide | In practice since 2006\n";

// ─── Build MIME email with attachments ───
function build_mime_email(string $to, string $from_name, string $from_email, string $subject, string $body, array $attachments, string $reply_to = ''): array {
    $boundary = '----InProBoundary_' . md5(uniqid());
    $headers  = "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: " . ($reply_to ?: $from_email) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: InPro-Mailer/1.0\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $body . "\r\n\r\n";

    foreach ($attachments as $file) {
        if (!file_exists($file['path'])) continue;
        $data = base64_encode(file_get_contents($file['path']));
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: {$file['mime']}; name=\"{$file['name']}\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"{$file['name']}\"\r\n\r\n";
        $message .= chunk_split($data) . "\r\n";
    }
    $message .= "--{$boundary}--";

    return ['headers' => $headers, 'message' => $message];
}

// ─── Send internal email (with attachments) ───
$subject_internal = "New Claim — {$insurer_claim_number} — {$insured_names} ({$claim_type})";
$internal = build_mime_email(CLAIMS_EMAIL, FROM_NAME, FROM_EMAIL, $subject_internal, $internal_body, $attachments, "{$adj_name} <{$adj_email}>");
$sent_internal = mail(CLAIMS_EMAIL, $subject_internal, $internal['message'], $internal['headers']);

// ─── Send confirmation to adjuster (no attachments) ───
$subject_confirm = "InPro Claim Received — {$insurer_claim_number}";
$confirm = build_mime_email($adj_email, FROM_NAME, FROM_EMAIL, $subject_confirm, $confirm_body, []);
mail($adj_email, $subject_confirm, $confirm['message'], $confirm['headers']);

// ─── Cleanup temp files ───
foreach ($attachments as $file) {
    if (file_exists($file['path'])) unlink($file['path']);
}
if (is_dir($upload_dir)) rmdir($upload_dir);

// ─── Redirect ───
header($sent_internal ? 'Location: pages/thank-you.html' : 'Location: pages/submit-claim.html?error=1');
exit;
