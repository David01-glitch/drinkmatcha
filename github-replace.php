<?php
// Replace a repo's contents with an uploaded zip — with one-click restore.
// Before replacing, the current HEAD is saved to a hidden backup branch
// (refs/heads/_backup_<branch>), so "restore" can roll it back.
//
// multipart/form-data:
//   action  = replace | restore
//   repo    = https://github.com/owner/repo
//   branch  = main
//   zip     = the new content (only for action=replace)
// Uses server-side GITHUB_TOKEN.

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }

$token = getenv('GITHUB_TOKEN');
if (!$token) { http_response_code(500); echo json_encode(['error' => 'GITHUB_TOKEN not configured']); exit; }

$action  = trim($_POST['action'] ?? 'replace');
$repoUrl = trim($_POST['repo'] ?? '');
$branch  = trim($_POST['branch'] ?? 'main') ?: 'main';

if (!preg_match('~github\.com[/:]([^/]+)/([^/\s]+?)(?:\.git)?(?:[/?#].*)?$~i', $repoUrl, $m)) {
  http_response_code(400); echo json_encode(['error' => 'Invalid GitHub repo URL']); exit;
}
$owner = $m[1]; $repo = $m[2];
$base = "/repos/$owner/$repo";
$backupBranch = '_backup_' . $branch;

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
      'User-Agent: matcha-html-tool',
    ],
    CURLOPT_TIMEOUT => 120,
    CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data = $resp ? json_decode($resp, true) : null;
  if ($code >= 300) throw new Exception(($data['message'] ?? ('HTTP ' . $code)) . " [$method $path]");
  return $data;
}
function ghTry($method, $path, $body = null) { try { return gh($method, $path, $body); } catch (Throwable $e) { return null; } }

try {
  // ---------------- RESTORE ----------------
  if ($action === 'restore') {
    $bref = ghTry('GET', "$base/git/ref/heads/" . rawurlencode($backupBranch));
    if (!$bref) { http_response_code(400); echo json_encode(['error' => 'No backup found for this branch — nothing to restore.']); exit; }
    $backupSha = $bref['object']['sha'];
    $backupCommit = gh('GET', "$base/git/commits/$backupSha");
    $backupTree = $backupCommit['tree']['sha'];

    $cur = gh('GET', "$base/git/ref/heads/" . rawurlencode($branch));
    $curSha = $cur['object']['sha'];

    $commit = gh('POST', "$base/git/commits", [
      'message' => 'Restore previous content (reverse replace)',
      'tree' => $backupTree,
      'parents' => [$curSha],
    ]);
    gh('PATCH', "$base/git/refs/heads/" . rawurlencode($branch), ['sha' => $commit['sha']]);

    echo json_encode([
      'ok' => true,
      'commitUrl' => "https://github.com/$owner/$repo/commit/" . $commit['sha'],
      'branch' => $branch,
      'restored' => true,
    ]);
    exit;
  }

  // ---------------- REPLACE ----------------
  if (empty($_FILES['zip']) || ($_FILES['zip']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No zip uploaded (it may be too large).']);
    exit;
  }
  $zip = new ZipArchive();
  if ($zip->open($_FILES['zip']['tmp_name']) !== true) { http_response_code(400); echo json_encode(['error' => 'Could not open zip']); exit; }
  // strip wrapper folder
  $prefix = '';
  if ($zip->numFiles > 0) {
    $first = $zip->getNameIndex(0); $slash = strpos($first, '/');
    if ($slash !== false) {
      $cand = substr($first, 0, $slash + 1); $all = true;
      for ($i = 1; $i < $zip->numFiles; $i++) { if (strpos($zip->getNameIndex($i), $cand) !== 0) { $all = false; break; } }
      if ($all) $prefix = $cand;
    }
  }
  $files = [];
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false || substr($name, -1) === '/') continue;
    if (strpos($name, '__MACOSX/') === 0 || basename($name) === '.DS_Store') continue;
    $path = $prefix && strpos($name, $prefix) === 0 ? substr($name, strlen($prefix)) : $name;
    if ($path === '') continue;
    $c = $zip->getFromIndex($i);
    if ($c === false) continue;
    $files[] = ['path' => $path, 'b64' => base64_encode($c)];
  }
  $zip->close();
  if (!count($files)) { http_response_code(400); echo json_encode(['error' => 'Zip had no files']); exit; }

  // Read current HEAD (may be empty repo)
  $curSha = null; $isEmpty = false;
  $cur = ghTry('GET', "$base/git/ref/heads/" . rawurlencode($branch));
  if ($cur) { $curSha = $cur['object']['sha']; }
  else { $isEmpty = true; }

  // Back up current HEAD to the hidden backup branch (only if repo has content)
  $backedUp = false;
  if (!$isEmpty && $curSha) {
    $existingBackup = ghTry('GET', "$base/git/ref/heads/" . rawurlencode($backupBranch));
    if ($existingBackup) {
      gh('PATCH', "$base/git/refs/heads/" . rawurlencode($backupBranch), ['sha' => $curSha, 'force' => true]);
    } else {
      gh('POST', "$base/git/refs", ['ref' => 'refs/heads/' . $backupBranch, 'sha' => $curSha]);
    }
    $backedUp = true;
  }

  // Bootstrap empty repo via Contents API so the Git Data API works
  if ($isEmpty) {
    $bootstrap = $files[0];
    $encPath = implode('/', array_map('rawurlencode', explode('/', $bootstrap['path'])));
    gh('PUT', "$base/contents/$encPath", ['message' => 'Init', 'content' => $bootstrap['b64'], 'branch' => $branch]);
    $cur = gh('GET', "$base/git/ref/heads/" . rawurlencode($branch));
    $curSha = $cur['object']['sha'];
  }

  // Clean replace: new tree WITHOUT base_tree -> repo = exactly the zip files
  $pushOnce = function () use ($base, $files, $curSha, $branch) {
    $treeItems = [];
    foreach ($files as $f) {
      $blob = gh('POST', "$base/git/blobs", ['content' => $f['b64'], 'encoding' => 'base64']);
      $treeItems[] = ['path' => $f['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
    }
    $tree = gh('POST', "$base/git/trees", ['tree' => $treeItems]); // no base_tree = full replace
    $commit = gh('POST', "$base/git/commits", [
      'message' => 'Replace repo content', 'tree' => $tree['sha'], 'parents' => $curSha ? [$curSha] : [],
    ]);
    gh('PATCH', "$base/git/refs/heads/" . rawurlencode($branch), ['sha' => $commit['sha']]);
    return ['commit' => $commit, 'count' => count($treeItems)];
  };

  $attempt = 0; $res = null;
  while ($attempt < 4) {
    try { $res = $pushOnce(); break; }
    catch (Throwable $e) {
      $msg = $e->getMessage();
      $transient = stripos($msg, 'authentication') !== false || stripos($msg, 'empty') !== false || stripos($msg, '409') !== false || stripos($msg, '401') !== false;
      if (!$transient || $attempt === 3) throw $e;
      sleep(2 + $attempt); $attempt++;
    }
  }

  echo json_encode([
    'ok' => true,
    'fileCount' => $res['count'],
    'commitUrl' => "https://github.com/$owner/$repo/commit/" . $res['commit']['sha'],
    'branch' => $branch,
    'backedUp' => $backedUp,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
