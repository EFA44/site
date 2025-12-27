<?php

if (!function_exists('getEnv')) {
    function getEnv($key, $default = null) {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

function getGithubClientId() {
    $id = getEnv('GITHUB_CLIENT_ID');
    if (!$id) die('Error: GITHUB_CLIENT_ID is missing');
    return $id;
}

function getGithubClientSecret() {
    $secret = getEnv('GITHUB_CLIENT_SECRET');
    if (!$secret) die('Error: GITHUB_CLIENT_SECRET is missing');
    return $secret;
}

function getRedirectUri() {
    $uri = getEnv('REDIRECT_URI');
    if (!$uri) die('Error: REDIRECT_URI is missing');
    return $uri;
}

/**
 * Get CMS origin from state parameter, origin parameter, environment variable, or fallback
 */
function getCmsOrigin() {
    // 1. Priority: state parameter (passed from index.php)
    if (!empty($_GET['state'])) {
        $state = $_GET['state'];
        if (filter_var($state, FILTER_VALIDATE_URL)) {
            return $state;
        }
    }
    
    // 2. Origin parameter (fallback)
    if (!empty($_GET['origin'])) {
        $origin = $_GET['origin'];
        if (filter_var($origin, FILTER_VALIDATE_URL)) {
            return $origin;
        }
    }
    
    // 3. Environment variable
    $envOrigin = getEnv('CMS_ORIGIN');
    if ($envOrigin && filter_var($envOrigin, FILTER_VALIDATE_URL)) {
        return $envOrigin;
    }
    
    // 4. Last resort
    return 'http://localhost:4000';
}

/**
 * Validate if the origin is in the allowed list
 */
function isAllowedOrigin($origin) {
    if (!$origin || !filter_var($origin, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    $allowed = array_map('trim', explode(',', getEnv('ALLOWED_ORIGINS', getEnv('CMS_ORIGIN', 'http://localhost:4000'))));
    return in_array($origin, $allowed, true);
}
?>