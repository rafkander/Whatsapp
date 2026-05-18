<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve existing files/directories directly
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Root redirect
if ($uri === '/' || $uri === '') {
    header('Location: /dashboard/');
    exit;
}

// SPA: serve dashboard/index.html for all /dashboard/* routes
if ($uri === '/dashboard' || $uri === '/dashboard/' || strpos($uri, '/dashboard/') === 0) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/dashboard/index.html');
    exit;
}

return false;
