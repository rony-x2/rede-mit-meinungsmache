<?php
/**
 * Datenbankzugang für den PoC „Rede mit Meinungsmache“.
 * Zugangsdaten liegen außerhalb des Webroots in ~/poc/config.php (Rolle 'lesen' oder 'admin'),
 * gemeinsam mit den übrigen PoCs; die Tabellen dieses Projekts beginnen mit mm_.
 */
declare(strict_types=1);

/** Anweisung für Suchanfragen und erzeugte Fragen (Frage gegen Frage); Artikeltexte werden ohne Anweisung eingebettet. */
const MM_VEK_ANWEISUNG = "Instruct: Given a question or topic about current German news, retrieve news articles that address it\nQuery: ";

function mm_home(): string
{
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if (!empty($pw['dir'])) {
            return rtrim($pw['dir'], '/');
        }
    }
    return rtrim((string)(getenv('HOME') ?: dirname(__DIR__, 3)), '/');
}

function mm_config(): array
{
    static $c = null;
    return $c ??= require mm_home() . '/poc/config.php';
}

function mm_db(string $rolle = 'lesen'): PDO
{
    static $verb = [];
    if (isset($verb[$rolle])) {
        return $verb[$rolle];
    }
    $c = mm_config();
    $z = $c[$rolle] ?? throw new RuntimeException("Rolle $rolle fehlt in config.php");
    $pdo = new PDO(
        'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
        $z['user'], $z['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    if ($rolle === 'lesen') {
        $pdo->exec('SET SESSION max_statement_time = 15');
    }
    return $verb[$rolle] = $pdo;
}

/**
 * Vektor zu einem Text (Qwen3-Embedding-0.6B, 384 Dim., normiert). Dokumente ohne, Suchanfragen mit Anweisung.
 * Weg 1: einbettung_url in config.php (OpenAI-artige Schnittstelle); Weg 2: Einbettungsweg von plenarprotokolle.digital.
 */
function mm_vektor(string $text, string $anweisung = '', float $warten = 8.0): ?array
{
    $url = (string)(mm_config()['einbettung_url'] ?? '');
    if ($url !== '') {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'timeout' => $warten, 'ignore_errors' => true,
            'content' => json_encode(['input' => $anweisung . $text, 'model' => 'qwen3-embedding-0.6b']),
        ]]);
        $roh = @file_get_contents(rtrim($url, '/') . '/embeddings', false, $ctx);
        $v = $roh === false ? null : (json_decode($roh, true)['data'][0]['embedding'] ?? null);
        if (!is_array($v) || count($v) < 384) {
            return null;
        }
        $v = array_slice(array_map('floatval', $v), 0, 384);
    } else {
        $lib = mm_home() . '/public_html/bundestag/lib/vektor_lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (!function_exists('bt_vek_anfrage')) {
            return null;
        }
        $v = bt_vek_anfrage($text, $warten, $anweisung);
        if (!$v) {
            return null;
        }
    }
    $n = sqrt(array_sum(array_map(static fn($x) => $x * $x, $v)));
    return $n > 0 ? array_map(static fn($x) => $x / $n, $v) : null;
}

/** Vektor als Literal für VEC_FromText(). */
function mm_vektor_text(array $v): string
{
    return '[' . implode(',', array_map(static fn($x) => sprintf('%.7g', $x), $v)) . ']';
}
