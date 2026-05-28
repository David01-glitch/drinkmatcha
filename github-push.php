<?php
// Push files to a GitHub repo using the server-side GITHUB_TOKEN.
// Used by the Phone Tool so users never paste a token.
//
// POST JSON: { repo, branch?, message?, files: [ {path, contentBase64}, ... ] }
// Returns:  { ok, commitUrl, fileCount }

// Never leak HTML errors — always respond JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['error' => 'Server error: ' . $e['message']]);
  }
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(['error' => 'POST only']); exit;
}

$token = getenv('GITHUB_TOKEN');
if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'GITHUB_TOKEN not configured on server']);
  exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$repoUrl = trim($input['repo'] ?? '');
$branch  = trim($input['branch'] ?? 'main') ?: 'main';
$message = trim($input['message'] ?? 'Update phone numbers') ?: 'Update phone numbers';
$files   = $input['files'] ?? [];

if (!preg_match('#github\.com[/:]([^/]+)/([^/\s]+?)(?:\.git)?(?:[/?#].*)?$#i', $repoUrl, $m)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid GitHub repo URL']);
  exit;
}
$owner = $m[1]; $repo = $m[2];
if (!is_array($files) || !count($files)) {
  http_response_code(400);
  echo json_encode(['error' => 'No files to push']);
  exit;
}

function gh($method, $path, $body = null) {
  global $token;
  $ch = curl_init('https://api.github.com' . $path);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $token,
      'Accept: application/vnd.github+json',
      'Content-Type: application/json',
      'User-Agent: matcha-phone-tool',
    ],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = $resp ? json_decode($resp, true) : null;
  if ($code >= 300) {
    throw new Exception(($data['message'] ?? ('HTTP ' . $code)) . ' [' . $method . ' ' . $path . ']');
  }
  return $data;
}

try {
  $base = "/repos/$owner/$repo";

  // 1. Latest commit + base tree of the branch
  $ref = gh('GET', "$base/git/ref/heads/" . rawurlencode($branch));
  $latestSha = $ref['object']['sha'];
  $latestCommit = gh('GET', "$base/git/commits/$latestSha");
  $baseTree = $latestCommit['tree']['sha'];

  // 2. Create a blob per file, build tree items
  $treeItems = [];
  foreach ($files as $f) {
    $path = $f['path'] ?? null;
    $content = $f['contentBase64'] ?? null;
    if (!$path || $content === null) continue;
    $blob = gh('POST', "$base/git/blobs", ['content' => $content, 'encoding' => 'base64']);
    $treeItems[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
  }
  if (!count($treeItems)) throw new Exception('No valid files in payload');

  // 3. Tree (overlaid on existing) -> commit -> move branch ref
  $tree = gh('POST', "$base/git/trees", ['base_tree' => $baseTree, 'tree' => $treeItems]);
  $commit = gh('POST', "$base/git/commits", [
    'message' => $message, 'tree' => $tree['sha'], 'parents' => [$latestSha],
  ]);
  gh('PATCH', "$base/git/refs/heads/" . rawurlencode($branch), ['sha' => $commit['sha']]);

  echo json_encode([
    'ok' => true,
    'fileCount' => count($treeItems),
    'commitUrl' => "https://github.com/$owner/$repo/commit/" . $commit['sha'],
    'branch' => $branch,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
