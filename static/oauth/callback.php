<?php
require_once 'config.php';

if (!isset($_GET['code'])) {
    die('Error: missing authorization code');
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

    // Return HTML that posts message back to CMS window
    $token = htmlspecialchars($data['access_token']);
    $originUrl = htmlspecialchars($origin);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>OAuth Callback</title>
    </head>
    <body>
        <script>
            // Send token back to the CMS window using postMessage
            const message = {
                token: "<?php echo $token; ?>"
            };
            window.opener.postMessage(message, "<?php echo $originUrl; ?>");
            window.close();
        </script>
        <p>Authenticating... If this page doesn't close, please <a href="<?php echo $originUrl; ?>/admin/">click here</a>.</p>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    error_log('OAuth Error: ' . $e->getMessage());
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>OAuth Error</title>
    </head>
    <body>
        <script>
            const message = {
                error: "<?php echo htmlspecialchars($e->getMessage()); ?>"
            };
            window.opener.postMessage(message, "<?php echo htmlspecialchars($origin); ?>");
            window.close();
        </script>
        <p>Authentication failed. If this page doesn't close, please <a href="<?php echo htmlspecialchars($origin); ?>/admin/">click here</a>.</p>
    </body>
    </html>
    <?php
    exit;
}
?>
