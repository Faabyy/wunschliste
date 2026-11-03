<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    // ---------- Gemeinsamer Zugangscode ----------

    public static function codeRequired(): bool
    {
        return Config::accessCode() !== '';
    }

    /** Ändert sich der Code, werden alle bisherigen Freischaltungen ungültig. */
    private static function fingerprint(string $secret): string
    {
        return hash_hmac('sha256', 'wunschliste', $secret);
    }

    public static function codeOk(): bool
    {
        if (!self::codeRequired()) {
            return true;
        }
        $stored = $_SESSION['code'] ?? '';
        return is_string($stored) && hash_equals(self::fingerprint(Config::accessCode()), $stored);
    }

    public static function acceptCode(string $input): bool
    {
        if (!hash_equals(Config::accessCode(), trim($input))) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['code'] = self::fingerprint(Config::accessCode());
        return true;
    }

    // ---------- Nutzer ----------

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if (is_int($id) && self::codeOk()) {
                self::$user = Wishlist::userById($id);
                if (self::$user === null) {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        unset($_SESSION['uid']);
        session_regenerate_id(true);
        self::$user = null;
    }

    // ---------- Verwaltung ----------

    public static function adminEnabled(): bool
    {
        return Config::adminPassword() !== '';
    }

    public static function isAdmin(): bool
    {
        if (!self::adminEnabled()) {
            return false;
        }
        $stored = $_SESSION['admin'] ?? '';
        return is_string($stored) && hash_equals(self::fingerprint(Config::adminPassword()), $stored);
    }

    public static function adminLogin(string $password): bool
    {
        if (!self::adminEnabled() || !hash_equals(Config::adminPassword(), $password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = self::fingerprint(Config::adminPassword());
        return true;
    }

    public static function adminLogout(): void
    {
        unset($_SESSION['admin']);
        session_regenerate_id(true);
    }
}
