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
$repo          = trim($input['repo'] ?? '');
$region        = trim($input['region'] ?? 'us-east-1');
$branch        = trim($input['branch'] ?? 'main') ?: 'main';
$nameOverride  = trim($input['name'] ?? '');
$existingAppId = trim($input['appId'] ?? '');

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
