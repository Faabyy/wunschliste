<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function check(): void
    {
        $sent = $_POST['_csrf'] ?? '';
        if (!is_string($sent) || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $sent)) {
            abort(400, 'Das Formular ist abgelaufen. Lade die Seite neu und versuch es noch einmal.');
        }
    }
}
