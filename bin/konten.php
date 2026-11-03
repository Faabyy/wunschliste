<?php
declare(strict_types=1);

// Konten auf der Kommandozeile verwalten.
//   php bin/konten.php liste
//   php bin/konten.php anlegen "Name"
//   php bin/konten.php umbenennen "Alter Name" "Neuer Name"
//   php bin/konten.php loeschen "Name"

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? '';
$args = array_slice($argv, 2);

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
                printf("%4d  %-40s %3d Wünsche\n", $a['id'], $a['name'], $a['items']);
            }
            break;
        case 'anlegen':
            if (count($args) !== 1) {
                throw new UserError('Aufruf: php bin/konten.php anlegen "Name"');
            }
            $user = Wishlist::createUser($args[0]);
            echo 'Angelegt: ' . $user['name'] . PHP_EOL;
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
            fwrite(STDERR, "Befehle: liste | anlegen NAME | umbenennen ALT NEU | loeschen NAME\n");
            exit(2);
    }
} catch (UserError $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
