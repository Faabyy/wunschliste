<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dir = Config::dataDir();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Datenordner kann nicht angelegt werden: ' . $dir);
        }
        $pdo = new PDO('sqlite:' . $dir . '/wunschliste.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    /** Führt $fn in einer Schreib-Transaktion aus. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::get();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Schema-Versionen; jede Stufe läuft genau einmal, gezählt über PRAGMA user_version. */
    private static function migrations(): array
    {
        return [
            1 => <<<'SQL'
                    CREATE TABLE users (
                        id         INTEGER PRIMARY KEY,
                        name       TEXT NOT NULL,
                        name_key   TEXT NOT NULL UNIQUE,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );

                    CREATE TABLE items (
                        id             INTEGER PRIMARY KEY,
                        user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        title          TEXT NOT NULL,
                        url            TEXT,
                        note           TEXT,
                        price_cents    INTEGER,
                        price_currency TEXT,
                        price_manual   INTEGER NOT NULL DEFAULT 0,
                        position       INTEGER NOT NULL,
                        created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE INDEX items_user_position ON items(user_id, position);

                    -- Eigene Tabelle: Abfragen für die eigene Liste berühren sie nie.
                    -- item_id als Primärschlüssel = höchstens eine Reservierung pro Wunsch.
                    CREATE TABLE reservations (
                        item_id    INTEGER PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
                        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        purchased  INTEGER NOT NULL DEFAULT 0,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE INDEX reservations_user ON reservations(user_id);

                    -- Hinweise an Schenkende (Wunsch gelöscht oder geändert).
                    CREATE TABLE notices (
                        id         INTEGER PRIMARY KEY,
                        user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        kind       TEXT NOT NULL CHECK (kind IN ('removed', 'changed')),
                        item_id    INTEGER REFERENCES items(id) ON DELETE SET NULL,
                        owner_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
                        owner_name TEXT NOT NULL,
                        item_title TEXT NOT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE INDEX notices_user ON notices(user_id);

                    -- Fehlversuche für Zugangscode, Namen und Verwaltung.
                    CREATE TABLE attempts (
                        ip   TEXT NOT NULL,
                        kind TEXT NOT NULL,
                        at   INTEGER NOT NULL
                    );
                    CREATE INDEX attempts_lookup ON attempts(ip, kind, at);
                SQL,
            // Status pro Konto: 1 = beschenkt (eigene Wunschliste), 0 = schenkt nur.
            2 => 'ALTER TABLE users ADD COLUMN can_wish INTEGER NOT NULL DEFAULT 1',
            // Bild zum Wunsch (Dateiname in data/bilder/); image_manual = hochgeladen oder bewusst entfernt.
            3 => 'ALTER TABLE items ADD COLUMN image TEXT;
                  ALTER TABLE items ADD COLUMN image_manual INTEGER NOT NULL DEFAULT 0;',
        ];
    }

    private static function migrate(PDO $pdo): void
    {
        $target = max(array_keys(self::migrations()));
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= $target) {
            return;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Erst in der Sperre lesen: Ein paralleler Aufruf kann schon migriert haben.
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            foreach (self::migrations() as $step => $sql) {
                if ($step > $version) {
                    $pdo->exec($sql);
                    $pdo->exec('PRAGMA user_version = ' . $step);
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
