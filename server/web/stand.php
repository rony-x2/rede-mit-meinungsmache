<?php
// Kennzahlen und neueste Artikel für die Projektseite (JSON, fünf Minuten zwischengespeichert).
declare(strict_types=1);
$home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '';
require ($home ?: (getenv('HOME') ?: dirname(__DIR__, 3))) . '/poc/mm/mcp_server.php';
mm_stand_bedienen();
