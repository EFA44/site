<?php
/**
 * GitHub OAuth - Authorization Endpoint
 * Redirects user to GitHub login
 */

// Check required environment variables
$clientId = getenv('GITHUB_CLIENT_ID');
$redirectUri = getenv('REDIRECT_URI');

if (!$clientId || !$redirectUri) {
    http_response_code(500);
    die(json_encode(['error' => 'Missing required environment variables']));
}

// Get the origin/scope from query parameters
$scope = isset($_GET['scope']) ? $_GET['scope'] : 'user:email';
$state = bin2hex(random_bytes(16));

// Store state in session for validation
session_start();
$_SESSION['oauth_state'] = $state;

// Build GitHub authorization URL
$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scope,
    'state' => $state,
];

$authUrl = 'https://github.com/login/oauth/authorize?' . http_build_query($params);

// Redirect to GitHub
header('Location: ' . $authUrl);
exit;
