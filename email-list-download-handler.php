<?php
/* email-list-download-handler.php (secure)
 * Purpose: capture “lead + download” submissions (toolkits, Echoes excerpt)
 * - CSRF + timestamp + honeypot checks
 * - Validates & sanitizes inputs
 * - Appends to CSV; logs events
 * - Emails admin (info@) with BCC; auto-reply to user
 * - Redirects to dl-thank-you.html?file=<slug>
 */

///////////////////// CONFIG ///////////////////////////////////////////////////
const BASE_URL    = 'https://briannjenga.co.ke';   // canonical origin (no trailing /)
$admin_to         = 'info@briannjenga.co.ke';
$admin_bcc        = 'jbnjenga2011@gmail.com';      // optional BCC
$from_mailbox     = 'noreply@briannjenga.co.ke';   // must be authorized in SPF/DKIM/DMARC
$site_name        = 'JBN Content Consultancy';

$storage_dir      = __DIR__ . '/storage';
$csv_file         = $storage_dir . '/leads.csv'; // master CSV
$log_file         = $storage_dir . '/dl.log';    // event log

// Slug -> title + public URL path (leading slash; matches real, case-sensitive paths)
$resources = [
  // Echoes of Valor (adjust only if you moved them)
  'echoes_ch1_pdf'   => ['title' => 'Echoes of Valor — Chapter 1 (PDF)',  'path' => '/assets/pdfs/echoes-ch1.pdf'],
  'echoes_ch1_epub'  => ['title' => 'Echoes of Valor — Chapter 1 (EPUB)', 'path' => '/assets/ebooks/echoes-ch1.epub'],
  'echoes_ch1_mobi'  => ['title' => 'Echoes of Valor — Chapter 1 (MOBI)', 'path' => '/assets/ebooks/echoes-ch1.mobi'],

  // Toolkits (verify exact filenames and case)
  'serp_real_estate_toolkit'                      => ['title' => '📍 SERP Real Estate Toolkit (PDF)',                               'path' => '/toolkits/pdfs/serp-real-estate-toolkit.pdf'],
  'content_value_quadrant'                        => ['title' => '📊 Build a Content Value Quadrant (PDF)',                        'path' => '/toolkits/pdfs/content-value-quadrant.pdf'],
  'omnichannel_starter_stack_for_small_brands'    => ['title' => '📦 Omnichannel Starter Stack (PDF)',                             'path' => '/toolkits/pdfs/omnichannel-starter-stack-for-small-brands.pdf'],
  '10_ways_to_make_your_content_more_bingeable'   => ['title' => '🎬 10 Ways to Make Content More Bingeable (PDF)',                 'path' => '/toolkits/pdfs/10-ways-to-make-your-content-more-bingeable.pdf'],
  'net_positive_business_scorecard'               => ['title' => '🌱 Net Positive Business Scorecard (PDF)',                        'path' => '/toolkits/pdfs/net-positive-business-scorecard.pdf'],
  '5_apps_that_prioritize_digital_wellbeing'      => ['title' => '📱 5 Apps that Prioritize Digital Well-Being (PDF)',             'path' => '/toolkits/pdfs/5-apps-that-prioritize-digital-wellbeing.pdf'],
  'multi_metric_esg'                              => ['title' => '🌍 Beyond Carbon — Multi-Metric ESG (PDF)',                       'path' => '/toolkits/pdfs/multi-metric-esg.pdf'],
  '5_case_studies_of_ethical_pay_to_play'         => ['title' => '✨ Mini Case Studies: Ethical Paid Visibility (PDF)',             'path' => '/toolkits/pdfs/5-case-studies-of-ethical-pay-to-play.pdf'],
  'checklist_10_everyday_actions_for_planetary_personal_health' => ['title' => '🌍✨ Checklist: 10 Everyday Actions for Planetary & Personal Health (PDF)', 'path' => '/toolkits/pdfs/checklist-10-everyday-actions-for-planetary-personal-health.pdf'],
  'return_of_the_lion_case_study'                 => ['title' => '🦁 Return of the Lion — Kenya’s Big Cat Regeneration (PDF)',      'path' => '/toolkits/pdfs/return-of-the-lion-case-study.pdf'],
];

///////////////////// HELPERS /////////////////////////////////////////////////
function clean($v){ return trim(filter_var($v, FILTER_SANITIZE_FULL_SPECIAL_CHARS)); }

function log_line($file,$msg){
  @file_put_contents($file, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND|LOCK_EX);
}

function msubj($s){
  // UTF-8 safe Subject (handles emojis)
  return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
}

// Unified mail sender with envelope sender (-f)
function send_mail($to,$subj,$body,$from,$replyTo=null,$bcc=null,$envelopeFrom=null){
  $h  = "MIME-Version: 1.0\r\n";
  $h .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $h .= "From: $from\r\n";
  if($replyTo){ $h .= "Reply-To: $replyTo\r\n"; }
  if($bcc){     $h .= "Bcc: $bcc\r\n"; }
  if($envelopeFrom){ $h .= "Return-Path: $envelopeFrom\r\n"; }
  $params = $envelopeFrom ? "-f$envelopeFrom" : "";
  return @mail($to, msubj($subj), $body, $h, $params);
}

///////////////////// BASIC GUARDRAILS ///////////////////////////////////////
// POST only
if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
  http_response_code(405);
  exit('Method not allowed');
}

// Session for CSRF
session_start();

// Extract
$name       = clean($_POST['name'] ?? '');
$email_raw  = $_POST['email'] ?? '';
$email      = filter_var($email_raw, FILTER_VALIDATE_EMAIL) ?: '';
$resource   = clean($_POST['resource'] ?? '');
$source     = clean($_POST['source']   ?? 'site_download');
$form_id    = clean($_POST['form_name']?? 'download_form');

// Honeypot (hardened)
$hp = trim($_POST['middle_initial_alt'] ?? '');
if ($hp !== '') {
  log_line($log_file, "HONEYPOT tripped: value=".json_encode($hp)." UA=".($_SERVER['HTTP_USER_AGENT'] ?? '')." IP=".($_SERVER['REMOTE_ADDR'] ?? ''));
  http_response_code(400); exit('Bad bot');
}

// Time-trap (>= 2s since render)
$ts = (int)($_POST['ts'] ?? 0);
if ($ts && (time() - $ts) < 2) { http_response_code(400); exit('Too fast'); }

// CSRF
$csrf_ok = (!empty($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']));
if (!$csrf_ok) { http_response_code(400); exit('Invalid submission'); }

// Validate required
if(!$name || !$email || !$resource || !isset($resources[$resource])){
  http_response_code(400);
  exit('Please complete all required fields.');
}

$ip  = $_SERVER['REMOTE_ADDR'] ?? '';
$ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
$now = date('c');

///////////////////// ENSURE STORAGE /////////////////////////////////////////
if(!is_dir($storage_dir)){ @mkdir($storage_dir, 0755, true); }
if(!file_exists($csv_file)){
  @file_put_contents($csv_file, "date,name,email,resource,ip,ua,source,form\n");
}
if(!file_exists($log_file)){ @touch($log_file); }

///////////////////// WRITE CSV + LOG ////////////////////////////////////////
$row = [$now, $name, $email, $resource, $ip, $ua, $source, $form_id];
$csv_ok = false;
if($f = @fopen($csv_file, 'a')){
  if(fputcsv($f, $row)){ $csv_ok = true; }
  fclose($f);
}
log_line($log_file, ($csv_ok?'CSV append OK':'CSV append FAIL').": $email, $resource");

///////////////////// URL CONSTRUCTION ///////////////////////////////////////
$title = $resources[$resource]['title'];
$path  = $resources[$resource]['path'];          // absolute web path (leading slash)
$full  = BASE_URL . $path;                       // single source of truth

// Optional proactive filesystem check to catch typos early
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
if ($docroot) {
  $fs_path = $docroot . $path;
  if (!@file_exists($fs_path)) {
    log_line($log_file, "WARNING: File not found on disk for {$resource} => {$fs_path}");
  }
}

///////////////////// EMAILS //////////////////////////////////////////////////
$admin_subject = "New download lead: {$title}";
$admin_body = "Lead captured on {$site_name}\n\n"
            . "Name:     {$name}\n"
            . "Email:    {$email}\n"
            . "Resource: {$title} ({$resource})\n"
            . "Link:     {$full}\n"
            . "IP:       {$ip}\n"
            . "UA:       {$ua}\n"
            . "Source:   {$source}\n"
            . "Form ID:  {$form_id}\n"
            . "Time:     {$now}\n";

// Admin notification
if (send_mail(
      $admin_to,
      $admin_subject,
      $admin_body,
      "JBN Content Consultancy <{$from_mailbox}>",  // From
      $email,                                       // Reply-To (lead)
      $admin_bcc,                                   // BCC
      $from_mailbox                                 // envelope sender (-f)
)) {
  log_line($log_file, 'Admin mail sent');
} else {
  log_line($log_file, 'Admin mail FAILED');
}

// User auto-reply
$user_subject = "Your download: {$title}";
$user_body    = "Hi {$name},\n\n"
              . "Thanks for requesting {$title}.\n"
              . "You can download it here:\n{$full}\n\n"
              . "Helpful links:\n"
              . "• Homepage: https://briannjenga.co.ke/index.html\n"
              . "• Blog: https://briannjenga.co.ke/blog/index.html\n"
              . "• Services page: https://briannjenga.co.ke/services.html\n"
              . "• Sustainability page: https://briannjenga.co.ke/sustainability.html\n"
              . "• All free toolkits: https://briannjenga.co.ke/toolkits/index.html\n\n"
              . "-- \n"
              . "JBN Content Consultancy\n"
              . "https://briannjenga.co.ke\n";

if (send_mail(
      $email,
      $user_subject,
      $user_body,
      "JBN Content Consultancy <{$from_mailbox}>",  // From (noreply)
      $admin_to,                                    // Reply-To (info@)
      null,                                         // no BCC
      $from_mailbox                                 // envelope sender (-f)
)) {
  log_line($log_file, 'Auto-reply sent');
} else {
  log_line($log_file, 'Auto-reply FAILED');
}

///////////////////// REDIRECT ///////////////////////////////////////////////
$dest = '/dl-thank-you.html?file='.rawurlencode($resource);
header('Location: ' . $dest, true, 302);
exit;
