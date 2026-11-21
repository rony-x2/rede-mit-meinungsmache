# Abruf: von meinungsmache.de in die Datenbank

`server/lib/abruf.php` holt die Artikel, `server/lib/quelle.php` liest und zerlegt sie. Verwendet wird nur, was jede Leserin im Browser sieht: der RSS-Feed, die Sitemap und die öffentlichen Artikelseiten. Der Abruf ist mit dem Betreiber von meinungsmache.de abgesprochen.

## Quellen

| Quelle | Adresse | wofür |
|---|---|---|
| RSS-Feed | `https://meinungsmache.de/rss/all` | die neuesten 100 Artikel, jede Stunde |
| Sitemap | `https://meinungsmache.de/sitemap.xml` | alle Artikeladressen mit Änderungsdatum, für das Archiv |
| Artikelseite | `https://meinungsmache.de/article/<slug>` | Volltext und Angaben zum Artikel |

Der Feed enthält bei eigenen Artikeln zwar den ganzen Text, bei Meldungen aber nur den Anfang und keine Angaben zu Quelle und Original. Darum wird jeder Artikel von seiner Seite gelesen. Jede Anfrage meldet sich mit der Kennung

```
Mozilla/5.0 (compatible; RedeMitMeinungsmache/1.0; +https://poc.deutschkaemer.info/meinungsmache/)
```

und zwischen zwei Artikelseiten liegt eine Pause (Voreinstellung 2 Sekunden, `--pause`).

## Zerlegung einer Artikelseite

`mm_artikel_holen()` liest das HTML mit `DOMDocument` und XPath:

| Feld | woher |
|---|---|
| `titel` | erste Überschrift `h1` im Artikel |
| `unterzeile` (Dachzeile) | die `h3` über dem Titel |
| `quelle` | Angabe „Quelle: …“ unter dem Titel (nur bei Meldungen) |
| `autor`, `autor_url` | Link auf `/autor/…` (bei eigenen Artikeln) |
| `bildquelle` | Link „Bildquelle: …“ |
| `original_url` | Link „Zum Originalartikel“ |
| `text` | Absätze, Zwischenüberschriften und Listen im Inhaltsbereich (`div.prose`), durch Leerzeilen getrennt |
| Links im Text | alle externen Links im Inhaltsbereich, in Reihenfolge, mit Linktext (Tabelle `mm_link`) |
| `veroeffentlicht`, `geaendert` | Meta-Angaben `article:published_time` und `article:modified_time`, gespeichert in UTC |
| `art` | `eigen`, wenn die Rubrik (`article:section`) „Eigene Artikel“ lautet, sonst `meldung` |

Beim Text werden Hinweiszeichen (ℹ︎), geschützte Leerzeichen und doppelte Leerzeichen bereinigt. Ändert sich der Aufbau der Seiten, schlägt die Zerlegung fehl; der Fehler steht dann im Lauf-Protokoll (`mm_abruf.fehler`) und der Artikel wird beim nächsten Lauf erneut versucht.

## Ablauf eines Laufs

1. Tabellen anlegen, falls sie fehlen (`schema.sql`).
2. **Feed:** Jeder Artikel, der noch nicht in der Datenbank steht, wird geholt. Artikel, die jünger als 48 Stunden sind, werden zusätzlich alle sechs Stunden erneut geholt, damit nachträgliche Korrekturen ankommen.
3. **Archiv** (nur mit `--nachholen`): Alle Adressen der Sitemap, die noch fehlen, neueste zuerst, höchstens `--max` (Voreinstellung 600).
4. **Speichern:** `mm_speichern()` legt einen Artikel an oder aktualisiert ihn. Hat sich das Änderungsdatum geändert, werden seine Vektoren gelöscht und neu berechnet; die Links im Text werden jedes Mal neu geschrieben.
5. **Einbetten:** Für alle Artikel ohne Vektoren (höchstens 1.000 je Lauf).
6. **Protokoll:** Eine Zeile in `mm_abruf` mit Zeit, Art des Laufs, Zahl neuer, geprüfter und eingebetteter Artikel, Fehlern und Dauer. Einträge älter als 90 Tage werden gelöscht.

```bash
php abruf.php                  # stündlicher Lauf: Feed, Aktualisierungen, Einbettung
php abruf.php --nachholen      # zusätzlich das Archiv aus der Sitemap
php abruf.php --einbetten      # nur fehlende Vektoren berechnen
php abruf.php --neu=SLUG       # einen Artikel neu holen
php abruf.php --still          # ohne Ausgabe (für Cron)
```

## Einbettung

Für die Suche nach Bedeutung wird jeder Artikel mit **Qwen3-Embedding-0.6B** eingebettet, auf 384 Dimensionen gekürzt (Matryoshka) und normiert. Vor den Text kommt ein Kopf aus Titel, Dachzeile, Quelle oder Autor, damit auch kurze Meldungen genug Zusammenhang haben:

```
Titel – Dachzeile
Quelle: Tagesschau
<Text>
```

Artikel bis etwa 380 Wörter bilden einen Textteil; längere werden in Teile zu 350 Wörtern zerlegt, jeder mit demselben Kopf. Dokumente werden ohne Anweisung eingebettet, Suchanfragen mit der Anweisung

```
Instruct: Given a question or topic about current German news, retrieve news articles that address it
Query: <Frage>
```

Das Modell läuft auf eigener Hardware; der Server erreicht es über den Einbettungsweg von plenarprotokolle.digital oder über einen eigenen Dienst (`einbettung_url`, siehe [server.md](server.md)). Ist es nicht erreichbar, bricht die Einbettung ab und der nächste Lauf holt sie nach. Die Suche fällt in der Zwischenzeit auf den Volltext zurück.

## Tabellen

| Tabelle | Inhalt |
|---|---|
| `mm_artikel` | ein Artikel je Zeile: Kennung (`slug`), Adresse, Titel, Dachzeile, Text, Art, Quelle, Original, Autor, Bildquelle, Zeiten (UTC); Volltextindex über Titel, Dachzeile und Text |
| `mm_link` | Links im Text je Artikel, mit Reihenfolge und Linktext |
| `mm_vektor` | Textteile mit Anfang, Länge und Vektor (`VECTOR(384)`) |
| `mm_abruf` | ein Eintrag je Lauf |

Bei einigen hundert Artikeln vergleicht `bedeutung_suchen` die Frage exakt mit allen Textteilen; ein Vektorindex lohnt sich erst bei deutlich mehr Daten.

## Grenzen

- Artikel, die auf meinungsmache.de gelöscht werden, bleiben in der Datenbank, bis sie von Hand entfernt werden.
- Spätere Änderungen an Artikeln, die älter als 48 Stunden sind, kommen nur mit `--neu=SLUG` an.
- Themen-Schlagworte der Seite (etwa die Themenseiten „AfD“ oder „CDU“) stehen nicht in den Artikelseiten und werden nicht übernommen; die Suche arbeitet mit dem Text.
- Bilder werden nicht übernommen, nur die Angabe zur Bildquelle.
