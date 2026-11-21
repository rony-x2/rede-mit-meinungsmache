# Projektseite

Die Seite <https://poc.deutschkaemer.info/meinungsmache/> ist statisches HTML. Sie entsteht aus einer Vorlage und folgt im Aufbau der Seite des Schwesterprojekts [Rede mit dem AfD-Gutachten](https://poc.deutschkaemer.info/afd-gutachten/).

## Dateien unter `seite/`

| Datei | Zweck |
|---|---|
| `vorlage.html` | Gerüst der Seite mit Platzhaltern `{{…}}` |
| `bauen.py` | baut `index.html`: Logos und Piktogramme inline, Beispiel-Unterhaltung, Diagramm, strukturierte Daten, aktueller Stand |
| `beispiele.json` | die Beispiel-Unterhaltungen oben rechts (Frage, Werkzeug, gekürzte Antwort, Link) |
| `diagramm.py` | Ablaufdiagramm als SVG, quer und hochkant; schreibt auch `docs/bilder/ablauf-*.svg` |
| `zeichen.py` | Projektzeichen; schreibt `favicon.svg` und die PNG-Größen nach `server/web/` |
| `vorschaubild.py` | Vorschaubild für Open Graph (`server/web/og.png`, 1200 × 630) |
| `seite.css`, `seite.js` | Gestaltung und Verhalten dieser Seite |
| `style.css` | gemeinsames Stylesheet aller PoC-Seiten (liegt auf dem Server unter `/style.css`) |
| `logos/` | Logos der Anbieter für die Einrichtungsanleitungen |

```bash
cd seite
python3 diagramm.py        # docs/bilder/ablauf-hell.svg und -dunkel.svg
python3 zeichen.py         # Favicons (braucht Playwright mit Chromium)
python3 vorschaubild.py    # og.png (braucht Playwright mit Chromium)
python3 bauen.py           # index.html; liest Kennzahlen und neueste Artikel von der Schnittstelle
```

## Aktueller Stand

Kennzahlen und „Zuletzt erschienen“ schreibt `bauen.py` mit dem Stand beim Bauen ins HTML, damit die Seite ohne JavaScript und für Suchmaschinen vollständig ist. Beim Aufruf lädt `seite.js` den aktuellen Stand aus `stand.php` nach (fünf Minuten zwischengespeichert, ohne Protokoll) und ersetzt beides; neu hinzugekommene Artikel leuchten kurz auf.

## Gestaltung

- **Zeichen:** Die Logo-Familie „Rede mit …“ zeigt eine Sprechblase (Chat mit einer Quelle) mit dem Projekt darin, hier eine Zeitungsseite. Die Kachel ist bernsteinfarben (`#a8681a`), angelehnt an die Kennfarbe von meinungsmache.de (`#efd19f`), aber dunkel genug für weiße Linien.
- **Farben:** Die Projektfarbe `--projekt` (Bernstein) markiert Zeichen, Dateinamen und die Briefing-Piktogramme; die Akzentfarbe der PoC-Seite (Türkis) bleibt für Bedienelemente. Im hellen Bild ist die Projektfarbe als Schrift dunkler (`#8f5a0e`), damit sie auf Weiß mindestens 4,5 : 1 erreicht. Auf Bildschirmen mit großem Farbraum (Display P3) kommen dieselben Farben in `oklch` mit mehr Sättigung.
- **Hell und dunkel** nach Systemeinstellung, volle Breite auf großen Bildschirmen, voll bedienbar ab 320 px Breite; Menü auf dem Smartphone als Klappmenü.
- **Bewegung** ist dezent (Beispiel-Unterhaltung, Ablauf, Aufleuchten neuer Artikel) und entfällt bei `prefers-reduced-motion`.

## Strukturierte Daten

Im Kopf stehen Open-Graph-Angaben für die Vorschau beim Teilen und ein JSON-LD-Block nach schema.org: `WebPage` mit Brotkrumen, die Schnittstelle als `SoftwareApplication`/`WebAPI`, die aufbereiteten Artikel als `Dataset`, meinungsmache.de als `NewsMediaOrganization`, der Quelltext als `SoftwareSourceCode` und der Betreiber als `Person`.
