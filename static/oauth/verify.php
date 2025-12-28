<?php
/**
 * GitHub OAuth - Token Verification
 * Verifies and validates access tokens
 */

header('Content-Type: application/json');

// Check required environment variables
$clientId = getenv('GITHUB_CLIENT_ID');
$allowedOrigins = explode(',', getenv('ALLOWED_ORIGINS'));

if (!$clientId) {
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

// Verify token with GitHub
$ch = curl_init('https://api.github.com/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: efa44-oauth',
    ],
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 401) {
    http_response_code(401);
    die(json_encode(['error' => 'Invalid or expired token']));
}

if ($httpCode !== 200) {
    http_response_code(400);
    die(json_encode(['error' => 'Failed to verify token']));
}

$userData = json_decode($response, true);

// Return user data
http_response_code(200);
echo json_encode([
    'success' => true,
    'user' => [
        'id' => $userData['id'],
        'login' => $userData['login'],
        'name' => $userData['name'],
        'avatar_url' => $userData['avatar_url'],
        'email' => $userData['email'],
    ],
]);
