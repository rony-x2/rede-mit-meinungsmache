# Anreicherung mit einem lokalen Sprachmodell

Wie beim Schwesterprojekt [rede-mit-dem-afd-gutachten](https://github.com/rony-x2/rede-mit-dem-afd-gutachten/blob/main/docs/suche.md) schreibt ein großes Sprachmodell zu jedem Artikel Suchhilfen, die ein kleines Einbettungsmodell anschließend in Vektoren umrechnet. Die Zusätze dienen nur der Suche und der Gliederung von Briefings; Antworten kommen immer aus dem Artikeltext.

## Was je Artikel entsteht

| Feld | Inhalt | wofür |
|---|---|---|
| `fragen` | 3 bis 5 Fragen in Alltagssprache, die der Artikel beantwortet | Suche nach Bedeutung: Frage gegen Frage |
| `stichwoerter` | 6 bis 15 Suchbegriffe: Synonyme, Alltagswörter, Oberbegriffe, Namen in üblicher Schreibweise | Messung als eigene Rangliste (siehe unten) |
| `themen` | 1 bis 3 Themen aus einer festen Liste (`MM_THEMEN` in `server/lib/anreicherung.php`) | Gliederung des Briefings, Filter `thema` |

Beispiel für die Meldung „VW fordert harte Einschnitte von seinen Beschäftigten“:

- Fragen: *Was will VW bei den Löhnen ändern? · Warum will Volkswagen die Kosten senken? · Wird die Arbeitszeit bei VW erhöht?*
- Suchbegriffe: *Volkswagen, Automobilindustrie, Tarifvertrag, Kürzungen, Arbeitszeit, Lohnverhandlungen …*
- Themen: *Wirtschaft und Arbeit*

Anweisung und Antwortschema stehen in `server/lib/anreicherung.php` (`mm_anr_system()`, `mm_anr_schema()`). Das Modell antwortet mit JSON nach diesem Schema; Themen außerhalb der Liste lässt das Schema nicht zu. Lange Artikel gehen nach 7.000 Zeichen gekürzt an das Modell.

## Ablauf

Der Webserver erreicht die Grafikkarte zu Hause nicht. Deshalb holt sich ein kleines Programm auf dem Rechner mit der Karte die Arbeit selbst ab:

1. `arbeiter/mm_anreichern.py` läuft dort stündlich per Cron (um :40, nach dem Abruf um :17).
2. Es fragt `https://poc.deutschkaemer.info/meinungsmache/anreicherung.php` nach Artikeln ohne Anreicherung. Die Antwort enthält Modell, Anweisung, Schema und je Artikel den vorbereiteten Text samt Prüfsumme der Fassung.
3. Ist nichts zu tun, endet der Lauf. Sonst prüft es die Karte: Ist im llama-server-Router ein anderes Modell geladen oder belegt ein anderes Programm mehr als 1,5 GB Grafikspeicher, wartet es auf die nächste Stunde. Sonst lädt es Gemma 4 26B-A4B, lässt vier Artikel gleichzeitig beantworten und gibt die Ergebnisse in Paketen zu 40 zurück.
4. Der Server übernimmt ein Ergebnis nur, wenn der Artikel noch dieselbe Fassung hat. Hat das Programm das Modell selbst geladen, entlädt es es am Ende wieder.
5. Der nächste Abruf (`abruf.php`) bettet Fragen und Suchbegriffe ein, **mit derselben Anweisung wie Suchanfragen**. So stehen sich beim Suchen Frage und Frage gegenüber, nicht Frage und Artikeltext.

Ändert sich ein Artikel auf meinungsmache.de, verwirft der Abruf Vektoren und Anreicherung; beide entstehen neu. Bis ein neuer Artikel angereichert ist, findet ihn die Suche über Text und Volltext.

Zugang: Das Programm schickt ein Kennwort in der Kopfzeile `X-Token`. Es liegt auf dem Rechner mit der Karte unter `~/.config/mm_anreichern/token`, auf dem Server nur als SHA-256 in `~/poc/mm/anreicherung.hash`.

```bash
# einmalig auf dem Rechner mit der Karte
mkdir -p ~/.config/mm_anreichern && chmod 700 ~/.config/mm_anreichern
python3 -c "import secrets; print(secrets.token_hex(24))" > ~/.config/mm_anreichern/token && chmod 600 ~/.config/mm_anreichern/token
python3 -c "import hashlib,pathlib; print(hashlib.sha256(pathlib.Path.home().joinpath('.config/mm_anreichern/token').read_text().strip().encode()).hexdigest())"
# die ausgegebene Prüfsumme auf dem Server in ~/poc/mm/anreicherung.hash schreiben, dann:
( crontab -l; echo '40 * * * * flock -n /tmp/mm_anreichern.lck python3 $HOME/worker/mm_anreichern.py >> $HOME/worker/mm_anreichern.log 2>&1' ) | crontab -
```

## Messung

Grundlage ist der Testsatz [`messung/testsatz.json`](../messung/testsatz.json): 34 Fragen in Alltagssprache, zu jeder die passenden Artikel, von Hand festgelegt. Viele Fragen verwenden bewusst andere Wörter als die Artikel („Zwischenwahlen in Amerika“ statt Midterms, „Tanken“ statt Spritpreise). `messung/messen.py` misst über die öffentliche Schnittstelle, `messung/varianten.php` vergleicht Varianten direkt auf dem Server.

Messung vom 21. November 2026 (34 Fragen, Platz des besten passenden Artikels; Gewichte für Reciprocal Rank Fusion mit k = 60):

| Variante | Platz 1 | unter den ersten 5 | unter den ersten 10 | MRR |
|---|---|---|---|---|
| Artikeltext und Volltext (Stand vor der Anreicherung) | 25 | 32 | 33 | 0,82 |
| nur Artikeltext | 29 | 34 | 34 | 0,92 |
| nur erzeugte Fragen | 28 | 34 | 34 | 0,90 |
| **erzeugte Fragen 1,0 + Artikeltext 0,75 (jetzt live)** | **31** | **34** | **34** | **0,96** |
| dazu Volltext 0,2 | 28 | 33 | 34 | 0,90 |
| dazu Suchbegriffe 0,2 | 31 | 34 | 34 | 0,96 |
| Gewichte wie beim Gutachten (Fragen 1,0, Text 0,35, Volltext 0,25) | 28 | 33 | 34 | 0,90 |

Was daraus folgt:

- **Die erzeugten Fragen helfen dort, wo die Wörter auseinandergehen:** „Wie sind die Zwischenwahlen in Amerika ausgegangen?“ stieg von Platz 14 auf 1, „Wie teuer ist Tanken gerade?“ von 10 auf 1, „Kommt eine Steuer auf Softdrinks?“ von 3 auf 1.
- **Der Volltext schadet hier.** Bei kurzen Nachrichten verdrängt er mit Wortzufällen passende Artikel; die Vektoren kennen Namen wie „Weidel“ oder „Outlook“ ohnehin. Er läuft deshalb nur noch als Ersatz, wenn die Einbettung nicht erreichbar ist. Für genaue Wörter gibt es weiter das Werkzeug `suchen`.
- **Die Suchbegriffe als eigener Vektor ändern nichts.** Sie bleiben gespeichert, gehen aber mit dem Gewicht 0 in die Suche ein (`MM_GEWICHT_STICH`).
- Der Artikeltext bleibt nötig: Nur mit den Fragen fiel Platz 1 auf 28.

Einschränkung: Der Testsatz ist klein und von Hand zusammengestellt. Die Unterschiede zwischen den besten Varianten liegen bei ein bis zwei Fragen. Kommen neue Artikel dazu, lohnt es sich, den Testsatz zu erweitern und erneut zu messen:

```bash
python3 messung/messen.py name                          # über die öffentliche Schnittstelle
php ~/poc/mm/messung/varianten.php testsatz.json --einzeln   # Varianten direkt auf dem Server
```

Aufwand: Die 436 Artikel des Archivs brauchten auf einer Tesla P40 mit Gemma 4 26B-A4B (vier Artikel gleichzeitig) rund 19 Minuten, das Einbetten der 2.183 Fragen und Suchbegriffe auf dem Webserver gut 20 Minuten. Im laufenden Betrieb kommen am Tag einige Artikel dazu.
