<?php
declare(strict_types=1);

/**
 * Bilder zu Wünschen. Sie liegen in data/bilder/ (außerhalb des Web-Roots) und werden über
 * /bild/<name> nur an angemeldete Nutzer ausgeliefert. Fremde Bildserver sehen so keine Besucher.
 *
 * Erlaubt sind JPEG, PNG, GIF und WebP, erkannt am Dateiinhalt (nie SVG). Mit GD wird auf höchstens
 * 800 px verkleinert und neu als JPEG gespeichert; ohne GD werden bei JPEG die Metadaten
 * (EXIF, also z. B. GPS-Position von Handyfotos) entfernt.
 */
final class Images
{
    private const MAX_SIDE = 800;
    private const MIN_SIDE = 60;
    private const NAME = '/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/';

    public static function dir(): string
    {
        $dir = Config::dataDir() . '/bilder';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Bildordner kann nicht angelegt werden: ' . $dir);
        }
        return $dir;
    }

    public static function path(string $name): ?string
    {
        return preg_match(self::NAME, $name) === 1 && is_file(self::dir() . '/' . $name) ? self::dir() . '/' . $name : null;
    }

    public static function delete(?string $name): void
    {
        if ($name !== null && ($path = self::path($name)) !== null) {
            @unlink($path);
        }
    }

    /** Lädt ein Bild aus dem Netz (gleiche SSRF-Regeln wie der Seitenabruf). */
    public static function fromUrl(string $url): ?string
    {
        $res = LinkFetcher::download($url, LinkFetcher::MAX_IMAGE);
        if ($res === null || $res['status'] !== 200 || $res['body'] === '') {
            return null;
        }
        try {
            return self::store($res['body']);
        } catch (UserError) {
            return null;
        }
    }

    /** Nimmt eine hochgeladene Datei aus $_FILES an. Null, wenn keine Datei gewählt wurde. */
    public static function fromUpload(mixed $file): ?string
    {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new UserError('Das Bild ist zu groß. Bitte ein kleineres wählen.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new UserError('Das Bild konnte nicht hochgeladen werden.');
        }
        if ((int) $file['size'] > LinkFetcher::MAX_IMAGE) {
            throw new UserError('Das Bild ist zu groß (höchstens 5 MB).');
        }
        return self::store((string) file_get_contents((string) $file['tmp_name']));
    }

    /** Prüft, verkleinert bzw. bereinigt und speichert ein Bild. Gibt den Dateinamen zurück. */
    public static function store(string $bytes): string
    {
        $ext = self::detect($bytes);
        $info = $ext === null ? false : @getimagesizefromstring($bytes);
        if ($ext === null || $info === false) {
            throw new UserError('Das ist kein Bild im Format JPEG, PNG, GIF oder WebP.');
        }
        [$width, $height] = $info;
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            throw new UserError('Das Bild ist zu klein.');
        }
        $resized = self::resize($bytes, $width, $height);
        if ($resized !== null) {
            [$bytes, $ext] = [$resized, 'jpg'];
        } elseif ($ext === 'jpg') {
            $bytes = self::stripJpegMetadata($bytes);
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (file_put_contents(self::dir() . '/' . $name, $bytes) === false) {
            throw new RuntimeException('Bild konnte nicht gespeichert werden.');
        }
        return $name;
    }

    private static function detect(string $b): ?string
    {
        return match (true) {
            str_starts_with($b, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($b, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($b, 'GIF87a'), str_starts_with($b, 'GIF89a') => 'gif',
            str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /** Mit GD: auf MAX_SIDE verkleinern, auf Weiß setzen (Transparenz) und als JPEG speichern. */
    private static function resize(string $bytes, int $width, int $height): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        imagejpeg($dst, null, 85);
        return (string) ob_get_clean();
    }

    /** Entfernt APP1–APP15 und Kommentare aus einem JPEG (EXIF, XMP, GPS), ohne neu zu kodieren. */
    public static function stripJpegMetadata(string $b): string
    {
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($b);
        while ($pos + 4 <= $len && $b[$pos] === "\xFF") {
            $marker = ord($b[$pos + 1]);
            if ($marker === 0xDA) {
                return $out . substr($b, $pos); // Bilddaten: Rest unverändert übernehmen.
            }
            $size = (ord($b[$pos + 2]) << 8) | ord($b[$pos + 3]);
            $segment = substr($b, $pos, 2 + $size);
            $isMeta = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE;
            $isIcc = $marker === 0xE2 && str_starts_with(substr($segment, 4), 'ICC_PROFILE');
            if (!$isMeta || $isIcc) {
                $out .= $segment;
            }
            $pos += 2 + $size;
        }
        return $b; // Unbekannter Aufbau: lieber unverändert lassen.
    }

    /** Adresse eines Bildes; ohne Dateiendung, weil `php -S` Adressen mit Endung nicht weiterreicht. */
    public static function url(string $name): string
    {
        return url('/bild/' . pathinfo($name, PATHINFO_FILENAME));
    }

    /** Liefert das Bild zur Kennung (Dateiname ohne Endung) aus. */
    public static function send(string $id): never
    {
        $name = null;
        foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
            if (self::path($id . '.' . $ext) !== null) {
                $name = $id . '.' . $ext;
                break;
            }
        }
        if ($name === null) {
            http_response_code(404);
            exit;
        }
        $path = (string) self::path($name);
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        header('Content-Type: ' . $types[pathinfo($name, PATHINFO_EXTENSION)]);
        header('Content-Length: ' . filesize($path));
        // Der Name ist zufällig und ändert sich bei jedem neuen Bild, daher lange zwischenspeichern.
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($path);
        exit;
    }
}
