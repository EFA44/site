<?php
/**
 * Sveltia CMS OAuth Handler for PHP
 * 
 * Handles GitHub OAuth authentication for Sveltia CMS
 * Based on: https://github.com/sveltia/sveltia-cms-auth
 * 
 * Environment variables required:
 * - GITHUB_CLIENT_ID
 * - GITHUB_CLIENT_SECRET
 * - ALLOWED_DOMAINS (optional, comma-separated)
 */

class SveltiaCMSAuthHandler {
    private $github_client_id;
    private $github_client_secret;
    private $allowed_domains;
    private $github_hostname;
    
    public function __construct() {
        $this->github_client_id = $_ENV['GITHUB_CLIENT_ID'] ?? $_SERVER['GITHUB_CLIENT_ID'] ?? '';
        $this->github_client_secret = $_ENV['GITHUB_CLIENT_SECRET'] ?? $_SERVER['GITHUB_CLIENT_SECRET'] ?? '';
        $this->allowed_domains = $_ENV['ALLOWED_DOMAINS'] ?? $_SERVER['ALLOWED_DOMAINS'] ?? '';
        $this->github_hostname = $_ENV['GITHUB_HOSTNAME'] ?? $_SERVER['GITHUB_HOSTNAME'] ?? 'github.com';
    }
    
    /**
     * Escape string for use in regex pattern
     */
    private function escapeRegExp($str) {
        return preg_quote($str, '/');
    }
    
    /**
     * Check if domain is allowed
     */
    private function isDomainAllowed($domain) {
        if (empty($this->allowed_domains)) {
            return true;
        }
        
        $allowed_list = array_map('trim', explode(',', $this->allowed_domains));
        
        foreach ($allowed_list as $pattern) {
            // Convert wildcard pattern to regex
            $regex_pattern = str_replace('\\*', '.+', $this->escapeRegExp($pattern));
            if (preg_match("/^{$regex_pattern}$/", $domain ?? '')) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate CSRF token (32 random hex chars)
     */
    private function generateCsrfToken() {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Output HTML response that communicates with window opener
     */
    private function outputHTML($args = []) {
        $provider = $args['provider'] ?? 'unknown';
        $token = $args['token'] ?? null;
        $error = $args['error'] ?? null;
        $errorCode = $args['errorCode'] ?? null;
        
        $state = $error ? 'error' : 'success';
        
        if ($error) {
            $content = json_encode([
                'provider' => $provider,
                'error' => $error,
                'errorCode' => $errorCode
            ]);
        } else {
            $content = json_encode([
                'provider' => $provider,
                'token' => $token
            ]);
        }
        
        // Clear CSRF token
        header("Set-Cookie: csrf-token=deleted; HttpOnly; Max-Age=0; Path=/; SameSite=Lax; Secure", false);
        header('Content-Type: text/html; charset=UTF-8');
        
        echo <<<HTML
<!doctype html>
<html>
<body>
<script>
(() => {
  window.addEventListener('message', ({ data, origin }) => {
    if (data === 'authorizing:$provider') {
      window.opener?.postMessage(
        'authorization:$provider:$state:$content',
        origin
      );
    }
  });
  window.opener?.postMessage('authorizing:$provider', '*');
})();
</script>
</body>
</html>
HTML;
    }
    
    /**
     * Handle the auth request (first step of OAuth flow)
     */
    private function handleAuth() {
        $provider = $_GET['provider'] ?? null;
        $site_id = $_GET['site_id'] ?? null; // domain
        
        if (!$provider || $provider !== 'github') {
            return $this->outputHTML([
                'error' => 'Your Git backend is not supported by the authenticator.',
                'errorCode' => 'UNSUPPORTED_BACKEND'
            ]);
        }
        
        // Check if domain is allowed
        if (!$this->isDomainAllowed($site_id)) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'Your domain is not allowed to use the authenticator.',
                'errorCode' => 'UNSUPPORTED_DOMAIN'
            ]);
        }
        
        // Check if credentials are configured
        if (!$this->github_client_id || !$this->github_client_secret) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'OAuth app client ID or secret is not configured.',
                'errorCode' => 'MISCONFIGURED_CLIENT'
            ]);
        }
        
        // Generate CSRF token
        $csrf_token = $this->generateCsrfToken();
        
        // Build authorization URL
        $auth_params = [
            'client_id' => $this->github_client_id,
            'redirect_uri' => $this->getBaseUrl() . '/callback',
            'scope' => 'repo,user',
            'state' => $csrf_token
        ];
        
        $auth_url = 'https://' . $this->github_hostname . '/login/oauth/authorize?' . http_build_query($auth_params);
        
        // Set CSRF token cookie (expires in 10 minutes)
        header("Set-Cookie: csrf-token=github_{$csrf_token}; HttpOnly; Path=/; Max-Age=600; SameSite=Lax; Secure", false);
        
        // Redirect to GitHub authorization
        header('Location: ' . $auth_url);
        exit;
    }
    
    /**
     * Handle the callback request (second step of OAuth flow)
     */
    private function handleCallback() {
        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;
        
        // Get CSRF token from cookie
        $csrf_cookie = $_COOKIE['csrf-token'] ?? null;
        
        if (!$csrf_cookie) {
            return $this->outputHTML([
                'error' => 'Potential CSRF attack detected. Authentication flow aborted.',
                'errorCode' => 'CSRF_DETECTED'
            ]);
        }
        
        // Parse cookie: format is "github_<token>"
        if (!preg_match('/^github_([0-9a-f]{32})$/', $csrf_cookie, $matches)) {
            return $this->outputHTML([
                'provider' => 'github',
                'error' => 'Potential CSRF attack detected. Authentication flow aborted.',
                'errorCode' => 'CSRF_DETECTED'
            ]);
        }
        
        $provider = 'github';
        $csrf_token = $matches[1];
        
        // Validate authorization code and state
        if (!$code || !$state) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'Failed to receive an authorization code. Please try again later.',
                'errorCode' => 'AUTH_CODE_REQUEST_FAILED'
            ]);
        }
        
        // Verify CSRF token matches state parameter
        if ($state !== $csrf_token) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'Potential CSRF attack detected. Authentication flow aborted.',
                'errorCode' => 'CSRF_DETECTED'
            ]);
        }
        
        // Exchange authorization code for access token
        $token_url = 'https://' . $this->github_hostname . '/login/oauth/access_token';
        
        $request_body = [
            'code' => $code,
            'client_id' => $this->github_client_id,
            'client_secret' => $this->github_client_secret
        ];
        
        $response = $this->fetchToken($token_url, $request_body);
        
        if ($response === false) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'Failed to request an access token. Please try again later.',
                'errorCode' => 'TOKEN_REQUEST_FAILED'
            ]);
        }
        
        $data = @json_decode($response, true);
        
        if (!$data) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'Server responded with malformed data. Please try again later.',
                'errorCode' => 'MALFORMED_RESPONSE'
            ]);
        }
        
        $token = $data['access_token'] ?? '';
        $error = $data['error'] ?? '';
        
        // Clear CSRF token
        header("Set-Cookie: csrf-token=deleted; HttpOnly; Max-Age=0; Path=/; SameSite=Lax; Secure", false);
        
        if ($error || !$token) {
            return $this->outputHTML([
                'provider' => $provider,
                'error' => $error ?: 'Failed to obtain access token.',
                'errorCode' => 'TOKEN_REQUEST_FAILED'
            ]);
        }
        
        return $this->outputHTML([
            'provider' => $provider,
            'token' => $token
        ]);
    }
    
    /**
     * Fetch access token from GitHub
     */
    private function fetchToken($token_url, $request_body) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'User-Agent: Sveltia-CMS-Auth-PHP'
                ],
                'content' => json_encode($request_body),
                'timeout' => 10
            ]
        ]);
        
        $response = @file_get_contents($token_url, false, $context);
        return $response;
    }
    
    /**
     * Get base URL of the OAuth handler
     */
    private function getBaseUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        $base_path = dirname($_SERVER['REQUEST_URI']);
        
        // Remove /index.php from path if present
        $base_path = str_replace('/index.php', '', $base_path);
        if (substr($base_path, -1) === '/') {
            $base_path = rtrim($base_path, '/');
        }
        
        return $protocol . '://' . $host . $base_path;
    }
    
    /**
     * Get the request path, handling various server configurations
     */
    private function getRequestPath() {
        // Try REQUEST_URI first
        if (!empty($_SERVER['REQUEST_URI'])) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        } elseif (!empty($_SERVER['PATH_INFO'])) {
            $path = $_SERVER['PATH_INFO'];
        } elseif (!empty($_SERVER['ORIG_PATH_INFO'])) {
            $path = $_SERVER['ORIG_PATH_INFO'];
        } else {
            return '/';
        }
        
        // Remove base path (static/oauth/)
        $base = '/oauth/';
        if (strpos($path, $base) === 0) {
            $path = substr($path, strlen($base) - 1); // Keep the leading /
        }
        
        // Remove index.php if present
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, 10);
            if (empty($path)) {
                $path = '/';
            }
        }
        
        // Normalize: ensure single leading slash, no trailing slash
        $path = '/' . trim($path, '/');
        
        return $path;
    }
    
    /**
     * Route request to appropriate handler
     */
    public function route() {
        $path = $this->getRequestPath();
        
        // Route to handlers
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Match /auth or /oauth/authorize or just /authorize
            if (in_array($path, ['/', '/auth', '/authorize', '/oauth/authorize'])) {
                return $this->handleAuth();
            }
            // Match /callback or /oauth/callback or /redirect or /oauth/redirect
            elseif (in_array($path, ['/callback', '/oauth/callback', '/redirect', '/oauth/redirect'])) {
                return $this->handleCallback();
            }
        }
        
        // 404 response
        http_response_code(404);
        echo '';
    }
}

// Initialize and route request
$handler = new SveltiaCMSAuthHandler();
$handler->route();
