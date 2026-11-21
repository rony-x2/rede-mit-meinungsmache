<?php
// Schnittstelle für den Anreicherungs-Arbeiter (arbeiter/mm_anreichern.py): Artikel holen, Ergebnisse abgeben. Nur mit Kennwort.
declare(strict_types=1);
$home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '';
require ($home ?: (getenv('HOME') ?: dirname(__DIR__, 3))) . '/poc/mm/anreicherung.php';
mm_anr_bedienen();
