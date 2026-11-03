<?php
declare(strict_types=1);

/** Einfache Bremse gegen Durchprobieren: höchstens N Fehlversuche je IP und Art in 15 Minuten. */
final class Throttle
{
    private const WINDOW = 15 * 60;
    private const LIMITS = ['code' => 10, 'admin' => 5, 'name' => 20];

    public static function blocked(string $kind): bool
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM attempts WHERE ip = ? AND kind = ? AND at > ?');
        $stmt->execute([client_ip(), $kind, time() - self::WINDOW]);
        return (int) $stmt->fetchColumn() >= (self::LIMITS[$kind] ?? 10);
    }

    public static function fail(string $kind): void
    {
        $db = Database::get();
        $db->prepare('INSERT INTO attempts (ip, kind, at) VALUES (?, ?, ?)')->execute([client_ip(), $kind, time()]);
        $db->prepare('DELETE FROM attempts WHERE at < ?')->execute([time() - self::WINDOW]);
    }

    public static function message(): string
    {
        return 'Zu viele Fehlversuche. Bitte warte eine Viertelstunde und versuch es dann noch einmal.';
    }
}
