<?php
declare(strict_types=1);

/**
 * Liest Preis und Vorschaubild von einer Produktseite (JSON-LD, Meta-Tags, Microdata) und lädt Bilder.
 *
 * Amazon und eBay sperren automatische Abrufe ihrer Seiten bewusst; das wird nicht umgangen.
 * Bei Amazon kommt das Bild über die öffentliche Bildadresse zur Artikelnummer (ASIN).
 *
 * Weil die Seite öffentlich erreichbar ist, darf der Abruf nicht als Werkzeug gegen das eigene Netz
 * taugen (SSRF): nur Ports 80/443, nur öffentliche IPv4-Adressen, und verbunden wird genau mit der
 * geprüften Adresse. Weiterleitungen werden einzeln nach denselben Regeln geprüft.
 */
final class LinkFetcher
{
    private const MAX_HTML = 2_000_000;
    public const MAX_IMAGE = 5_000_000;
    private const MAX_HOPS = 4;
    private const TIMEOUT = 8.0;
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';

    /**
     * Was sich aus dem Link lesen lässt.
     * @return array{price: ?array{cents:int, currency:string}, image: ?string, shop: ?string}
     */
    public static function inspect(string $url): array
    {
        $page = self::download($url, self::MAX_HTML);
        $final = $page['url'] ?? $url;
        $shop = self::shop($final) ?? self::shop($url);
        $html = $page['body'] ?? '';
        $price = $shop === null && $html !== '' ? self::extractPrice($html) : null;
        $image = $shop === null && $html !== '' ? self::extractImage($html, $final) : null;
        if ($shop === 'amazon' && ($asin = self::amazonAsin($final) ?? self::amazonAsin($url)) !== null) {
            $image = 'https://images-na.ssl-images-amazon.com/images/P/' . $asin . '.01._SCLZZZZZZZ_.jpg';
        }
        return ['price' => $price, 'image' => $image, 'shop' => $shop];
    }

    /** Shops, die automatische Abrufe sperren. */
    public static function shop(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (preg_match('/(^|\.)(amazon\.[a-z.]+|amzn\.(to|eu|com|de)|a\.co)$/', $host) === 1) {
            return 'amazon';
        }
        if (preg_match('/(^|\.)(ebay\.[a-z.]+|ebay\.us)$/', $host) === 1) {
            return 'ebay';
        }
        return null;
    }

    public static function amazonAsin(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~/(?:dp|gp/product|gp/aw/d|exec/obidos/ASIN|o/ASIN)/([A-Z0-9]{10})(?:[/?]|$)~i', $path, $m) === 1) {
            return strtoupper($m[1]);
        }
        return null;
    }

    // ---------- Abruf ----------

    /**
     * Lädt eine Adresse und folgt Weiterleitungen. Gibt auch bei Fehlerstatus die Zieladresse zurück.
     * @return array{url: string, status: int, type: string, body: string}|null
     */
    public static function download(string $url, int $maxBytes): ?array
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

            $response = self::request($scheme, $host, $ip, $port, $target, $deadline, $maxBytes);
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
            $encoding = strtolower($headers['content-encoding'] ?? '');
            if ($encoding === 'gzip') {
                $body = @gzdecode($body);
            } elseif ($encoding === 'deflate') {
                $body = @gzuncompress($body) ?: @gzinflate($body);
            }
            return [
                'url' => $url,
                'status' => $status,
                'type' => strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])),
                'body' => $status === 200 && is_string($body) ? $body : '',
            ];
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
    private static function request(string $scheme, string $host, string $ip, int $port, string $target, float $deadline, int $maxBytes): ?array
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
        while (!feof($fp) && strlen($raw) < $maxBytes + 16384 && microtime(true) < $deadline) {
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

    /** Vorschaubild der Seite als absolute Adresse. */
    public static function extractImage(string $html, string $pageUrl): ?string
    {
        $candidates = [];
        preg_match_all('~<meta\b[^>]*>~i', $html, $m);
        $meta = [];
        foreach ($m[0] as $tag) {
            $a = self::attributes($tag);
            $key = strtolower($a['property'] ?? $a['name'] ?? $a['itemprop'] ?? '');
            if ($key !== '' && isset($a['content']) && !isset($meta[$key])) {
                $meta[$key] = $a['content'];
            }
        }
        foreach (['og:image:secure_url', 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src', 'image'] as $key) {
            if (isset($meta[$key])) {
                $candidates[] = $meta[$key];
            }
        }
        preg_match_all('~<script[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>~is', $html, $ld);
        foreach ($ld[1] as $json) {
            $data = json_decode(trim($json), true);
            if (is_array($data) && ($img = self::jsonLdImage($data, 0)) !== null) {
                $candidates[] = $img;
            }
        }
        if (preg_match('~<link\b[^>]*rel\s*=\s*["\']image_src["\'][^>]*>~i', $html, $l) === 1) {
            $candidates[] = self::attributes($l[0])['href'] ?? '';
        }
        foreach ($candidates as $candidate) {
            $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($candidate === '' || str_starts_with($candidate, 'data:')) {
                continue;
            }
            $abs = preg_match('~^https?://~i', $candidate) === 1 ? $candidate : self::resolveUrl($pageUrl, $candidate);
            if ($abs !== null && strlen($abs) <= 2000) {
                return $abs;
            }
        }
        return null;
    }

    private static function jsonLdImage(array $node, int $depth): ?string
    {
        if ($depth > 12) {
            return null;
        }
        $type = $node['@type'] ?? '';
        $isProduct = $type === 'Product' || (is_array($type) && in_array('Product', $type, true));
        if ($isProduct && isset($node['image'])) {
            $img = $node['image'];
            if (is_array($img)) {
                $img = array_is_list($img) ? ($img[0] ?? null) : ($img['url'] ?? $img['contentUrl'] ?? null);
                if (is_array($img)) {
                    $img = $img['url'] ?? $img['contentUrl'] ?? null;
                }
            }
            if (is_string($img) && $img !== '') {
                return $img;
            }
        }
        foreach ($node as $value) {
            if (is_array($value) && ($found = self::jsonLdImage($value, $depth + 1)) !== null) {
                return $found;
            }
        }
        return null;
    }

    /** @return array{cents:int, currency:string}|null */
    public static function extractPrice(string $html): ?array
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
