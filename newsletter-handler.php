<?php
// newsletter-handler.php
// Hardened CSV append + admin notification + auto-reply + lightweight logging

// --- config ---
$adminTo   = 'info@briannjenga.co.ke';              // receives the notification
$bccTo     = 'jbnjenga2011@gmail.com';              // optional BCC
$fromEmail = 'noreply@briannjenga.co.ke';           // domain mailbox
$siteName  = 'JBN Content Consultancy';

// Absolute path to CSV
$csvPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/').'/storage/newsletter.csv';
// Optional log (handy while testing): /public_html/storage/nl.log
$logPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/').'/storage/nl.log';

// --- mini logger (safe to keep; remove if you prefer) ---
function nl_log($msg){
  global $logPath;
  $line = '['.date('Y-m-d H:i:s')."] $msg\n";
  @error_log($line, 3, $logPath);
}

// Ensure POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Method not allowed';
  nl_log('405 – non-POST request');
  exit;
}

// Grab inputs
$name   = trim($_POST['name']   ?? '');
$email  = trim($_POST['email']  ?? '');
$source = trim($_POST['source'] ?? 'newsletter');

// Validate
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo 'Please provide a name and a valid email.';
  nl_log('422 – invalid input: name="'.$name.'", email="'.$email.'"');
  exit;
}

// Build row
$now = date('c'); // ISO-8601
$ip  = $_SERVER['REMOTE_ADDR'] ?? '';

// Ensure file exists with header
if (!file_exists($csvPath)) {
  // Try to create with header
  if (@file_put_contents($csvPath, "date,name,email,source,ip\n", LOCK_EX) === false) {
    http_response_code(500);
    echo 'Unable to initialize storage.';
    nl_log('500 – failed to create CSV at '.$csvPath);
    exit;
  }
}

// Append safely
$fh = @fopen($csvPath, 'a');
if (!$fh) {
  http_response_code(500);
  echo 'Unable to open storage for writing.';
  nl_log('500 – fopen() failed on CSV: '.$csvPath);
  exit;
}
@flock($fh, LOCK_EX);
fputcsv($fh, [$now, $name, $email, $source, $ip]);
@flock($fh, LOCK_UN);
fclose($fh);
nl_log("CSV append OK: $email");

// --- Send admin notification ---
$subject = "New newsletter signup – $siteName";
$body    = "New subscriber:\n\n".
           "Name:  $name\n".
           "Email: $email\n".
           "Source: $source\n".
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
nl_log('Admin mail '.($sentAdmin ? 'sent' : 'FAILED'));

// --- Send auto-reply ---
$autoSubject = "You’re in! Thanks for subscribing to $siteName";
$autoBody    = "Hi $name,\n\n"
             . "Thanks for joining the $siteName newsletter. You’ll get select posts, "
             . "toolkits, and the historical-fiction updates first.\n\n"
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
nl_log('Auto-reply '.($sentAuto ? 'sent' : 'FAILED'));

// Redirect to thank-you page (adjust path if needed)
header('Location: /nl-thank-you.html');
exit;