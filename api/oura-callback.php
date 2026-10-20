<?php
// Oura sends the browser back here after login. The state must match the one oura-connect.php just made.
require __DIR__ . '/lib.php';

$saved = kv_get('oauth_state');
kv_set('oauth_state', null);
$ok = $saved && hash_equals($saved['state'], (string) ($_GET['state'] ?? '')) && $saved['at'] > time() - 600
    && isset($_GET['code']) && oura_token_request([
        'grant_type' => 'authorization_code',
        'code' => (string) $_GET['code'],
        'redirect_uri' => app_url() . '/api/oura-callback.php',
    ]);

header('Location: ' . app_url() . '/#oura=' . ($ok ? 'ok' : 'failed'), true, 302);
