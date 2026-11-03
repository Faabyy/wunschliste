<?php
declare(strict_types=1);

// Router für `php -S`, der den Webspace-Fall nachbildet: Der ganze Projektordner liegt unter
// /wunschliste/ im Web, und die beiden .htaccess-Dateien leiten alles nach public/ bzw. index.php.
// Aufruf: php -S 127.0.0.1:8000 tests/webspace_router.php

const PREFIX = '/wunschliste';
$root = dirname(__DIR__);
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== PREFIX && !str_starts_with($path, PREFIX . '/')) {
    http_response_code(404);
    echo 'außerhalb';
    return true;
}

$rest = substr($path, strlen(PREFIX));
// Root-.htaccess: alles nach public/ (ein „/public/…“ bleibt, wie es ist).
$inPublic = str_starts_with($rest, '/public/') ? substr($rest, strlen('/public')) : $rest;
$file = realpath($root . '/public' . $inPublic);

if ($file !== false && is_file($file) && str_starts_with($file, $root . '/public/') && !str_ends_with($file, '.php')) {
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'txt' => 'text/plain'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

// public/.htaccess: alles andere an index.php.
$_SERVER['SCRIPT_NAME'] = PREFIX . '/public/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
chdir($root . '/public');
require $root . '/public/index.php';
