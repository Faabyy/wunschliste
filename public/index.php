<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/routes.php';

set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Fehler</title><p>Da ist leider etwas schiefgelaufen. Bitte versuch es gleich noch einmal.</p>';
});

enforce_https();
send_security_headers();
start_session();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') {
    $method = 'GET';
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
$base = base_path();
if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
    $path = substr($path, strlen($base)) ?: '/';
}
if ($path !== '/') {
    $path = rtrim($path, '/');
}

$routes = [
    ['GET',  '#^/$#',                                   'route_home'],
    ['POST', '#^/anmelden$#',                           'route_login'],
    ['POST', '#^/abmelden$#',                           'route_logout'],
    ['GET',  '#^/listen$#',                             'route_people'],
    ['GET',  '#^/liste/(\d+)$#',                        'route_list'],
    ['GET',  '#^/schenken$#',                           'route_gifts'],
    ['GET',  '#^/sichtbarkeit$#',                       'route_visibility'],
    ['POST', '#^/sichtbarkeit$#',                       'route_visibility_save'],
    ['POST', '#^/eintrag$#',                            'route_item_create'],
    ['GET',  '#^/eintrag/(\d+)$#',                      'route_item_edit'],
    ['POST', '#^/eintrag/(\d+)$#',                      'route_item_update'],
    ['POST', '#^/eintrag/(\d+)/loeschen$#',             'route_item_delete'],
    ['POST', '#^/eintrag/(\d+)/(hoch|runter)$#',        'route_item_move'],
    ['POST', '#^/eintrag/(\d+)/reservieren$#',          'route_reserve'],
    ['POST', '#^/eintrag/(\d+)/freigeben$#',            'route_release'],
    ['POST', '#^/eintrag/(\d+)/gekauft$#',              'route_purchased'],
    ['POST', '#^/hinweis/(\d+)/ok$#',                   'route_notice_dismiss'],
    ['GET',  '#^/bild/([a-f0-9]{32})$#',                'route_image'],
    ['GET',  '#^/verwaltung$#',                         'route_admin'],
    ['POST', '#^/verwaltung/anmelden$#',                'route_admin_login'],
    ['POST', '#^/verwaltung/abmelden$#',                'route_admin_logout'],
    ['POST', '#^/verwaltung/konto$#',                   'route_admin_create'],
    ['POST', '#^/verwaltung/konto/(\d+)$#',             'route_admin_rename'],
    ['POST', '#^/verwaltung/konto/(\d+)/status$#',      'route_admin_status'],
    ['POST', '#^/verwaltung/konto/(\d+)/loeschen$#',    'route_admin_delete'],
];

$pathMatched = false;
foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if (preg_match($pattern, $path, $m) !== 1) {
        continue;
    }
    $pathMatched = true;
    if ($routeMethod !== $method) {
        continue;
    }
    if ($method === 'POST') {
        Csrf::check();
    }
    $handler(...array_slice($m, 1));
    exit;
}

if ($pathMatched) {
    header('Allow: ' . ($method === 'GET' ? 'POST' : 'GET'));
    abort(405, 'Diese Aktion geht so nicht.');
}
abort(404, 'Diese Seite gibt es nicht.');
