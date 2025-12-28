<?php
/**
 * Sveltia CMS OAuth Handler for GitHub
 * Handles the OAuth authorization and callback flow
 */

// Get request path and method
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/oauth', '', $path);

// Route requests
if ($method === 'GET') {
    if ($path === '/auth' || $path === '/authorize') {
        handleAuth();
    } elseif ($path === '/callback' || $path === '/redirect') {
        handleCallback();
    } else {
        http_response_code(404);
        exit;
    }
} else {
    http_response_code(404);
    exit;
}

/**
 * Output HTML response that communicates with the window opener
 */
function outputHTML($data = []) {
    $provider = $data['provider'] ?? 'unknown';
    $token = $data['token'] ?? null;
    $error = $data['error'] ?? null;
    $errorCode = $data['errorCode'] ?? null;

    $state = $error ? 'error' : 'success';
    
    if ($error) {
        $content = [
            'provider' => $provider,
            'error' => $error,
            'errorCode' => $errorCode
        ];
    } else {
        $content = [
            'provider' => $provider,
            'token' => $token
        ];
    }

    $contentJson = json_encode($content);

    header('Content-Type: text/html;charset=UTF-8');
    // Delete CSRF token
    header('Set-Cookie: csrf-token=deleted; HttpOnly; Max-Age=0; Path=/; SameSite=Lax; Secure', false);

    echo <<<HTML
<!doctype html>
<html>
<body>
<script>
(() => {
    window.addEventListener('message', ({ data, origin }) => {
        if (data === 'authorizing:{$provider}') {
            window.opener?.postMessage(
                'authorization:{$provider}:{$state}:{$contentJson}',
                origin
            );
        }
    });
    window.opener?.postMessage('authorizing:{$provider}', '*');
})();
</script>
</body>
</html>
HTML;
    exit;
}

/**
 * Escape string for use in regex
 */
function escapeRegExp($str) {
    return preg_quote($str, '/');
}

/**
 * Check if domain matches allowed domains pattern
 */
function isDomainAllowed($domain, $allowedDomains) {
    if (empty($allowedDomains)) {
        return true;
    }

    $domains = array_map('trim', explode(',', $allowedDomains));
    
    foreach ($domains as $pattern) {
        // Convert wildcard to regex
        $pattern = escapeRegExp($pattern);
        $pattern = str_replace('\\*', '.+', $pattern);
        $pattern = "/^{$pattern}$/";
        
        if (preg_match($pattern, $domain ?? '')) {
            return true;
        }
    }

    return false;
}

/**
 * Generate random CSRF token
 */
function generateCSRFToken() {
    return bin2hex(random_bytes(16));
}

/**
 * Handle the auth method (first request in authorization flow)
 */
function handleAuth() {
    $provider = $_GET['provider'] ?? null;
    $domain = $_GET['site_id'] ?? null;

    // Get environment variables
    $githubClientId = getenv('GITHUB_CLIENT_ID');
    $githubClientSecret = getenv('GITHUB_CLIENT_SECRET');
    $allowedOrigins = getenv('ALLOWED_ORIGINS');
    $cmsOrigin = getenv('CMS_ORIGIN');

    // Check provider
    $supportedProviders = ['github', 'gitlab'];
    if (!$provider || !in_array($provider, $supportedProviders)) {
        outputHTML([
            'error' => 'Your Git backend is not supported by the authenticator.',
            'errorCode' => 'UNSUPPORTED_BACKEND'
        ]);
    }

    // Check if domain is allowed (optional validation)
    if (!empty($allowedOrigins) && !isDomainAllowed($domain, $allowedOrigins)) {
        outputHTML([
            'provider' => $provider,
            'error' => 'Your domain is not allowed to use the authenticator.',
            'errorCode' => 'UNSUPPORTED_DOMAIN'
        ]);
    }

    // GitHub OAuth
    if ($provider === 'github') {
        if (empty($githubClientId) || empty($githubClientSecret)) {
            outputHTML([
                'provider' => 'github',
                'error' => 'OAuth app client ID or secret is not configured.',
                'errorCode' => 'MISCONFIGURED_CLIENT'
            ]);
        }

        // Generate CSRF token
        $csrfToken = generateCSRFToken();

        // Build authorization URL
        $params = [
            'client_id' => $githubClientId,
            'redirect_uri' => $cmsOrigin . '/oauth/callback',
            'response_type' => 'code',
            'scope' => 'repo,user',
            'state' => $csrfToken
        ];

        $authUrl = 'https://github.com/login/oauth/authorize?' . http_build_query($params);

        // Set CSRF token cookie
        setcookie(
            'csrf-token',
            'github_' . $csrfToken,
            [
                'expires' => time() + 600, // 10 minutes
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => true
            ]
        );

        // Redirect to authorization server
        header('Location: ' . $authUrl);
        exit;
    }

    // Unsupported provider
    outputHTML([
        'provider' => $provider,
        'error' => 'Your Git backend is not supported by the authenticator.',
        'errorCode' => 'UNSUPPORTED_BACKEND'
    ]);
}

/**
 * Handle the callback method (second request in authorization flow)
 */
function handleCallback() {
    $code = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? null;

    // Get environment variables
    $githubClientId = getenv('GITHUB_CLIENT_ID');
    $githubClientSecret = getenv('GITHUB_CLIENT_SECRET');
    $cmsOrigin = getenv('CMS_ORIGIN');

    // Extract CSRF token from cookie
    $csrfCookie = $_COOKIE['csrf-token'] ?? null;
    $provider = null;
    $csrfToken = null;

    if ($csrfCookie && preg_match('/^([a-z-]+?)_([0-9a-f]{32})$/', $csrfCookie, $matches)) {
        $provider = $matches[1];
        $csrfToken = $matches[2];
    }

    // Validate provider
    $supportedProviders = ['github', 'gitlab'];
    if (!$provider || !in_array($provider, $supportedProviders)) {
        outputHTML([
            'error' => 'Your Git backend is not supported by the authenticator.',
            'errorCode' => 'UNSUPPORTED_BACKEND'
        ]);
    }

    // Validate authorization code and state
    if (empty($code) || empty($state)) {
        outputHTML([
            'provider' => $provider,
            'error' => 'Failed to receive an authorization code. Please try again later.',
            'errorCode' => 'AUTH_CODE_REQUEST_FAILED'
        ]);
    }

    // Validate CSRF token
    if (empty($csrfToken) || $state !== $csrfToken) {
        outputHTML([
            'provider' => $provider,
            'error' => 'Potential CSRF attack detected. Authentication flow aborted.',
            'errorCode' => 'CSRF_DETECTED'
        ]);
    }

    // GitHub token exchange
    if ($provider === 'github') {
        if (empty($githubClientId) || empty($githubClientSecret)) {
            outputHTML([
                'provider' => 'github',
                'error' => 'OAuth app client ID or secret is not configured.',
                'errorCode' => 'MISCONFIGURED_CLIENT'
            ]);
        }

        $tokenUrl = 'https://github.com/login/oauth/access_token';
        $requestBody = [
            'code' => $code,
            'client_id' => $githubClientId,
            'client_secret' => $githubClientSecret
        ];

        // Make request to GitHub API
        $response = null;
        $token = '';
        $error = '';

        try {
            $ch = curl_init($tokenUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                throw new Exception('cURL error');
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                outputHTML([
                    'provider' => $provider,
                    'error' => 'Server responded with malformed data. Please try again later.',
                    'errorCode' => 'MALFORMED_RESPONSE'
                ]);
            }

            $token = $data['access_token'] ?? '';
            $error = $data['error'] ?? '';

        } catch (Exception $e) {
            outputHTML([
                'provider' => $provider,
                'error' => 'Failed to request an access token. Please try again later.',
                'errorCode' => 'TOKEN_REQUEST_FAILED'
            ]);
        }

        outputHTML([
            'provider' => $provider,
            'token' => $token,
            'error' => $error
        ]);
    }

    // Unsupported provider
    outputHTML([
        'provider' => $provider,
        'error' => 'Your Git backend is not supported by the authenticator.',
        'errorCode' => 'UNSUPPORTED_BACKEND'
    ]);
}
