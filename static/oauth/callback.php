<?php
require_once 'config.php';

if (!isset($_GET['code'])) {
    $origin = getCmsOrigin();
    header('Location: ' . $origin . '/admin/?error=missing_code');
    exit;
}

$code = $_GET['code'];
$origin = $_GET['state'] ?? getCmsOrigin();

if (!isAllowedOrigin($origin)) {
    http_response_code(403);
    die('Unauthorized origin: ' . htmlspecialchars($origin));
}

try {
    // Exchange authorization code for access token
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://github.com/login/oauth/access_token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => getGithubClientId(),
            'client_secret' => getGithubClientSecret(),
            'code' => $code,
            'redirect_uri' => getRedirectUri(),
        ]),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['access_token'])) {
        throw new Exception('OAuth failed: ' . json_encode($data));
    }

    // Redirect to CMS with access token (use 'token' parameter for SvetliaCMS)
    $cmsUrl = $origin . '/admin/?token=' . urlencode($data['access_token']);
    header('Location: ' . $cmsUrl);
    exit;

} catch (Exception $e) {
    error_log('OAuth Error: ' . $e->getMessage());
    header('Location: ' . $origin . '/admin/?error=' . urlencode($e->getMessage()));
    exit;
}
?>
