<?php
/**
 * mcp_server.php – MCP-Schnittstelle zu den Artikeln von meinungsmache.de („Rede mit Meinungsmache“).
 *
 * Transport „Streamable HTTP“ in der einfachsten Form: jede Nachricht ein POST mit einem JSON-RPC-Objekt,
 * jede Antwort ein JSON-Objekt, keine Sitzungen. Nur lesend, ohne Anmeldung.
 * Schutz: Origin-Prüfung, Aufrufgrenzen über APCu, Zeitgrenze je Abfrage, gekürzte Antworten.
 * Protokoll: Zeit, Werkzeug, gekürzte Argumente, Dauer – keine Adressen.
 *
 * Inhalte: meinungsmache.de (mit Zustimmung des Betreibers). Aufbereitung: Dennis Deutschkämer.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/anreicherung.php';

const MM_VERSIONEN     = ['2025-11-25', '2025-06-18', '2025-03-26'];
const MM_NAME          = 'meinungsmache';
const MM_VERSION       = '0.1.0';
const MM_WEB           = 'https://poc.deutschkaemer.info/meinungsmache/';
const MM_ICONS = [
    ['src' => MM_WEB . 'favicon.svg', 'mimeType' => 'image/svg+xml', 'sizes' => ['any']],
    ['src' => MM_WEB . 'icon-512.png', 'mimeType' => 'image/png', 'sizes' => ['512x512']],
    ['src' => MM_WEB . 'icon-192.png', 'mimeType' => 'image/png', 'sizes' => ['192x192']],
    ['src' => MM_WEB . 'favicon-32.png', 'mimeType' => 'image/png', 'sizes' => ['32x32']],
];
const MM_JE_MINUTE     = 240;
const MM_CLIENT_MINUTE = 120;
const MM_CLIENT_STUNDE = 1500;
const MM_MAX_ZEICHEN   = 60000;
const MM_ZONE          = 'Europe/Berlin';
// Gewichte der Ranglisten in bedeutung_suchen (Reciprocal Rank Fusion, k = 60), gemessen mit messung/varianten.php
// am 21.11.2026 (34 Testfragen): Fragen + Text 31× Platz 1, nur Text 29×, Text + Volltext 25×. Suchbegriffe und
// Volltext brachten nichts oder schadeten; der Volltext springt nur ein, wenn die Einbettung nicht erreichbar ist.
const MM_GEWICHT_FRAGEN = 1.0;    // erzeugte Fragen (Frage gegen Frage)
const MM_GEWICHT_STICH  = 0.0;    // Suchbegriffe als ein Vektor
const MM_GEWICHT_TEXT   = 0.75;   // Artikeltext
const MM_GEWICHT_VOLL   = 0.0;    // Volltext mit den tragenden Wörtern (Ersatz ohne Einbettung: 1,0)
const MM_ORIGINS       = ['https://claude.ai', 'https://claude.com', 'https://www.claude.ai',
                          'https://chatgpt.com', 'https://chat.openai.com', 'https://poc.deutschkaemer.info'];
const MM_QUELLE = 'Quelle: meinungsmache.de – Nachrichten, Meinungen und Analysen. Meldungen fassen Berichte anderer Medien zusammen '
    . '(Originalquelle jeweils angegeben), eigene Artikel stammen von der Redaktion. Texte © meinungsmache.de bzw. den Originalmedien; '
    . 'stündlich abgerufen und aufbereitet von Dennis Deutschkämer (inoffiziell, maßgeblich ist meinungsmache.de).';

final class MmFehler extends RuntimeException {}     // Protokollfehler (JSON-RPC error)
final class MmEingabe extends RuntimeException {}    // Werkzeugfehler, den das Modell beheben kann (isError)

function mm_mcp_bedienen(): never
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($origin !== '' && !in_array($origin, MM_ORIGINS, true)) {
        mm_http(403, ['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => 'Origin nicht erlaubt']]);
    }
    $methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($methode === 'GET') {
        if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream')) {
            header('Allow: POST');
            mm_http(405, null);
        }
        mm_infoseite();
    }
    if ($methode !== 'POST') {
        header('Allow: GET, POST');
        mm_http(405, null);
    }
    $pv = (string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '');
    if ($pv !== '' && !in_array($pv, MM_VERSIONEN, true)) {
        mm_http(400, ['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => "Protokollversion $pv wird nicht unterstützt"]]);
    }
    try {
        $n = json_decode((string)file_get_contents('php://input', false, null, 0, 1_000_000), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        mm_http(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Kein gültiges JSON']]);
    }
    if (!is_array($n) || array_is_list($n)) {
        mm_http(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Erwartet wird genau ein JSON-RPC-Objekt']]);
    }
    if (!array_key_exists('id', $n) || !isset($n['method'])) {
        mm_http(202, null);
    }
    $id = $n['id'];
    $m = (string)$n['method'];
    $p = is_array($n['params'] ?? null) ? $n['params'] : [];
    try {
        $erg = match ($m) {
            'initialize'               => mm_initialize($p),
            'ping'                     => new stdClass(),
            'tools/list'               => ['tools' => mm_werkzeugliste()],
            'tools/call'               => mm_aufruf($p),
            'resources/list'           => ['resources' => []],
            'resources/templates/list' => ['resourceTemplates' => []],
            'prompts/list'             => ['prompts' => []],
            default                    => throw new MmFehler("Unbekannte Methode: $m", -32601),
        };
        mm_http(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $erg]);
    } catch (MmFehler $e) {
        mm_http(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]]);
    } catch (Throwable $e) {
        error_log('meinungsmache mcp: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        mm_http(200, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Interner Fehler']]);
    }
}

function mm_http(int $status, ?array $body): never
{
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

function mm_initialize(array $p): array
{
    $v = (string)($p['protocolVersion'] ?? '');
    return [
        'protocolVersion' => in_array($v, MM_VERSIONEN, true) ? $v : MM_VERSIONEN[0],
        'capabilities'    => ['tools' => ['listChanged' => false]],
        'serverInfo'      => ['name' => MM_NAME, 'title' => 'Rede mit Meinungsmache', 'version' => MM_VERSION,
                              'websiteUrl' => MM_WEB, 'icons' => MM_ICONS],
        'instructions'    => mm_anleitung(),
    ];
}

function mm_anleitung(): string
{
    $q = MM_QUELLE;
    return <<<TXT
Artikel der Nachrichtenseite meinungsmache.de („Aktuelle Nachrichten, Meinungen und Analysen“), stündlich aktualisiert, zurück bis Dezember 2025. Zwei Arten: Meldungen fassen einen Bericht eines anderen Mediums zusammen (Feld quelle, Link original_url, z. B. Tagesschau, Süddeutsche Zeitung); eigene Artikel stammen von der Redaktion (Feld autor), oft Erfahrungsberichte, Analysen oder Meinungsbeiträge.

Einordnung: meinungsmache.de wählt Themen aus und ordnet sie teils wertend ein. Antworten geben wieder, was dort steht, und machen das kenntlich („laut meinungsmache.de“, „in einem Beitrag von Gerrit heißt es …“). Bei Meldungen die Originalquelle nennen und verlinken; Meinungsbeiträge als Meinung der Autorin bzw. des Autors kennzeichnen. Keine eigene Bewertung von Personen, Parteien oder Positionen hinzufügen; für eine ausgewogene Einordnung auf weitere Quellen hinweisen.

Briefing (z. B. „Was war heute Morgen los?“, „Mach mir ein Abendbriefing“): Werkzeug `briefing` mit zeitraum morgen (seit gestern 18 Uhr), abend (seit heute 6 Uhr), tag (24 Stunden) oder woche. Daraus ein kurzes Briefing schreiben: Zeitraum nennen, nach Themen gruppieren (Feld themen je Artikel, Übersicht im Feld themen der Antwort), je Meldung ein bis zwei Sätze, jeweils mit Link zum Artikel auf meinungsmache.de und – falls vorhanden – zur Originalquelle; eigene Artikel der Redaktion gesondert aufführen. Uhrzeiten in deutscher Zeit.

Vorgehen bei Fragen: `bedeutung_suchen` (Frage in eigenen Worten) oder `suchen` (genaue Wörter), dann `artikel_lesen` für den vollständigen Text mit Quellenlinks. `neueste` listet Artikel nach Zeit, wahlweise nach Quelle, Art, Autor oder Thema gefiltert; mit `thema` lässt sich auch das Briefing eingrenzen (etwa nur „Klima und Umwelt“); `quellen` zeigt, aus welchen Medien die Meldungen stammen; `ueberblick` nennt Umfang und Stand.

Urheberrecht: Die Texte gehören meinungsmache.de bzw. den Originalmedien. Zusammenfassen und verlinken, nicht vollständig wiedergeben; kurze Zitate kenntlich machen.

{$q}
TXT;
}

function mm_werkzeugliste(): array
{
    $ro = ['readOnlyHint' => true, 'openWorldHint' => false];
    $thema = ['type' => 'string', 'enum' => MM_THEMEN, 'description' => 'nur Artikel zu diesem Thema (Themen vergibt ein lokales Sprachmodell; neue Artikel erst nach der Anreicherung)'];
    $zeit = ['seit' => ['type' => 'string', 'description' => 'Ab diesem Zeitpunkt (ISO-Datum oder Datum mit Uhrzeit, deutsche Zeit), z. B. 2026-11-20 oder 2026-11-20T18:00'],
             'bis'  => ['type' => 'string', 'description' => 'Bis zu diesem Zeitpunkt (wie seit)']];
    return [
        ['name' => 'ueberblick', 'title' => 'Überblick',
         'description' => 'Umfang und Stand des Bestands: Zahl der Artikel, Zeitraum, letzter Abruf, häufigste Quellen, Autorinnen und Autoren der eigenen Artikel.',
         'inputSchema' => ['type' => 'object', 'properties' => new stdClass()], 'annotations' => $ro],
        ['name' => 'briefing', 'title' => 'Briefing',
         'description' => 'Alle Artikel eines Zeitraums für ein Morgen- oder Abendbriefing: mit Dachzeile, Text (bei langen Artikeln gekürzt), Quelle oder Autor und Links. morgen = seit gestern 18 Uhr, abend = seit heute 6 Uhr, tag = letzte 24 Stunden, woche = letzte 7 Tage (deutsche Zeit).',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'zeitraum' => ['type' => 'string', 'enum' => ['morgen', 'abend', 'tag', 'woche'], 'default' => 'tag'],
             'seit' => $zeit['seit'],
             'thema' => $thema,
         ]], 'annotations' => $ro],
        ['name' => 'neueste', 'title' => 'Neueste Artikel',
         'description' => 'Artikel nach Zeit, neueste zuerst; wahlweise nach Zeitraum, Quelle (z. B. Tagesschau), Art (meldung, eigen), Autor oder Thema gefiltert. Liefert Kurztexte; den vollen Text mit artikel_lesen.',
         'inputSchema' => ['type' => 'object', 'properties' => $zeit + [
             'quelle' => ['type' => 'string', 'description' => 'Medium der Originalmeldung, z. B. Tagesschau'],
             'art' => ['type' => 'string', 'enum' => ['meldung', 'eigen']],
             'autor' => ['type' => 'string'],
             'thema' => $thema,
             'anzahl' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 20],
         ]], 'annotations' => $ro],
        ['name' => 'bedeutung_suchen', 'title' => 'Suche nach Bedeutung',
         'description' => 'Findet Artikel zu einer Frage oder einem Thema in eigenen Worten, auch wenn andere Begriffe verwendet werden (Vektoren über Artikeltext und vorab erzeugte Fragen).',
         'inputSchema' => ['type' => 'object', 'required' => ['frage'], 'properties' => [
             'frage' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500],
         ] + $zeit + ['anzahl' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 10]]], 'annotations' => $ro],
        ['name' => 'suchen', 'title' => 'Volltextsuche',
         'description' => 'Sucht genaue Wörter in Titel, Dachzeile und Text. Wort* für den Wortanfang, "feste Wendung" in Anführungszeichen.',
         'inputSchema' => ['type' => 'object', 'required' => ['anfrage'], 'properties' => [
             'anfrage' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 300],
         ] + $zeit + ['anzahl' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15]]], 'annotations' => $ro],
        ['name' => 'artikel_lesen', 'title' => 'Artikel lesen',
         'description' => 'Vollständiger Artikel mit Dachzeile, Text, Quelle oder Autor, Link zum Original und den im Text verlinkten Quellen.',
         'inputSchema' => ['type' => 'object', 'properties' => [
             'id' => ['type' => 'integer', 'description' => 'Kennung aus anderen Antworten'],
             'url' => ['type' => 'string', 'description' => 'oder Adresse des Artikels auf meinungsmache.de'],
         ]], 'annotations' => $ro],
        ['name' => 'quellen', 'title' => 'Quellen',
         'description' => 'Medien, deren Berichte in den Meldungen zusammengefasst werden, mit Anzahl und letztem Datum; dazu die Autorinnen und Autoren der eigenen Artikel.',
         'inputSchema' => ['type' => 'object', 'properties' => $zeit], 'annotations' => $ro],
        ['name' => 'search', 'title' => 'Suche (ChatGPT)',
         'description' => 'Vereinfachte Suche für Clients, die nur search/fetch nutzen. Liefert Artikel mit id, Titel und Adresse.',
         'inputSchema' => ['type' => 'object', 'required' => ['query'], 'properties' => ['query' => ['type' => 'string']]], 'annotations' => $ro],
        ['name' => 'fetch', 'title' => 'Abruf (ChatGPT)',
         'description' => 'Volltext eines Artikels aus search.',
         'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string']]], 'annotations' => $ro],
    ];
}

function mm_aufruf(array $p): array
{
    $name = (string)($p['name'] ?? '');
    $args = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
    mm_grenzen();
    $t0 = microtime(true);
    try {
        $daten = match ($name) {
            'ueberblick'       => mm_w_ueberblick(),
            'briefing'         => mm_w_briefing($args),
            'neueste'          => mm_w_neueste($args),
            'bedeutung_suchen' => mm_w_bedeutung($args),
            'suchen'           => mm_w_suchen($args),
            'artikel_lesen'    => mm_w_lesen($args),
            'quellen'          => mm_w_quellen($args),
            'search'           => mm_w_search($args),
            'fetch'            => mm_w_fetch($args),
            default            => throw new MmFehler("Unbekanntes Werkzeug: $name", -32602),
        };
        $fehler = null;
    } catch (MmEingabe $e) {
        $daten = null;
        $fehler = $e->getMessage();
    }
    mm_log($name, $args, $t0, $fehler);
    if ($fehler !== null) {
        return ['content' => [['type' => 'text', 'text' => $fehler]], 'isError' => true];
    }
    if (!in_array($name, ['search', 'fetch'], true)) {
        $daten['quelle_hinweis'] = MM_QUELLE;
    }
    $json = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
    if (mb_strlen($json) > MM_MAX_ZEICHEN) {
        $json = mb_substr($json, 0, MM_MAX_ZEICHEN) . "\n… (gekürzt)";
    }
    return ['content' => [['type' => 'text', 'text' => $json]]];
}

/* ------------------------------------------------------------------ Hilfen */

/** UTC aus der Datenbank → deutsche Zeit „2026-11-21 18:00“. */
function mm_zeit(?string $utc): ?string
{
    if (!$utc) {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(MM_ZONE))->format('Y-m-d H:i');
}

/** Eingabe in deutscher Zeit → UTC für SQL. */
function mm_utc(string $s, string $feld): string
{
    try {
        $d = new DateTimeImmutable(trim($s), new DateTimeZone(MM_ZONE));
    } catch (Exception) {
        throw new MmEingabe("$feld: Datum nicht lesbar, bitte z. B. 2026-11-20 oder 2026-11-20T18:00 angeben.");
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** WHERE-Teil für seit/bis. */
function mm_zeitfilter(array $a, array &$par, string $sp = 'a.veroeffentlicht'): string
{
    $w = '';
    if (!empty($a['seit'])) {
        $w .= " AND $sp >= ?";
        $par[] = mm_utc((string)$a['seit'], 'seit');
    }
    if (!empty($a['bis'])) {
        $bis = (string)$a['bis'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($bis))) {
            $bis .= 'T23:59:59';
        }
        $w .= " AND $sp <= ?";
        $par[] = mm_utc($bis, 'bis');
    }
    return $w;
}

function mm_thema_pruefen(array $a): ?string
{
    if (empty($a['thema'])) {
        return null;
    }
    if (!in_array($a['thema'], MM_THEMEN, true)) {
        throw new MmEingabe('thema: eines von ' . implode(', ', MM_THEMEN));
    }
    return $a['thema'];
}

function mm_kurz(string $text, int $n = 320): string
{
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_strlen($text) > $n ? rtrim(mb_substr($text, 0, $n)) . '…' : $text;
}

/** Artikel als Treffer: knapp, mit Links. */
/** Themen eines Artikels aus der Anreicherung (leer, solange er nicht angereichert ist). */
function mm_themen_von(int $id): array
{
    static $st = null;
    $st ??= mm_db()->prepare('SELECT thema FROM mm_thema WHERE artikel_id = ? ORDER BY thema');
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function mm_eintrag(array $a, int $kurz = 320): array
{
    $e = ['id' => (int)$a['id'], 'zeit' => mm_zeit($a['veroeffentlicht']), 'titel' => $a['titel']];
    if ($a['unterzeile']) {
        $e['dachzeile'] = $a['unterzeile'];
    }
    $e['art'] = $a['art'];
    if ($a['art'] === 'eigen') {
        $e['autor'] = $a['autor'] ?: 'Redaktion';
    } else {
        $e['quelle'] = $a['quelle'];
    }
    if ($th = mm_themen_von((int)$a['id'])) {
        $e['themen'] = $th;
    }
    $e['text'] = $kurz > 0 ? mm_kurz($a['text'], $kurz) : $a['text'];
    $e['url'] = $a['url'];
    if ($a['original_url']) {
        $e['original_url'] = $a['original_url'];
    }
    return $e;
}

function mm_boolesch(string $q): array
{
    $q = trim(preg_replace('/\s+/', ' ', $q));
    $teile = [];
    $woerter = [];
    preg_match_all('/"([^"]+)"/u', $q, $m);
    foreach ($m[1] as $ph) {
        $teile[] = '+"' . str_replace('"', '', $ph) . '"';
        $woerter[] = $ph;
    }
    foreach (preg_split('/\s+/u', trim(preg_replace('/"[^"]*"/u', ' ', $q)), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $w = preg_replace('/[^\p{L}\p{N}*\-]/u', '', $w);
        if (mb_strlen(rtrim($w, '*')) < 3) {
            continue;
        }
        $woerter[] = rtrim($w, '*');
        $teile[] = '+' . (str_contains($w, '-') ? '"' . rtrim($w, '*') . '"' : $w);
    }
    if (!$teile) {
        throw new MmEingabe('Die Anfrage braucht mindestens ein Wort mit drei Buchstaben.');
    }
    return [implode(' ', $teile), $woerter];
}

function mm_stand(): array
{
    $db = mm_db();
    $z = $db->query('SELECT COUNT(*) n, MIN(veroeffentlicht) von, MAX(veroeffentlicht) bis FROM mm_artikel')->fetch();
    $ab = $db->query('SELECT zeit FROM mm_abruf WHERE art <> \'einbetten\' ORDER BY id DESC LIMIT 1')->fetchColumn();
    return ['artikel' => (int)$z['n'], 'von' => mm_zeit($z['von']), 'bis' => mm_zeit($z['bis']),
            'letzter_abruf' => mm_zeit($ab ?: null)];
}

/* ------------------------------------------------------------------ Werkzeuge im Einzelnen */

function mm_w_ueberblick(): array
{
    $db = mm_db();
    $arten = $db->query("SELECT art, COUNT(*) n FROM mm_artikel GROUP BY art")->fetchAll(PDO::FETCH_KEY_PAIR);
    $quellen = $db->query("SELECT quelle, COUNT(*) n FROM mm_artikel WHERE art='meldung' AND quelle IS NOT NULL
                           GROUP BY quelle ORDER BY n DESC LIMIT 12")->fetchAll(PDO::FETCH_KEY_PAIR);
    $autoren = $db->query("SELECT COALESCE(autor,'Redaktion') a, COUNT(*) n FROM mm_artikel WHERE art='eigen'
                           GROUP BY a ORDER BY n DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'titel' => 'Rede mit Meinungsmache',
        'beschreibung' => 'Artikel von meinungsmache.de: Meldungen (Zusammenfassungen von Berichten anderer Medien mit Link zum Original) und eigene Artikel der Redaktion. Stündlich aktualisiert.',
        'stand' => mm_stand(),
        'meldungen' => (int)($arten['meldung'] ?? 0),
        'eigene_artikel' => (int)($arten['eigen'] ?? 0),
        'haeufigste_quellen' => array_map('intval', $quellen),
        'autoren_eigene_artikel' => array_map('intval', $autoren),
        'themen' => array_map('intval', $db->query('SELECT thema, COUNT(*) n FROM mm_thema GROUP BY thema ORDER BY n DESC')->fetchAll(PDO::FETCH_KEY_PAIR)),
        'angereichert' => (int)$db->query('SELECT COUNT(*) FROM mm_anreicherung')->fetchColumn(),
        'webseite' => 'https://meinungsmache.de',
    ];
}

function mm_w_briefing(array $a): array
{
    $tz = new DateTimeZone(MM_ZONE);
    $jetzt = new DateTimeImmutable('now', $tz);
    $art = (string)($a['zeitraum'] ?? 'tag');
    if (!empty($a['seit'])) {
        $seit = new DateTimeImmutable(mm_utc((string)$a['seit'], 'seit'), new DateTimeZone('UTC'));
        $art = 'seit';
    } else {
        $seit = match ($art) {
            'morgen' => $jetzt->modify('yesterday')->setTime(18, 0),
            'abend'  => ($jetzt->format('H') < 6 ? $jetzt->modify('yesterday') : $jetzt)->setTime(6, 0),
            'woche'  => $jetzt->modify('-7 days'),
            'tag'    => $jetzt->modify('-24 hours'),
            default  => throw new MmEingabe('zeitraum: morgen, abend, tag oder woche'),
        };
    }
    $seitUtc = $seit->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $thema = mm_thema_pruefen($a);
    $st = mm_db()->prepare('SELECT a.* FROM mm_artikel a WHERE a.veroeffentlicht >= ?'
        . ($thema ? ' AND EXISTS (SELECT 1 FROM mm_thema t WHERE t.artikel_id = a.id AND t.thema = ?)' : '')
        . ' ORDER BY a.veroeffentlicht DESC LIMIT 120');
    $st->execute($thema ? [$seitUtc, $thema] : [$seitUtc]);
    $alle = $st->fetchAll();
    $meldungen = [];
    $eigene = [];
    foreach ($alle as $r) {
        if ($r['art'] === 'eigen') {
            $eigene[] = mm_eintrag($r, 900);
        } else {
            $meldungen[] = mm_eintrag($r, 700);
        }
    }
    return [
        'zeitraum' => $art, 'seit' => $seit->setTimezone($tz)->format('Y-m-d H:i'), 'bis' => $jetzt->format('Y-m-d H:i'),
        'anzahl' => count($alle), 'themen' => mm_themen_zaehlen(array_merge($meldungen, $eigene)),
        'meldungen' => $meldungen, 'eigene_artikel' => $eigene,
        'hinweis' => $alle ? 'Für das Briefing nach Themen gruppieren (Feld themen je Artikel; noch nicht angereicherte Artikel selbst zuordnen), je Meldung ein bis zwei Sätze, mit Link zum Artikel und zur Originalquelle; eigene Artikel der Redaktion gesondert nennen und als deren Beitrag kennzeichnen.'
                          : 'In diesem Zeitraum sind keine Artikel erschienen. Mit zeitraum „tag“ oder „woche“ lässt sich weiter zurückblicken.',
        'stand' => mm_stand(),
    ];
}

/** Wie oft jedes Thema in einer Trefferliste vorkommt. */
function mm_themen_zaehlen(array $eintraege): array
{
    $z = [];
    foreach ($eintraege as $e) {
        foreach ($e['themen'] ?? [] as $t) {
            $z[$t] = ($z[$t] ?? 0) + 1;
        }
    }
    arsort($z);
    return $z;
}

function mm_w_neueste(array $a): array
{
    $par = [];
    $w = mm_zeitfilter($a, $par);
    if (!empty($a['quelle'])) {
        $w .= ' AND a.quelle LIKE ?';
        $par[] = '%' . str_replace(['%', '_'], '', (string)$a['quelle']) . '%';
    }
    if (!empty($a['art']) && in_array($a['art'], ['meldung', 'eigen'], true)) {
        $w .= ' AND a.art = ?';
        $par[] = $a['art'];
    }
    if (!empty($a['autor'])) {
        $w .= ' AND a.autor LIKE ?';
        $par[] = '%' . str_replace(['%', '_'], '', (string)$a['autor']) . '%';
    }
    if ($thema = mm_thema_pruefen($a)) {
        $w .= ' AND EXISTS (SELECT 1 FROM mm_thema t WHERE t.artikel_id = a.id AND t.thema = ?)';
        $par[] = $thema;
    }
    $n = max(1, min(60, (int)($a['anzahl'] ?? 20)));
    $st = mm_db()->prepare("SELECT a.* FROM mm_artikel a WHERE 1 $w ORDER BY a.veroeffentlicht DESC LIMIT $n");
    $st->execute($par);
    return ['treffer' => array_map(static fn($r) => mm_eintrag($r), $st->fetchAll()), 'stand' => mm_stand()];
}

function mm_w_suchen(array $a): array
{
    [$bool, $woerter] = mm_boolesch((string)($a['anfrage'] ?? ''));
    $par = [$bool];
    $w = mm_zeitfilter($a, $par);
    $n = max(1, min(50, (int)($a['anzahl'] ?? 15)));
    $st = mm_db()->prepare("SELECT a.*, MATCH(titel, unterzeile, text) AGAINST(? IN BOOLEAN MODE) s FROM mm_artikel a
                            WHERE MATCH(titel, unterzeile, text) AGAINST(? IN BOOLEAN MODE) $w ORDER BY a.veroeffentlicht DESC LIMIT $n");
    $st->execute(array_merge([$bool], $par));
    $treffer = [];
    foreach ($st->fetchAll() as $r) {
        $e = mm_eintrag($r, 0);
        $e['text'] = mm_ausschnitt($r['text'], $woerter);
        $treffer[] = $e;
    }
    return ['anfrage' => $a['anfrage'], 'treffer' => $treffer,
            'hinweis' => 'Neueste zuerst. Vollständigen Text mit artikel_lesen (id).'];
}

function mm_ausschnitt(string $text, array $woerter, int $breite = 320): string
{
    $text = preg_replace('/\s+/u', ' ', $text);
    $pos = null;
    foreach ($woerter as $w) {
        $p = mb_stripos($text, trim($w, '*'));
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = max(0, ($pos ?? 0) - intdiv($breite, 3));
    return ($start > 0 ? '…' : '') . trim(mb_substr($text, $start, $breite)) . ($start + $breite < mb_strlen($text) ? '…' : '');
}

/** Tragende Wörter einer Frage für die Volltextsuche: ohne deutsche Füllwörter, mindestens drei Buchstaben. */
function mm_suchwoerter(string $frage): array
{
    static $fuell = ['aber','alle','allem','allen','aller','alles','als','also','am','an','auch','auf','aus','bei','beim','bin','bis',
        'bitte','das','dass','dem','den','der','des','die','dies','diese','diesem','diesen','dieser','dieses','doch','dort','durch',
        'ein','eine','einem','einen','einer','eines','gab','gibt','gerade','gestern','habe','haben','hat','heute','hier','ich','ihr',
        'im','in','ist','jetzt','kann','mir','mit','nach','neu','neue','neues','nicht','noch','nur','oder','sein','seit','sich','sie',
        'sind','so','steht','über','um','und','uns','unter','vom','von','vor','war','waren','was','weiß','welche','welcher','welches',
        'wenn','wer','werden','wie','wieso','wird','wo','woche','zu','zum','zur','tut','läuft','meinungsmache','zeig',
        'erzähl','sag','schreibt','geschrieben','berichtet','artikel','meldung','meldungen','thema','themen','etwas','eigentlich'];
    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($frage), $m);
    $aus = [];
    foreach ($m[0] as $w) {
        if (mb_strlen($w) >= 3 && !in_array($w, $fuell, true)) {
            $aus[$w] = true;
        }
    }
    return array_slice(array_keys($aus), 0, 12);
}

function mm_w_bedeutung(array $a): array
{
    $frage = trim((string)($a['frage'] ?? ''));
    if (mb_strlen($frage) < 3) {
        throw new MmEingabe('frage: bitte eine Frage oder ein Thema angeben.');
    }
    $n = max(1, min(30, (int)($a['anzahl'] ?? 10)));
    $db = mm_db();
    $par = [];
    $zeit = mm_zeitfilter($a, $par);
    $punkte = [];
    $naehe = [];
    $v = mm_vektor($frage, MM_VEK_ANWEISUNG);
    if ($v) {
        // exakt über alle Textteile (wenige tausend Zeilen), je Artikel der beste Teil
        $st = $db->prepare("SELECT v.artikel_id, MIN(VEC_DISTANCE_COSINE(v.vektor, VEC_FromText(?))) d
                            FROM mm_vektor v JOIN mm_artikel a ON a.id = v.artikel_id WHERE 1 $zeit
                            GROUP BY v.artikel_id ORDER BY d LIMIT 60");
        $st->execute(array_merge([mm_vektor_text($v)], $par));
        foreach ($st->fetchAll() as $i => $r) {
            $id = (int)$r['artikel_id'];
            $punkte[$id] = ($punkte[$id] ?? 0) + MM_GEWICHT_TEXT / (60 + $i + 1);
            $naehe[$id] = round(1 - (float)$r['d'], 3);
        }
    }
    if ($v && (MM_GEWICHT_FRAGEN > 0 || MM_GEWICHT_STICH > 0)) {
        // erzeugte Fragen (nr > 0) und Suchbegriffe (nr = 0) aus der Anreicherung, je Artikel die nächste
        foreach ([['nr > 0', MM_GEWICHT_FRAGEN], ['nr = 0', MM_GEWICHT_STICH]] as [$bed, $g]) {
            if ($g <= 0) {
                continue;
            }
            $st = $db->prepare("SELECT f.artikel_id, MIN(VEC_DISTANCE_COSINE(f.vektor, VEC_FromText(?))) d
                                FROM mm_frage f JOIN mm_artikel a ON a.id = f.artikel_id WHERE f.$bed AND f.vektor IS NOT NULL $zeit
                                GROUP BY f.artikel_id ORDER BY d LIMIT 60");
            $st->execute(array_merge([mm_vektor_text($v)], $par));
            foreach ($st->fetchAll() as $i => $r) {
                $punkte[(int)$r['artikel_id']] = ($punkte[(int)$r['artikel_id']] ?? 0) + $g / (60 + $i + 1);
            }
        }
    }
    // Volltext als weitere Rangliste: nur die tragenden Wörter der Frage (ohne Füllwörter), als Wortanfang
    $woerter = mm_suchwoerter($frage);
    $gVoll = $v ? MM_GEWICHT_VOLL : 1.0;
    if ($woerter && $gVoll > 0) {
        $bool = implode(' ', array_map(static fn($w) => $w . '*', $woerter));
        $st = $db->prepare("SELECT a.id FROM mm_artikel a WHERE MATCH(titel, unterzeile, text) AGAINST(? IN BOOLEAN MODE) $zeit
                            ORDER BY MATCH(titel, unterzeile, text) AGAINST(? IN BOOLEAN MODE) DESC LIMIT 60");
        $st->execute(array_merge([$bool], $par, [$bool]));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
            $punkte[(int)$id] = ($punkte[(int)$id] ?? 0) + $gVoll / (60 + $i + 1);
        }
    }
    arsort($punkte);
    $ids = array_slice(array_keys($punkte), 0, $n);
    $treffer = [];
    if ($ids) {
        $zeilen = $db->query('SELECT * FROM mm_artikel WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($ids as $id) {
            $r = $zeilen[$id] + ['id' => $id];
            $e = mm_eintrag($r);
            if (isset($naehe[$id])) {
                $e['naehe'] = $naehe[$id];
            }
            $treffer[] = $e;
        }
    }
    return [
        'frage' => $frage, 'verfahren' => $v ? 'bedeutung' : 'volltext',
        'treffer' => $treffer,
        'hinweis' => ($v ? 'Sortiert nach Bedeutung (Artikeltext und vorab erzeugte Fragen); naehe = inhaltliche Nähe des Textes (1 = sehr nah). Für genaue Wörter und Namen das Werkzeug suchen. '
                         : 'Die Suche nach Bedeutung ist gerade nicht erreichbar, daher Volltextsuche. ')
                   . 'Vollständigen Text mit artikel_lesen (id) lesen; Aussagen am Text prüfen.',
    ];
}

function mm_w_lesen(array $a): array
{
    $db = mm_db();
    if (!empty($a['id'])) {
        $st = $db->prepare('SELECT * FROM mm_artikel WHERE id = ?');
        $st->execute([(int)$a['id']]);
    } elseif (!empty($a['url'])) {
        $slug = preg_match('~/article/([a-z0-9-]+)~', (string)$a['url'], $m) ? $m[1] : (string)$a['url'];
        $st = $db->prepare('SELECT * FROM mm_artikel WHERE slug = ?');
        $st->execute([$slug]);
    } else {
        throw new MmEingabe('Bitte id oder url angeben.');
    }
    $r = $st->fetch();
    if (!$r) {
        throw new MmEingabe('Artikel nicht gefunden. Kennungen liefern neueste, suchen und bedeutung_suchen.');
    }
    $e = mm_eintrag($r, 0);
    if ($r['geaendert'] && $r['geaendert'] !== $r['veroeffentlicht']) {
        $e['bearbeitet'] = mm_zeit($r['geaendert']);
    }
    if ($r['bildquelle']) {
        $e['bildquelle'] = $r['bildquelle'];
    }
    $l = $db->prepare('SELECT url, text FROM mm_link WHERE artikel_id = ? ORDER BY nr');
    $l->execute([$r['id']]);
    $links = $l->fetchAll();
    if ($links) {
        $e['quellenlinks'] = $links;
    }
    return $e;
}

function mm_w_quellen(array $a): array
{
    $par = [];
    $zeit = mm_zeitfilter($a, $par);
    $db = mm_db();
    $st = $db->prepare("SELECT quelle, COUNT(*) n, MAX(veroeffentlicht) zuletzt FROM mm_artikel a
                        WHERE art='meldung' AND quelle IS NOT NULL $zeit GROUP BY quelle ORDER BY n DESC");
    $st->execute($par);
    $q = array_map(static fn($r) => ['quelle' => $r['quelle'], 'meldungen' => (int)$r['n'], 'zuletzt' => mm_zeit($r['zuletzt'])], $st->fetchAll());
    $st = $db->prepare("SELECT COALESCE(autor,'Redaktion') autor, COUNT(*) n, MAX(veroeffentlicht) zuletzt FROM mm_artikel a
                        WHERE art='eigen' $zeit GROUP BY autor ORDER BY n DESC");
    $st->execute($par);
    $au = array_map(static fn($r) => ['autor' => $r['autor'], 'artikel' => (int)$r['n'], 'zuletzt' => mm_zeit($r['zuletzt'])], $st->fetchAll());
    return ['quellen' => $q, 'eigene_artikel' => $au, 'stand' => mm_stand()];
}

function mm_w_search(array $a): array
{
    $erg = mm_w_bedeutung(['frage' => (string)($a['query'] ?? ''), 'anzahl' => 10]);
    return ['results' => array_map(static fn($t) => ['id' => (string)$t['id'], 'title' => $t['titel'], 'url' => $t['url']], $erg['treffer'])];
}

function mm_w_fetch(array $a): array
{
    $e = mm_w_lesen(['id' => (int)($a['id'] ?? 0)]);
    $text = ($e['dachzeile'] ?? '') . "\n" . $e['titel'] . "\n" . ($e['quelle'] ?? $e['autor'] ?? '') . ', ' . $e['zeit'] . "\n\n" . $e['text'];
    return ['id' => (string)$e['id'], 'title' => $e['titel'], 'text' => trim($text), 'url' => $e['url'],
            'metadata' => ['quelle' => $e['quelle'] ?? null, 'autor' => $e['autor'] ?? null, 'original_url' => $e['original_url'] ?? null]];
}

/* ------------------------------------------------------------------ Grenzen und Protokoll */

function mm_grenzen(): void
{
    if (!function_exists('apcu_inc')) {
        return;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bereich = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)) : implode('.', array_slice(explode('.', $ip), 0, 3));
    $min = intdiv(time(), 60);
    $std = intdiv(time(), 3600);
    foreach ([["mm:g:$min", 120, MM_JE_MINUTE], ['mm:c:' . md5($bereich) . ":$min", 120, MM_CLIENT_MINUTE],
              ['mm:c:' . md5($bereich) . ":h$std", 7200, MM_CLIENT_STUNDE]] as [$k, $ttl, $max]) {
        apcu_add($k, 0, $ttl);
        if (apcu_inc($k) > $max) {
            throw new MmFehler('Zu viele Anfragen – bitte kurz warten.', -32000);
        }
    }
}

function mm_log(string $werkzeug, array $args, float $t0, ?string $fehler): void
{
    $dir = mm_home() . '/poc/mm/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $zeile = date('Y-m-d H:i:s') . "\t" . $werkzeug . "\t" . mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE), 0, 300)
        . "\t" . (int)round((microtime(true) - $t0) * 1000) . 'ms' . ($fehler ? "\tFEHLER: " . mb_substr($fehler, 0, 120) : '') . "\n";
    @file_put_contents($dir . '/' . date('Y-m') . '.log', $zeile, FILE_APPEND | LOCK_EX);
    if (random_int(1, 200) === 1) {
        $grenze = date('Y-m', strtotime('first day of -2 months'));
        foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9].log') ?: [] as $datei) {
            if (basename($datei, '.log') < $grenze) {
                @unlink($datei);
            }
        }
    }
}

function mm_infoseite(): never
{
    header('Content-Type: text/html; charset=utf-8');
    $url = MM_WEB . 'mcp';
    echo <<<HTML
<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>MCP-Schnittstelle – Rede mit Meinungsmache</title><meta name="robots" content="noindex">
<link rel="icon" href="/meinungsmache/favicon.svg" type="image/svg+xml"><link rel="icon" href="/meinungsmache/favicon-32.png" sizes="32x32" type="image/png">
<link rel="stylesheet" href="/style.css"></head><body><main class="rahmen" style="padding:48px 0">
<p class="kicker">MCP-Schnittstelle</p><h1>Rede mit Meinungsmache</h1>
<p>Diese Adresse ist für Sprachmodelle gedacht (Model Context Protocol). In Claude oder ChatGPT als benutzerdefinierten Connector eintragen:</p>
<p><code>$url</code></p>
<p>Mehr dazu auf der <a href="/meinungsmache/">Projektseite</a>.</p></main></body></html>
HTML;
    exit;
}

/* ------------------------------------------------------------------ Stand für die Projektseite */

/** Kennzahlen und neueste Artikel als JSON für die Projektseite; fünf Minuten im Arbeitsspeicher, ohne Protokoll. */
function mm_stand_bedienen(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Robots-Tag: noindex');
    $json = function_exists('apcu_fetch') ? apcu_fetch('mm:stand') : false;
    if ($json === false) {
        $db = mm_db();
        $z = $db->query("SELECT COUNT(*) n, SUM(art='meldung') m, SUM(art='eigen') e, COUNT(DISTINCT quelle) q,
                         MIN(veroeffentlicht) von, MAX(veroeffentlicht) bis FROM mm_artikel")->fetch();
        $ab = $db->query("SELECT zeit FROM mm_abruf WHERE art <> 'einbetten' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $neu = [];
        foreach ($db->query('SELECT * FROM mm_artikel ORDER BY veroeffentlicht DESC LIMIT 6') as $r) {
            $neu[] = ['titel' => $r['titel'], 'url' => $r['url'], 'zeit' => mm_zeit($r['veroeffentlicht']), 'art' => $r['art'],
                      'von' => $r['art'] === 'eigen' ? ($r['autor'] ?: 'Redaktion') : $r['quelle']];
        }
        $json = json_encode(['artikel' => (int)$z['n'], 'meldungen' => (int)$z['m'], 'eigene' => (int)$z['e'],
                             'quellen' => (int)$z['q'], 'von' => mm_zeit($z['von']), 'bis' => mm_zeit($z['bis']),
                             'letzter_abruf' => mm_zeit($ab ?: null), 'neueste' => $neu],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (function_exists('apcu_store')) {
            apcu_store('mm:stand', $json, 300);
        }
    }
    echo $json;
    exit;
}
