<?php
declare(strict_types=1);

/**
 * Liest den Preis von einer Produktseite (JSON-LD, Meta-Tags, Microdata, Amazon).
 *
 * Weil die Seite öffentlich erreichbar ist, darf der Abruf nicht als Werkzeug gegen das eigene Netz
 * taugen (SSRF): nur Ports 80/443, nur öffentliche IPv4-Adressen, und verbunden wird genau mit der
 * geprüften Adresse. Weiterleitungen werden einzeln nach denselben Regeln geprüft.
 */
final class PriceFetcher
{
    private const MAX_BYTES = 2_000_000;
    private const MAX_HOPS = 4;
    private const TIMEOUT = 8.0;
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

    /** @return array{cents:int, currency:string}|null */
    public static function fetch(string $url): ?array
    {
        $html = self::download($url);
        return $html === null ? null : self::extract($html);
    }

    // ---------- Abruf ----------

    public static function download(string $url): ?string
    {
        // Manche Webspaces sperren Netzwerkfunktionen; dann gibt es eben keinen Preis.
        if (!self::available()) {
            return null;
        }
        $deadline = microtime(true) + self::TIMEOUT;
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = strtolower($parts['host'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                return null;
            }
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            if ($port !== ($scheme === 'https' ? 443 : 80)) {
                return null;
            }
            $ip = self::resolvePublic($host);
            if ($ip === null) {
                return null;
            }
            $target = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
            if (isset($parts['query'])) {
                $target .= '?' . $parts['query'];
            }
            if (preg_match('/[\s\x00-\x1F\x7F]/', $target . $host) === 1) {
                return null;
            }

            $response = self::request($scheme, $host, $ip, $port, $target, $deadline);
            if ($response === null) {
                return null;
            }
            [$status, $headers, $body] = $response;
            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                $url = self::resolveUrl($url, $headers['location']);
                if ($url === null) {
                    return null;
                }
                continue;
            }
            if ($status !== 200) {
                return null;
            }
            $encoding = strtolower($headers['content-encoding'] ?? '');
            if ($encoding === 'gzip') {
                $body = @gzdecode($body);
            } elseif ($encoding === 'deflate') {
                $body = @gzuncompress($body) ?: @gzinflate($body);
            }
            return is_string($body) ? $body : null;
        }
        return null;
    }

    public static function available(): bool
    {
        foreach (['stream_socket_client', 'gethostbynamel', 'stream_context_create'] as $fn) {
            if (!function_exists($fn)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{0:int, 1:array<string,string>, 2:string}|null */
    private static function request(string $scheme, string $host, string $ip, int $port, string $target, float $deadline): ?array
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return null;
        }
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ]]);
        $remote = ($scheme === 'https' ? 'ssl' : 'tcp') . '://' . $ip . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, min(5.0, $remaining), STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            return null;
        }
        stream_set_timeout($fp, 5);
        fwrite($fp, "GET {$target} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . 'User-Agent: ' . self::USER_AGENT . "\r\n"
            . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n"
            . "Accept-Language: de-DE,de;q=0.9,en;q=0.6\r\n"
            . "Accept-Encoding: gzip\r\n"
            . "Upgrade-Insecure-Requests: 1\r\n"
            . "Sec-Fetch-Dest: document\r\n"
            . "Sec-Fetch-Mode: navigate\r\n"
            . "Sec-Fetch-Site: none\r\n"
            . "Connection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp) && strlen($raw) < self::MAX_BYTES && microtime(true) < $deadline) {
            $chunk = fread($fp, 65536);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($fp)['timed_out'])) {
                break;
            }
            $raw .= $chunk;
        }
        fclose($fp);

        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            return null;
        }
        $lines = explode("\r\n", substr($raw, 0, $split));
        if (preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})~', array_shift($lines) ?? '', $m) !== 1) {
            return null;
        }
        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        $body = substr($raw, $split + 4);
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            $body = self::dechunk($body);
        }
        return [(int) $m[1], $headers, $body];
    }

    private static function dechunk(string $body): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($body);
        while ($pos < $len) {
            $eol = strpos($body, "\r\n", $pos);
            if ($eol === false) {
                break;
            }
            $size = hexdec(trim(explode(';', substr($body, $pos, $eol - $pos))[0]));
            if (!is_int($size) || $size <= 0) {
                break;
            }
            $out .= substr($body, $eol + 2, $size);
            $pos = $eol + 2 + $size + 2;
        }
        return $out;
    }

    private static function resolveUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'];
        if (str_starts_with($location, '//')) {
            return $b['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $location) === 1) {
            return null;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/') ?: '/';
        return $origin . $dir . $location;
    }

    /** Gibt eine öffentliche IPv4-Adresse zurück, oder null, wenn auch nur eine Adresse intern ist. */
    public static function resolvePublic(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ips = [$host];
        } elseif (preg_match('/^[a-z0-9.-]+$/', $host) === 1 && !str_ends_with($host, '.local') && !str_ends_with($host, '.internal')) {
            $ips = gethostbynamel($host) ?: [];
        } else {
            return null;
        }
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIpv4($ip)) {
                return null;
            }
        }
        return $ips[0];
    }

    public static function isPublicIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $blocked = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
            ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15],
            ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 3],
        ];
        $n = ip2long($ip);
        foreach ($blocked as [$net, $bits]) {
            $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
        return true;
    }

    // ---------- Auswertung ----------

    /** @return array{cents:int, currency:string}|null */
    public static function extract(string $html): ?array
    {
        return self::fromJsonLd($html)
            ?? self::fromMeta($html)
            ?? self::fromMicrodata($html)
            ?? self::fromAmazon($html);
    }

    private static function result(mixed $amount, mixed $currency, bool $machine): ?array
    {
        if (is_int($amount) || is_float($amount)) {
            $cents = (int) round((float) $amount * 100);
        } elseif (is_string($amount)) {
            $cents = parse_price(html_entity_decode($amount, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $machine);
        } else {
            return null;
        }
        if ($cents === null || $cents <= 0 || $cents >= 100_000_000) {
            return null;
        }
        $currency = is_string($currency) ? strtoupper(trim($currency)) : '';
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $currency = 'EUR';
        }
        return ['cents' => $cents, 'currency' => $currency];
    }

    private static function fromJsonLd(string $html): ?array
    {
        preg_match_all('~<script[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>~is', $html, $m);
        foreach ($m[1] as $json) {
            $data = json_decode(trim($json), true);
            if (is_array($data) && ($found = self::walkJsonLd($data, 0)) !== null) {
                return $found;
            }
        }
        return null;
    }

    private static function walkJsonLd(array $node, int $depth): ?array
    {
        if ($depth > 12) {
            return null;
        }
        if (isset($node['offers']) && is_array($node['offers'])) {
            $offers = array_is_list($node['offers']) ? $node['offers'] : [$node['offers']];
            foreach ($offers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }
                $spec = $offer['priceSpecification'] ?? null;
                if (is_array($spec) && array_is_list($spec)) {
                    $spec = $spec[0] ?? null;
                }
                $amount = $offer['price'] ?? $offer['lowPrice'] ?? (is_array($spec) ? ($spec['price'] ?? null) : null);
                $currency = $offer['priceCurrency'] ?? (is_array($spec) ? ($spec['priceCurrency'] ?? null) : null);
                if (($found = self::result($amount, $currency, true)) !== null) {
                    return $found;
                }
            }
        }
        foreach ($node as $value) {
            if (is_array($value) && ($found = self::walkJsonLd($value, $depth + 1)) !== null) {
                return $found;
            }
        }
        return null;
    }

    private static function attributes(string $tag): array
    {
        preg_match_all('~([\w:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~', $tag, $m, PREG_SET_ORDER);
        $attrs = [];
        foreach ($m as $a) {
            $attrs[strtolower($a[1])] = $a[2] !== '' ? $a[2] : ($a[3] ?? '') . ($a[4] ?? '');
        }
        return $attrs;
    }

    private static function fromMeta(string $html): ?array
    {
        preg_match_all('~<meta\b[^>]*>~i', $html, $m);
        $values = [];
        foreach ($m[0] as $tag) {
            $a = self::attributes($tag);
            $key = strtolower($a['property'] ?? $a['name'] ?? $a['itemprop'] ?? '');
            if ($key !== '' && isset($a['content']) && !isset($values[$key])) {
                $values[$key] = $a['content'];
            }
        }
        foreach (['product:price:amount' => 'product:price:currency', 'og:price:amount' => 'og:price:currency'] as $amount => $currency) {
            if (isset($values[$amount]) && ($found = self::result($values[$amount], $values[$currency] ?? null, true)) !== null) {
                return $found;
            }
        }
        return null;
    }

    private static function fromMicrodata(string $html): ?array
    {
        if (preg_match('~<[^>]+itemprop\s*=\s*["\']price["\'][^>]*>~i', $html, $m) !== 1) {
            return null;
        }
        $content = self::attributes($m[0])['content'] ?? null;
        $currency = null;
        if (preg_match('~<[^>]+itemprop\s*=\s*["\']priceCurrency["\'][^>]*>~i', $html, $c) === 1) {
            $currency = self::attributes($c[0])['content'] ?? null;
        }
        return $content === null ? null : self::result($content, $currency, true);
    }

    private static function fromAmazon(string $html): ?array
    {
        if (preg_match('~class="a-offscreen"[^>]*>\s*([^<]{1,40})<~', $html, $m) !== 1) {
            return null;
        }
        $text = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $currency = match (true) {
            str_contains($text, '$') => 'USD',
            str_contains($text, '£') => 'GBP',
            default => 'EUR',
        };
        return self::result($text, $currency, false);
    }
}
