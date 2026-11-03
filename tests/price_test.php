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
same(PriceFetcher::extract($ld), ['cents' => 4995, 'currency' => 'EUR'], 'JSON-LD mit @graph');
$ld2 = '<script type="application/ld+json">[{"@type":"Product","offers":[{"@type":"AggregateOffer","lowPrice":12,"priceCurrency":"CHF"}]}]</script>';
same(PriceFetcher::extract($ld2), ['cents' => 1200, 'currency' => 'CHF'], 'JSON-LD AggregateOffer');
$meta = '<meta content="19.90" property="product:price:amount"><meta property="product:price:currency" content="EUR">';
same(PriceFetcher::extract($meta), ['cents' => 1990, 'currency' => 'EUR'], 'Meta-Tags (Attribute vertauscht)');
$micro = '<span itemprop="price" content="7.50">7,50 €</span><meta itemprop="priceCurrency" content="EUR">';
same(PriceFetcher::extract($micro), ['cents' => 750, 'currency' => 'EUR'], 'Microdata');
$amazon = '<span class="a-price"><span class="a-offscreen">89,99&nbsp;€</span></span>';
same(PriceFetcher::extract($amazon), ['cents' => 8999, 'currency' => 'EUR'], 'Amazon a-offscreen');
same(PriceFetcher::extract('<html>nichts</html>'), null, 'Seite ohne Preis');

echo "SSRF-Sperre\n";
foreach (['127.0.0.1', '10.1.2.3', '192.168.178.1', '172.20.0.5', '100.101.102.103', '169.254.169.254', '0.0.0.0', '224.0.0.1'] as $ip) {
    same(PriceFetcher::isPublicIpv4($ip), false, "{$ip} gesperrt");
}
same(PriceFetcher::isPublicIpv4('93.184.215.14'), true, 'öffentliche Adresse erlaubt');
same(PriceFetcher::download('http://127.0.0.1/'), null, 'Abruf von 127.0.0.1 verweigert');
same(PriceFetcher::download('http://localhost/'), null, 'Abruf von localhost verweigert');
same(PriceFetcher::download('https://example.com:8443/'), null, 'Fremder Port verweigert');
same(PriceFetcher::download('file:///etc/passwd'), null, 'file:// verweigert');

echo $fails === 0 ? "\nAlles OK.\n" : "\n{$fails} Fehler.\n";
exit($fails === 0 ? 0 : 1);
