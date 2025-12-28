<?php
/**
 * GitHub OAuth - Health Check
 * Verifies OAuth system is running
 */

header('Content-Type: application/json');

$allowedOrigins = explode(',', getenv('ALLOWED_ORIGINS') ?? '');

// CORS headers
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], array_map('trim', $allowedOrigins))) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check environment variables
$required = ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET', 'REDIRECT_URI', 'CMS_ORIGIN'];
$missing = [];

foreach ($required as $var) {
    if (!getenv($var)) {
        $missing[] = $var;
    }
}

if (!empty($missing)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required environment variables',
        'missing' => $missing,
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'message' => 'OAuth system is running',
    'endpoints' => [
        'authorize' => '/oauth/authorize.php',
        'callback' => '/oauth/callback.php',
        'verify' => '/oauth/verify.php',
        'logout' => '/oauth/logout.php',
        'health' => '/oauth/health.php',
    ],
]);
