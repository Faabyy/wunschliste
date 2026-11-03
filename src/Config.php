<?php
declare(strict_types=1);

/**
 * Einstellungen kommen aus config.php (Webspace per FTP) oder aus Umgebungsvariablen (Docker).
 * Eine gesetzte Umgebungsvariable hat Vorrang vor der Datei.
 */
final class Config
{
    private const ENV = [
        'code' => 'WUNSCHLISTE_CODE',
        'admin_passwort' => 'WUNSCHLISTE_ADMIN_PASSWORT',
        'https' => 'WUNSCHLISTE_HTTPS',
        'preisabruf' => 'WUNSCHLISTE_PREISABRUF',
        'datenordner' => 'WUNSCHLISTE_DATA',
        'basispfad' => 'WUNSCHLISTE_BASISPFAD',
    ];

    private static ?array $file = null;

    public static function file(): string
    {
        $path = getenv('WUNSCHLISTE_CONFIG');
        return $path !== false && $path !== '' ? $path : dirname(__DIR__) . '/config.php';
    }

    public static function hasFile(): bool
    {
        return is_file(self::file());
    }

    private static function get(string $key): string
    {
        $env = getenv(self::ENV[$key]);
        if ($env !== false && trim($env) !== '') {
            return trim($env);
        }
        if (self::$file === null) {
            $loaded = self::hasFile() ? require self::file() : [];
            self::$file = is_array($loaded) ? $loaded : [];
        }
        $value = self::$file[$key] ?? '';
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function dataDir(): string
    {
        $dir = self::get('datenordner');
        return $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/data';
    }

    /** Gemeinsamer Zugangscode für die ganze Seite; leer = kein Code nötig. */
    public static function accessCode(): string
    {
        return self::get('code');
    }

    /** Passwort für /verwaltung; leer = Web-Verwaltung aus, nur bin/konten.php. */
    public static function adminPassword(): string
    {
        return self::get('admin_passwort');
    }

    /** Erzwingt das Secure-Flag, z. B. hinter einem Reverse-Proxy mit TLS. */
    public static function forceHttps(): bool
    {
        return self::get('https') === '1';
    }

    public static function priceFetch(): bool
    {
        return self::get('preisabruf') !== '0';
    }

    /** Nur nötig, wenn die automatische Erkennung des Unterordners nicht passt, z. B. „/wunschliste“. */
    public static function basePath(): ?string
    {
        $base = self::get('basispfad');
        return $base === '' ? null : '/' . trim($base, '/');
    }
}
