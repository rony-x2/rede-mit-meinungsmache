<?php
// Einstieg der MCP-Schnittstelle „Rede mit Meinungsmache“; die Bibliothek liegt außerhalb des Webroots in ~/poc/mm/.
declare(strict_types=1);
$home = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '';
require ($home ?: (getenv('HOME') ?: dirname(__DIR__, 3))) . '/poc/mm/mcp_server.php';
mm_mcp_bedienen();
