<?php
/**
 * varianten.php – vergleicht Varianten von bedeutung_suchen mit dem Testsatz, direkt auf dem Server.
 *
 *   php varianten.php testsatz.json [--einzeln]
 *
 * Je Frage werden vier Ranglisten gebildet: Artikeltext (Vektoren), erzeugte Fragen (Vektoren, Frage gegen Frage),
 * Suchbegriffe (Vektor) und Volltext (tragende Wörter). Daraus entstehen mit verschiedenen Gewichten zusammengeführte
 * Listen (Reciprocal Rank Fusion, k = 60). Gezählt wird der beste Platz eines als passend markierten Artikels.
 */
declare(strict_types=1);
require getenv('HOME') . '/poc/mm/mcp_server.php';

$satz = json_decode((string)file_get_contents($argv[1] ?? 'testsatz.json'), true);
$einzeln = in_array('--einzeln', $argv, true);
$db = mm_db();

$VARIANTEN = [
    'text'                        => ['text' => 1.0],
    'text + volltext (bisher)'    => ['text' => 1.0, 'voll' => 0.5],
    'fragen'                      => ['fragen' => 1.0],
    'fragen + text 1/0,5'         => ['fragen' => 1.0, 'text' => 0.5],
    'fragen + text 1/0,75'        => ['fragen' => 1.0, 'text' => 0.75],
    'fragen + text 1/1'           => ['fragen' => 1.0, 'text' => 1.0],
    'fragen + text 0,75/1'        => ['fragen' => 0.75, 'text' => 1.0],
    '1/0,75 + volltext 0,1'       => ['fragen' => 1.0, 'text' => 0.75, 'voll' => 0.1],
    '1/0,75 + volltext 0,2'       => ['fragen' => 1.0, 'text' => 0.75, 'voll' => 0.2],
    '1/0,75 + stich 0,2'          => ['fragen' => 1.0, 'text' => 0.75, 'stich' => 0.2],
    '1/0,5 + stich 0,3'           => ['fragen' => 1.0, 'text' => 0.5, 'stich' => 0.3],
    'wie AfD (1 / 0,35 / 0,25)'   => ['fragen' => 1.0, 'text' => 0.35, 'voll' => 0.25],
];

$listen = static function (string $frage) use ($db): array {
    $v = mm_vektor_text(mm_vektor($frage, MM_VEK_ANWEISUNG, 20.0) ?? throw new RuntimeException('keine Einbettung'));
    $l = [];
    $st = $db->prepare('SELECT artikel_id FROM mm_vektor GROUP BY artikel_id ORDER BY MIN(VEC_DISTANCE_COSINE(vektor, VEC_FromText(?))) LIMIT 60');
    $st->execute([$v]);
    $l['text'] = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $db->prepare('SELECT artikel_id FROM mm_frage WHERE nr > 0 AND vektor IS NOT NULL GROUP BY artikel_id
                        ORDER BY MIN(VEC_DISTANCE_COSINE(vektor, VEC_FromText(?))) LIMIT 60');
    $st->execute([$v]);
    $l['fragen'] = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $db->prepare('SELECT artikel_id FROM mm_frage WHERE nr = 0 AND vektor IS NOT NULL ORDER BY VEC_DISTANCE_COSINE(vektor, VEC_FromText(?)) LIMIT 60');
    $st->execute([$v]);
    $l['stich'] = $st->fetchAll(PDO::FETCH_COLUMN);
    $l['voll'] = [];
    if ($w = mm_suchwoerter($frage)) {
        $b = implode(' ', array_map(static fn($x) => $x . '*', $w));
        $st = $db->prepare('SELECT id FROM mm_artikel WHERE MATCH(titel, unterzeile, text) AGAINST(? IN BOOLEAN MODE)
                            ORDER BY MATCH(titel, unterzeile, text) AGAINST(? IN BOOLEAN MODE) DESC LIMIT 60');
        $st->execute([$b, $b]);
        $l['voll'] = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_map(static fn($x) => array_map('intval', $x), $l);
};

$plaetze = [];
foreach ($satz as $t) {
    $l = $listen($t['frage']);
    foreach ($VARIANTEN as $name => $gew) {
        $p = [];
        foreach ($gew as $liste => $g) {
            foreach ($l[$liste] as $i => $id) {
                $p[$id] = ($p[$id] ?? 0) + $g / (60 + $i + 1);
            }
        }
        arsort($p);
        $rang = array_keys($p);
        $platz = null;
        foreach ($rang as $i => $id) {
            if (in_array($id, $t['treffer'], true)) {
                $platz = $i + 1;
                break;
            }
        }
        $plaetze[$name][] = $platz;
        if ($einzeln && $name === array_key_last($VARIANTEN)) {
            // nichts
        }
    }
    if ($einzeln) {
        printf("%-58s %s\n", mb_substr($t['frage'], 0, 58), implode(' ', array_map(static fn($n) => str_pad((string)($plaetze[$n][count($plaetze[$n]) - 1] ?? '–'), 3, ' ', STR_PAD_LEFT), array_keys($VARIANTEN))));
    }
}
printf("\n%-30s %7s %5s %6s %6s\n", 'Variante', 'Platz 1', 'Top 5', 'Top 10', 'MRR');
foreach ($plaetze as $name => $p) {
    $n = count($p);
    printf("%-30s %7d %5d %6d %6.3f\n", $name, count(array_filter($p, static fn($x) => $x === 1)),
        count(array_filter($p, static fn($x) => $x && $x <= 5)), count(array_filter($p, static fn($x) => $x && $x <= 10)),
        array_sum(array_map(static fn($x) => $x ? 1 / $x : 0, $p)) / $n);
}
