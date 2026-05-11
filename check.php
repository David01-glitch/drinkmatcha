<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$url = $_GET['url'] ?? '';
if (!filter_var($url, FILTER_VALIDATE_URL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid URL']);
  exit;
}

function fetchUrl($url, $timeout = 10) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MatchaDashboard/1.0)',
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return ['body' => $body ?: '', 'code' => $code, 'url' => $finalUrl];
  }
  $ctx = stream_context_create([
    'http' => [
      'timeout' => $timeout,
      'follow_location' => 1,
      'user_agent' => 'Mozilla/5.0 (compatible; MatchaDashboard/1.0)',
      'ignore_errors' => true,
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
  ]);
  $body = @file_get_contents($url, false, $ctx);
  return ['body' => $body ?: '', 'code' => 200, 'url' => $url];
}

$main = fetchUrl($url);
$html = $main['body'];
$lower = strtolower($html);
$fetched = $html !== '';

$checks = [];
$evidence = [];

// HTTPS / SSL
$checks['c_ssl'] = strpos($url, 'https://') === 0 && ($main['code'] === 0 || ($main['code'] >= 200 && $main['code'] < 400));
$evidence['c_ssl'] = $checks['c_ssl'] ? 'HTTPS OK' : 'Not HTTPS or SSL error';

// GTM container
$expectedGtm = strtoupper(trim($_GET['gtm'] ?? ''));
preg_match_all('/GTM-[A-Z0-9]{4,}/i', $html, $allGtm);
$foundIds = array_unique(array_map('strtoupper', $allGtm[0]));
$anyGtm = !empty($foundIds) || strpos($lower, 'googletagmanager.com') !== false;

if ($expectedGtm) {
  $checks['c_gtm'] = in_array($expectedGtm, $foundIds, true);
  if ($checks['c_gtm']) {
    $evidence['c_gtm'] = "Found expected {$expectedGtm}";
  } elseif (!empty($foundIds)) {
    $evidence['c_gtm'] = "Expected {$expectedGtm}, found " . implode(', ', $foundIds);
  } else {
    $evidence['c_gtm'] = "Expected {$expectedGtm}, none found";
  }
} else {
  $checks['c_gtm'] = $anyGtm;
  $evidence['c_gtm'] = !empty($foundIds) ? 'Found ' . implode(', ', $foundIds) : ($anyGtm ? 'gtm.js found' : 'No GTM tag');
}

// GTM events (dataLayer push)
$checks['c_gtm_events'] = strpos($lower, 'datalayer') !== false && (strpos($lower, 'datalayer.push') !== false || strpos($lower, 'datalayer =') !== false);
$evidence['c_gtm_events'] = $checks['c_gtm_events'] ? 'dataLayer found' : 'No dataLayer';

// Favicon + meta title/description
$hasFavicon = preg_match('/<link[^>]*rel=["\'](?:shortcut\s+)?icon["\']/i', $html);
$hasTitle = preg_match('/<title>\s*([^<]{2,})\s*<\/title>/i', $html);
$hasDesc = preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $html);
$checks['c_favicon'] = $hasFavicon && $hasTitle && $hasDesc;
$missing = [];
if (!$hasFavicon) $missing[] = 'favicon';
if (!$hasTitle) $missing[] = 'title';
if (!$hasDesc) $missing[] = 'description';
$evidence['c_favicon'] = $missing ? 'Missing: ' . implode(', ', $missing) : 'All present';

// Mobile viewport
$checks['c_mobile'] = (bool)preg_match('/<meta[^>]*name=["\']viewport["\']/i', $html);
$evidence['c_mobile'] = $checks['c_mobile'] ? 'Viewport meta tag found' : 'No viewport meta';

// Cookie banner
$cookieHit = preg_match('/cookie[^<>]{0,40}(consent|banner|accept|policy)/i', $html)
  || strpos($lower, 'accept cookies') !== false
  || strpos($lower, 'we use cookies') !== false
  || strpos($lower, 'cookie-consent') !== false
  || strpos($lower, 'cookieconsent') !== false;
$checks['c_cookie'] = (bool)$cookieHit;
$evidence['c_cookie'] = $cookieHit ? 'Cookie banner detected' : 'No cookie banner';

// Extract all links and their text
preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $links);
$allLinks = [];
foreach ($links[1] as $i => $href) {
  $text = strip_tags($links[2][$i]);
  $allLinks[] = strtolower($href . ' ' . $text);
}
$linksJoined = implode(' | ', $allLinks);

function hasLink($joined, $patterns) {
  foreach ($patterns as $p) {
    if (preg_match($p, $joined)) return true;
  }
  return false;
}

$checks['c_privacy'] = hasLink($linksJoined, ['/privacy/i']);
$checks['c_terms'] = hasLink($linksJoined, ['/terms[\s\-_]?(of|&|and)?[\s\-_]?(use|service|conditions)?/i', '/\btos\b/i', '/conditions/i']);
$checks['c_about'] = hasLink($linksJoined, ['/about/i']);
$checks['c_contact'] = hasLink($linksJoined, ['/contact/i', '/get[\s\-_]?in[\s\-_]?touch/i']);
$checks['c_refund'] = hasLink($linksJoined, ['/refund/i', '/return[\s\-_]?policy/i', '/shipping[\s\-_]?&?[\s\-_]?return/i']);

$evidence['c_privacy'] = $checks['c_privacy'] ? 'Privacy link found' : 'No privacy link';
$evidence['c_terms'] = $checks['c_terms'] ? 'Terms link found' : 'No terms link';
$evidence['c_about'] = $checks['c_about'] ? 'About link found' : 'No about link';
$evidence['c_contact'] = $checks['c_contact'] ? 'Contact link found' : 'No contact link';
$evidence['c_refund'] = $checks['c_refund'] ? 'Refund link found' : 'No refund link';

// Navigation
$hasNav = preg_match('/<nav\b/i', $html);
$linkCount = count($links[0]);
$checks['c_nav'] = $hasNav || $linkCount >= 4;
$evidence['c_nav'] = $checks['c_nav'] ? ($hasNav ? "<nav> + {$linkCount} links" : "{$linkCount} links found") : 'Insufficient navigation';

// Unique content (text length heuristic)
$text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
$textLen = strlen($text);
$checks['c_unique'] = $textLen > 800;
$evidence['c_unique'] = "~{$textLen} chars of text" . ($checks['c_unique'] ? '' : ' (low, may be template)');

// Platform detection from URL
$host = parse_url($url, PHP_URL_HOST) ?? '';
$platform = 'Other';
$appname = '';
if (preg_match('/^([^.]+)\.herokuapp\.com$/i', $host, $hm)) {
  $platform = 'Heroku';
  $appname = $hm[1];
} elseif (preg_match('/\.amplifyapp\.com$/i', $host)) {
  $platform = 'AWS Amplify';
  $parts = explode('.', $host);
  $appname = $parts[0] ?? '';
} elseif (preg_match('/\.vercel\.app$/i', $host)) {
  $platform = 'Other';
  $parts = explode('.', $host);
  $appname = $parts[0] ?? '';
} elseif (preg_match('/\.netlify\.app$/i', $host)) {
  $platform = 'Other';
  $parts = explode('.', $host);
  $appname = $parts[0] ?? '';
}

// Cookie page Yes/No mirrors cookie banner detection
$cookie = $checks['c_cookie'] ? 'Yes' : 'No';

echo json_encode([
  'fetched' => $fetched,
  'http_code' => $main['code'],
  'final_url' => $main['url'],
  'platform' => $platform,
  'appname' => $appname,
  'cookie' => $cookie,
  'checks' => $checks,
  'evidence' => $evidence,
], JSON_PRETTY_PRINT);
