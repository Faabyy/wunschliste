<?php
declare(strict_types=1);

// Konten auf der Kommandozeile verwalten.
//   php bin/konten.php liste
//   php bin/konten.php anlegen "Name" [beschenkt|schenker]   (ohne Angabe: schenker)
//   php bin/konten.php status "Name" beschenkt|schenker
//   php bin/konten.php umbenennen "Alter Name" "Neuer Name"
//   php bin/konten.php loeschen "Name"

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

function parse_status(?string $value): bool
{
    if ($value === null || $value === 'schenker') {
        return false;
    }
    if ($value === 'beschenkt') {
        return true;
    }
    throw new UserError('Status muss „beschenkt“ oder „schenker“ sein.');
}

function find_user(string $name): array
{
    $user = Wishlist::userByName($name);
    if ($user === null) {
        throw new UserError('Kein Konto mit dem Namen „' . $name . '“.');
    }
    return $user;
}

try {
    switch ($command) {
        case 'liste':
            foreach (Wishlist::accounts() as $a) {
                printf("%4d  %-40s %-10s %3d Wünsche\n", $a['id'], $a['name'], $a['can_wish'] ? 'beschenkt' : 'schenker', $a['items']);
            }
            break;
        case 'anlegen':
            if (count($args) < 1 || count($args) > 2) {
                throw new UserError('Aufruf: php bin/konten.php anlegen "Name" [beschenkt|schenker]');
            }
            $user = Wishlist::createUser($args[0], parse_status($args[1] ?? null));
            echo 'Angelegt: ' . $user['name'] . ($user['can_wish'] ? ' (beschenkt)' : ' (schenker)') . PHP_EOL;
            break;
        case 'status':
            if (count($args) !== 2) {
                throw new UserError('Aufruf: php bin/konten.php status "Name" beschenkt|schenker');
            }
            Wishlist::setCanWish((int) find_user($args[0])['id'], parse_status($args[1]));
            echo 'Status geändert.' . PHP_EOL;
            break;
        case 'umbenennen':
            if (count($args) !== 2) {
                throw new UserError('Aufruf: php bin/konten.php umbenennen "Alter Name" "Neuer Name"');
            }
            Wishlist::renameUser((int) find_user($args[0])['id'], $args[1]);
            echo 'Umbenannt.' . PHP_EOL;
            break;
        case 'loeschen':
            if (count($args) !== 1) {
                throw new UserError('Aufruf: php bin/konten.php loeschen "Name"');
            }
            $user = find_user($args[0]);
            Wishlist::deleteUser((int) $user['id']);
            echo 'Gelöscht: ' . $user['name'] . PHP_EOL;
            break;
        default:
            fwrite(STDERR, "Befehle: liste | anlegen NAME [beschenkt|schenker] | status NAME beschenkt|schenker | umbenennen ALT NEU | loeschen NAME\n");
            exit(2);
    }
} catch (UserError $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
