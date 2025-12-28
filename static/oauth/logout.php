<?php
/**
 * GitHub OAuth - Logout
 * Revokes the access token
 */

header('Content-Type: application/json');

// Check required environment variables
$clientId = getenv('GITHUB_CLIENT_ID');
$clientSecret = getenv('GITHUB_CLIENT_SECRET');
$allowedOrigins = explode(',', getenv('ALLOWED_ORIGINS'));

if (!$clientId || !$clientSecret) {
    http_response_code(500);
    die(json_encode(['error' => 'Missing required environment variables']));
}

// CORS headers
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], array_map('trim', $allowedOrigins))) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Get token from request
$input = json_decode(file_get_contents('php://input'), true);
$accessToken = $input['access_token'] ?? null;

// Check Authorization header as fallback
if (!$accessToken && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
    if (count($parts) === 2 && $parts[0] === 'Bearer') {
        $accessToken = $parts[1];
    }
}

if (!$accessToken) {
    http_response_code(401);
    die(json_encode(['error' => 'Missing access token']));
}

// Revoke token with GitHub
$revokeUrl = 'https://api.github.com/applications/' . $clientId . '/grant';
$ch = curl_init($revokeUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'DELETE',
    CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
    CURLOPT_POSTFIELDS => json_encode(['access_token' => $accessToken]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
        'User-Agent: efa44-oauth',
    ],
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// GitHub returns 204 on success
if ($httpCode !== 204 && $httpCode !== 200) {
    http_response_code(400);
    die(json_encode(['error' => 'Failed to revoke token']));
}

http_response_code(200);
echo json_encode(['success' => true]);
