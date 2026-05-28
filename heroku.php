<?php
// Heroku deploy automation — mirrors deploy.php (Amplify) but for Heroku.
// Frontend POSTs JSON: { action, repo, region, branch?, name?, appId? }
// Actions: deploy | status | list | delete | diag
//
// Requires Railway env var:
//   HEROKU_API_KEY

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'POST only']);
  exit;
}

$apiKey = getenv('HEROKU_API_KEY');

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($input['action'] ?? 'deploy');

// --- Helper: call the Heroku Platform API ---
function heroku($method, $path, $body = null) {
  global $apiKey;
  $ch = curl_init('https://api.heroku.com' . $path);
  $headers = [
    'Authorization: Bearer ' . $apiKey,
    'Accept: application/vnd.heroku+json; version=3',
    'Content-Type: application/json',
  ];
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
  ];
  if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return ['code' => $code, 'body' => $resp ? json_decode($resp, true) : null, 'raw' => $resp, 'curlErr' => $err];
}

if (!$apiKey) {
  http_response_code(500);
  echo json_encode(['error' => 'HEROKU_API_KEY not configured on server']);
  exit;
}

// ---------------------- DIAG ----------------------
if ($action === 'diag') {
  $acct = heroku('GET', '/account');
  echo json_encode([
    'env' => ['HEROKU_API_KEY' => 'set (…' . substr($apiKey, -4) . ')'],
    'account' => $acct['code'] === 200
      ? ['email' => $acct['body']['email'] ?? null, 'id' => $acct['body']['id'] ?? null]
      : ['error' => $acct['body']['message'] ?? ('HTTP ' . $acct['code'])],
  ], JSON_PRETTY_PRINT);
  exit;
}

// ---------------------- LIST ----------------------
if ($action === 'list') {
  $res = heroku('GET', '/apps');
  if ($res['code'] !== 200) {
    http_response_code(500);
    echo json_encode(['error' => $res['body']['message'] ?? ('HTTP ' . $res['code'])]);
    exit;
  }
  $apps = [];
  foreach (($res['body'] ?? []) as $a) {
    $apps[] = [
      'appId'      => $a['id'] ?? null,
      'name'       => $a['name'] ?? null,
      'region'     => $a['region']['name'] ?? null,
      'webUrl'     => $a['web_url'] ?? null,
      'gitUrl'     => $a['git_url'] ?? null,
      'createdAt'  => $a['created_at'] ?? null,
      'updatedAt'  => $a['updated_at'] ?? null,
      'stack'      => $a['stack']['name'] ?? null,
    ];
  }
  echo json_encode(['apps' => $apps]);
  exit;
}

// ---------------------- DELETE ----------------------
if ($action === 'delete') {
  $appId = trim($input['appId'] ?? '');
  if (!$appId) {
    http_response_code(400);
    echo json_encode(['error' => 'appId (or app name) required to delete']);
    exit;
  }
  $res = heroku('DELETE', '/apps/' . rawurlencode($appId));
  if ($res['code'] >= 300) {
    http_response_code(500);
    echo json_encode(['error' => $res['body']['message'] ?? ('HTTP ' . $res['code'])]);
    exit;
  }
  echo json_encode(['ok' => true, 'deletedAppId' => $appId]);
  exit;
}

// ---------------------- STATUS ----------------------
if ($action === 'status') {
  $appId   = trim($input['appId'] ?? '');
  $buildId = trim($input['buildId'] ?? '');
  if (!$appId || !$buildId) {
    http_response_code(400);
    echo json_encode(['error' => 'appId, buildId required']);
    exit;
  }
  $res = heroku('GET', '/apps/' . rawurlencode($appId) . '/builds/' . rawurlencode($buildId));
  if ($res['code'] >= 300) {
    http_response_code(500);
    echo json_encode(['error' => $res['body']['message'] ?? ('HTTP ' . $res['code'])]);
    exit;
  }
  $b = $res['body'];
  echo json_encode([
    'status'      => $b['status'] ?? null,   // pending | building | succeeded | failed
    'buildId'     => $b['id'] ?? $buildId,
    'outputUrl'   => $b['output_stream_url'] ?? null,
    'releaseId'   => $b['release']['id'] ?? null,
    'webUrl'      => $b['app'] ? ('https://' . ($b['app']['name'] ?? '') . '.herokuapp.com') : null,
  ]);
  exit;
}

// ---------------------- DEPLOY (default) ----------------------
$repo         = trim($input['repo'] ?? '');
$region       = trim($input['region'] ?? 'us');   // 'us' | 'eu'
$branch       = trim($input['branch'] ?? 'main') ?: 'main';
$nameOverride = trim($input['name'] ?? '');
$existingApp  = trim($input['appId'] ?? '');

if (!preg_match('#^https?://github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $repo, $m)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid GitHub URL. Expected https://github.com/owner/repo']);
  exit;
}
$owner = $m[1];
$repoName = $m[2];
// Heroku fetches the tarball directly — it handles the wrapper folder itself.
$tarballUrl = "https://github.com/$owner/$repoName/tarball/" . rawurlencode($branch);

// Heroku app names: lowercase letters, numbers, dashes, must start with a letter, 3-30 chars
$desiredName = strtolower($nameOverride ?: $repoName);
$desiredName = preg_replace('/[^a-z0-9-]/', '-', $desiredName);
$desiredName = preg_replace('/-+/', '-', trim($desiredName, '-'));
if (!preg_match('/^[a-z]/', $desiredName)) $desiredName = 'm-' . $desiredName;
$desiredName = substr($desiredName, 0, 26) . '-' . substr(bin2hex(random_bytes(2)), 0, 3);

try {
  $appName = $existingApp ?: null;

  if (!$appName) {
    // Create the app
    $create = heroku('POST', '/apps', ['name' => $desiredName, 'region' => $region]);
    if ($create['code'] >= 300) {
      throw new Exception($create['body']['message'] ?? ('Create app failed: HTTP ' . $create['code']));
    }
    $appName = $create['body']['name'];
  }

  // Trigger a build from the GitHub tarball
  $build = heroku('POST', '/apps/' . rawurlencode($appName) . '/builds', [
    'source_blob' => ['url' => $tarballUrl, 'version' => $branch],
  ]);
  if ($build['code'] >= 300) {
    throw new Exception($build['body']['message'] ?? ('Build start failed: HTTP ' . $build['code']));
  }

  echo json_encode([
    'appId'   => $appName,
    'buildId' => $build['body']['id'] ?? null,
    'status'  => $build['body']['status'] ?? 'pending',
    'url'     => "https://{$appName}.herokuapp.com",
    'consoleUrl' => "https://dashboard.heroku.com/apps/{$appName}",
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
