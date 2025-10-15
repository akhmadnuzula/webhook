<?php
// index.php — acts as router (for php -S and Apache via .htaccess) and home

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = __DIR__ . $uri;

// When running under php -S as a router file, allow static files
if (PHP_SAPI === 'cli-server') {
    if ($uri !== '/' && is_file($path)) {
        return false; // let server handle static
    }
}

// Pretty routes
if (preg_match('~^/hook/([A-Za-z0-9_-]+)/?$~', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/webhook.php';
    exit;
}
if (preg_match('~^/view/([A-Za-z0-9_-]+)/?$~', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/viewer.php';
    exit;
}

// Home / New ID
if ($uri === '/' || $uri === '/index.php' || preg_match('~^/new/?$~', $uri)) {
    require __DIR__ . '/home.php';
    exit;
}

// Fallback 404
http_response_code(404);
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<title>404 Not Found</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<pre>Not Found: <?=htmlspecialchars($uri, ENT_QUOTES, 'UTF-8')?></pre>
