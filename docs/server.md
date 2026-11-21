# Server einrichten und betreiben

## Voraussetzungen

- PHP 8.1 oder neuer mit `pdo_mysql`, `curl`, `dom` und `mbstring`; `apcu` empfohlen (Aufrufgrenzen, Zwischenspeicher für `stand.php`)
- MariaDB 11.7 oder neuer (Datentyp `VECTOR`, `VEC_DISTANCE_COSINE`)
- Apache mit `mod_rewrite` (für die Adresse `/mcp` ohne Endung)
- Cron für den stündlichen Abruf
- ein Einbettungsdienst mit Qwen3-Embedding-0.6B (siehe unten)

## Verzeichnisse

| auf dem Server | aus dem Repository | Inhalt |
|---|---|---|
| `~/poc/mm/` | `server/lib/` | Abruf, MCP-Server, Datenbankzugang, Schema; außerhalb des Webroots |
| `~/poc/mm/logs/` | – | Aufrufprotokoll je Monat (legt der Server an) |
| `~/poc/config.php` | `server/config.beispiel.php` | Zugangsdaten, gemeinsam für alle PoCs |
| `~/public_html/poc/meinungsmache/` | `server/web/` und `seite/index.html`, `seite.css`, `seite.js` | öffentlicher Teil: Projektseite, `mcp.php`, `stand.php`, `.htaccess`, Zeichen |
| `~/public_html/poc/style.css` | `seite/style.css` | gemeinsames Stylesheet aller PoC-Seiten |

`mcp.php` und `stand.php` im Webroot laden die Bibliothek aus `~/poc/mm/mcp_server.php`. Die `.htaccess` leitet `/meinungsmache/mcp` intern auf `mcp.php` um, ohne Weiterleitung: MCP-Clients folgen bei `POST` keinem 301.

## Datenbank

Die Tabellen (`mm_artikel`, `mm_link`, `mm_vektor`, `mm_abruf`) legt `abruf.php` beim ersten Lauf aus `schema.sql` an. Dafür braucht die Rolle `admin` die Rechte `CREATE`, `SELECT`, `INSERT`, `UPDATE`, `DELETE`; die Rolle `lesen` für die Schnittstelle nur `SELECT`. Alle Zeiten stehen in UTC, die Verbindung setzt `time_zone = '+00:00'`.

## Einbettungsdienst

Für die Suche nach Bedeutung muss der Server Texte mit **Qwen3-Embedding-0.6B** in Vektoren umrechnen. Zwei Wege:

1. **Eigener Dienst** mit OpenAI-artiger Schnittstelle, eingetragen als `einbettung_url` in `~/poc/config.php`:

   ```bash
   llama-server -m Qwen3-Embedding-0.6B-Q8_0.gguf --embedding --pooling last --port 1240
   ```

2. **Ohne Eintrag** nutzt der Server den Einbettungsweg von plenarprotokolle.digital auf demselben Konto (eine Warteschlange, die ein Rechner mit dem Modell abarbeitet).

Der Server kürzt die Vektoren auf 384 Dimensionen und normiert sie. Auf einer CPU dauert eine Frage etwa 0,15 Sekunden, ein Artikel unter einer Sekunde.

## Anreicherung

Fragen, Suchbegriffe und Themen schreibt ein Sprachmodell auf einem Rechner mit Grafikkarte; das Programm dafür und seine Einrichtung beschreibt [anreicherung.md](anreicherung.md). Ohne Anreicherung arbeitet die Schnittstelle weiter, nur ohne Themen und mit etwas weniger treffsicherer Suche nach Bedeutung.

## Erster Lauf und Cron

```bash
# Archiv seit Dezember 2025 (rund 450 Artikel, mit 2 s Pause etwa 20 Minuten)
php ~/poc/mm/abruf.php --nachholen

# danach stündlich, um :17; flock verhindert, dass sich zwei Läufe überschneiden
mkdir -p ~/.tmp
( crontab -l 2>/dev/null; echo '17 * * * * /usr/bin/flock -n $HOME/.tmp/mm_abruf.lck /usr/bin/php $HOME/poc/mm/abruf.php --still >> $HOME/poc/mm/cron.log 2>&1' ) | crontab -
```

Auf dem Webhosting von Hetzner heißt die PHP-Kommandozeile je nach Version etwa `php85`.

## Betrieb

- **Läufe prüfen:** `SELECT * FROM mm_abruf ORDER BY id DESC LIMIT 24;` zeigt je Lauf neue, geprüfte und eingebettete Artikel, Fehler und Dauer. Das Werkzeug `ueberblick` nennt den letzten Abruf.
- **Einzelnen Artikel neu holen:** `php ~/poc/mm/abruf.php --neu=<slug>`
- **Fehlende Vektoren nachholen:** `php ~/poc/mm/abruf.php --einbetten`
- **Aufrufe ansehen:** `~/poc/mm/logs/JJJJ-MM.log` (Zeit, Werkzeug, Argumente, Dauer)
- **Schnittstelle testen:** siehe Beispiel in [werkzeuge.md](werkzeuge.md#beispiel)
