# Wunschliste

Eine kleine Wunschlisten-Seite für Familie und Freunde: Jede Person führt eine eigene Liste, die
anderen können Wünsche heimlich als „Ich schenke das“ reservieren. Wer sich etwas wünscht, sieht
**nie**, was reserviert ist und von wem, so bleibt die Überraschung.

Reines PHP (8.2 oder neuer, für Docker 8.5), SQLite über PDO, kein Framework, kein Composer,
kein Build-Schritt, keine externen Schriften oder CDNs.

> [!WARNING]
> **Es gibt keine Passwörter.** Wer den Zugangscode kennt, kann sich mit *jedem* Namen anmelden,
> auch mit deinem, und sieht dann die Reservierungen auf deiner Liste. Die Seite ist nur für
> einen vertrauenswürdigen Kreis gedacht. Am besten betreibst du sie im LAN oder über Tailscale.
> Muss sie aus dem Internet erreichbar sein, dann nur mit gesetztem `WUNSCHLISTE_CODE`, hinter
> HTTPS, und ohne etwas Wichtiges darin.

## Funktionen

- **Anmeldung nur mit Namen** (ohne Rücksicht auf Groß-/Kleinschreibung, auch bei Umlauten), dazu
  optional ein gemeinsamer **Zugangscode**, den man einmal pro Gerät eingibt.
- **Konten legt nur der Betreiber an**: über `/verwaltung` (eigenes Passwort) oder auf der
  Kommandozeile. Unbekannte Namen werden abgelehnt.
- **Zwei Status pro Konto**: *Beschenkt* (eigene Wunschliste, kann auch schenken) oder *schenkt nur*
  (keine eigene Liste, sieht direkt die Wunschlisten der Beschenkten). Neue Konten schenken
  standardmäßig nur. Wechselt ein beschenktes Konto auf „schenkt nur“, bleiben seine Wünsche und
  Reservierungen gespeichert, sind aber für alle ausgeblendet, bis du es zurückstellst.
- **Nur ein Beschenkter** (z. B. ein Kind): Bei ihm entfallen „Andere Listen“ und „Was ich schenke“,
  Schenkende landen nach dem Login direkt auf seiner Liste. Kommt ein zweiter Beschenkter dazu,
  erscheinen Übersicht und Abschnitt automatisch wieder.
- **Eigene Liste**: Wünsche mit Titel, Link (nur http/https, Anzeige als Domain), Notiz und Preis
  anlegen, bearbeiten, löschen und sortieren.
- **Preis und Bild aus dem Link**: Ohne eigene Angabe liest die Seite Preis und Vorschaubild aus den
  Produktdaten des Shops (JSON-LD, Meta-Tags, Microdata). Der Preis erscheint als „ca. …“.
  **Amazon und eBay sperren automatische Abrufe bewusst**, das wird nicht umgangen. Bei Amazon kommt
  das Bild trotzdem über die öffentliche Bildadresse zur Artikelnummer, den Preis trägt man von
  Hand ein. Bei eBay beides von Hand. Die Seite weist nach dem Speichern darauf hin.
- **Eigene Bilder**: Pro Wunsch lässt sich ein Bild hochladen (JPEG, PNG, GIF, WebP, kein SVG). Der
  Browser verkleinert es vorher. Metadaten wie der GPS-Standort von Handyfotos werden entfernt.
  Bilder liegen in `data/bilder/` und werden nur an Angemeldete ausgeliefert. Fremde Bildserver
  sehen so keine Besucher.
- **Geheime Reservierung**: Andere sehen „Anna schenkt das“, der Besitzer sieht nichts. Nur wer
  reserviert hat, kann die Reservierung aufheben.
- **Wer sieht meine Liste?** Jede beschenkte Person legt selbst fest, ob alle ihre Liste sehen oder
  nur ausgewählte Konten (Link „ändern“ unter der eigenen Liste). Für alle anderen gibt es die
  Liste dann nicht: nicht in der Übersicht, nicht über die Adresse, kein Reservieren. Wer vorher
  schon etwas zugesagt hat, behält die Zusage unter „Was ich schenke“ und kann sie abhaken oder
  aufheben. Neue Konten sehen eine eingeschränkte Liste erst, wenn sie ausgewählt werden.
- **Hinweise an Schenkende**: Löscht der Besitzer einen reservierten Wunsch, bekommt der Schenkende
  beim nächsten Besuch „Ein von dir reservierter Wunsch von X wurde entfernt: …“. Ändert er Titel,
  Link oder Notiz, gibt es ebenfalls einen dezenten Hinweis.
- **Was ich schenke**: alle eigenen Zusagen über alle Listen, mit privatem Haken „gekauft“ und
  ungefährer Summe.
- Hell und dunkel nach Systemeinstellung, fürs Handy gebaut (Tableiste unten), funktioniert auch
  ohne JavaScript.

## Wie die Reservierung geheim bleibt

Das ist serverseitig abgesichert, nicht nur in der Anzeige:

- Reservierungen liegen in einer eigenen Tabelle `reservations`. Alle Abfragen für die eigene
  Liste (`Wishlist::ownItems()`, `ownItem()`) lesen diese Tabelle nie. Im HTML, in Attributen,
  Klassen oder Zählern des Besitzers kann deshalb nichts davon auftauchen.
- `/liste/<eigene-id>` leitet auf die eigene Ansicht um, und `Wishlist::itemsFor()` verweigert
  die eigene Liste ausdrücklich.
- Bearbeiten, Löschen, Reservieren- oder Freigeben-Versuche des Besitzers liefern immer dieselbe
  Antwort, egal ob reserviert ist.
- Die Verwaltung zeigt nur Namen und Anzahl der Wünsche, keine Reservierungen.
- Die Einstellung „Wer sieht meine Liste?“ liest und ändert keine Reservierungen. Der Besitzer
  erfährt dort also nicht, ob jemand, den er herausnimmt, schon etwas zugesagt hat.
- `tests/smoke.py` prüft das: Die Seiten des Besitzers müssen vor und nach einer Reservierung
  **byte-gleich** sein.

## Lokal starten

```sh
php bin/konten.php anlegen "Fabian" beschenkt   # erstes Konto anlegen
php -S localhost:8000 -t public
```

Dann <http://localhost:8000> öffnen. Die Datenbank `data/wunschliste.sqlite` und das Schema
entstehen beim ersten Aufruf von selbst. Ohne Umgebungsvariablen gibt es keinen Zugangscode und
keine Web-Verwaltung. Mit beidem:

```sh
WUNSCHLISTE_CODE=tannenbaum WUNSCHLISTE_ADMIN_PASSWORT=geheim php -S localhost:8000 -t public
```

## Hochladen per FTP (Webspace)

Läuft auf normalem Webspace mit **Apache, PHP 8.1 oder neuer (empfohlen 8.3+) und pdo_sqlite**,
ohne Kommandozeile.

1. Paket bauen: `bin/ftp-paket.sh` legt `ftp-upload/wunschliste/` an. Darin ist nur, was auf den
   Server gehört, keine Docker- und Testdateien.
2. Im Paket `config.sample.php` kopieren zu `config.php` und mindestens `code` (Zugangscode für
   alle) und `admin_passwort` (für dich) eintragen.
3. Den Ordner per FTP hochladen. **Wichtig:** Die versteckten Dateien `.htaccess` (im Hauptordner,
   in `public/`, `src/` und `data/`) müssen mit. Im FTP-Programm ggf. „versteckte Dateien
   anzeigen“ einschalten.
4. Wohin? Am besten bekommt die Seite eine eigene Subdomain (z. B. `wunsch.example.de`), deren
   Zielverzeichnis auf `wunschliste/public` zeigt. Geht das nicht, kann der Ordner auch so in einen
   Unterordner (`example.de/wunschliste/`). Dann leitet die `.htaccess` im Hauptordner alles nach
   `public/` um, und `data/`, `src/` und `config.php` bleiben unerreichbar.
5. Falls `data/` nicht beschreibbar ist: im FTP-Programm die Rechte des Ordners auf 755 (oder 775)
   setzen.
6. `…/verwaltung` öffnen und mit dem Verwaltungspasswort anmelden. Oben steht die
   **Server-Prüfung**: PHP-Version, Schreibrechte, HTTPS und ob die Datenbank vor Abruf aus dem Web
   geschützt ist. Alles sollte grün sein. Dann die Konten anlegen.

**Updates:** Paket neu bauen und hochladen. `config.php` und `data/` auf dem Server dabei nicht
löschen. Das Paket enthält beides nicht, also wird nichts überschrieben.

**HTTPS:** Mit `'https' => true` (Vorgabe in der Vorlage) leitet die Seite `http://` auf
`https://` um. Für die (Sub-)Domain muss dafür ein SSL-Zertifikat eingerichtet sein.

## Betrieb mit Docker

```sh
cp .env.example .env        # Code und Verwaltungspasswort eintragen
docker compose up -d --build
docker compose exec -u www-data wunschliste php bin/konten.php anlegen "Fabian" beschenkt
```

Die Seite läuft dann auf Port 8080. Die Daten liegen im benannten Volume `wunschliste-data`
(`/var/www/html/data` im Container), außerhalb des Web-Roots `public/`.

**Updates:** Das Image baut auf `php:8.5-apache` auf, der aktuellen stabilen PHP-Reihe. Damit
Sicherheitsupdates von PHP und Apache ankommen, regelmäßig neu bauen:

```sh
docker compose build --pull && docker compose up -d
```

Kommt eine neue PHP-Reihe heraus, `ARG PHP_VERSION` im `Dockerfile` anheben.

**Aus dem Internet erreichbar:** Davor einen Reverse-Proxy mit HTTPS setzen (z. B. Caddy oder
Traefik), `WUNSCHLISTE_HTTPS=1` setzen und `WUNSCHLISTE_CODE` auf jeden Fall füllen. Fehlversuche
bei Code, Namen und Verwaltung werden pro IP gebremst. Hinter einem Proxy sehen alle Anfragen
gleich aus, dann gilt die Bremse für alle gemeinsam.

## Einstellungen

Entweder in `config.php` (Vorlage `config.sample.php`) oder als Umgebungsvariable. Eine gesetzte
Umgebungsvariable hat Vorrang.

| `config.php` | Umgebungsvariable | Bedeutung |
|---|---|---|
| `code` | `WUNSCHLISTE_CODE` | Gemeinsamer Zugangscode. Leer = kein Code. Ändern meldet alle Geräte ab. |
| `admin_passwort` | `WUNSCHLISTE_ADMIN_PASSWORT` | Passwort für `/verwaltung`. Leer = Verwaltung aus. |
| `https` | `WUNSCHLISTE_HTTPS` | `true`/`1`: auf HTTPS umleiten, Cookie nur über HTTPS. |
| `preisabruf` | `WUNSCHLISTE_PREISABRUF` | `false`/`0` schaltet das Lesen von Preisen aus Links ab. |
| `basispfad` | `WUNSCHLISTE_BASISPFAD` | Unterordner, falls die automatische Erkennung nicht passt. |
| `datenordner` | `WUNSCHLISTE_DATA` | Anderer Datenordner (Standard: `data/` neben `public/`). |

## Konten auf der Kommandozeile

```sh
php bin/konten.php liste
php bin/konten.php anlegen "Lena" beschenkt     # mit eigener Wunschliste
php bin/konten.php anlegen "Oma Gerda"          # schenkt nur (Standard)
php bin/konten.php status "Oma Gerda" beschenkt
php bin/konten.php umbenennen "Oma Gerda" "Oma"
php bin/konten.php loeschen "Oma"
```

Wird ein Konto gelöscht, verschwinden seine Wünsche. Wer davon etwas reserviert hatte, bekommt einen
Hinweis.

## Sicherheit

- Alle Ausgaben laufen durch `htmlspecialchars`, alle Abfragen sind Prepared Statements.
- CSRF-Token in jedem POST-Formular, Änderungen nur per POST (danach Redirect).
- Session-Cookie mit `HttpOnly` und `SameSite=Lax`, neue Session-ID bei jeder Anmeldung.
- Strenge Content-Security-Policy (`default-src 'self'`, kein Inline-JS oder -CSS),
  `frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `noindex`.
- Der Preisabruf verbindet sich nur mit öffentlichen IPv4-Adressen auf Port 80/443 und prüft jede
  Weiterleitung neu. So kann niemand über die Seite Geräte im eigenen Netz abfragen (SSRF).

## Struktur

```
public/        Web-Root: index.php (einziger Einstieg, Routing), assets/
src/           bootstrap, Config, Database (Schema), Auth, Csrf, Throttle,
               Wishlist (alle Abfragen), PriceFetcher, routes.php, views/
bin/           konten.php (Konten per Kommandozeile), ftp-paket.sh (Upload-Ordner bauen)
data/          SQLite-Datenbank und Sessions (nicht im Git)
docker/        Apache- und PHP-Konfiguration fürs Image
tests/         smoke.py (Kernfälle gegen php -S), price_test.php
```

## Tests

```sh
python3 tests/smoke.py            # startet php -S selbst, nutzt einen leeren Datenordner
python3 tests/smoke.py webspace   # dasselbe als Webspace-Fall: Unterordner, config.php
php tests/price_test.php    # Preiserkennung und SSRF-Sperre, ohne Netz
```

---

Co-Authored-By: Claude
