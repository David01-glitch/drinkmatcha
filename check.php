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

// GTM / GA4 container
// Accept any input — extract the ID (GTM-XXXX or G-XXXXXXXXXX) from whatever was pasted
$rawExpected = $_GET['gtm'] ?? '';
$expectedId = '';
if (preg_match('/(GTM-[A-Z0-9]{4,}|G-[A-Z0-9]{6,}|UA-[0-9]{4,}-[0-9]+)/i', $rawExpected, $em)) {
  $expectedId = strtoupper($em[1]);
}

preg_match_all('/(GTM-[A-Z0-9]{4,}|G-[A-Z0-9]{6,}|UA-[0-9]{4,}-[0-9]+)/i', $html, $allIds);
$foundIds = array_values(array_unique(array_map('strtoupper', $allIds[0])));
$anyTag = !empty($foundIds) || strpos($lower, 'googletagmanager.com') !== false;

if ($expectedId) {
  $checks['c_gtm'] = in_array($expectedId, $foundIds, true);
  if ($checks['c_gtm']) {
    $evidence['c_gtm'] = "Found expected {$expectedId}";
  } elseif (!empty($foundIds)) {
    $evidence['c_gtm'] = "Expected {$expectedId}, found " . implode(', ', $foundIds);
  } else {
    $evidence['c_gtm'] = "Expected {$expectedId}, none found on page";
  }
} else {
  $checks['c_gtm'] = $anyTag;
  $evidence['c_gtm'] = !empty($foundIds) ? 'Found ' . implode(', ', $foundIds) : ($anyTag ? 'gtag/gtm script found' : 'No GTM/GA tag');
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

// Page detection: check both (a) links on the homepage AND (b) probe common direct URLs
$baseHost = rtrim(preg_replace('/(\?.*|#.*)$/', '', $main['url']), '/');
$base = preg_replace('/\/[^\/]*\.[a-z]{2,5}$/i', '', $baseHost); // strip trailing /page.html
if (!preg_match('/\/$/', $base) && parse_url($base, PHP_URL_PATH) === null) $base .= '';

function probeExists($base, $paths, $timeout = 5) {
  foreach ($paths as $p) {
    $u = rtrim($base, '/') . $p;
    if (function_exists('curl_init')) {
      $ch = curl_init($u);
      curl_setopt_array($ch, [
        CURLOPT_NOBODY => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MatchaDashboard/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
      ]);
      $body = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($code >= 200 && $code < 400 && $body && strlen(strip_tags($body)) > 200) {
        return ['found' => true, 'path' => $p, 'code' => $code];
      }
    }
  }
  return ['found' => false];
}

$pageProbes = [
  'c_privacy' => ['/privacy', '/privacy.html', '/privacy-policy', '/privacy-policy.html', '/privacypolicy.html'],
  'c_terms' => ['/terms', '/terms.html', '/terms-of-service', '/terms-and-conditions', '/tos', '/tos.html', '/terms-conditions.html'],
  'c_about' => ['/about', '/about.html', '/about-us', '/about-us.html', '/aboutus.html'],
  'c_contact' => ['/contact', '/contact.html', '/contact-us', '/contact-us.html', '/contactus.html'],
  'c_refund' => ['/refund', '/refund.html', '/refund-policy', '/refund-policy.html', '/return', '/return.html', '/return-policy', '/return-policy.html', '/shipping-and-returns.html'],
];

$linkPatterns = [
  'c_privacy' => ['/privacy/i'],
  'c_terms' => ['/terms[\s\-_]?(of|&|and)?[\s\-_]?(use|service|conditions)?/i', '/\btos\b/i', '/conditions/i'],
  'c_about' => ['/about/i'],
  'c_contact' => ['/contact/i', '/get[\s\-_]?in[\s\-_]?touch/i'],
  'c_refund' => ['/refund/i', '/return[\s\-_]?policy/i', '/shipping[\s\-_]?&?[\s\-_]?return/i'],
];

foreach ($pageProbes as $key => $paths) {
  $hasLinkResult = hasLink($linksJoined, $linkPatterns[$key]);
  if ($hasLinkResult) {
    $checks[$key] = true;
    $evidence[$key] = 'Link found in nav';
    continue;
  }
  $probe = probeExists($base, $paths);
  $checks[$key] = $probe['found'];
  $evidence[$key] = $probe['found'] ? "Page exists at {$probe['path']}" : 'Not found in links or common URLs';
}

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
