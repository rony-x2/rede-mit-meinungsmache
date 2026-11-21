<?php
/**
 * anreicherung.php – Suchhilfen je Artikel von einem lokalen Sprachmodell (wie beim Schwesterprojekt rede-mit-dem-afd-gutachten).
 *
 * Der Webserver erreicht die Grafikkarte zu Hause nicht. Deshalb holt sich ein kleines Programm auf dem Rechner mit dem
 * Modell (arbeiter/mm_anreichern.py) über web/anreicherung.php die Artikel ohne Anreicherung samt Anweisung und
 * Antwortschema, lässt sie beantworten und gibt die Ergebnisse zurück. Eingebettet werden die Fragen danach von abruf.php.
 *
 * Je Artikel: 3 bis 5 Fragen in Alltagssprache, 6 bis 15 Suchbegriffe (Synonyme, Oberbegriffe, Namen) und 1 bis 3 Themen
 * aus einer festen Liste. Die Zusätze dienen nur der Suche und der Gliederung von Briefings.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const MM_ANR_MODELL = 'google/gemma-4-26b-a4b';
const MM_THEMEN = [
    'Innenpolitik', 'Parteien und Wahlen', 'Rechtsextremismus', 'Wirtschaft und Arbeit', 'Soziales und Rente',
    'Energie und Preise', 'Klima und Umwelt', 'Verkehr und Mobilität', 'Gesundheit', 'Bildung', 'Digitales und KI',
    'Sicherheit und Bundeswehr', 'Justiz und Kriminalität', 'Medien und Pressefreiheit', 'Migration',
    'Ukraine und Russland', 'Nahost', 'USA', 'Europa', 'Weitere Weltpolitik', 'Gesellschaft und Kultur', 'Sport',
];

function mm_anr_system(): string
{
    $themen = implode(', ', MM_THEMEN);
    return <<<TXT
Du hilfst, einen Suchindex für eine deutsche Nachrichtenseite zu verbessern. Du bekommst einen Artikel mit Titel, Dachzeile, Quelle oder Autor und Text. Schreibe:
1. "fragen": 3 bis 5 kurze Fragen, wie Leserinnen und Leser sie in einem Chat stellen würden und die genau dieser Artikel beantwortet. Alltagssprache, neutral formuliert, ohne eigene Wertung; nicht nur die Wörter des Titels wiederholen. Nur Fragen, auf die der Artikel wirklich eine Antwort gibt.
2. "stichwoerter": 6 bis 15 Suchbegriffe, die im Text nicht unbedingt vorkommen: Synonyme, Alltagswörter, Oberbegriffe (z. B. Tanken für Spritpreise, Zwischenwahlen für Midterms), Abkürzungen und ihre ausgeschriebene Form sowie die genannten Personen, Organisationen, Orte und Länder in üblicher Schreibweise.
3. "themen": 1 bis 3 Themen, nur aus dieser Liste: {$themen}.
Erfinde keine Inhalte, die nicht im Artikel stehen. Antworte nur mit JSON.
TXT;
}

function mm_anr_schema(): array
{
    return ['type' => 'object', 'properties' => [
        'fragen' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 6],
        'stichwoerter' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 3, 'maxItems' => 20],
        'themen' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MM_THEMEN], 'minItems' => 1, 'maxItems' => 3],
    ], 'required' => ['fragen', 'stichwoerter', 'themen']];
}

/** Text für das Modell: Kopf und Text, lange Artikel gekürzt (die Fragen sollen den Kern treffen). */
function mm_anr_prompt(array $a): string
{
    $text = $a['text'];
    if (mb_strlen($text) > 7000) {
        $text = mb_substr($text, 0, 7000) . ' …';
    }
    return 'Titel: ' . $a['titel'] . "\n"
        . ($a['unterzeile'] ? 'Dachzeile: ' . $a['unterzeile'] . "\n" : '')
        . ($a['art'] === 'eigen' ? 'Eigener Artikel der Redaktion, von ' . ($a['autor'] ?: 'der Redaktion') : 'Meldung nach einem Bericht von ' . ($a['quelle'] ?: 'unbekannt'))
        . "\n\n" . $text;
}

/** Artikel ohne Anreicherung, neueste zuerst. */
function mm_anr_offen(PDO $db, int $n): array
{
    $n = max(1, min(100, $n));
    $q = $db->query("SELECT a.* FROM mm_artikel a LEFT JOIN mm_anreicherung r ON r.artikel_id = a.id
                     WHERE r.artikel_id IS NULL ORDER BY a.veroeffentlicht DESC LIMIT $n");
    return array_map(static fn($a) => ['id' => (int)$a['id'], 'fassung' => md5($a['titel'] . "\n" . $a['unterzeile'] . "\n" . $a['text']),
                                       'prompt' => mm_anr_prompt($a)], $q->fetchAll());
}

/** Ergebnisse übernehmen; nur für Artikel, deren Text sich seit der Abholung nicht geändert hat. @return int übernommen */
function mm_anr_speichern(PDO $db, array $ergebnisse, string $modell): int
{
    $art = $db->prepare('SELECT titel, unterzeile, text FROM mm_artikel WHERE id = ?');
    $ins = $db->prepare('REPLACE INTO mm_anreicherung (artikel_id, modell, zeit, fragen, stichwoerter, themen) VALUES (?,?,UTC_TIMESTAMP(),?,?,?)');
    $n = 0;
    foreach (array_slice($ergebnisse, 0, 100) as $e) {
        $id = (int)($e['id'] ?? 0);
        $art->execute([$id]);
        $a = $art->fetch();
        if (!$a || md5($a['titel'] . "\n" . $a['unterzeile'] . "\n" . $a['text']) !== ($e['fassung'] ?? '')) {
            continue;
        }
        $sauber = static fn($l, int $max, int $len) => array_values(array_slice(array_unique(array_filter(array_map(
            static fn($s) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s)), 0, $len), is_array($l) ? $l : []),
            static fn($s) => mb_strlen($s) >= 2)), 0, $max));
        $fragen = $sauber($e['fragen'] ?? [], 6, 300);
        $stich = $sauber($e['stichwoerter'] ?? [], 20, 80);
        $themen = array_values(array_intersect(MM_THEMEN, $sauber($e['themen'] ?? [], 3, 60)));
        if (!$fragen) {
            continue;
        }
        $db->beginTransaction();
        $ins->execute([$id, mb_substr($modell, 0, 120), json_encode($fragen, JSON_UNESCAPED_UNICODE),
                       json_encode($stich, JSON_UNESCAPED_UNICODE), json_encode($themen, JSON_UNESCAPED_UNICODE)]);
        $db->prepare('DELETE FROM mm_thema WHERE artikel_id = ?')->execute([$id]);
        $t = $db->prepare('INSERT INTO mm_thema (artikel_id, thema) VALUES (?,?)');
        foreach ($themen as $th) {
            $t->execute([$id, $th]);
        }
        $db->prepare('DELETE FROM mm_frage WHERE artikel_id = ?')->execute([$id]);
        $f = $db->prepare('INSERT INTO mm_frage (artikel_id, nr, text) VALUES (?,?,?)');
        if ($stich) {
            $f->execute([$id, 0, mb_substr(implode(', ', $stich), 0, 600)]);
        }
        foreach ($fragen as $i => $fr) {
            $f->execute([$id, $i + 1, $fr]);
        }
        $db->commit();
        $n++;
    }
    return $n;
}

/** Anreicherung eines geänderten Artikels verwerfen (abruf.php). */
function mm_anr_verwerfen(PDO $db, int $id): void
{
    foreach (['mm_anreicherung', 'mm_thema', 'mm_frage'] as $t) {
        $db->prepare("DELETE FROM $t WHERE artikel_id = ?")->execute([$id]);
    }
}

/** Fehlende Vektoren der erzeugten Fragen und Suchbegriffe (mit Suchanweisung, Frage gegen Frage). */
function mm_anr_einbetten(PDO $db, int $grenze, callable $log): int
{
    $q = $db->query("SELECT artikel_id, nr, text FROM mm_frage WHERE vektor IS NULL ORDER BY artikel_id DESC, nr LIMIT $grenze");
    $up = $db->prepare('UPDATE mm_frage SET vektor = VEC_FromText(?) WHERE artikel_id = ? AND nr = ?');
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        $v = mm_vektor($r['text'], MM_VEK_ANWEISUNG, 20.0);
        if (!$v) {
            $log('Einbettung nicht erreichbar – Fragen später');
            break;
        }
        $up->execute([mm_vektor_text($v), $r['artikel_id'], $r['nr']]);
        $n++;
    }
    return $n;
}

/** Zugang für den Arbeiter: Kennwort nur als SHA-256 in ~/poc/mm/anreicherung.hash. */
function mm_anr_zugang(string $token): bool
{
    $datei = mm_home() . '/poc/mm/anreicherung.hash';
    $soll = is_file($datei) ? trim((string)file_get_contents($datei)) : '';
    return $soll !== '' && strlen($token) >= 32 && hash_equals($soll, hash('sha256', $token));
}

/** HTTP-Schnittstelle für den Arbeiter (web/anreicherung.php). */
function mm_anr_bedienen(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $antwort = static function (int $code, array $d): never {
        http_response_code($code);
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };
    if (!mm_anr_zugang((string)($_SERVER['HTTP_X_TOKEN'] ?? ''))) {
        usleep(300000);
        $antwort(403, ['fehler' => 'Kein Zugang']);
    }
    $db = mm_db('admin');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $d = json_decode((string)file_get_contents('php://input', false, null, 0, 2_000_000), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $antwort(400, ['fehler' => 'Kein gültiges JSON']);
        }
        $n = mm_anr_speichern($db, (array)($d['ergebnisse'] ?? []), (string)($d['modell'] ?? MM_ANR_MODELL));
        $antwort(200, ['uebernommen' => $n]);
    }
    $antwort(200, ['modell' => MM_ANR_MODELL, 'system' => mm_anr_system(), 'json_schema' => mm_anr_schema(),
                   'parameter' => ['temperatur' => 0.2, 'max_tokens' => 1200],
                   'artikel' => mm_anr_offen($db, (int)($_GET['n'] ?? 20))]);
}
