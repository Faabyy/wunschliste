#!/usr/bin/env python3
"""Prüft die Kernfälle gegen einen echten `php -S`-Server mit leerem Datenordner.

Aufruf: python3 tests/smoke.py            (public/ als Web-Root, Einstellungen aus Umgebungsvariablen)
        python3 tests/smoke.py webspace   (Webspace-Fall: Unterordner /wunschliste, config.php)
Startet den Server selbst, legt Konten per bin/konten.php an und meldet am Ende OK oder den Fehler.
"""
import http.cookiejar
import os
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib
import struct

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CODE = "tannenbaum"
ADMIN = "geheim-admin"


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.raw = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def get(self, path):
        try:
            with self.opener.open(self.base + path) as r:
                return r.status, r.read().decode(), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(), dict(e.headers)

    def csrf(self, html):
        m = re.search(r'name="_csrf" value="([0-9a-f]+)"', html)
        assert m, "kein CSRF-Token gefunden"
        return m.group(1)

    def upload(self, path, data, files, follow=True):
        """POST als multipart/form-data mit Dateien {feld: (name, bytes, typ)}."""
        token = self.csrf(self.get("/")[1])
        boundary = uuid.uuid4().hex
        parts = []
        for k, v in {**data, "_csrf": token}.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        for k, (name, content, ctype) in files.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\n'
                         f'Content-Type: {ctype}\r\n\r\n'.encode() + content + b"\r\n")
        body = b"".join(parts) + f"--{boundary}--\r\n".encode()
        req = urllib.request.Request(self.base + path, data=body,
                                     headers={"Content-Type": f"multipart/form-data; boundary={boundary}"})
        opener = self.opener if follow else self.raw
        try:
            with opener.open(req) as r:
                return r.status, r.read().decode(), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(), dict(e.headers)

    def get_bytes(self, path):
        try:
            with self.opener.open(self.base + path) as r:
                return r.status, r.read(), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read(), dict(e.headers)

    def post(self, path, data, token=None, follow=True):
        if token is None:
            token = self.csrf(self.get("/")[1])
        body = urllib.parse.urlencode({**data, "_csrf": token}).encode()
        opener = self.opener if follow else self.raw
        try:
            with opener.open(urllib.request.Request(self.base + path, data=body)) as r:
                return r.status, r.read().decode(), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(), dict(e.headers)


def make_png(w=120, h=90):
    """Einfarbiges PNG, gebaut ohne Zusatzbibliotheken."""
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    raw = b"".join(b"\x00" + b"\xa7\x28\x3d" * w for _ in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


def check(cond, msg):
    if not cond:
        print("FEHLER:", msg)
        sys.exit(1)
    print("  ok ", msg)


def main():
    webspace = sys.argv[1:] == ["webspace"]
    data_dir = tempfile.mkdtemp(prefix="wunschliste-test-")
    port = free_port()
    if webspace:
        config = os.path.join(data_dir, "config-test.php")
        with open(config, "w") as f:
            f.write(f"<?php return ['code' => '{CODE}', 'admin_passwort' => '{ADMIN}', 'https' => false, 'preisabruf' => false];\n")
        env = {k: v for k, v in os.environ.items() if not k.startswith("WUNSCHLISTE_")}
        env.update(WUNSCHLISTE_DATA=os.path.join(data_dir, "data"), WUNSCHLISTE_CONFIG=config)
        cmd = ["php", "-S", f"127.0.0.1:{port}", os.path.join(ROOT, "tests/webspace_router.php")]
        base = f"http://127.0.0.1:{port}/wunschliste"
    else:
        env = dict(os.environ, WUNSCHLISTE_DATA=data_dir, WUNSCHLISTE_CODE=CODE,
                   WUNSCHLISTE_ADMIN_PASSWORT=ADMIN, WUNSCHLISTE_PREISABRUF="0")
        cmd = ["php", "-S", f"127.0.0.1:{port}", "-t", os.path.join(ROOT, "public")]
        base = f"http://127.0.0.1:{port}"
    server = subprocess.Popen(cmd, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    try:
        time.sleep(0.6)
        run(base, env)
        if webspace:
            run_webspace(base)
        else:
            run_single(env)
    finally:
        server.terminate()
        err = server.stderr.read().decode()
        bad = [l for l in err.splitlines() if "PHP " in l and ("Warning" in l or "Error" in l or "Notice" in l or "Deprecated" in l)]
        if bad:
            print("PHP-Meldungen im Serverlog:\n" + "\n".join(bad))
            sys.exit(1)
    print("\nAlle Kernfälle OK.")


def run_single(env):
    """Nur ein Beschenkter: keine anderen Listen bei ihm, Schenkende landen direkt auf seiner Liste."""
    print("Nur ein Beschenkter")
    env = dict(env, WUNSCHLISTE_DATA=tempfile.mkdtemp(prefix="wunschliste-single-"))
    port = free_port()
    server = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", os.path.join(ROOT, "public")],
                              env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(0.6)
        base = f"http://127.0.0.1:{port}"
        konten(env, "anlegen", "Lena", "beschenkt")
        konten(env, "anlegen", "Oma")
        konten(env, "anlegen", "Opa")
        lena = login(base, "Lena")
        lena.post("/eintrag", {"titel": "Puppenhaus", "link": "", "notiz": "", "preis": ""})
        s, html, _ = lena.get("/")
        check("Puppenhaus" in html and "Andere Listen" not in html and 'class="col-others"' not in html,
              "Beschenkte sieht keinen Abschnitt „Andere Listen“")
        check('class="tabbar"' not in html and "Was ich schenke" not in html, "Keine Tableiste und kein „Was ich schenke“")
        check("<h1 id=\"h-own\">Meine Wunschliste</h1>" in lena.get("/listen")[1], "/listen führt zurück zur eigenen Liste")

        oma = login(base, "Oma")
        s, html, _ = oma.get("/")
        check("Wünsche von Lena" in html and "Puppenhaus" in html, "Schenkende landen direkt auf Lenas Liste")
        check("Alle Listen" not in html, "Kein Link zurück zu „Alle Listen“")
        check(">Wunschliste<" in html and "Was ich schenke" in html, "Menü: Wunschliste und Was ich schenke")
        item = int(re.search(r'/eintrag/(\d+)/reservieren', html).group(1))
        check('name="zurueck" value="/"' in html, "Reservieren springt zurück auf die Startseite")
        s, html, h = oma.post(f"/eintrag/{item}/reservieren", {"zurueck": "/"}, follow=False)
        check(h.get("Location", "").startswith("/#e"), "Weiterleitung nach dem Reservieren auf /")
        s, html, _ = oma.get("/")
        check("Du schenkst das" in html, "Reservierung auf der Startseite sichtbar")
        check("Oma schenkt das" in login(base, "Opa").get("/")[1], "Opa sieht Omas Reservierung")
        check("<h1>Wünsche von Lena</h1>" in oma.get("/listen")[1], "/listen führt direkt zu Lenas Liste")
        s, html, _ = lena.get("/")
        check("Oma" not in html.split('<main')[1] and "Puppenhaus" in html, "Lena merkt nichts von der Reservierung")

        konten(env, "anlegen", "Max", "beschenkt")
        check("Andere Listen" in lena.get("/")[1], "Mit zweitem Beschenkten erscheint „Andere Listen“ wieder")
        check("Wunschlisten" in oma.get("/")[1] and "Wünsche von Lena" not in oma.get("/")[1],
              "Mit zwei Beschenkten wieder die Übersicht für Schenkende")
    finally:
        server.terminate()


def run_webspace(base):
    print("Webspace-Fall")
    b = Browser(base)
    s, html, h = b.get("/")
    check('href="/wunschliste/assets/app.css?v=' in html, "Assets mit Unterordner verlinkt")
    check('action="/wunschliste/anmelden"' in html, "Formulare mit Unterordner")
    check("path=/wunschliste/" in h.get("Set-Cookie", ""), "Cookie auf den Unterordner beschränkt")
    s, css, h = b.get("/assets/app.css")
    check(s == 200 and "--accent" in css, "CSS wird ausgeliefert")
    s, _, h = b.post("/anmelden", {"code": CODE, "name": "Fabian"}, follow=False)
    check(h.get("Location") == "/wunschliste/", "Weiterleitung bleibt im Unterordner")
    for path in ("/data/schutztest.txt", "/data/wunschliste.sqlite", "/config.php", "/src/Config.php", "/public/../data/schutztest.txt"):
        s, body, _ = b.get(path)
        check("SCHUTZTEST" not in body and "SQLite format" not in body and "return [" not in body, f"{path} nicht abrufbar")
    admin = Browser(base)
    s, html, _ = admin.get("/verwaltung")
    admin.post("/verwaltung/anmelden", {"passwort": ADMIN}, token=admin.csrf(html))
    s, html, _ = admin.get("/verwaltung")
    check("Server-Prüfung" in html and "/wunschliste/data/schutztest.txt" in html, "Server-Prüfung mit Datenbank-Test")
    check("check-fail" not in html, "Server-Prüfung ohne Fehler")


def konten(env, *args):
    return subprocess.run(["php", os.path.join(ROOT, "bin/konten.php"), *args], env=env,
                          capture_output=True, text=True)


def login(base, name):
    b = Browser(base)
    b.post("/anmelden", {"code": CODE, "name": name})
    return b


def run(base, env):
    print("Konten")
    check(konten(env, "anlegen", "Fabian", "beschenkt").returncode == 0, "Konto Fabian per CLI angelegt")
    check(konten(env, "anlegen", "  FABIAN ").returncode == 1, "Doppelter Name (andere Schreibweise) abgelehnt")
    check(konten(env, "anlegen", "Jürgen", "beschenkt").returncode == 0, "Konto Jürgen angelegt")
    check(konten(env, "anlegen", "Anna").returncode == 0, "Konto Anna (schenkt nur) angelegt")

    anon = Browser(base)
    s, html, h = anon.get("/")
    check("Zugangscode" in html, "Startseite fragt nach Zugangscode")
    check("default-src 'self'" in h.get("Content-Security-Policy", ""), "CSP-Header gesetzt")
    check("HttpOnly" in h.get("Set-Cookie", "") and "SameSite=Lax" in h.get("Set-Cookie", ""), "Cookie HttpOnly + SameSite=Lax")

    s, html, _ = anon.post("/anmelden", {"code": "falsch", "name": "Fabian"})
    check("Zugangscode stimmt nicht" in html, "Falscher Code abgelehnt")
    s, html, _ = anon.post("/anmelden", {"code": CODE, "name": "Unbekannt"})
    check("kenne ich nicht" in html, "Unbekannter Name legt kein Konto an")
    s, _, h = anon.post("/eintrag", {"titel": "x"}, token="0" * 64, follow=False)
    check(s == 400, "POST ohne gültiges CSRF-Token abgelehnt")

    print("Besitzer legt Wünsche an")
    fabian = login(base, "  fabian ")
    s, html, _ = fabian.get("/")
    check("Meine Wunschliste" in html and ">Fabian<" in html, "Login unabhängig von Groß-/Kleinschreibung")
    fabian.post("/eintrag", {"titel": "Wanderschuhe", "link": "www.amazon.de/dp/B0TEST", "notiz": "Größe 44", "preis": ""})
    fabian.post("/eintrag", {"titel": "Buch <script>alert(1)</script>", "link": "", "notiz": "", "preis": "24,99"})
    fabian.post("/eintrag", {"titel": "Thermoskanne", "link": "https://example.com/kanne", "notiz": "", "preis": ""})
    s, html, _ = fabian.post("/eintrag", {"titel": "Böser Link", "link": "javascript:alert(1)", "notiz": "", "preis": ""})
    check("Nur Links mit http" in html, "javascript:-Link abgelehnt")
    s, html, _ = fabian.get("/")
    check("<script>alert(1)" not in html and "&lt;script&gt;" in html, "Ausgabe wird escaped")
    check(">amazon.de<" in html, "Link als Domain angezeigt")
    check('rel="noopener noreferrer"' in html and 'target="_blank"' in html, "Link öffnet neuen Tab mit noopener noreferrer")
    check("24,99" in html, "Manueller Preis angezeigt")
    ids = [int(x) for x in re.findall(r'<li class="card card-own" id="e(\d+)"', html)]
    check(len(ids) == 3, "Drei Wünsche in der eigenen Liste")
    shoes, book, flask = ids

    fabian.post(f"/eintrag/{flask}/hoch", {})
    s, html, _ = fabian.get("/")
    order = [int(x) for x in re.findall(r'<li class="card card-own" id="e(\d+)"', html)]
    check(order == [shoes, flask, book], "Sortieren nach oben funktioniert")

    fabian_id = int(re.search(r"(\d+)\s+Fabian", konten(env, "liste").stdout).group(1))
    s, _, h = fabian.get(f"/liste/{fabian_id}")
    check(s == 200 and "Meine Wunschliste" in _, "Eigene Liste über /liste/ID führt zur Besitzeransicht")

    # Was der Besitzer vor jeder Reservierung sieht
    pages = ["/", f"/eintrag/{shoes}", f"/eintrag/{book}", "/listen", "/schenken"]
    before = {p: fabian.get(p)[1] for p in pages}

    print("Zweite Person reserviert")
    juergen = login(base, "JÜRGEN")
    s, html, _ = juergen.get("/")
    check(">Jürgen<" in html, "Login mit Umlaut und anderer Schreibweise")
    s, html, _ = juergen.get(f"/liste/{fabian_id}")
    check(html.count("Ich schenke das") == 3, "Drei freie Wünsche bei Fabian sichtbar")
    s, html, _ = juergen.post(f"/eintrag/{shoes}/reservieren", {"zurueck": f"/liste/{fabian_id}"})
    check("Du schenkst das" in html, "Jürgen hat die Wanderschuhe reserviert")
    juergen.post(f"/eintrag/{book}/reservieren", {"zurueck": f"/liste/{fabian_id}"})

    s, html, _ = juergen.get("/schenken")
    check("Wanderschuhe" in html and "Gekauft" in html, "Reservierung erscheint unter „Was ich schenke“")

    print("Dritte Person sieht die Reservierung")
    anna = login(base, "anna")
    s, html, _ = anna.get(f"/liste/{fabian_id}")
    check("Jürgen schenkt das" in html, "Andere sehen, wer reserviert hat")
    s, html, _ = anna.post(f"/eintrag/{shoes}/reservieren", {"zurueck": f"/liste/{fabian_id}"})
    check("schon jemand anderes reserviert" in html, "Doppelte Reservierung verhindert")
    s, html, _ = anna.post(f"/eintrag/{shoes}/freigeben", {"zurueck": f"/liste/{fabian_id}"})
    check("nur du aufheben" in html, "Nur der Reservierende kann aufheben")

    print("Besitzer darf nichts merken")
    after = {p: fabian.get(p)[1] for p in pages}
    for p in pages:
        check(before[p] == after[p], f"{p}: Besitzeransicht byte-gleich vor und nach der Reservierung")
    # Zusätzlich direkt nach Spuren suchen (Name „Jürgen“ steht legitim in der Personenübersicht).
    for p in ("/", f"/eintrag/{shoes}", f"/eintrag/{book}"):
        own = after[p].split('class="col-others"')[0]
        for word in ("Jürgen", "reserv", "schenkt das", "Du schenkst", "is-taken", "is-mine", "giver", "vergeben"):
            check(word.lower() not in own.lower(), f"{p}: keine Spur von „{word}“")

    s, html, _ = fabian.post(f"/eintrag/{shoes}/reservieren", {})
    check("eigener Wunsch" in html, "Eigenen Wunsch reservieren abgelehnt")
    r1 = fabian.post(f"/eintrag/{shoes}/freigeben", {})[1]
    r2 = fabian.post(f"/eintrag/{flask}/freigeben", {})[1]
    check(r1 == r2, "Freigeben-Versuch des Besitzers: gleiche Antwort für reserviert und frei")

    print("Bearbeiten und Löschen reservierter Wünsche")
    s1, b1, h1 = fabian.post(f"/eintrag/{shoes}", {"titel": "Wanderschuhe (braun)", "link": "https://www.amazon.de/dp/B0TEST", "notiz": "Größe 44", "preis": ""}, follow=False)
    s2, b2, h2 = fabian.post(f"/eintrag/{flask}", {"titel": "Thermoskanne groß", "link": "https://example.com/kanne", "notiz": "", "preis": ""}, follow=False)
    check((s1, h1.get("Location", "").split("#")[0]) == (s2, h2.get("Location", "").split("#")[0]) and b1 == b2,
          "Bearbeiten: gleiche Antwort für reserviert und frei")
    s, html, _ = fabian.get("/")
    check("Gespeichert." in html and "Jürgen" not in html.split('class="col-others"')[0], "Nach dem Bearbeiten keine Spur")
    s, html, _ = juergen.get("/")
    check("hat einen Wunsch geändert" in html and "Wanderschuhe (braun)" in html, "Schenkender bekommt Änderungshinweis")

    d1 = fabian.post(f"/eintrag/{book}/loeschen", {}, follow=False)
    d2 = fabian.post(f"/eintrag/{flask}/loeschen", {}, follow=False)
    check(d1[0] == d2[0] and d1[1] == d2[1] and d1[2].get("Location") == d2[2].get("Location"),
          "Löschen: gleiche Antwort für reserviert und frei")
    s, html, _ = fabian.get("/")
    check("Jürgen" not in html.split('class="col-others"')[0] and "entfernt" not in html, "Besitzer sieht nach dem Löschen keinen Hinweis")
    s, html, _ = juergen.get("/")
    check("Ein von dir reservierter Wunsch von Fabian wurde entfernt" in html and "Buch &lt;script&gt;" in html,
          "Schenkender bekommt Löschhinweis")
    s, html, _ = anna.get("/")
    check("entfernt" not in html, "Unbeteiligte bekommen keinen Hinweis")

    notice = int(re.search(r'/hinweis/(\d+)/ok', html := juergen.get("/")[1]).group(1))
    juergen.post(f"/hinweis/{notice}/ok", {"zurueck": "/"})
    s, html, _ = juergen.get("/")
    check(f"/hinweis/{notice}/ok" not in html, "Hinweis lässt sich ausblenden")

    print("Bilder")
    form = {"titel": "Wanderschuhe (braun)", "link": "https://www.amazon.de/dp/B0TEST", "notiz": "Größe 44", "preis": ""}
    s, html, _ = fabian.upload(f"/eintrag/{shoes}", form, {"bild": ("schuh.png", make_png(), "image/png")})
    m = re.search(r'src="(?:/wunschliste)?(/bild/[0-9a-f]{32})"', html)
    check(m is not None, "Hochgeladenes Bild erscheint auf der Karte")
    img = m.group(1)
    s, data, h = fabian.get_bytes(img)
    check(s == 200 and data.startswith(b"\x89PNG") and h.get("Content-Type") in ("image/png", "image/jpeg"), "Bild wird vom eigenen Server ausgeliefert")
    check(img in juergen.get(f"/liste/{fabian_id}")[1], "Andere sehen das Bild")
    s, data, _ = Browser(base).get_bytes(img)
    check(not data.startswith(b"\x89PNG"), "Ohne Anmeldung kein Bild")
    s, html, _ = fabian.upload(f"/eintrag/{shoes}", form, {"bild": ("boese.svg", b"<svg xmlns='http://www.w3.org/2000/svg'/>", "image/svg+xml")})
    check("kein Bild im Format" in html and img in html, "SVG-Upload abgelehnt, altes Bild bleibt")
    stored = [f for f in os.listdir(os.path.join(env["WUNSCHLISTE_DATA"], "bilder")) if f.startswith(img.rsplit("/", 1)[1])]
    path = os.path.join(env["WUNSCHLISTE_DATA"], "bilder", stored[0] if stored else "fehlt")
    check(os.path.exists(path), "Bilddatei liegt in data/bilder")
    fabian.post(f"/eintrag/{shoes}", {**form, "bild_entfernen": "1"})
    check(not os.path.exists(path) and img not in fabian.get("/")[1], "Bild entfernen löscht die Datei")

    print("Gekauft, freigeben, Verwaltung")
    juergen.post(f"/eintrag/{shoes}/gekauft", {})
    s, html, _ = juergen.get("/schenken")
    check('aria-pressed="true"' in html, "„Gekauft“ gesetzt")
    s, html, _ = anna.get(f"/liste/{fabian_id}")
    check("gekauft" not in html.lower(), "„Gekauft“ sehen andere nicht")
    juergen.post(f"/eintrag/{shoes}/freigeben", {"zurueck": "/schenken"})
    s, html, _ = anna.get(f"/liste/{fabian_id}")
    check("Jürgen schenkt das" not in html and "Ich schenke das" in html, "Reservierung aufgehoben")

    admin = Browser(base)
    s, html, _ = admin.get("/verwaltung")
    check("Passwort" in html, "Verwaltung verlangt Passwort")
    admin.post("/verwaltung/anmelden", {"passwort": ADMIN}, token=admin.csrf(html))
    s, html, _ = admin.get("/verwaltung")
    check("Neues Konto" in html and "Anna" in html, "Verwaltung nach Anmeldung offen")
    admin.post("/verwaltung/konto", {"name": "Oma Gerda"}, token=admin.csrf(html))
    s, html, _ = admin.get("/verwaltung")
    check("Oma Gerda" in html, "Konto über die Verwaltung angelegt")
    s, _, _ = anna.post("/verwaltung/konto", {"name": "Hacker"})
    s, html, _ = admin.get("/verwaltung")
    check("Hacker" not in html, "Ohne Verwaltungs-Anmeldung kein Konto anlegbar")

    s, html, _ = anon.get("/eintrag/1")
    check("Dein Name" in html, "Ohne Anmeldung keine Unterseiten")

    print("Status: beschenkt oder schenkt nur")
    ids = {m.group(2): int(m.group(1)) for m in re.finditer(r"^\s*(\d+)\s+(\S+)", konten(env, "liste").stdout, re.M)}
    check("Oma" in ids and "schenker" in [l.split()[-3] for l in konten(env, "liste").stdout.splitlines() if "Oma Gerda" in l],
          "Neues Konto aus der Verwaltung schenkt standardmäßig nur")
    s, html, _ = anna.get("/")
    check("Wunschlisten" in html and "Wunsch hinzufügen" not in html and "Meine Liste" not in html,
          "Schenkerin sieht Wunschlisten statt eigener Liste")
    check(f"/liste/{fabian_id}" in html, "Schenkerin sieht Fabians Liste in der Übersicht")
    s, html, _ = anna.post("/eintrag", {"titel": "Darf nicht", "link": "", "notiz": "", "preis": ""})
    check("keine eigene Wunschliste" in html, "Schenkerin kann keinen Wunsch anlegen")
    check("Darf nicht" not in konten(env, "liste").stdout and "Darf nicht" not in anna.get(f"/liste/{fabian_id}")[1],
          "Kein Wunsch der Schenkerin gespeichert oder sichtbar")
    s, html, _ = fabian.get("/listen")
    check(f'/liste/{ids["Anna"]}"' not in html, "Schenkerin taucht nicht in den Listen auf")
    s, _, _ = fabian.get(f"/liste/{ids['Anna']}")
    check(s == 404, "Liste einer Schenkerin gibt es nicht (404)")

    juergen_id = ids["Jürgen"]
    juergen.post("/eintrag", {"titel": "Fahrradlampe", "link": "", "notiz": "", "preis": ""})
    jitem = int(re.search(r'<li class="card card-own" id="e(\d+)"', juergen.get("/")[1]).group(1))
    fabian.post(f"/eintrag/{jitem}/reservieren", {"zurueck": f"/liste/{juergen_id}"})
    check("Fahrradlampe" in fabian.get("/schenken")[1], "Fabian schenkt Jürgen die Fahrradlampe")

    s, html, _ = admin.get("/verwaltung")
    admin.post(f"/verwaltung/konto/{juergen_id}/status", {"status": "schenker"}, token=admin.csrf(html))
    check(f'/liste/{juergen_id}"' not in fabian.get("/listen")[1], "Jürgen auf „schenkt nur“: aus den Listen verschwunden")
    check(fabian.get(f"/liste/{juergen_id}")[0] == 404, "Jürgens Liste nicht mehr abrufbar")
    check("Fahrradlampe" not in fabian.get("/schenken")[1], "Reservierung auf ausgeblendeter Liste ausgeblendet")
    s, html, _ = fabian.post(f"/eintrag/{jitem}/freigeben", {"zurueck": "/schenken"})
    check("gibt es nicht mehr" in html, "Ausgeblendeter Wunsch lässt sich nicht bearbeiten")
    check("Wünsche von Fabian" in juergen.get("/")[1], "Jürgen landet als Schenker direkt bei Fabian (einziger Beschenkter)")
    s, html, _ = juergen.get(f"/eintrag/{jitem}")
    check("keine eigene Wunschliste" in html, "Jürgen kann seine ausgeblendeten Wünsche nicht bearbeiten")
    s, html, _ = admin.get("/verwaltung")
    check("ausgeblendet" in html, "Verwaltung zeigt ausgeblendete Wünsche an")

    admin.post(f"/verwaltung/konto/{juergen_id}/status", {"status": "beschenkt"}, token=admin.csrf(html))
    check("Fahrradlampe" in juergen.get("/")[1], "Zurück auf „beschenkt“: Wünsche wieder da")
    check("Fahrradlampe" in fabian.get("/schenken")[1], "Zurück auf „beschenkt“: Reservierung wieder da")


if __name__ == "__main__":
    main()
