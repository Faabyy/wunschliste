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

/** Holt den Preis aus dem Link, wenn keiner von Hand eingetragen ist. */
function fill_price(int $itemId, array $data): void
{
    if ($data['price_manual'] || $data['url'] === null || !Config::priceFetch()) {
        return;
    }
    Wishlist::setPrice($itemId, PriceFetcher::fetch($data['url']));
}

// ---------- Anmeldung ----------

function route_home(): void
{
    $user = Auth::user();
    if ($user === null) {
        page('login', ['needCode' => !Auth::codeOk(), 'old' => take_input()], 'Anmelden');
    }
    $uid = (int) $user['id'];
    page('home', [
        'user' => $user,
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
    page('people', ['people' => Wishlist::others((int) $user['id'])], 'Andere Listen', 'people');
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
    if ($owner === null) {
        abort(404, 'Diese Liste gibt es nicht.');
    }
    page('list', [
        'owner' => $owner,
        'items' => Wishlist::itemsFor($ownerId, (int) $user['id']),
        'viewerId' => (int) $user['id'],
    ], 'Wünsche von ' . $owner['name'], 'people');
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
    $user = require_user();
    try {
        $data = item_input();
    } catch (UserError $e) {
        flash('error', $e->getMessage());
        remember_input(raw_item_input() + ['open' => true]);
        redirect('/#neu');
    }
    $id = Wishlist::addItem((int) $user['id'], $data);
    fill_price($id, $data);
    flash('success', 'Wunsch hinzugefügt.');
    redirect('/#e' . $id);
}

function route_item_edit(string $id): void
{
    $user = require_user();
    $item = Wishlist::ownItem((int) $user['id'], (int) $id);
    if ($item === null) {
        abort(404, 'Diesen Wunsch gibt es nicht, oder er gehört nicht dir.');
    }
    $old = take_input();
    page('edit', ['item' => $item, 'old' => $old], 'Wunsch bearbeiten', 'home');
}

function route_item_update(string $id): void
{
    $user = require_user();
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
    $refetch = !$data['price_manual'] && $data['url'] !== null
        && ($data['url'] !== $item['url'] || $item['price_manual'] || $item['price_cents'] === null || isset($_POST['preis_neu']));
    if (!$data['price_manual'] && !$refetch && $data['url'] !== null) {
        // Automatisch gelesenen Preis behalten.
        $data['price_cents'] = $item['price_cents'];
        $data['price_currency'] = $item['price_currency'];
    }
    if (!attempt(fn() => Wishlist::updateItem($uid, (int) $item['id'], $data))) {
        redirect('/eintrag/' . $item['id']);
    }
    if ($refetch) {
        fill_price((int) $item['id'], $data);
    }
    flash('success', 'Gespeichert.');
    redirect('/#e' . $item['id']);
}

function route_item_delete(string $id): void
{
    $user = require_user();
    attempt(fn() => Wishlist::deleteItem((int) $user['id'], (int) $id), 'Wunsch gelöscht.');
    redirect('/');
}

function route_item_move(string $id, string $direction): void
{
    $user = require_user();
    attempt(fn() => Wishlist::move((int) $user['id'], (int) $id, $direction === 'hoch' ? -1 : 1));
    redirect('/#e' . (int) $id);
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
    page('admin', ['accounts' => Wishlist::accounts()], 'Verwaltung');
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
        $user = Wishlist::createUser($_POST['name'] ?? '');
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

function route_admin_delete(string $id): void
{
    require_admin();
    attempt(fn() => Wishlist::deleteUser((int) $id), 'Konto gelöscht.');
    redirect('/verwaltung');
}
