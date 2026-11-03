<?php
declare(strict_types=1);

// Aufruf: php tests/price_test.php   (ohne Netz; prüft Preiserkennung und SSRF-Sperre)

require dirname(__DIR__) . '/src/bootstrap.php';

$fails = 0;
function same(mixed $actual, mixed $expected, string $label): void
{
    global $fails;
    if ($actual === $expected) {
        echo "  ok  {$label}\n";
    } else {
        $fails++;
        echo "FEHLER {$label}: erwartet " . var_export($expected, true) . ', bekommen ' . var_export($actual, true) . "\n";
    }
}

echo "Preise von Hand\n";
same(parse_price('29,99'), 2999, '29,99');
same(parse_price('29.99 €'), 2999, '29.99 €');
same(parse_price('1.299'), 129900, '1.299 (Tausender)');
same(parse_price('1.299,50'), 129950, '1.299,50');
same(parse_price('1,299.50'), 129950, '1,299.50');
same(parse_price('30'), 3000, '30');
same(parse_price('9,9'), 990, '9,9');
same(parse_price('abc'), null, 'abc');
same(parse_price('0'), null, '0');
same(parse_price('1299.000', true), 129900, 'Maschinenformat 1299.000');

echo "Preise aus HTML\n";
$ld = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Product","name":"X","offers":{"@type":"Offer","price":"49.95","priceCurrency":"EUR"}}]}</script>';
same(LinkFetcher::extractPrice($ld), ['cents' => 4995, 'currency' => 'EUR'], 'JSON-LD mit @graph');
$ld2 = '<script type="application/ld+json">[{"@type":"Product","offers":[{"@type":"AggregateOffer","lowPrice":12,"priceCurrency":"CHF"}]}]</script>';
same(LinkFetcher::extractPrice($ld2), ['cents' => 1200, 'currency' => 'CHF'], 'JSON-LD AggregateOffer');
$meta = '<meta content="19.90" property="product:price:amount"><meta property="product:price:currency" content="EUR">';
same(LinkFetcher::extractPrice($meta), ['cents' => 1990, 'currency' => 'EUR'], 'Meta-Tags (Attribute vertauscht)');
$micro = '<span itemprop="price" content="7.50">7,50 €</span><meta itemprop="priceCurrency" content="EUR">';
same(LinkFetcher::extractPrice($micro), ['cents' => 750, 'currency' => 'EUR'], 'Microdata');
$amazon = '<span class="a-price"><span class="a-offscreen">89,99&nbsp;€</span></span>';
same(LinkFetcher::extractPrice($amazon), ['cents' => 8999, 'currency' => 'EUR'], 'Amazon a-offscreen');
same(LinkFetcher::extractPrice('<html>nichts</html>'), null, 'Seite ohne Preis');

echo "Bilder aus HTML und Links\n";
same(LinkFetcher::extractImage('<meta property="og:image" content="/img/p.jpg?x=1&amp;y=2">', 'https://shop.example/p/1'),
    'https://shop.example/img/p.jpg?x=1&y=2', 'og:image relativ, Entities aufgelöst');
same(LinkFetcher::extractImage('<script type="application/ld+json">{"@type":"Product","image":["https://cdn.example/a.jpg"]}</script>', 'https://x.example/'),
    'https://cdn.example/a.jpg', 'JSON-LD Product.image als Liste');
same(LinkFetcher::extractImage('<meta name="twitter:image" content="//cdn.example/t.png">', 'https://x.example/'),
    'https://cdn.example/t.png', 'twitter:image protokollrelativ');
same(LinkFetcher::extractImage('<p>kein Bild</p>', 'https://x.example/'), null, 'Seite ohne Bild');
same(LinkFetcher::amazonAsin('https://www.amazon.de/Apple-AirPods/dp/B0CHWRXH8B/ref=sr_1_1?th=1'), 'B0CHWRXH8B', 'ASIN aus /dp/');
same(LinkFetcher::amazonAsin('https://www.amazon.de/gp/product/3548372562'), '3548372562', 'ASIN aus /gp/product/');
same(LinkFetcher::shop('https://amzn.eu/d/abc'), 'amazon', 'amzn.eu ist Amazon');
same(LinkFetcher::shop('https://www.ebay.de/itm/123'), 'ebay', 'ebay.de ist eBay');
same(LinkFetcher::shop('https://www.ikea.com/de/'), null, 'IKEA ist kein gesperrter Shop');

echo "Bildprüfung\n";
function rejects(string $bytes): bool
{
    try {
        Images::store($bytes);
        return false;
    } catch (UserError) {
        return true;
    }
}
same(rejects('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), true, 'SVG abgelehnt');
same(rejects('<?php echo 1;'), true, 'Text abgelehnt');
same(rejects(base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==')), true, '1×1-GIF (Platzhalter) abgelehnt');
// Kleines JPEG mit EXIF-Segment (APP1) zum Testen der Bereinigung
$jpeg = "\xFF\xD8" . "\xFF\xE1\x00\x10Exif\x00\x00GPSDATA!" . "\xFF\xDB\x00\x04\x00\x00" . "\xFF\xDA\x00\x02BILDDATEN\xFF\xD9";
$clean = Images::stripJpegMetadata($jpeg);
same(str_contains($clean, 'GPSDATA') || str_contains($clean, 'Exif'), false, 'EXIF/GPS aus JPEG entfernt');
same(str_contains($clean, 'BILDDATEN'), true, 'Bilddaten bleiben erhalten');

echo "SSRF-Sperre\n";
foreach (['127.0.0.1', '10.1.2.3', '192.168.178.1', '172.20.0.5', '100.101.102.103', '169.254.169.254', '0.0.0.0', '224.0.0.1'] as $ip) {
    same(LinkFetcher::isPublicIpv4($ip), false, "{$ip} gesperrt");
}
same(LinkFetcher::isPublicIpv4('93.184.215.14'), true, 'öffentliche Adresse erlaubt');
same(LinkFetcher::download('http://127.0.0.1/', 1000), null, 'Abruf von 127.0.0.1 verweigert');
same(LinkFetcher::download('http://localhost/', 1000), null, 'Abruf von localhost verweigert');
same(LinkFetcher::download('https://example.com:8443/', 1000), null, 'Fremder Port verweigert');
same(LinkFetcher::download('file:///etc/passwd', 1000), null, 'file:// verweigert');

echo $fails === 0 ? "\nAlles OK.\n" : "\n{$fails} Fehler.\n";
exit($fails === 0 ? 0 : 1);
