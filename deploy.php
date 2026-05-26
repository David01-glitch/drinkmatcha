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
  $regionsToCheck = $input['regions'] ?? [
    'us-east-1','us-east-2','us-west-2',
    'ca-central-1',
    'eu-west-1','eu-west-2','eu-central-1',
    'ap-south-1','ap-southeast-1','ap-southeast-2',
    'ap-northeast-1','ap-northeast-2',
  ];
  $allApps = [];
  $errors = [];
  foreach ($regionsToCheck as $r) {
    try {
      $client = new Aws\Amplify\AmplifyClient([
        'region'      => $r,
        'version'     => 'latest',
        'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
      ]);
      $next = null;
      do {
        $params = ['maxResults' => 100];
        if ($next) $params['nextToken'] = $next;
        $res = $client->listApps($params);
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
        $next = $res['nextToken'] ?? null;
      } while ($next);
    } catch (Aws\Exception\AwsException $e) {
      $errors[$r] = $e->getAwsErrorMessage() ?: $e->getMessage();
    }
  }
  echo json_encode(['apps' => $allApps, 'errors' => $errors]);
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

try {
  $client = new Aws\Amplify\AmplifyClient([
    'region'      => $region,
    'version'     => 'latest',
    'credentials' => ['key' => $awsKey, 'secret' => $awsSecret],
  ]);

  $appId = $existingAppId ?: null;
  $defaultDomain = null;

  if (!$appId) {
    // Create a fresh Amplify app for manual deploys.
    $createRes = $client->createApp([
      'name'     => $nameOverride ?: $repoName,
      'platform' => 'WEB',
      // No 'repository' / 'accessToken' — keeps us in manual deploy mode,
      // no GitHub App needed.
    ]);
    $appId = $createRes['app']['appId'];
    $defaultDomain = $createRes['app']['defaultDomain'];

    // Create the branch (Amplify needs one before deployments).
    $client->createBranch([
      'appId'      => $appId,
      'branchName' => $branch,
      'stage'      => 'PRODUCTION',
      'enableAutoBuild' => false,
    ]);
  } else {
    $g = $client->getApp(['appId' => $appId]);
    $defaultDomain = $g['app']['defaultDomain'];

    // Ensure branch exists.
    try {
      $client->getBranch(['appId' => $appId, 'branchName' => $branch]);
    } catch (Aws\Exception\AwsException $e) {
      if ($e->getAwsErrorCode() === 'NotFoundException') {
        $client->createBranch([
          'appId' => $appId, 'branchName' => $branch,
          'stage' => 'PRODUCTION', 'enableAutoBuild' => false,
        ]);
      } else { throw $e; }
    }
  }

  // Trigger the deployment using the public GitHub archive URL.
  $jobRes = $client->startDeployment([
    'appId'      => $appId,
    'branchName' => $branch,
    'sourceUrl'  => $zipUrl,
  ]);

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
