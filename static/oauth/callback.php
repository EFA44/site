<?php
/**
 * GitHub OAuth - Callback Handler
 * Handles GitHub's redirect after user authorization
 */

header('Content-Type: application/json');

// Check required environment variables
$clientId = getenv('GITHUB_CLIENT_ID');
$clientSecret = getenv('GITHUB_CLIENT_SECRET');
$cmsOrigin = getenv('CMS_ORIGIN');
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

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle GET request from GitHub redirect
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $code = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? null;
    $error = $_GET['error'] ?? null;
    $errorDescription = $_GET['error_description'] ?? null;
    
    // Check for GitHub authorization errors
    if ($error) {
        http_response_code(400);
        die(json_encode([
            'error' => $error,
            'error_description' => $errorDescription,
        ]));
    }
    
    // Validate code and state
    if (!$code || !$state) {
        http_response_code(400);
        die(json_encode(['error' => 'Missing authorization code or state']));
    }
    
    // Exchange code for access token
    $tokenUrl = 'https://github.com/login/oauth/access_token';
    $postData = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => getenv('REDIRECT_URI'),
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code(400);
        die(json_encode(['error' => 'Failed to exchange code for token']));
    }

    $tokenData = json_decode($response, true);

    if (!isset($tokenData['access_token'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Invalid token response from GitHub']));
    }

    $accessToken = $tokenData['access_token'];

    // Get user info from GitHub
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

    $userResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code(400);
        die(json_encode(['error' => 'Failed to fetch user information']));
    }

    $userData = json_decode($userResponse, true);

    // Return user data and token
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'access_token' => $accessToken,
        'user' => [
            'id' => $userData['id'],
            'login' => $userData['login'],
            'name' => $userData['name'],
            'avatar_url' => $userData['avatar_url'],
            'email' => $userData['email'],
        ],
    ]);
    exit;
}

// Handle POST request for token exchange
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? null;
$state = $input['state'] ?? null;

// Validate code
if (!$code) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing authorization code']));
}

// Exchange code for access token
$tokenUrl = 'https://github.com/login/oauth/access_token';
$postData = [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'redirect_uri' => getenv('REDIRECT_URI'),
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POSTFIELDS => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(400);
    die(json_encode(['error' => 'Failed to exchange code for token']));
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid token response from GitHub']));
}

$accessToken = $tokenData['access_token'];

// Get user info from GitHub
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

$userResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(400);
    die(json_encode(['error' => 'Failed to fetch user information']));
}

$userData = json_decode($userResponse, true);

// Return user data and token
http_response_code(200);
echo json_encode([
    'success' => true,
    'access_token' => $accessToken,
    'user' => [
        'id' => $userData['id'],
        'login' => $userData['login'],
        'name' => $userData['name'],
        'avatar_url' => $userData['avatar_url'],
        'email' => $userData['email'],
    ],
]);
