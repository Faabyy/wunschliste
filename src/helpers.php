<?php
declare(strict_types=1);

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Kam diese Anfrage per HTTPS (auch hinter einem Proxy)? */
function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function is_https(): bool
{
    return Config::forceHttps() || request_is_https();
}

/** Mit https = true in config.php wird http:// auf https:// umgeleitet (nicht für localhost). */
function enforce_https(): void
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!Config::forceHttps() || request_is_https() || $host === ''
        || preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host) === 1
        || preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) !== 1) {
        return;
    }
    header('Location: https://' . preg_replace('/:\d+$/', '', $host) . ($_SERVER['REQUEST_URI'] ?? '/'), true, 308);
    exit;
}

/**
 * Pfad, unter dem die Seite läuft: '' für eine eigene (Sub-)Domain, sonst z. B. '/wunschliste'.
 * Erkannt am Pfad von index.php; liegt public/ nicht als Web-Root, fällt „/public“ weg,
 * weil die .htaccess im Hauptordner alles dorthin umleitet.
 */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = Config::basePath();
    if ($configured !== null) {
        return $base = $configured === '/' ? '' : $configured;
    }
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($dir !== '' && str_ends_with($dir, '/public') && !str_starts_with($uri . '/', $dir . '/')) {
        $dir = substr($dir, 0, -strlen('/public'));
    }
    return $base = $dir;
}

/** Interner Pfad wie '/listen' → vollständiger Pfad mit Unterordner. */
function url(string $path): string
{
    return base_path() . $path;
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, '/') ? url($path) : $path), true, 303);
    exit;
}

function abort(int $status, string $message): never
{
    http_response_code($status);
    page('error', ['message' => $message], 'Hoppla');
}

// ---------- Kurzmeldungen und alte Eingaben (nur für die nächste Seite) ----------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function remember_input(array $input): void
{
    $_SESSION['old'] = $input;
}

function take_input(): array
{
    $old = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);
    return $old;
}

// ---------- Views ----------

function view(string $name, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/views/' . $name . '.php';
    return (string) ob_get_clean();
}

function page(string $name, array $vars, string $title, string $active = ''): never
{
    $content = view($name, $vars);
    $user = Auth::user();
    echo view('layout', [
        'content' => $content,
        'title' => $title,
        'active' => $active,
        'user' => $user,
        'otherLists' => $user === null ? 0 : count(Wishlist::others((int) $user['id'])),
        'hasGifts' => $user !== null && Wishlist::gifts((int) $user['id']) !== [],
        'flashes' => take_flashes(),
    ]);
    exit;
}

function asset(string $file): string
{
    $mtime = @filemtime(dirname(__DIR__) . '/public/assets/' . $file);
    return url('/assets/' . $file) . '?v=' . ($mtime ?: '1');
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="i' . ($class !== '' ? ' ' . $class : '') . '" aria-hidden="true" focusable="false">'
        . '<use href="' . e(asset('icons.svg')) . '#' . $name . '"></use></svg>';
}

/** Häkchen inline, damit es beim Anhaken gezeichnet werden kann. */
function check_mark(): string
{
    return '<svg class="check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
}

function avatar(string $name, string $extra = ''): string
{
    $words = preg_split('/\s+/u', trim($name)) ?: [''];
    $initials = first_char($words[0]);
    if (count($words) > 1) {
        $initials .= first_char($words[count($words) - 1]);
    }
    $tone = crc32(name_key($name)) % 8;
    return '<span class="avatar av-' . $tone . ($extra !== '' ? ' ' . $extra : '') . '" aria-hidden="true">'
        . e(upper($initials)) . '</span>';
}

function first_char(string $s): string
{
    return preg_match('/^./u', $s, $m) ? $m[0] : '';
}

// ---------- Text ----------

/** Länge in Zeichen, auch ohne mbstring. */
function str_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return (int) preg_match_all('/./us', $s);
}

function lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtr(strtolower($s), latin_case_map());
}

function upper(string $s): string
{
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($s, 'UTF-8');
    }
    return strtr(strtoupper($s), array_flip(latin_case_map()));
}

/** Großbuchstaben À–Þ → Kleinbuchstaben, als Ersatz, falls mbstring fehlt. */
function latin_case_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $utf8 = static fn(int $cp): string => chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        for ($cp = 0xC0; $cp <= 0xDE; $cp++) {
            if ($cp !== 0xD7) {
                $map[$utf8($cp)] = $utf8($cp + 0x20);
            }
        }
    }
    return $map;
}

/** Eine Zeile Text: gültiges UTF-8, ohne Steuerzeichen, Leerraum zusammengefasst. */
function clean_line(mixed $value): string
{
    $s = is_string($value) ? $value : '';
    if (preg_match('//u', $s) !== 1) {
        return '';
    }
    $s = preg_replace('/[\p{Cc}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return trim($s);
}

function normalize_name(mixed $value): string
{
    return clean_line($value);
}

/** Vergleichsschlüssel für Namen: getrimmt, ohne Rücksicht auf Groß-/Kleinschreibung. */
function name_key(string $name): string
{
    $key = lower(normalize_name($name));
    return str_replace("\u{1E9E}", 'ß', $key);
}

// ---------- Links und Preise ----------

function domain_of(string $url): string
{
    $host = lower((string) parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\d*\./', '', $host) ?? $host;
}

function format_price(?int $cents, ?string $currency, bool $approx = false): string
{
    if ($cents === null) {
        return '';
    }
    $amount = number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.');
    $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF'];
    $currency = strtoupper($currency ?: 'EUR');
    return ($approx ? 'ca. ' : '') . $amount . "\u{00A0}" . ($symbols[$currency] ?? $currency);
}

/** Preis aus Eingaben wie „29,99“, „1.299“ oder „29.99 €“ in Cent. */
function parse_price(string $raw, bool $machine = false): ?int
{
    $s = preg_replace('/[^\d.,]/', '', $raw) ?? '';
    if ($s === '' || preg_match('/\d/', $s) !== 1) {
        return null;
    }
    if ($machine) {
        // Maschinenformat (JSON-LD, Meta-Tags): Punkt ist das Dezimaltrennzeichen.
        $s = str_replace(',', '', $s);
        $parts = explode('.', $s);
        $int = $parts[0];
        $frac = count($parts) > 1 ? $parts[count($parts) - 1] : '';
        if (count($parts) > 2) {
            $int = implode('', array_slice($parts, 0, -1));
        }
    } else {
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        $sep = null;
        if ($lastDot !== false && $lastComma !== false) {
            $sep = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false || $lastComma !== false) {
            $char = $lastDot !== false ? '.' : ',';
            $after = substr($s, (int) strrpos($s, $char) + 1);
            if (substr_count($s, $char) === 1 && strlen($after) !== 3) {
                $sep = $char;
            }
        }
        if ($sep === null) {
            $int = str_replace(['.', ','], '', $s);
            $frac = '';
        } else {
            $pos = (int) strrpos($s, $sep);
            $int = str_replace(['.', ','], '', substr($s, 0, $pos));
            $frac = substr($s, $pos + 1);
        }
    }
    $int = ltrim($int, '0');
    $frac = substr(str_pad($frac, 2, '0'), 0, 2);
    if (strlen($int) > 9) {
        return null;
    }
    $cents = (int) $int * 100 + (int) $frac;
    return $cents > 0 ? $cents : null;
}

/** Prüft einen Link und ergänzt fehlendes https://. Gibt null für „kein Link“ zurück. */
function normalize_url(mixed $value): ?string
{
    $url = is_string($value) ? trim($value) : '';
    if ($url === '') {
        return null;
    }
    if (preg_match('//u', $url) !== 1 || preg_match('/\s/u', $url) === 1) {
        throw new UserError('Der Link sieht nicht gültig aus.');
    }
    if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $url, $m) === 1 && !preg_match('~^https?://~i', $url)) {
        // „amazon.de:443/…“ wäre auch ein „Schema“, kommt aber praktisch nicht vor.
        throw new UserError('Nur Links mit http:// oder https:// sind erlaubt.');
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    if (strlen($url) > 2000) {
        throw new UserError('Der Link ist zu lang.');
    }
    $parts = parse_url($url);
    if (
        filter_var($url, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || empty($parts['host'])
        || !str_contains($parts['host'], '.')
    ) {
        throw new UserError('Der Link sieht nicht gültig aus.');
    }
    return $url;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function count_label(int $n, string $one, string $many): string
{
    return $n . "\u{00A0}" . ($n === 1 ? $one : $many);
}
