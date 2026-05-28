<?php
// Deploy a public GitHub repo to AWS Amplify (manual one-click deploy).
// Frontend POSTs JSON: { repo, region, branch?, name?, appId? }
// Returns: { appId, jobId, status, url }
//
// Requires Railway env vars:
//   AWS_ACCESS_KEY_ID
//   AWS_SECRET_ACCESS_KEY

require __DIR__ . '/vendor/autoload.php';

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

$awsKey    = getenv('AWS_ACCESS_KEY_ID');
$awsSecret = getenv('AWS_SECRET_ACCESS_KEY');
if (!$awsKey || !$awsSecret) {
  http_response_code(500);
  echo json_encode(['error' => 'AWS credentials not configured on server']);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action        = trim($input['action'] ?? 'deploy');
$repo          = trim($input['repo'] ?? '');
$region        = trim($input['region'] ?? 'us-east-1');
$branch        = trim($input['branch'] ?? 'main') ?: 'main';
$nameOverride  = trim($input['name'] ?? '');
$existingAppId = trim($input['appId'] ?? '');

// --- DIAG action: report env vars + look up an app's service role ---
if ($action === 'diag') {
  $appId = trim($input['appId'] ?? '');
  $diag = [
    'env' => [
      'AWS_ACCESS_KEY_ID'        => $awsKey ? ('set (…' . substr($awsKey, -4) . ')') : 'NOT SET',
      'AWS_SECRET_ACCESS_KEY'    => $awsSecret ? 'set' : 'NOT SET',
      'GITHUB_TOKEN'             => getenv('GITHUB_TOKEN') ? ('set (…' . substr(getenv('GITHUB_TOKEN'), -4) . ')') : 'NOT SET',
      'AMPLIFY_SERVICE_ROLE_ARN' => getenv('AMPLIFY_SERVICE_ROLE_ARN') ?: 'NOT SET',
    ],
  ];
  if ($appId) {
    try {
      $client = new Aws\Amplify\AmplifyClient([
        'region' => $region, 'version' => 'latest',
        'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
      ]);
      $g = $client->getApp(['appId' => $appId]);
      $diag['app'] = [
        'appId'             => $g['app']['appId'] ?? null,
        'name'              => $g['app']['name'] ?? null,
        'repository'        => $g['app']['repository'] ?? null,
        'iamServiceRoleArn' => $g['app']['iamServiceRoleArn'] ?? '(none — this is the problem)',
        'defaultDomain'     => $g['app']['defaultDomain'] ?? null,
      ];
    } catch (Aws\Exception\AwsException $e) {
      $diag['app_error'] = $e->getAwsErrorMessage() ?: $e->getMessage();
    }
  }
  echo json_encode($diag, JSON_PRETTY_PRINT);
  exit;
}

// --- GITHUB_REPOS action: list all repos for the token owner ---
if ($action === 'github_repos') {
  $token = getenv('GITHUB_TOKEN');
  if (!$token) {
    http_response_code(500);
    echo json_encode(['error' => 'GITHUB_TOKEN not configured on server']);
    exit;
  }
  $all = [];
  $page = 1;
  do {
    $ch = curl_init("https://api.github.com/user/repos?per_page=100&sort=updated&page=$page&affiliation=owner");
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: matcha-dashboard',
      ],
      CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
      http_response_code(500);
      echo json_encode(['error' => 'GitHub API HTTP ' . $code, 'detail' => substr($resp, 0, 200)]);
      exit;
    }
    $batch = json_decode($resp, true) ?: [];
    foreach ($batch as $r) {
      $all[] = [
        'name'        => $r['name'] ?? null,
        'fullName'    => $r['full_name'] ?? null,
        'url'         => $r['html_url'] ?? null,
        'private'     => $r['private'] ?? false,
        'description' => $r['description'] ?? null,
        'updatedAt'   => $r['updated_at'] ?? null,
        'defaultBranch' => $r['default_branch'] ?? 'main',
      ];
    }
    $page++;
  } while (count($batch) === 100 && $page <= 10);
  echo json_encode(['repos' => $all]);
  exit;
}

// --- APP_STATUSES action: latest build status for many apps at once ---
// Input: { apps: [ {appId, region, branch?}, ... ] }
// Returns: { statuses: { appId: {status, jobId, commitMessage, startTime, endTime} } }
if ($action === 'app_statuses') {
  $items = $input['apps'] ?? [];
  $byRegion = [];
  foreach ($items as $it) {
    $r = $it['region'] ?? 'us-east-1';
    $byRegion[$r][] = $it;
  }
  $out = [];
  foreach ($byRegion as $r => $list) {
    try {
      $client = new Aws\Amplify\AmplifyClient([
        'region' => $r, 'version' => 'latest',
        'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
      ]);
      $promises = [];
      foreach ($list as $it) {
        $promises[$it['appId']] = $client->listJobsAsync([
          'appId' => $it['appId'],
          'branchName' => $it['branch'] ?? 'main',
          'maxResults' => 1,
        ]);
      }
      $settled = GuzzleHttp\Promise\Utils::settle($promises)->wait();
      foreach ($settled as $appId => $res) {
        if ($res['state'] === 'fulfilled') {
          $job = $res['value']['jobSummaries'][0] ?? null;
          if ($job) {
            $out[$appId] = [
              'status'        => $job['status'] ?? null,
              'jobId'         => $job['jobId'] ?? null,
              'commitMessage' => $job['commitMessage'] ?? null,
              'startTime'     => isset($job['startTime']) ? $job['startTime']->format(DATE_ATOM) : null,
              'endTime'       => isset($job['endTime']) ? $job['endTime']->format(DATE_ATOM) : null,
            ];
          }
        }
      }
    } catch (Throwable $e) { /* skip region on error */ }
  }
  echo json_encode(['statuses' => $out]);
  exit;
}

// --- STATUS action: poll a deployment job's status ---
if ($action === 'status') {
  $appId = trim($input['appId'] ?? '');
  $jobId = trim($input['jobId'] ?? '');
  $branchName = trim($input['branch'] ?? 'main') ?: 'main';
  if (!$appId || !$jobId) {
    http_response_code(400);
    echo json_encode(['error' => 'appId, jobId required']);
    exit;
  }
  try {
    $client = new Aws\Amplify\AmplifyClient([
      'region'      => $region,
      'version'     => 'latest',
      'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
    ]);
    $res = $client->getJob([
      'appId'      => $appId,
      'branchName' => $branchName,
      'jobId'      => $jobId,
    ]);
    $summary = $res['job']['summary'] ?? [];
    $steps = [];
    foreach (($res['job']['steps'] ?? []) as $s) {
      $steps[] = [
        'name'         => $s['stepName'] ?? null,
        'status'       => $s['status'] ?? null,
        'statusReason' => $s['statusReason'] ?? null,
        'startTime'    => isset($s['startTime']) ? $s['startTime']->format(DATE_ATOM) : null,
        'endTime'      => isset($s['endTime']) ? $s['endTime']->format(DATE_ATOM) : null,
        'logUrl'       => $s['logUrl'] ?? null,
      ];
    }
    echo json_encode([
      'status'             => $summary['status'] ?? null,         // PENDING | PROVISIONING | RUNNING | FAILED | SUCCEED | CANCELLING | CANCELLED
      'jobId'              => $summary['jobId'] ?? $jobId,
      'commitMessage'      => $summary['commitMessage'] ?? null,
      'startTime'          => isset($summary['startTime']) ? $summary['startTime']->format(DATE_ATOM) : null,
      'endTime'            => isset($summary['endTime']) ? $summary['endTime']->format(DATE_ATOM) : null,
      'statusUpdateReason' => $summary['statusUpdateReason'] ?? null,
      'steps'              => $steps,
    ]);
    exit;
  } catch (Aws\Exception\AwsException $e) {
    http_response_code(500);
    echo json_encode([
      'error' => $e->getAwsErrorMessage() ?: $e->getMessage(),
      'code'  => $e->getAwsErrorCode(),
    ]);
    exit;
  }
}

// --- LIST action: list Amplify apps across regions ---
if ($action === 'list') {
  // Default-enabled AWS regions where Amplify Hosting is available.
  // Opt-in regions are deliberately excluded — they return slow STS
  // errors and just slow the scan down without ever finding any apps.
  // Frontend has the opt-in list and hides those options separately.
  $regionsToCheck = $input['regions'] ?? [
    'us-east-1','us-east-2','us-west-1','us-west-2',
    'ca-central-1',
    'sa-east-1',
    'eu-west-1','eu-west-2','eu-west-3','eu-central-1','eu-north-1',
    'ap-south-1',
    'ap-southeast-1','ap-southeast-2',
    'ap-northeast-1','ap-northeast-2','ap-northeast-3',
  ];
  // Tiny server-side disk cache — 30s TTL. Lets multiple users share the
  // same scan result without each one hammering AWS. Falls back to fresh
  // scan if cache file is missing or stale, or if 'force' is passed.
  $force = !empty($input['force']);
  $cachePath = sys_get_temp_dir() . '/amplify_list_cache.json';
  if (!$force && is_file($cachePath) && (time() - filemtime($cachePath)) < 30) {
    readfile($cachePath);
    exit;
  }

  $allApps = [];
  $errors = [];

  // Fire all regions concurrently via async promises with a hard per-region
  // timeout. Wait() with a 20s overall cap so the request never hangs.
  $promises = [];
  $clients = [];
  foreach ($regionsToCheck as $r) {
    try {
      $clients[$r] = new Aws\Amplify\AmplifyClient([
        'region'      => $r,
        'version'     => 'latest',
        'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
        'http'        => ['timeout' => 8, 'connect_timeout' => 4],
        'retries'     => 1,
      ]);
      $promises[$r] = $clients[$r]->listAppsAsync(['maxResults' => 100]);
    } catch (Throwable $e) {
      $errors[$r] = $e->getMessage();
    }
  }
  try {
    $results = GuzzleHttp\Promise\Utils::settle($promises)->wait();
  } catch (Throwable $e) {
    $results = [];
    $errors['_global'] = 'settle wait failed: ' . $e->getMessage();
  }
  foreach ($results as $r => $result) {
    if ($result['state'] === 'fulfilled') {
      $res = $result['value'];
      foreach (($res['apps'] ?? []) as $a) {
        $allApps[] = [
          'appId'         => $a['appId'] ?? null,
          'name'          => $a['name'] ?? null,
          'region'        => $r,
          'defaultDomain' => $a['defaultDomain'] ?? null,
          'repository'    => $a['repository'] ?? null,
          'platform'      => $a['platform'] ?? null,
          'createTime'    => isset($a['createTime']) ? $a['createTime']->format(DATE_ATOM) : null,
          'updateTime'    => isset($a['updateTime']) ? $a['updateTime']->format(DATE_ATOM) : null,
        ];
      }
    } else {
      $reason = $result['reason'];
      $errors[$r] = method_exists($reason, 'getAwsErrorMessage')
        ? ($reason->getAwsErrorMessage() ?: $reason->getMessage())
        : $reason->getMessage();
    }
  }
  $payload = json_encode(['apps' => $allApps, 'errors' => $errors]);
  @file_put_contents($cachePath, $payload);
  echo $payload;
  exit;
}

// --- DELETE action: remove an Amplify app entirely ---
if ($action === 'delete') {
  if (!$existingAppId) {
    http_response_code(400);
    echo json_encode(['error' => 'appId required to delete']);
    exit;
  }
  try {
    $client = new Aws\Amplify\AmplifyClient([
      'region'      => $region,
      'version'     => 'latest',
      'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
    ]);
    $client->deleteApp(['appId' => $existingAppId]);
    @unlink(sys_get_temp_dir() . '/amplify_list_cache.json'); // bust server cache
    echo json_encode(['ok' => true, 'deletedAppId' => $existingAppId]);
    exit;
  } catch (Aws\Exception\AwsException $e) {
    http_response_code(500);
    echo json_encode([
      'error' => $e->getAwsErrorMessage() ?: $e->getMessage(),
      'code'  => $e->getAwsErrorCode(),
    ]);
    exit;
  }
}

// Parse owner/repo from URL like https://github.com/owner/repo(.git)?
if (!preg_match('#^https?://github\.com/([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $repo, $m)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid GitHub URL. Expected https://github.com/owner/repo']);
  exit;
}
$owner = $m[1];
$repoName = $m[2];
$zipUrl = "https://github.com/$owner/$repoName/archive/refs/heads/" . rawurlencode($branch) . ".zip";

$githubToken    = getenv('GITHUB_TOKEN');             // optional — when set, use repo-connected mode
$serviceRoleArn = getenv('AMPLIFY_SERVICE_ROLE_ARN'); // optional — required for builds in repo-connected mode

try {
  $client = new Aws\Amplify\AmplifyClient([
    'region'      => $region,
    'version'     => 'latest',
    'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
  ]);

  $appId = $existingAppId ?: null;
  $defaultDomain = null;
  $usingRepoMode = !empty($githubToken);

  if (!$appId) {
    if ($usingRepoMode) {
      // Repo-connected mode: Amplify clones the repo, auto-detects the
      // framework (Vite/React/Next/Astro/static/etc.) and runs the build.
      // This works for both prebuilt static sites and modern JS apps.
      $createParams = [
        'name'        => $nameOverride ?: $repoName,
        'repository'  => "https://github.com/$owner/$repoName",
        'accessToken' => $githubToken,
        'platform'    => 'WEB',
        'enableBranchAutoBuild' => true,
      ];
      if ($serviceRoleArn) {
        $createParams['iamServiceRoleArn'] = $serviceRoleArn;
      }
      $createRes = $client->createApp($createParams);
    } else {
      // Manual mode (no token configured): static-site zip upload only.
      $createRes = $client->createApp([
        'name'     => $nameOverride ?: $repoName,
        'platform' => 'WEB',
      ]);
    }
    $appId = $createRes['app']['appId'];
    $defaultDomain = $createRes['app']['defaultDomain'];

    $client->createBranch([
      'appId'      => $appId,
      'branchName' => $branch,
      'stage'      => 'PRODUCTION',
      'enableAutoBuild' => $usingRepoMode, // auto-rebuild on git push when connected
    ]);
  } else {
    $g = $client->getApp(['appId' => $appId]);
    $defaultDomain = $g['app']['defaultDomain'];
    // If existing app is repo-connected, stay in repo mode regardless of token
    if (!empty($g['app']['repository'])) $usingRepoMode = true;
    // If the app has no service role but we now have one configured, patch it in
    if ($usingRepoMode && $serviceRoleArn && empty($g['app']['iamServiceRoleArn'])) {
      try { $client->updateApp(['appId' => $appId, 'iamServiceRoleArn' => $serviceRoleArn]); } catch (Exception $e) {}
    }

    try {
      $client->getBranch(['appId' => $appId, 'branchName' => $branch]);
    } catch (Aws\Exception\AwsException $e) {
      if ($e->getAwsErrorCode() === 'NotFoundException') {
        $client->createBranch([
          'appId' => $appId, 'branchName' => $branch,
          'stage' => 'PRODUCTION', 'enableAutoBuild' => $usingRepoMode,
        ]);
      } else { throw $e; }
    }
  }

  // === REPO-CONNECTED MODE: just kick off a RELEASE job and skip zip steps ===
  if ($usingRepoMode) {
    $jobRes = $client->startJob([
      'appId'      => $appId,
      'branchName' => $branch,
      'jobType'    => 'RELEASE',
      'jobReason'  => 'Triggered from dashboard',
    ]);
    echo json_encode([
      'appId'         => $appId,
      'jobId'         => $jobRes['jobSummary']['jobId'] ?? null,
      'status'        => $jobRes['jobSummary']['status'] ?? 'PENDING',
      'url'           => "https://{$branch}.{$defaultDomain}",
      'defaultDomain' => $defaultDomain,
      'consoleUrl'    => "https://{$region}.console.aws.amazon.com/amplify/home?region={$region}#/{$appId}",
      'mode'          => 'repo-connected',
    ]);
    exit;
  }
  // === Otherwise fall through to manual zip-upload flow below ===

  // -------------------------------------------------------------
  // GitHub archive zips wrap files in a top-level folder
  // (e.g. "repo-main/"), which breaks Amplify's root path. So we
  // repack the zip server-side, stripping that wrapper, then PUT
  // it to Amplify's presigned upload URL.
  // -------------------------------------------------------------
  $tmpDir = sys_get_temp_dir();
  $rawZip = tempnam($tmpDir, 'gh_') . '.zip';
  $repackedZip = tempnam($tmpDir, 'rp_') . '.zip';

  try {
    // 1. Download the GitHub zip
    $ghCh = curl_init($zipUrl);
    $fp = fopen($rawZip, 'wb');
    curl_setopt_array($ghCh, [
      CURLOPT_FILE => $fp,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_USERAGENT => 'matcha-deploy/1.0',
    ]);
    if (!curl_exec($ghCh)) {
      throw new Exception('GitHub zip download failed: ' . curl_error($ghCh));
    }
    $ghCode = curl_getinfo($ghCh, CURLINFO_HTTP_CODE);
    curl_close($ghCh);
    fclose($fp);
    if ($ghCode !== 200) {
      throw new Exception("GitHub zip returned HTTP $ghCode (does the branch '$branch' exist on $owner/$repoName?)");
    }

    // 2. Open, detect wrapper folder, rewrite into a flat zip
    $inZip = new ZipArchive();
    if ($inZip->open($rawZip) !== true) {
      throw new Exception('Could not open downloaded zip');
    }
    // Detect common prefix (the wrapper folder, e.g. "repo-main/")
    $prefix = '';
    if ($inZip->numFiles > 0) {
      $first = $inZip->getNameIndex(0);
      $slash = strpos($first, '/');
      if ($slash !== false) {
        $candidate = substr($first, 0, $slash + 1);
        $allShare = true;
        for ($i = 1; $i < $inZip->numFiles; $i++) {
          if (strpos($inZip->getNameIndex($i), $candidate) !== 0) {
            $allShare = false; break;
          }
        }
        if ($allShare) $prefix = $candidate;
      }
    }

    $outZip = new ZipArchive();
    if ($outZip->open($repackedZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      $inZip->close();
      throw new Exception('Could not create repacked zip');
    }
    for ($i = 0; $i < $inZip->numFiles; $i++) {
      $name = $inZip->getNameIndex($i);
      $newName = $prefix && strpos($name, $prefix) === 0 ? substr($name, strlen($prefix)) : $name;
      if ($newName === '' || substr($newName, -1) === '/') continue; // skip dir entries
      $outZip->addFromString($newName, $inZip->getFromIndex($i));
    }
    $inZip->close();
    $outZip->close();

    // 3. Ask Amplify for a presigned upload URL
    $dep = $client->createDeployment([
      'appId'      => $appId,
      'branchName' => $branch,
    ]);
    $uploadUrl = $dep['zipUploadUrl'];
    $jobId     = $dep['jobId'];

    // 4. Upload the repacked zip via HTTP PUT
    $upCh = curl_init($uploadUrl);
    $fp = fopen($repackedZip, 'rb');
    curl_setopt_array($upCh, [
      CURLOPT_PUT => true,
      CURLOPT_INFILE => $fp,
      CURLOPT_INFILESIZE => filesize($repackedZip),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 120,
      CURLOPT_HTTPHEADER => ['Content-Type: application/zip'],
    ]);
    $upResp = curl_exec($upCh);
    $upCode = curl_getinfo($upCh, CURLINFO_HTTP_CODE);
    $upErr = curl_error($upCh);
    curl_close($upCh);
    fclose($fp);
    if ($upCode >= 300) {
      throw new Exception("Upload to Amplify S3 failed: HTTP $upCode $upErr — body: " . substr($upResp, 0, 200));
    }

    // 5. Tell Amplify to deploy the uploaded zip
    $jobRes = $client->startDeployment([
      'appId'      => $appId,
      'branchName' => $branch,
      'jobId'      => $jobId,
    ]);
  } finally {
    @unlink($rawZip);
    @unlink($repackedZip);
  }

  @unlink(sys_get_temp_dir() . '/amplify_list_cache.json'); // bust server cache after new app
  echo json_encode([
    'appId'         => $appId,
    'jobId'         => $jobRes['jobSummary']['jobId'] ?? null,
    'status'        => $jobRes['jobSummary']['status'] ?? 'PENDING',
    'url'           => "https://{$branch}.{$defaultDomain}",
    'defaultDomain' => $defaultDomain,
    'consoleUrl'    => "https://{$region}.console.aws.amazon.com/amplify/home?region={$region}#/{$appId}",
  ]);
} catch (Aws\Exception\AwsException $e) {
  http_response_code(500);
  echo json_encode([
    'error'   => $e->getAwsErrorMessage() ?: $e->getMessage(),
    'code'    => $e->getAwsErrorCode(),
    'type'    => $e->getAwsErrorType(),
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
