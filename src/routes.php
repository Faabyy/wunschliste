<?php
declare(strict_types=1);

// ---------- Hilfen ----------

function require_user(): array
{
    $user = Auth::user();
    if ($user === null) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            flash('error', 'Bitte melde dich zuerst an.');
        }
        redirect('/');
    }
    return $user;
}

/** Wie require_user(), aber nur für Konten mit eigener Wunschliste. */
function require_wisher(): array
{
    $user = require_user();
    if (!$user['can_wish']) {
        flash('error', 'Dein Konto hat keine eigene Wunschliste.');
        redirect('/');
    }
    return $user;
}

function require_admin(): void
{
    if (!Auth::isAdmin()) {
        redirect('/verwaltung');
    }
}

/** Rücksprungziel aus dem Formular, nur bekannte interne Seiten. */
function back_target(string $fallback): string
{
    $back = $_POST['zurueck'] ?? '';
    return is_string($back) && preg_match('#^/(?:liste/\d+|schenken|listen)?$#', $back) === 1 ? $back : $fallback;
}

/** Führt eine Aktion aus und zeigt UserError als Meldung. */
function attempt(callable $fn, ?string $success = null): bool
{
    try {
        $fn();
        if ($success !== null) {
            flash('success', $success);
        }
        return true;
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        return false;
    }
}

/** Liest und prüft das Formular für einen Wunsch. */
function item_input(): array
{
    $title = clean_line($_POST['titel'] ?? '');
    $note = clean_line($_POST['notiz'] ?? '');
    $priceRaw = clean_line($_POST['preis'] ?? '');
    $input = ['titel' => $title, 'link' => is_string($_POST['link'] ?? null) ? trim($_POST['link']) : '', 'notiz' => $note, 'preis' => $priceRaw];

    if ($title === '') {
        throw new UserError('Bitte gib einen Titel ein.');
    }
    if (str_len($title) > 150) {
        throw new UserError('Der Titel darf höchstens 150 Zeichen lang sein.');
    }
    if (str_len($note) > 300) {
        throw new UserError('Die Notiz darf höchstens 300 Zeichen lang sein.');
    }
    $price = null;
    if ($priceRaw !== '') {
        $price = parse_price($priceRaw);
        if ($price === null) {
            throw new UserError('Den Preis verstehe ich nicht. Beispiel: 24,99');
        }
    }
    return [
        'title' => $title,
        'url' => normalize_url($input['link']),
        'note' => $note === '' ? null : $note,
        'price_cents' => $price,
        'price_currency' => $price === null ? null : 'EUR',
        'price_manual' => $price !== null,
    ];
}

function raw_item_input(): array
{
    $get = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    return ['titel' => $get('titel'), 'link' => $get('link'), 'notiz' => $get('notiz'), 'preis' => $get('preis')];
}

/**
 * Liest Preis und Bild aus dem Link, soweit gewünscht und nicht von Hand gesetzt.
 * Sperrt der Shop automatische Abrufe (Amazon, eBay), gibt es einen Hinweis.
 */
function enrich_from_link(int $itemId, array $data, bool $wantPrice, bool $wantImage): void
{
    if ($data['url'] === null || !Config::priceFetch() || (!$wantPrice && !$wantImage)) {
        return;
    }
    try {
        $info = LinkFetcher::inspect($data['url']);
        if ($wantPrice) {
            Wishlist::setPrice($itemId, $info['price']);
        }
        $image = null;
        if ($wantImage && $info['image'] !== null && ($image = Images::fromUrl($info['image'])) !== null) {
            Wishlist::setImage($itemId, $image, false);
        }
    } catch (Throwable $e) {
        error_log('Abruf des Links fehlgeschlagen: ' . $e->getMessage());
        return;
    }
    $missingPrice = $wantPrice && $info['price'] === null;
    if ($info['shop'] === 'amazon' && $missingPrice) {
        flash('info', 'Amazon gibt Preise nicht automatisch heraus. Trag den Preis bei Bedarf von Hand ein.');
    } elseif ($info['shop'] === 'ebay') {
        flash('info', 'eBay gibt Preis und Bild nicht automatisch heraus. Trag den Preis von Hand ein und lade bei Bedarf ein Bild hoch.');
    }
}

// ---------- Anmeldung ----------

function route_home(): void
{
    $user = Auth::user();
    if ($user === null) {
        page('login', [
            'needCode' => !Auth::codeOk(),
            'old' => take_input(),
            'noAccounts' => Wishlist::accounts() === [],
        ], 'Anmelden');
    }
    $uid = (int) $user['id'];
    if (!$user['can_wish']) {
        $people = Wishlist::others($uid);
        if (count($people) === 1) {
            // Nur ein Beschenkter: dessen Liste ist gleich die Startseite.
            show_list(Wishlist::userById((int) $people[0]['id']), $user, true);
        }
        page('giver_home', [
            'people' => $people,
            'notices' => Wishlist::notices($uid),
        ], 'Wunschlisten', 'people');
    }
    page('home', [
        'user' => $user,
        'visibility' => visibility_label($user, Wishlist::viewerChoices($uid)),
        'items' => Wishlist::ownItems($uid),
        'people' => Wishlist::others($uid),
        'notices' => Wishlist::notices($uid),
        'old' => take_input(),
    ], 'Meine Wunschliste', 'home');
}

function route_login(): void
{
    if (!Auth::codeOk()) {
        if (Throttle::blocked('code')) {
            flash('error', Throttle::message());
            redirect('/');
        }
        if (!Auth::acceptCode(is_string($_POST['code'] ?? null) ? $_POST['code'] : '')) {
            Throttle::fail('code');
            flash('error', 'Der Zugangscode stimmt nicht.');
            redirect('/');
        }
    }
    $name = normalize_name($_POST['name'] ?? '');
    if ($name === '') {
        flash('error', 'Bitte gib deinen Namen ein.');
        redirect('/');
    }
    if (Throttle::blocked('name')) {
        flash('error', Throttle::message());
        redirect('/');
    }
    $user = Wishlist::userByName($name);
    if ($user === null) {
        Throttle::fail('name');
        remember_input(['name' => $name]);
        flash('error', 'Den Namen „' . $name . '“ kenne ich nicht. Konten legt der Betreiber der Seite an.');
        redirect('/');
    }
    Auth::login($user);
    redirect('/');
}

function route_logout(): void
{
    Auth::logout();
    flash('success', 'Du bist abgemeldet.');
    redirect('/');
}

// ---------- Listen ----------

function route_people(): void
{
    $user = require_user();
    $people = Wishlist::others((int) $user['id']);
    if ($people === [] || (count($people) === 1 && !$user['can_wish'])) {
        redirect('/');
    }
    page('people', ['people' => $people], 'Andere Listen', 'people');
}

/** Fremde Liste anzeigen; $asHome = als Startseite eines Schenkenden, wenn es nur diese gibt. */
function show_list(array $owner, array $user, bool $asHome): never
{
    $viewerId = (int) $user['id'];
    page('list', [
        'owner' => $owner,
        'items' => Wishlist::itemsFor((int) $owner['id'], $viewerId),
        'viewerId' => $viewerId,
        'single' => !$user['can_wish'] && count(Wishlist::others($viewerId)) === 1,
        'back' => $asHome ? '/' : '/liste/' . (int) $owner['id'],
        'notices' => $asHome ? Wishlist::notices($viewerId) : [],
    ], 'Wünsche von ' . $owner['name'], 'people');
}

function route_list(string $id): void
{
    $user = require_user();
    $ownerId = (int) $id;
    if ($ownerId === (int) $user['id']) {
        // Die eigene Liste gibt es nur in der Besitzeransicht.
        redirect('/');
    }
    $owner = Wishlist::userById($ownerId);
    // Nicht freigegebene Listen sehen aus wie nicht vorhandene.
    if ($owner === null || !Wishlist::canSee($ownerId, (int) $user['id'])) {
        abort(404, 'Diese Liste gibt es nicht.');
    }
    show_list($owner, $user, false);
}

function route_gifts(): void
{
    $user = require_user();
    page('gifts', [
        'groups' => Wishlist::gifts((int) $user['id']),
        'notices' => Wishlist::notices((int) $user['id']),
    ], 'Was ich schenke', 'gifts');
}

// ---------- Eigene Wünsche ----------

function route_item_create(): void
{
    $user = require_wisher();
    try {
        $data = item_input();
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        remember_input(raw_item_input() + ['open' => true]);
        redirect('/#neu');
    }
    try {
        $upload = Images::fromUpload($_FILES['bild'] ?? null);
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        remember_input(raw_item_input() + ['open' => true]);
        redirect('/#neu');
    }
    $id = Wishlist::addItem((int) $user['id'], $data);
    if ($upload !== null) {
        Wishlist::setImage($id, $upload, true);
    }
    flash('success', 'Wunsch hinzugefügt.');
    enrich_from_link($id, $data, !$data['price_manual'], $upload === null);
    redirect('/#e' . $id);
}

function route_item_edit(string $id): void
{
    $user = require_wisher();
    $item = Wishlist::ownItem((int) $user['id'], (int) $id);
    if ($item === null) {
        abort(404, 'Diesen Wunsch gibt es nicht, oder er gehört nicht dir.');
    }
    $old = take_input();
    page('edit', ['item' => $item, 'old' => $old], 'Wunsch bearbeiten', 'home');
}

function route_item_update(string $id): void
{
    $user = require_wisher();
    $uid = (int) $user['id'];
    $item = Wishlist::ownItem($uid, (int) $id);
    if ($item === null) {
        abort(404, 'Diesen Wunsch gibt es nicht, oder er gehört nicht dir.');
    }
    try {
        $data = item_input();
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        remember_input(raw_item_input());
        redirect('/eintrag/' . $item['id']);
    }
    try {
        $upload = Images::fromUpload($_FILES['bild'] ?? null);
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        remember_input(raw_item_input());
        redirect('/eintrag/' . $item['id']);
    }
    $itemId = (int) $item['id'];
    $refresh = isset($_POST['neu_lesen']);
    $urlChanged = $data['url'] !== $item['url'];
    $wantPrice = !$data['price_manual'] && $data['url'] !== null
        && ($urlChanged || $refresh || $item['price_manual'] || $item['price_cents'] === null);
    if (!$data['price_manual'] && !$wantPrice && $data['url'] !== null) {
        // Automatisch gelesenen Preis behalten.
        $data['price_cents'] = $item['price_cents'];
        $data['price_currency'] = $item['price_currency'];
    }
    if (!attempt(fn() => Wishlist::updateItem($uid, $itemId, $data))) {
        Images::delete($upload);
        redirect('/eintrag/' . $itemId);
    }

    // Bild: hochgeladen > entfernt > aus dem Link (wenn nicht von Hand gesetzt).
    $imageManual = (bool) $item['image_manual'];
    if ($upload !== null) {
        Wishlist::setImage($itemId, $upload, true);
        $imageManual = true;
    } elseif (isset($_POST['bild_entfernen'])) {
        Wishlist::setImage($itemId, null, true);
        $imageManual = true;
    } elseif ($refresh) {
        $imageManual = false;
    }
    if (!$imageManual && $data['url'] === null && $item['image'] !== null) {
        Wishlist::setImage($itemId, null, false);
    }
    $wantImage = !$imageManual && $data['url'] !== null && ($urlChanged || $refresh || $item['image'] === null);

    flash('success', 'Gespeichert.');
    enrich_from_link($itemId, $data, $wantPrice, $wantImage);
    redirect('/#e' . $itemId);
}

function route_image(string $id): void
{
    require_user();
    Images::send($id);
}

function route_item_delete(string $id): void
{
    $user = require_wisher();
    attempt(fn() => Wishlist::deleteItem((int) $user['id'], (int) $id), 'Wunsch gelöscht.');
    redirect('/');
}

function route_item_move(string $id, string $direction): void
{
    $user = require_wisher();
    attempt(fn() => Wishlist::move((int) $user['id'], (int) $id, $direction === 'hoch' ? -1 : 1));
    redirect('/#e' . (int) $id);
}

// ---------- Sichtbarkeit der eigenen Liste ----------

/** Kurztext für die Startseite, ohne Namen. Leer, wenn es keine anderen Konten gibt. */
function visibility_label(array $user, array $choices): string
{
    if ($choices === []) {
        return '';
    }
    if (!$user['restricted']) {
        return 'Sichtbar für alle';
    }
    $allowed = count(array_filter($choices, static fn(array $c): bool => (bool) $c['allowed']));
    return $allowed === 0 ? 'Für niemanden sichtbar' : 'Sichtbar für ' . count_label($allowed, 'Person', 'Personen');
}

function route_visibility(): void
{
    $user = require_wisher();
    page('visibility', [
        'restricted' => (bool) $user['restricted'],
        'choices' => Wishlist::viewerChoices((int) $user['id']),
    ], 'Wer sieht meine Liste?', 'home');
}

function route_visibility_save(): void
{
    $user = require_wisher();
    $restricted = ($_POST['sichtbar'] ?? '') === 'auswahl';
    $ids = is_array($_POST['personen'] ?? null) ? $_POST['personen'] : [];
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_scalar'))));
    Wishlist::setVisibility((int) $user['id'], $restricted, $ids);
    $user['restricted'] = $restricted ? 1 : 0;
    $label = visibility_label($user, Wishlist::viewerChoices((int) $user['id']));
    flash('success', match (true) {
        !$restricted => 'Gespeichert. Alle können deine Liste sehen.',
        $label === 'Für niemanden sichtbar' => 'Gespeichert. Deine Liste sieht jetzt niemand außer dir.',
        default => 'Gespeichert. ' . $label . '.',
    });
    redirect('/');
}

// ---------- Reservieren ----------

function route_reserve(string $id): void
{
    $user = require_user();
    attempt(fn() => Wishlist::reserve((int) $user['id'], (int) $id));
    redirect(back_target('/listen') . '#e' . (int) $id);
}

function route_release(string $id): void
{
    $user = require_user();
    attempt(fn() => Wishlist::release((int) $user['id'], (int) $id));
    redirect(back_target('/listen') . '#e' . (int) $id);
}

function route_purchased(string $id): void
{
    $user = require_user();
    attempt(fn() => Wishlist::togglePurchased((int) $user['id'], (int) $id));
    redirect('/schenken#e' . (int) $id);
}

function route_notice_dismiss(string $id): void
{
    $user = require_user();
    Wishlist::dismissNotice((int) $user['id'], (int) $id);
    redirect(back_target('/'));
}

// ---------- Verwaltung ----------

function route_admin(): void
{
    if (!Auth::isAdmin()) {
        page('admin_login', ['enabled' => Auth::adminEnabled()], 'Verwaltung');
    }
    page('admin', ['accounts' => Wishlist::accounts(), 'checks' => server_checks()], 'Verwaltung');
}

/** Prüfungen für den Betrieb, z. B. nach dem Hochladen per FTP. Status: ok, warn, fail. */
function server_checks(): array
{
    $dataDir = Config::dataDir();
    $checks = [
        ['PHP-Version', PHP_VERSION_ID >= 80100 ? 'ok' : 'fail', PHP_VERSION . (PHP_VERSION_ID >= 80300 ? '' : ' (8.3 oder neuer empfohlen)')],
        ['SQLite (pdo_sqlite)', 'ok', 'vorhanden'],
        ['Ordner data/ beschreibbar', is_writable($dataDir) && is_writable($dataDir . '/sessions') ? 'ok' : 'fail',
            is_writable($dataDir) ? 'ja' : 'nein: im FTP-Programm Rechte auf 755 (oder 775) setzen'],
        ['config.php', Config::hasFile() ? 'ok' : 'warn', Config::hasFile() ? 'vorhanden' : 'fehlt, Einstellungen kommen nur aus Umgebungsvariablen'],
        ['Zugangscode', Config::accessCode() !== '' ? 'ok' : 'warn', Config::accessCode() !== '' ? 'gesetzt' : 'nicht gesetzt: jeder, der die Adresse kennt, kommt rein'],
        ['HTTPS', request_is_https() ? 'ok' : 'warn', request_is_https() ? 'aktiv' : 'diese Seite wurde ohne HTTPS geladen'],
        ['Preise aus Links', !Config::priceFetch() ? 'warn' : (LinkFetcher::available() ? 'ok' : 'warn'),
            !Config::priceFetch() ? 'ausgeschaltet' : (LinkFetcher::available() ? 'möglich' : 'der Server erlaubt keine Verbindungen nach außen')],
        ['Unterordner', 'ok', base_path() === '' ? 'keiner (eigene Domain)' : base_path()],
    ];
    return $checks;
}

function route_admin_login(): void
{
    if (Throttle::blocked('admin')) {
        flash('error', Throttle::message());
        redirect('/verwaltung');
    }
    $password = is_string($_POST['passwort'] ?? null) ? $_POST['passwort'] : '';
    if (!Auth::adminLogin($password)) {
        Throttle::fail('admin');
        flash('error', 'Das Passwort stimmt nicht.');
    }
    redirect('/verwaltung');
}

function route_admin_logout(): void
{
    Auth::adminLogout();
    redirect('/verwaltung');
}

function route_admin_create(): void
{
    require_admin();
    attempt(function (): void {
        $user = Wishlist::createUser($_POST['name'] ?? '', ($_POST['status'] ?? '') === 'beschenkt');
        flash('success', 'Konto „' . $user['name'] . '“ angelegt.');
    });
    redirect('/verwaltung');
}

function route_admin_rename(string $id): void
{
    require_admin();
    attempt(fn() => Wishlist::renameUser((int) $id, $_POST['name'] ?? ''), 'Umbenannt.');
    redirect('/verwaltung');
}

function route_admin_status(string $id): void
{
    require_admin();
    $canWish = ($_POST['status'] ?? '') === 'beschenkt';
    attempt(fn() => Wishlist::setCanWish((int) $id, $canWish), $canWish
        ? 'Das Konto hat jetzt eine Wunschliste.'
        : 'Das Konto schenkt jetzt nur. Seine Wünsche bleiben gespeichert, sind aber ausgeblendet.');
    redirect('/verwaltung');
}

function route_admin_delete(string $id): void
{
    require_admin();
    attempt(fn() => Wishlist::deleteUser((int) $id), 'Konto gelöscht.');
    redirect('/verwaltung');
}
