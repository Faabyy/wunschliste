#!/usr/bin/env bash
# Baut ftp-upload/wunschliste/ mit genau den Dateien, die per FTP auf den Webspace gehören.
# Nicht enthalten: config.php (wird nie überschrieben), Datenbank, Docker- und Testdateien.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/ftp-upload/wunschliste"
rm -rf "$root/ftp-upload"
mkdir -p "$out/data"

cp -r "$root/public" "$root/src" "$out/"
cp "$root/.htaccess" "$root/config.sample.php" "$out/"
cp "$root/data/.htaccess" "$root/data/schutztest.txt" "$out/data/"

find "$out" -type f | sed "s|$root/ftp-upload/||" | sort
