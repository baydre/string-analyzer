<?php
// Router script for PHP built-in server
// Serves static files directly and routes all other requests to index.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Allow direct access to swagger.yaml and docs.html
if ($uri === '/swagger.yaml' || $uri === '/docs.html') {
    return false; // Serve as static file
}

// If the request points to an existing file or directory, let the server handle it
if ($uri !== '/' && file_exists($file)) {
    return false;
}

// Otherwise, route everything through index.php
require __DIR__ . '/index.php';
