<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/Config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Throttle.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Csrf.php';
require __DIR__ . '/Wishlist.php';
require __DIR__ . '/PriceFetcher.php';

/** Fehler, deren Meldung dem Nutzer gezeigt werden darf. */
final class UserError extends RuntimeException
{
}

const SESSION_LIFETIME = 60 * 60 * 24 * 365;

function start_session(): void
{
    $dir = Config::dataDir() . '/sessions';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Sitzungsordner kann nicht angelegt werden: ' . $dir);
    }
    ini_set('session.save_path', $dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
    session_name('wunschliste');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header_remove('X-Powered-By');
}
