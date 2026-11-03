<?php
declare(strict_types=1);

/** Einstellungen kommen aus Umgebungsvariablen, damit nichts Geheimes im Code steht. */
final class Config
{
    private static function env(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : trim($value);
    }

    public static function dataDir(): string
    {
        $dir = self::env('WUNSCHLISTE_DATA');
        return $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/data';
    }

    /** Gemeinsamer Zugangscode für die ganze Seite; leer = kein Code nötig. */
    public static function accessCode(): string
    {
        return self::env('WUNSCHLISTE_CODE');
    }

    /** Passwort für /verwaltung; leer = Web-Verwaltung aus, nur bin/konten.php. */
    public static function adminPassword(): string
    {
        return self::env('WUNSCHLISTE_ADMIN_PASSWORT');
    }

    /** Erzwingt das Secure-Flag, z. B. hinter einem Reverse-Proxy mit TLS. */
    public static function forceHttps(): bool
    {
        return self::env('WUNSCHLISTE_HTTPS') === '1';
    }

    public static function priceFetch(): bool
    {
        return self::env('WUNSCHLISTE_PREISABRUF') !== '0';
    }
}
