<?php
// Setup check after a deploy: what the server has, never any secrets or data.
require __DIR__ . '/lib.php';
$db = false;
try { db(); $db = true; } catch (Throwable $e) {}
reply([
    'ok' => $db && function_exists('curl_init') && config('key') && config('oura_client_id') ? true : false,
    'php' => PHP_VERSION,
    'sqlite' => extension_loaded('pdo_sqlite'),
    'database' => $db,
    'curl' => function_exists('curl_init'),
    'configured' => (bool) config('key'),
    'oura_app' => (bool) config('oura_client_id'),
]);
