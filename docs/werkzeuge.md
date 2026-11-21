# Werkzeuge der Schnittstelle

`server/lib/mcp_server.php` spricht das Model Context Protocol (MCP) über **Streamable HTTP** in der einfachsten Form: jede Nachricht ein `POST` mit genau einem JSON-RPC-Objekt, jede Antwort ein JSON-Objekt, keine Sitzungen und kein Server-Sent-Events-Strom. Unterstützte Protokollversionen: `2025-11-25`, `2025-06-18`, `2025-03-26`.

- **Adresse:** `https://poc.deutschkaemer.info/meinungsmache/mcp`
- **Methoden:** `initialize`, `ping`, `tools/list`, `tools/call`; `resources/list`, `resources/templates/list` und `prompts/list` liefern leere Listen.
- **GET** ohne `Accept: text/event-stream` zeigt eine kurze Infoseite, mit diesem Kopf antwortet der Server mit 405.
- Alle Werkzeuge lesen nur (`readOnlyHint: true`) und brauchen keine Anmeldung.

## Werkzeuge

Zeiten in Ein- und Ausgaben gelten in deutscher Zeit (Europe/Berlin), gespeichert wird in UTC. Jede Antwort außer `search` und `fetch` enthält das Feld `quelle_hinweis` mit der Quellenangabe.

### `briefing`

Alle Artikel eines Zeitraums, neueste zuerst, getrennt in `meldungen` und `eigene_artikel`, mit Dachzeile, Text (Meldungen bis 700, eigene Artikel bis 900 Zeichen), Quelle oder Autor und Links. Höchstens 120 Artikel.

| `zeitraum` | ab |
|---|---|
| `morgen` | gestern 18 Uhr |
| `abend` | heute 6 Uhr (vor 6 Uhr: gestern 6 Uhr) |
| `tag` (Voreinstellung) | vor 24 Stunden |
| `woche` | vor 7 Tagen |

Mit `seit` (etwa `2026-11-19T12:00`) gilt stattdessen ein eigener Anfang. Das Feld `hinweis` sagt dem Modell, wie es das Briefing schreiben soll; ist der Zeitraum leer, schlägt es einen längeren vor.

### `neueste`

Artikel nach Zeit, neueste zuerst, mit Kurztext (320 Zeichen). Filter: `seit`, `bis` (ein reines Datum gilt bis zum Tagesende), `quelle` (Teil des Namens, etwa „Tagesschau“), `art` (`meldung` oder `eigen`), `autor`; `anzahl` 1 bis 60.

### `bedeutung_suchen`

Findet Artikel zu einer Frage oder einem Thema in eigenen Worten. Die Frage wird mit Suchanweisung eingebettet ([abruf.md](abruf.md#einbettung)) und exakt mit allen Textteilen verglichen (Kosinusabstand, je Artikel der beste Teil). Parallel läuft eine Volltextsuche mit den tragenden Wörtern der Frage (ohne Füllwörter wie „was“, „sich“, „beim“, jeweils als Wortanfang). Beide Ranglisten (je 60 Plätze) werden mit Reciprocal Rank Fusion (k = 60) zusammengeführt, der Volltext mit halbem Gewicht: Bei Nachrichten trägt die Bedeutung mehr, die Wörter helfen bei Namen und seltenen Begriffen. Das Feld `naehe` (1 − Abstand) zeigt die inhaltliche Nähe. Ist die Einbettung nicht erreichbar, antwortet das Werkzeug nur aus dem Volltext und sagt das im Feld `verfahren`. Optional `seit`, `bis`, `anzahl` (bis 30).

### `suchen`

Volltextsuche (MariaDB `FULLTEXT`, Boolean Mode) in Titel, Dachzeile und Text. Alle Wörter müssen vorkommen; `Wort*` sucht den Wortanfang, `"feste Wendung"` in Anführungszeichen eine Wortfolge. Wörter unter drei Buchstaben werden übergangen. Treffer neueste zuerst, mit Ausschnitt um die erste Fundstelle. Optional `seit`, `bis`, `anzahl` (bis 50).

### `artikel_lesen`

Ein Artikel vollständig, nach `id` oder `url` (Adresse auf meinungsmache.de oder nur der Slug). Dazu Bildquelle, Zeitpunkt einer Bearbeitung und `quellenlinks`: alle Links im Text mit Linktext.

### `quellen`

Medien, deren Berichte die Meldungen zusammenfassen, mit Anzahl und letztem Datum; dazu die Autorinnen und Autoren der eigenen Artikel. Optional `seit`, `bis`.

### `ueberblick`

Zahl der Artikel, Meldungen und eigenen Artikel, Zeitraum, letzter Abruf, die zwölf häufigsten Quellen und die Autorinnen und Autoren.

### `search`, `fetch`

Für Clients, die von einer Schnittstelle nur Suche und Abruf verwenden (ChatGPT ohne Entwicklermodus). `search` nutzt `bedeutung_suchen` und liefert `results` mit `id`, `title`, `url`; `fetch` liefert den Artikel als `text` mit `metadata` (Quelle, Autor, Original).

## Anweisungen an das Modell

`initialize` liefert im Feld `instructions` eine Anleitung, die Clients dem Modell mitgeben. Kernpunkte:

- **Einordnung:** meinungsmache.de wählt Themen aus und ordnet sie teils wertend ein. Antworten geben wieder, was dort steht, und machen das kenntlich („laut meinungsmache.de“). Bei Meldungen die Originalquelle nennen und verlinken, Meinungsbeiträge als Meinung der Autorin oder des Autors kennzeichnen. Keine eigene Bewertung von Personen, Parteien oder Positionen; für eine ausgewogene Einordnung auf weitere Quellen hinweisen.
- **Briefing:** Zeitraum nennen, nach Themen gruppieren, je Meldung ein bis zwei Sätze mit Link zum Artikel und zur Originalquelle, eigene Artikel der Redaktion gesondert aufführen, Uhrzeiten in deutscher Zeit.
- **Vorgehen:** `bedeutung_suchen` oder `suchen`, dann `artikel_lesen` für den vollen Text.
- **Urheberrecht:** zusammenfassen und verlinken, nicht vollständig wiedergeben; kurze Zitate kenntlich machen.

## Grenzen und Schutz

| Grenze | Wert |
|---|---|
| Aufrufe insgesamt | 240 je Minute |
| je Client (Adressbereich) | 120 je Minute, 1.500 je Stunde |
| Abfragezeit | 15 Sekunden je Datenbankabfrage |
| Antwortlänge | 60.000 Zeichen, danach gekürzt |
| Herkunft | Browser-Anfragen nur von Claude, ChatGPT und poc.deutschkaemer.info (Kopfzeile `Origin`) |

Der Adressbereich (die ersten drei Teile einer IPv4-Adresse, 48 Bit bei IPv6) wird nur als Abdruck im Arbeitsspeicher (APCu) gezählt. Das Protokoll unter `~/poc/mm/logs/JJJJ-MM.log` enthält Zeit, Werkzeug, gekürzte Argumente und Dauer, keine Adressen; Dateien älter als drei Monate werden gelöscht.

## Projektseite: `stand.php`

Für die Kennzahlen und die Liste „Zuletzt erschienen“ liefert `server/web/stand.php` ein kleines JSON (Zahl der Artikel, Meldungen, eigenen Artikel und Quellen, Zeitraum, letzter Abruf, sechs neueste Artikel). Es wird fünf Minuten im Arbeitsspeicher gehalten, nicht protokolliert und zählt nicht zu den Aufrufgrenzen der Schnittstelle.

## Beispiel

```bash
curl -s https://poc.deutschkaemer.info/meinungsmache/mcp \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"briefing","arguments":{"zeitraum":"morgen"}}}'
```

Einrichtung in Claude, ChatGPT, Grok, LM Studio, Claude Code, Codex, Cursor, VS Code und über die APIs von Anthropic und OpenAI: siehe [Projektseite](https://poc.deutschkaemer.info/meinungsmache/#einrichten).
