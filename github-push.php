<?php
// Push a ZIP's contents to a GitHub repo using the server-side GITHUB_TOKEN.
// Used by the Phone Tool so users never paste a token.
//
// Accepts multipart/form-data:
//   repo     = https://github.com/owner/repo
//   branch   = main
//   message  = commit message
//   zip      = the .zip file (binary upload)
// Returns: { ok, commitUrl, fileCount, branch }

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

$repoUrl    = trim($_POST['repo'] ?? '');
$branch     = trim($_POST['branch'] ?? 'main') ?: 'main';
$message    = trim($_POST['message'] ?? 'Update phone numbers') ?: 'Update phone numbers';
$replaceAll = ($_POST['replaceAll'] ?? '0') === '1';

if (!preg_match('~github\.com[/:]([^/]+)/([^/\s]+?)(?:\.git)?(?:[/?#].*)?$~i', $repoUrl, $m)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid GitHub repo URL']);
  exit;
}
$owner = $m[1]; $repo = $m[2];

if (empty($_FILES['zip']) || ($_FILES['zip']['error'] ?? 1) !== UPLOAD_ERR_OK) {
  $code = $_FILES['zip']['error'] ?? 'none';
  http_response_code(400);
  echo json_encode(['error' => 'No zip uploaded (upload error code: ' . $code . '). The file may be too large.']);
  exit;
}

// --- Open the uploaded zip and collect text+binary files ---
$zip = new ZipArchive();
if ($zip->open($_FILES['zip']['tmp_name']) !== true) {
  http_response_code(400);
  echo json_encode(['error' => 'Could not open the uploaded zip']);
  exit;
}

// Detect a common wrapper folder (e.g. "site-main/") so files land at repo root
$prefix = '';
if ($zip->numFiles > 0) {
  $first = $zip->getNameIndex(0);
  $slash = strpos($first, '/');
  if ($slash !== false) {
    $cand = substr($first, 0, $slash + 1);
    $all = true;
    for ($i = 1; $i < $zip->numFiles; $i++) {
      if (strpos($zip->getNameIndex($i), $cand) !== 0) { $all = false; break; }
    }
    if ($all) $prefix = $cand;
  }
}

$files = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
  $name = $zip->getNameIndex($i);
  if ($name === false || substr($name, -1) === '/') continue;      // skip dirs
  if (strpos($name, '__MACOSX/') === 0) continue;                  // skip mac junk
  if (basename($name) === '.DS_Store') continue;
  $path = $prefix && strpos($name, $prefix) === 0 ? substr($name, strlen($prefix)) : $name;
  if ($path === '') continue;
  $content = $zip->getFromIndex($i);
  if ($content === false) continue;
  $files[] = ['path' => $path, 'b64' => base64_encode($content)];
}
$zip->close();

if (!count($files)) {
  http_response_code(400);
  echo json_encode(['error' => 'Zip had no files to push']);
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
    CURLOPT_TIMEOUT => 120,
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

  // Try to read the existing branch. If the repo is empty (no commits) or
  // the branch doesn't exist yet, we'll bootstrap it first.
  $latestSha = null;
  $baseTree = null;
  $isEmpty = false;
  try {
    $ref = gh('GET', "$base/git/ref/heads/" . rawurlencode($branch));
    $latestSha = $ref['object']['sha'];
    $latestCommit = gh('GET', "$base/git/commits/$latestSha");
    $baseTree = $latestCommit['tree']['sha'];
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'empty') !== false || stripos($msg, 'Not Found') !== false || stripos($msg, '409') !== false || stripos($msg, '404') !== false) {
      $isEmpty = true; // first commit to a fresh repo / new branch
    } else {
      throw $e;
    }
  }

  // GitHub's Git Data API (blobs/trees) cannot operate on a repo with zero
  // commits. Bootstrap an empty repo by creating the first file via the
  // Contents API, which DOES work on empty repos and creates the initial
  // commit + branch. Then continue with the efficient single-commit flow.
  $wasEmpty = $isEmpty;
  if ($isEmpty) {
    $bootstrap = $files[0];
    $encPath = implode('/', array_map('rawurlencode', explode('/', $bootstrap['path'])));
    gh('PUT', "$base/contents/$encPath", [
      'message' => $message . ' (init)',
      'content' => $bootstrap['b64'],
      'branch'  => $branch,
    ]);
    // Re-read the freshly created branch so we have a base for the full commit
    $ref = gh('GET', "$base/git/ref/heads/" . rawurlencode($branch));
    $latestSha = $ref['object']['sha'];
    $latestCommit = gh('GET', "$base/git/commits/$latestSha");
    $baseTree = $latestCommit['tree']['sha'];
    $isEmpty = false; // branch now exists — use PATCH below
  }

  // After bootstrapping a brand-new repo, GitHub's git backend needs a
  // moment before the Git Data API works (else it 401s "Requires
  // authentication"). The closure below is retried a few times on those
  // transient errors. Blobs/trees/commits are idempotent, so retrying is safe.
  $doGitPush = function () use ($base, $files, $baseTree, $replaceAll, $latestSha, $message, $branch, $isEmpty) {
    $treeItems = [];
    foreach ($files as $f) {
      $blob = gh('POST', "$base/git/blobs", ['content' => $f['b64'], 'encoding' => 'base64']);
      $treeItems[] = ['path' => $f['path'], 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
    }
    $treeBody = ['tree' => $treeItems];
    if ($baseTree && !$replaceAll) $treeBody['base_tree'] = $baseTree;
    $tree = gh('POST', "$base/git/trees", $treeBody);

    $commitBody = ['message' => $message, 'tree' => $tree['sha']];
    $commitBody['parents'] = $latestSha ? [$latestSha] : [];
    $commit = gh('POST', "$base/git/commits", $commitBody);

    if ($isEmpty) {
      gh('POST', "$base/git/refs", ['ref' => 'refs/heads/' . $branch, 'sha' => $commit['sha']]);
    } else {
      gh('PATCH', "$base/git/refs/heads/" . rawurlencode($branch), ['sha' => $commit['sha']]);
    }
    return ['commit' => $commit, 'count' => count($treeItems)];
  };

  $attempt = 0; $result = null; $lastErr = null;
  while ($attempt < 4) {
    try { $result = $doGitPush(); break; }
    catch (Throwable $e) {
      $lastErr = $e;
      $m = $e->getMessage();
      $transient = stripos($m, 'authentication') !== false || stripos($m, 'empty') !== false || stripos($m, '409') !== false || stripos($m, '401') !== false;
      if (!$transient || $attempt === 3) throw $e;
      sleep(2 + $attempt); // 2s, 3s, 4s backoff while the repo backend warms up
      $attempt++;
    }
  }
  $commit = $result['commit'];
  $treeItems = array_fill(0, $result['count'], 1); // for count in response

  echo json_encode([
    'ok' => true,
    'fileCount' => count($treeItems),
    'commitUrl' => "https://github.com/$owner/$repo/commit/" . $commit['sha'],
    'branch' => $branch,
    'firstCommit' => $wasEmpty,
    'mode' => $replaceAll ? 'replaced-all' : ($wasEmpty ? 'first-commit' : 'overlay'),
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
