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
    finally:
        server.terminate()
        err = server.stderr.read().decode()
        bad = [l for l in err.splitlines() if "PHP " in l and ("Warning" in l or "Error" in l or "Notice" in l or "Deprecated" in l)]
        if bad:
            print("PHP-Meldungen im Serverlog:\n" + "\n".join(bad))
            sys.exit(1)
    print("\nAlle Kernfälle OK.")


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
    check(konten(env, "anlegen", "Fabian").returncode == 0, "Konto Fabian per CLI angelegt")
    check(konten(env, "anlegen", "  FABIAN ").returncode == 1, "Doppelter Name (andere Schreibweise) abgelehnt")
    check(konten(env, "anlegen", "Jürgen").returncode == 0, "Konto Jürgen angelegt")
    konten(env, "anlegen", "Anna")

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


if __name__ == "__main__":
    main()
