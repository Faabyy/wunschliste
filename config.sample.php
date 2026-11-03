<?php
// Vorlage. Kopieren nach config.php (im selben Ordner) und ausfüllen.
// config.php wird nie überschrieben, wenn du eine neue Version hochlädst, und kommt nicht ins Git.

return [
    // Gemeinsamer Zugangscode für alle, einmal pro Gerät einzugeben.
    // Leer lassen nur, wenn die Seite ausschließlich im eigenen Netz läuft.
    'code' => '',

    // Passwort für /verwaltung, wo du Konten anlegst. Leer = Verwaltung aus.
    // Ein langes, eigenes Passwort wählen; es ist der einzige Schutz der Kontenverwaltung.
    'admin_passwort' => '',

    // true: http:// wird auf https:// umgeleitet und das Anmelde-Cookie nur über HTTPS gesendet.
    // Voraussetzung: Für die (Sub-)Domain ist ein SSL-Zertifikat eingerichtet.
    'https' => true,

    // false schaltet das Lesen von Preisen und Bildern aus Links ab.
    'preisabruf' => true,

    // Nur ausfüllen, wenn Links falsch aussehen: der Unterordner, z. B. '/wunschliste'.
    'basispfad' => '',
];
