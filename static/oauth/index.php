<?php
require_once 'config.php';

$origin = getCmsOrigin();

if (!isAllowedOrigin($origin)) {
    http_response_code(403);
    die('Unauthorized origin: ' . htmlspecialchars($origin));
}

// Redirect to GitHub
$githubAuthUrl = sprintf(
    'https://github.com/login/oauth/authorize?client_id=%s&redirect_uri=%s&scope=repo,user&state=%s',
    getGithubClientId(),
    urlencode(getRedirectUri()),
    urlencode($origin)
);

header('Location: ' . $githubAuthUrl);
exit;
?>
