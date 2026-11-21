<?php
/**
 * abruf.php – holt neue Artikel von meinungsmache.de und bettet sie für die Suche nach Bedeutung ein.
 * Nur auf der Kommandozeile; stündlich per cron (siehe docs/server.md).
 *
 *   php abruf.php                 Feed prüfen, neue Artikel holen, junge Artikel auf Änderungen prüfen, einbetten
 *   php abruf.php --nachholen     zusätzlich fehlende Artikel aus der Sitemap holen (Archiv), --max=N, --pause=Sekunden
 *   php abruf.php --einbetten     nur fehlende Vektoren berechnen (Artikel und Fragen aus der Anreicherung)
 *   php abruf.php --neu=SLUG      einen Artikel neu holen
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db.php';
require __DIR__ . '/quelle.php';
require __DIR__ . '/anreicherung.php';

const MM_TEIL_WOERTER = 350;     // Wörter je Textteil für die Einbettung

$opt = getopt('', ['nachholen', 'einbetten', 'max::', 'pause::', 'neu::', 'still']);
$max = (int)($opt['max'] ?? 600);
$pause = (float)($opt['pause'] ?? 2.0);
$still = isset($opt['still']);
$t0 = microtime(true);
$db = mm_db('admin');
$db->exec((string)file_get_contents(__DIR__ . '/schema.sql'));

$log = static function (string $s) use ($still): void {
    if (!$still) {
        echo date('H:i:s'), ' ', $s, PHP_EOL;
    }
};

function mm_speichern(PDO $db, array $a): int
{
    $alt = $db->prepare('SELECT id, geaendert FROM mm_artikel WHERE slug = ?');
    $alt->execute([$a['slug']]);
    $z = $alt->fetch();
    $werte = [$a['url'], mb_substr($a['titel'], 0, 500), $a['unterzeile'] ? mb_substr($a['unterzeile'], 0, 600) : null,
              $a['text'], mb_strlen($a['text']), $a['art'], $a['quelle'], $a['original_url'] ? mb_substr($a['original_url'], 0, 800) : null,
              $a['autor'], $a['autor_url'], $a['bildquelle'] ? mb_substr($a['bildquelle'], 0, 400) : null,
              $a['veroeffentlicht'], $a['geaendert'], gmdate('Y-m-d H:i:s')];
    if ($z) {
        $id = (int)$z['id'];
        $db->prepare('UPDATE mm_artikel SET url=?, titel=?, unterzeile=?, text=?, zeichen=?, art=?, quelle=?, original_url=?,
                      autor=?, autor_url=?, bildquelle=?, veroeffentlicht=?, geaendert=?, abgerufen=? WHERE id=?')
           ->execute([...$werte, $id]);
        if ($z['geaendert'] !== $a['geaendert']) {
            $db->prepare('DELETE FROM mm_vektor WHERE artikel_id = ?')->execute([$id]);   // neu einbetten
            mm_anr_verwerfen($db, $id);                                                     // und neu anreichern
        }
    } else {
        $db->prepare('INSERT INTO mm_artikel (url, titel, unterzeile, text, zeichen, art, quelle, original_url, autor, autor_url,
                      bildquelle, veroeffentlicht, geaendert, abgerufen, slug) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([...$werte, $a['slug']]);
        $id = (int)$db->lastInsertId();
    }
    $db->prepare('DELETE FROM mm_link WHERE artikel_id = ?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO mm_link (artikel_id, nr, url, text) VALUES (?,?,?,?)');
    foreach (array_slice($a['links'], 0, 200) as $i => $l) {
        $ins->execute([$id, $i + 1, mb_substr($l['url'], 0, 800), $l['text'] !== '' ? $l['text'] : null]);
    }
    return $z ? 0 : 1;
}

/** Textteile für die Einbettung: Kopf (Titel, Dachzeile) vor jedem Teil, Teile zu 350 Wörtern. */
function mm_teile(array $a): array
{
    $kopf = trim($a['titel'] . ($a['unterzeile'] ? ' – ' . $a['unterzeile'] : ''))
          . ($a['quelle'] ? "\nQuelle: " . $a['quelle'] : '') . ($a['autor'] ? "\nvon " . $a['autor'] : '');
    preg_match_all('/\S+/u', $a['text'], $m, PREG_OFFSET_CAPTURE);
    $w = $m[0];
    if (count($w) <= MM_TEIL_WOERTER + 30) {
        return [[0, 0, strlen($a['text']), $kopf . "\n" . $a['text']]];
    }
    $aus = [];
    for ($i = 0, $n = 0; $i < count($w); $i += MM_TEIL_WOERTER, $n++) {
        $st = $w[$i][1];
        $ende = $w[min($i + MM_TEIL_WOERTER, count($w)) - 1];
        $len = $ende[1] + strlen($ende[0]) - $st;
        $aus[] = [$n, $st, $len, $kopf . "\n" . substr($a['text'], $st, $len)];
    }
    return $aus;
}

function mm_einbetten(PDO $db, int $grenze, callable $log): int
{
    $q = $db->query('SELECT a.* FROM mm_artikel a LEFT JOIN mm_vektor v ON v.artikel_id = a.id
                     WHERE v.artikel_id IS NULL ORDER BY a.veroeffentlicht DESC LIMIT ' . $grenze);
    $ins = $db->prepare('REPLACE INTO mm_vektor (artikel_id, teil, start, laenge, vektor) VALUES (?,?,?,?, VEC_FromText(?))');
    $n = 0;
    foreach ($q->fetchAll() as $a) {
        $zeilen = [];
        foreach (mm_teile($a) as [$teil, $st, $len, $text]) {
            $v = mm_vektor($text, '', 20.0);
            if (!$v) {
                $log('Einbettung nicht erreichbar – später erneut');
                return $n;
            }
            $zeilen[] = [(int)$a['id'], $teil, $st, $len, mm_vektor_text($v)];
        }
        foreach ($zeilen as $z) {
            $ins->execute($z);
        }
        $n++;
    }
    return $n;
}

$neu = 0; $geaendert = 0; $fehler = [];
$holen = static function (string $slug) use ($db, &$neu, &$geaendert, &$fehler, $pause, $log): void {
    try {
        $a = mm_artikel_holen($slug);
        if (mm_speichern($db, $a)) {
            $neu++;
            $log("neu: {$a['titel']}");
        } else {
            $geaendert++;
        }
    } catch (Throwable $e) {
        $fehler[] = $e->getMessage();
        $log('Fehler: ' . $e->getMessage());
    }
    usleep((int)($pause * 1e6));
};

$art = isset($opt['einbetten']) ? 'einbetten' : 'feed';
if (isset($opt['neu'])) {
    $holen((string)$opt['neu']);
} elseif (!isset($opt['einbetten'])) {
    $bekannt = $db->query('SELECT slug, veroeffentlicht, abgerufen FROM mm_artikel')->fetchAll(PDO::FETCH_UNIQUE);
    // 1. Feed: neue Artikel; junge Artikel (< 48 h) alle 6 Stunden erneut, falls sie nachträglich bearbeitet wurden
    try {
        foreach (mm_feed() as $f) {
            $b = $bekannt[$f['slug']] ?? null;
            if (!$b) {
                $holen($f['slug']);
            } elseif (strtotime($b['veroeffentlicht'] . ' UTC') > time() - 48 * 3600 && strtotime($b['abgerufen'] . ' UTC') < time() - 6 * 3600) {
                $holen($f['slug']);
            }
        }
    } catch (Throwable $e) {
        $fehler[] = $e->getMessage();
        $log('Feed: ' . $e->getMessage());
    }
    // 2. Archiv aus der Sitemap (nur mit --nachholen)
    if (isset($opt['nachholen'])) {
        $art = 'nachholen';
        $bekannt = $db->query('SELECT slug, 1 FROM mm_artikel')->fetchAll(PDO::FETCH_KEY_PAIR);
        $offen = array_diff_key(mm_sitemap(), $bekannt);
        arsort($offen);
        $log(count($offen) . ' Artikel aus der Sitemap fehlen, hole bis zu ' . $max);
        foreach (array_slice(array_keys($offen), 0, $max) as $slug) {
            $holen($slug);
        }
    }
}
$eingebettet = mm_einbetten($db, 1000, $log);
$fragen = mm_anr_einbetten($db, 3000, $log);     // Fragen und Suchbegriffe aus der Anreicherung (arbeiter/mm_anreichern.py)
$log("fertig: $neu neu, $geaendert erneut geprüft, $eingebettet eingebettet, $fragen Fragen eingebettet, " . count($fehler) . ' Fehler');
$db->prepare('INSERT INTO mm_abruf (zeit, art, neu, geaendert, eingebettet, fehler, dauer) VALUES (UTC_TIMESTAMP(),?,?,?,?,?,?)')
   ->execute([$art, $neu, $geaendert, $eingebettet, $fehler ? implode("\n", array_slice($fehler, 0, 20)) : null,
              round(microtime(true) - $t0, 1)]);
$db->exec('DELETE FROM mm_abruf WHERE zeit < UTC_TIMESTAMP() - INTERVAL 90 DAY');
