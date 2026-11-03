<?php
declare(strict_types=1);

/**
 * Alle Datenbankabfragen.
 *
 * Regel für die geheime Reservierung: Funktionen für die EIGENE Liste (own*) lesen die Tabelle
 * reservations nie. Was der Besitzer zu sehen bekommt, kann deshalb nichts darüber enthalten.
 */
final class Wishlist
{
    private const ITEM_COLUMNS = 'i.id, i.user_id, i.title, i.url, i.note, i.price_cents, i.price_currency, i.price_manual, i.position';

    private static function db(): PDO
    {
        return Database::get();
    }

    private static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private static function all(string $sql, array $params = []): array
    {
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ---------- Nutzer ----------

    public static function userById(int $id): ?array
    {
        return self::one('SELECT id, name, can_wish FROM users WHERE id = ?', [$id]);
    }

    public static function userByName(string $name): ?array
    {
        $key = name_key($name);
        return $key === '' ? null : self::one('SELECT id, name, can_wish FROM users WHERE name_key = ?', [$key]);
    }

    /** Alle anderen Beschenkten mit Anzahl Wünsche und davon noch freien. */
    public static function others(int $viewerId): array
    {
        return self::all(
            'SELECT u.id, u.name, COUNT(i.id) AS items, COUNT(i.id) - COUNT(r.item_id) AS free
               FROM users u
               LEFT JOIN items i ON i.user_id = u.id
               LEFT JOIN reservations r ON r.item_id = i.id
              WHERE u.id <> ? AND u.can_wish = 1
              GROUP BY u.id
              ORDER BY u.name_key',
            [$viewerId]
        );
    }

    // ---------- Eigene Liste (ohne Reservierungsdaten) ----------

    public static function ownItems(int $ownerId): array
    {
        return self::all('SELECT ' . self::ITEM_COLUMNS . ' FROM items i WHERE i.user_id = ? ORDER BY i.position, i.id', [$ownerId]);
    }

    public static function ownItem(int $ownerId, int $itemId): ?array
    {
        return self::one('SELECT ' . self::ITEM_COLUMNS . ' FROM items i WHERE i.id = ? AND i.user_id = ?', [$itemId, $ownerId]);
    }

    public static function addItem(int $ownerId, array $data): int
    {
        return Database::tx(function (PDO $db) use ($ownerId, $data): int {
            $stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM items WHERE user_id = ?');
            $stmt->execute([$ownerId]);
            $position = (int) $stmt->fetchColumn();
            $db->prepare(
                'INSERT INTO items (user_id, title, url, note, price_cents, price_currency, price_manual, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ownerId, $data['title'], $data['url'], $data['note'],
                $data['price_cents'], $data['price_currency'], $data['price_manual'] ? 1 : 0, $position,
            ]);
            return (int) $db->lastInsertId();
        });
    }

    /**
     * Ändert einen eigenen Wunsch. Ist er reserviert und haben sich Titel, Link oder Notiz geändert,
     * bekommt der Schenkende einen Hinweis. Für den Besitzer verläuft alles gleich.
     */
    public static function updateItem(int $ownerId, int $itemId, array $data): void
    {
        Database::tx(function (PDO $db) use ($ownerId, $itemId, $data): void {
            $old = self::ownItem($ownerId, $itemId);
            if ($old === null) {
                throw new UserError('Diesen Wunsch gibt es nicht (mehr).');
            }
            $db->prepare(
                "UPDATE items SET title = ?, url = ?, note = ?, price_cents = ?, price_currency = ?, price_manual = ?,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND user_id = ?"
            )->execute([
                $data['title'], $data['url'], $data['note'], $data['price_cents'], $data['price_currency'],
                $data['price_manual'] ? 1 : 0, $itemId, $ownerId,
            ]);

            $changed = $old['title'] !== $data['title'] || $old['url'] !== $data['url'] || $old['note'] !== $data['note'];
            if (!$changed) {
                return;
            }
            $giver = $db->prepare('SELECT user_id FROM reservations WHERE item_id = ?');
            $giver->execute([$itemId]);
            $giverId = $giver->fetchColumn();
            if ($giverId === false) {
                return;
            }
            // Pro Wunsch höchstens ein offener Änderungshinweis, mit dem aktuellen Titel.
            $db->prepare("DELETE FROM notices WHERE user_id = ? AND item_id = ? AND kind = 'changed'")
                ->execute([$giverId, $itemId]);
            self::addNotice($db, (int) $giverId, 'changed', $itemId, $ownerId, $data['title']);
        });
    }

    public static function setPrice(int $itemId, ?array $price): void
    {
        self::db()->prepare('UPDATE items SET price_cents = ?, price_currency = ? WHERE id = ? AND price_manual = 0')
            ->execute([$price['cents'] ?? null, $price['currency'] ?? null, $itemId]);
    }

    /** Löscht einen eigenen Wunsch. War er reserviert, erfährt es nur der Schenkende. */
    public static function deleteItem(int $ownerId, int $itemId): void
    {
        Database::tx(function (PDO $db) use ($ownerId, $itemId): void {
            $item = self::ownItem($ownerId, $itemId);
            if ($item === null) {
                throw new UserError('Diesen Wunsch gibt es nicht (mehr).');
            }
            self::noticeRemoved($db, $item);
            $db->prepare('DELETE FROM items WHERE id = ? AND user_id = ?')->execute([$itemId, $ownerId]);
        });
    }

    private static function noticeRemoved(PDO $db, array $item): void
    {
        $stmt = $db->prepare('SELECT user_id FROM reservations WHERE item_id = ?');
        $stmt->execute([$item['id']]);
        $giverId = $stmt->fetchColumn();
        $db->prepare('DELETE FROM notices WHERE item_id = ?')->execute([$item['id']]);
        if ($giverId !== false) {
            self::addNotice($db, (int) $giverId, 'removed', null, (int) $item['user_id'], $item['title']);
        }
    }

    private static function addNotice(PDO $db, int $userId, string $kind, ?int $itemId, int $ownerId, string $title): void
    {
        $owner = self::userById($ownerId);
        $db->prepare('INSERT INTO notices (user_id, kind, item_id, owner_id, owner_name, item_title) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $kind, $itemId, $ownerId, $owner['name'] ?? '?', $title]);
    }

    public static function move(int $ownerId, int $itemId, int $direction): void
    {
        Database::tx(function (PDO $db) use ($ownerId, $itemId, $direction): void {
            $item = self::ownItem($ownerId, $itemId);
            if ($item === null) {
                throw new UserError('Diesen Wunsch gibt es nicht (mehr).');
            }
            $sql = $direction < 0
                ? 'SELECT id, position FROM items WHERE user_id = ? AND position < ? ORDER BY position DESC LIMIT 1'
                : 'SELECT id, position FROM items WHERE user_id = ? AND position > ? ORDER BY position ASC LIMIT 1';
            $stmt = $db->prepare($sql);
            $stmt->execute([$ownerId, $item['position']]);
            $neighbour = $stmt->fetch();
            if ($neighbour === false) {
                return;
            }
            $update = $db->prepare('UPDATE items SET position = ? WHERE id = ?');
            $update->execute([$neighbour['position'], $item['id']]);
            $update->execute([$item['position'], $neighbour['id']]);
        });
    }

    // ---------- Fremde Listen (mit Reservierungen) ----------

    public static function itemsFor(int $ownerId, int $viewerId): array
    {
        if ($ownerId === $viewerId) {
            // Darf nie passieren; die eigene Liste läuft ausschließlich über ownItems().
            throw new LogicException('Reservierungen der eigenen Liste werden nie geladen.');
        }
        return self::all(
            'SELECT ' . self::ITEM_COLUMNS . ', r.user_id AS giver_id, g.name AS giver_name
               FROM items i
               LEFT JOIN reservations r ON r.item_id = i.id
               LEFT JOIN users g ON g.id = r.user_id
              WHERE i.user_id = ?
              ORDER BY i.position, i.id',
            [$ownerId]
        );
    }

    public static function reserve(int $viewerId, int $itemId): void
    {
        self::assertForeign($viewerId, $itemId);
        try {
            self::db()->prepare('INSERT INTO reservations (item_id, user_id) VALUES (?, ?)')->execute([$itemId, $viewerId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new UserError('Das hat gerade schon jemand anderes reserviert.');
            }
            throw $e;
        }
    }

    public static function release(int $viewerId, int $itemId): void
    {
        self::assertForeign($viewerId, $itemId);
        $stmt = self::db()->prepare('DELETE FROM reservations WHERE item_id = ? AND user_id = ?');
        $stmt->execute([$itemId, $viewerId]);
        if ($stmt->rowCount() === 0) {
            throw new UserError('Diese Reservierung kannst nur du aufheben, wenn du sie gemacht hast.');
        }
        self::db()->prepare("DELETE FROM notices WHERE user_id = ? AND item_id = ?")->execute([$viewerId, $itemId]);
    }

    public static function togglePurchased(int $viewerId, int $itemId): void
    {
        self::assertForeign($viewerId, $itemId);
        $stmt = self::db()->prepare('UPDATE reservations SET purchased = 1 - purchased WHERE item_id = ? AND user_id = ?');
        $stmt->execute([$itemId, $viewerId]);
        if ($stmt->rowCount() === 0) {
            throw new UserError('Das hast du nicht reserviert.');
        }
    }

    /**
     * Prüft zuerst und ohne Blick auf Reservierungen, ob es der eigene Wunsch ist. So bekommt der
     * Besitzer immer dieselbe Antwort, egal ob jemand reserviert hat.
     */
    private static function assertForeign(int $viewerId, int $itemId): void
    {
        $owner = self::one('SELECT i.user_id FROM items i JOIN users u ON u.id = i.user_id WHERE i.id = ? AND u.can_wish = 1', [$itemId]);
        if ($owner === null) {
            throw new UserError('Diesen Wunsch gibt es nicht mehr.');
        }
        if ((int) $owner['user_id'] === $viewerId) {
            throw new UserError('Das ist dein eigener Wunsch.');
        }
    }

    /** Was ich verschenke, gruppiert nach Person. */
    public static function gifts(int $viewerId): array
    {
        $rows = self::all(
            'SELECT ' . self::ITEM_COLUMNS . ', u.name AS owner_name, r.purchased
               FROM reservations r
               JOIN items i ON i.id = r.item_id
               JOIN users u ON u.id = i.user_id
              WHERE r.user_id = ? AND u.can_wish = 1
              ORDER BY u.name_key, i.position, i.id',
            [$viewerId]
        );
        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['user_id']]['name'] = $row['owner_name'];
            $groups[(int) $row['user_id']]['items'][] = $row;
        }
        return $groups;
    }

    // ---------- Hinweise ----------

    public static function notices(int $userId): array
    {
        return self::all('SELECT id, kind, item_id, owner_id, owner_name, item_title FROM notices WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public static function dismissNotice(int $userId, int $noticeId): void
    {
        self::db()->prepare('DELETE FROM notices WHERE id = ? AND user_id = ?')->execute([$noticeId, $userId]);
    }

    // ---------- Verwaltung ----------

    public static function accounts(): array
    {
        return self::all(
            'SELECT u.id, u.name, u.can_wish, u.created_at, COUNT(i.id) AS items
               FROM users u LEFT JOIN items i ON i.user_id = u.id
              GROUP BY u.id ORDER BY u.name_key'
        );
    }

    public static function validName(mixed $raw): string
    {
        $name = normalize_name($raw);
        if ($name === '') {
            throw new UserError('Bitte gib einen Namen ein.');
        }
        if (str_len($name) > 40) {
            throw new UserError('Der Name darf höchstens 40 Zeichen lang sein.');
        }
        return $name;
    }

    public static function createUser(mixed $raw, bool $canWish): array
    {
        $name = self::validName($raw);
        try {
            self::db()->prepare('INSERT INTO users (name, name_key, can_wish) VALUES (?, ?, ?)')
                ->execute([$name, name_key($name), $canWish ? 1 : 0]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new UserError('Den Namen „' . $name . '“ gibt es schon.');
            }
            throw $e;
        }
        return ['id' => (int) self::db()->lastInsertId(), 'name' => $name, 'can_wish' => $canWish ? 1 : 0];
    }

    public static function renameUser(int $id, mixed $raw): void
    {
        $name = self::validName($raw);
        try {
            $stmt = self::db()->prepare('UPDATE users SET name = ?, name_key = ? WHERE id = ?');
            $stmt->execute([$name, name_key($name), $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new UserError('Den Namen „' . $name . '“ gibt es schon.');
            }
            throw $e;
        }
        if ($stmt->rowCount() === 0) {
            throw new UserError('Dieses Konto gibt es nicht.');
        }
    }

    /**
     * Beschenkt (eigene Liste) oder schenkt nur. Wünsche bleiben beim Wechsel erhalten und sind
     * nur ausgeblendet, solange das Konto „schenkt nur“ ist.
     */
    public static function setCanWish(int $id, bool $canWish): void
    {
        $stmt = self::db()->prepare('UPDATE users SET can_wish = ? WHERE id = ?');
        $stmt->execute([$canWish ? 1 : 0, $id]);
        if (self::userById($id) === null) {
            throw new UserError('Dieses Konto gibt es nicht.');
        }
    }

    public static function deleteUser(int $id): void
    {
        Database::tx(function (PDO $db) use ($id): void {
            if (self::userById($id) === null) {
                throw new UserError('Dieses Konto gibt es nicht.');
            }
            foreach (self::ownItems($id) as $item) {
                self::noticeRemoved($db, $item);
            }
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        });
    }
}
