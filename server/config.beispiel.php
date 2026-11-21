<?php
/**
 * Vorlage für ~/poc/config.php – außerhalb des Webroots ablegen, nicht ins Repository übernehmen.
 * Rolle 'lesen' braucht nur SELECT (MCP-Schnittstelle), 'admin' legt Tabellen an und schreibt (abruf.php).
 * Dieselbe Datei nutzen alle PoCs auf dem Konto; die Tabellen dieses Projekts beginnen mit mm_.
 */
return [
    'host'  => 'localhost',
    'name'  => 'poc_db',
    'lesen' => ['user' => 'poc_lesen', 'pass' => '…'],
    'admin' => ['user' => 'poc_admin', 'pass' => '…'],

    // Optional: eigener Einbettungsdienst mit OpenAI-artiger Schnittstelle, z. B.
    //   llama-server -m Qwen3-Embedding-0.6B-Q8_0.gguf --embedding --pooling last --port 1240
    // Ohne Eintrag nutzt der Server den Einbettungsweg von plenarprotokolle.digital auf demselben Konto;
    // ist keiner erreichbar, fällt bedeutung_suchen auf die Volltextsuche zurück und abruf.php bettet beim nächsten Lauf ein.
    // 'einbettung_url' => 'http://127.0.0.1:1240/v1',
];
