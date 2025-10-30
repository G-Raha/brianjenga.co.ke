<?php
// newsletter-handler.php
// Hardened CSV append + admin notification + auto-reply + lightweight logging

// --- config ---
$adminTo   = 'info@briannjenga.co.ke';              // receives the notification
$bccTo     = 'jbnjenga2011@gmail.com';              // optional BCC
$fromEmail = 'noreply@briannjenga.co.ke';           // domain mailbox
$siteName  = 'JBN Content Consultancy';

// Absolute path to CSV
$csvPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/').'/storage/contact.csv';
// Optional log (handy while testing): /public_html/storage/ct.log
$logPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/').'/storage/ct.log';

// --- mini logger (safe to keep; remove if you prefer) ---
function ct_log($msg){
  global $logPath;
  $line = '['.date('Y-m-d H:i:s')."] $msg\n";
  @error_log($line, 3, $logPath);
}

// Ensure POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method not allowed';
  ct_log('405 – non-POST request');
  exit;
}

// Grab inputs
$name   = trim($_POST['name']   ?? '');
$email  = trim($_POST['email']  ?? '');
$source = trim($_POST['source'] ?? 'contact form');
$message = trim($_POST['message'] ?? '');

// Validate
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo 'Please provide a name and a valid email.';
  ct_log('422 – invalid input: name="'.$name.'", email="'.$email.'"');
  exit;
}

// Build row
$now = date('c'); // ISO-8601
$ip  = $_SERVER['REMOTE_ADDR'] ?? '';

// Ensure file exists with header
if (!file_exists($csvPath)) {
  // Try to create with header
  if (@file_put_contents($csvPath, "date,name,email,message,source,ip\n", LOCK_EX) === false) {
    http_response_code(500);
    echo 'Unable to initialize storage.';
    ct_log('500 – failed to create CSV at '.$csvPath);
    exit;
  }
}

// Append safely
$fh = @fopen($csvPath, 'a');
if (!$fh) {
  http_response_code(500);
  echo 'Unable to open storage for writing.';
  ct_log('500 – fopen() failed on CSV: '.$csvPath);
  exit;
}
@flock($fh, LOCK_EX);
fputcsv($fh, [$now, $name, $email, $message, $source, $ip]);
@flock($fh, LOCK_UN);
fclose($fh);
ct_log("CSV append OK: $email");

// --- Send admin notification ---
$subject = "New contact – $siteName";
$body    = "New contact submission:\n\n".
           "Name:  $name\n".
           "Email: $email\n".
           "Source: $source\n".
           "Message: $message\n";
           "IP: $ip\n".
           "When: $now\n";

$headers = [];
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-Type: text/plain; charset=UTF-8";
$headers[] = "From: $siteName <$fromEmail>";
$headers[] = "Reply-To: $email";
if ($bccTo) $headers[] = "Bcc: $bccTo";

// -f sets the envelope sender for deliverability
$sentAdmin = @mail($adminTo, $subject, $body, implode("\r\n", $headers), "-f $fromEmail");
ct_log('Admin mail '.($sentAdmin ? 'sent' : 'FAILED'));

// --- Send auto-reply ---
$autoSubject = "Thanks for Contacting $siteName";
$autoBody    = "Hi $name,\n\n"
             . "Thank you for your interest in collaborating with $siteName. Expect my response, "
             . "in no more than two business days.\n\n"
             . "Useful links:\n"
             . "• Blog: https://briannjenga.co.ke/blog/index.html\n"
             . "• Services Page: https://briannjenga.co.ke/services.html\n"
             . "• Sustainability Page: https://briannjenga.co.ke/sustainability.html\n"
             . "• Echoes of Valor Excerpt: https://briannjenga.co.ke/blog/#echoes\n"
             . "• Free Toolkits: https://briannjenga.co.ke/toolkits/index.html\n\n"
             . "Cheers,\nBrian\n";


$autoHeaders = [];
$autoHeaders[] = "MIME-Version: 1.0";
$autoHeaders[] = "Content-Type: text/plain; charset=UTF-8";
$autoHeaders[] = "From: $siteName <$fromEmail>";
$autoHeaders[] = "Reply-To: $adminTo";

$sentAuto = @mail($email, $autoSubject, $autoBody, implode("\r\n", $autoHeaders), "-f $fromEmail");
ct_log('Auto-reply '.($sentAuto ? 'sent' : 'FAILED'));

// Redirect to thank-you page (adjust path if needed)
header('Location: /thank-you.html');
exit;