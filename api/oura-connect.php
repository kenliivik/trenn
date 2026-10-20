<?php
// Starts the one-time Oura login. The app asks for the address (with its key) and then opens it.
require __DIR__ . '/lib.php';
require_key();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply_error('post only', 405);

$state = bin2hex(random_bytes(16));
kv_set('oauth_state', ['state' => $state, 'at' => time()]);
reply(['url' => OURA_AUTHORIZE . '?' . http_build_query([
    'client_id' => config('oura_client_id'),
    'redirect_uri' => app_url() . '/api/oura-callback.php',
    'response_type' => 'code',
    'scope' => 'daily heartrate',
    'state' => $state,
])]);
