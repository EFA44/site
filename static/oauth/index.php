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
    private $debug_log_file;
    private $debug_enabled;
    
    public function __construct() {
        $this->github_client_id = $_ENV['GITHUB_CLIENT_ID'] ?? $_SERVER['GITHUB_CLIENT_ID'] ?? '';
        $this->github_client_secret = $_ENV['GITHUB_CLIENT_SECRET'] ?? $_SERVER['GITHUB_CLIENT_SECRET'] ?? '';
        $this->allowed_domains = $_ENV['ALLOWED_DOMAINS'] ?? $_SERVER['ALLOWED_DOMAINS'] ?? '';
        $this->github_hostname = $_ENV['GITHUB_HOSTNAME'] ?? $_SERVER['GITHUB_HOSTNAME'] ?? 'github.com';
        
        // Debug logging enabled via DEBUG_OAUTH environment variable
        $this->debug_enabled = !empty($_ENV['DEBUG_OAUTH'] ?? $_SERVER['DEBUG_OAUTH'] ?? false);
        
        // Set debug log file in same directory as this script
        $this->debug_log_file = __DIR__ . '/debug.log';
    }
    
    /**
     * Write debug message to log file (only if DEBUG_OAUTH is enabled)
     */
    private function debugLog($message) {
        if (!$this->debug_enabled) {
            return;
        }
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";
        @file_put_contents($this->debug_log_file, $log_message, FILE_APPEND);
        error_log($message);
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
        
        $this->debugLog("OAuth Debug Callback - Code: " . ($code ? 'received' : 'missing'));
        $this->debugLog("OAuth Debug Callback - State: " . ($state ? 'received' : 'missing'));
        $this->debugLog("OAuth Debug Callback - Cookies: " . json_encode($_COOKIE));
        
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
            $this->debugLog("OAuth Debug Callback - Invalid CSRF cookie format: " . $csrf_cookie);
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
            $this->debugLog("OAuth Debug Callback - CSRF mismatch! State: $state, Token: $csrf_token");
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
            $this->debugLog("OAuth Debug Callback - fetchToken returned false");
            return $this->outputHTML([
                'provider' => $provider,
                'error' => 'Failed to request an access token. Please try again later.',
                'errorCode' => 'TOKEN_REQUEST_FAILED'
            ]);
        }
        
        $data = @json_decode($response, true);
        
        if (!$data) {
            $this->debugLog("OAuth Debug Callback - JSON decode failed. Response: " . $response);
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
            $this->debugLog("OAuth Debug Callback - Token error: " . $error);
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
        $this->debugLog("OAuth Debug - Fetching token from: " . $token_url);
        $this->debugLog("OAuth Debug - Request body: " . json_encode($request_body));
        
        $json_body = json_encode($request_body);
        
        $context_options = [
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'User-Agent: Sveltia-CMS-Auth-PHP'
                ],
                'content' => $json_body,
                'timeout' => 10,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        $context = stream_context_create($context_options);
        
        $this->debugLog("OAuth Debug - Stream context created");
        
        $response = @file_get_contents($token_url, false, $context);
        
        if ($response === false) {
            $this->debugLog("OAuth Debug - Token request failed! (file_get_contents returned false)");
            $this->debugLog("OAuth Debug - HTTP response headers: " . json_encode($http_response_header ?? []));
            
            // Try alternative: use cURL if available
            if (function_exists('curl_init')) {
                $this->debugLog("OAuth Debug - Attempting with cURL as fallback");
                return $this->fetchTokenWithCurl($token_url, $request_body);
            }
        } else {
            $this->debugLog("OAuth Debug - Token response: " . $response);
        }
        
        return $response;
    }
    
    /**
     * Fallback: Fetch access token using cURL
     */
    private function fetchTokenWithCurl($token_url, $request_body) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Sveltia-CMS-Auth-PHP'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        
        $this->debugLog("OAuth Debug cURL - Response: " . ($response ? 'received' : 'false'));
        $this->debugLog("OAuth Debug cURL - Error number: " . $curl_errno);
        $this->debugLog("OAuth Debug cURL - Error message: " . $curl_error);
        
        curl_close($ch);
        
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
        
        // Remove base path (/oauth/)
        $base = '/oauth/';
        if (strpos($path, $base) === 0) {
            $path = '/' . substr($path, strlen($base)); // Extract path after /oauth/
        }
        
        // Remove index.php if present
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, 10);
        }
        
        // Normalize: ensure single leading slash, no trailing slash (but keep single /)
        if (empty($path) || $path === '/') {
            return '/';
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        
        return $path;
    }
    
    /**
     * Route request to appropriate handler
     */
    public function route() {
        $path = $this->getRequestPath();
        
        // Debug output
        $this->debugLog("OAuth Debug - REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
        $this->debugLog("OAuth Debug - PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'N/A'));
        $this->debugLog("OAuth Debug - Parsed path: " . $path);
        $this->debugLog("OAuth Debug - REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
        $this->debugLog("OAuth Debug - GET params: " . json_encode($_GET));
        
        // Route to handlers
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Match /auth or /authorize (path has /oauth/ stripped)
            if (in_array($path, ['/auth', '/authorize'])) {
                $this->debugLog("OAuth Debug - Routing to handleAuth");
                return $this->handleAuth();
            }
            // Match /callback or /redirect (path has /oauth/ stripped)
            elseif (in_array($path, ['/callback', '/redirect'])) {
                $this->debugLog("OAuth Debug - Routing to handleCallback");
                return $this->handleCallback();
            }
        }
        
        // 404 response
        $this->debugLog("OAuth Debug - No route matched! Path: " . $path . ", Method: " . $_SERVER['REQUEST_METHOD']);
        http_response_code(404);
        echo '';
    }
}

// Initialize and route request
$handler = new SveltiaCMSAuthHandler();
$handler->route();
