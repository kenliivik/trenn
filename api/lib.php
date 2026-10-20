<?php
// Shared helpers for the trenn API: config, database, auth and Oura. Not served (see .htaccess).
date_default_timezone_set('Europe/Tallinn');

define('DATA_DIR', getenv('TRENN_DATA') ?: __DIR__ . '/data');
const OURA_AUTHORIZE = 'https://cloud.ouraring.com/oauth/authorize';
const OURA_TOKEN = 'https://api.ouraring.com/oauth/token';
const OURA_API = 'https://api.ouraring.com/v2/usercollection/';
const TYPES = ['kb', 'rope', 'ellip'];
const RATINGS = ['easy', 'right', 'hard'];

// api/data/config.json is written by the deploy from the GitHub "production" environment secrets.
function config($key) {
    static $c;
    if ($c === null) {
        $f = DATA_DIR . '/config.json';
        $c = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }
    return $c[$key] ?? null;
}

function db() {
    static $pdo;
    if (!$pdo) {
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
        // Second lock on the database and config, in case the rule in api/.htaccess is ever lost. The deploy never touches data/.
        if (!is_file(DATA_DIR . '/.htaccess')) file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
        $pdo = new PDO('sqlite:' . DATA_DIR . '/trenn.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL,
            started_at INTEGER NOT NULL,
            ended_at INTEGER NOT NULL,
            rating TEXT,
            items TEXT NOT NULL,
            hr TEXT,
            hr_checked_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        // Added with exercise sets: which kettlebell set a session trained (older rows stay empty = the first set).
        $cols = array_column($pdo->query('PRAGMA table_info(sessions)')->fetchAll(), 'name');
        if (!in_array('set_id', $cols, true)) $pdo->exec('ALTER TABLE sessions ADD COLUMN set_id TEXT');
    }
    return $pdo;
}

function q($sql, $args = []) {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function kv_get($k) {
    $v = q('SELECT v FROM kv WHERE k = ?', [$k])->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}

function kv_set($k, $v) {
    if ($v === null) q('DELETE FROM kv WHERE k = ?', [$k]);
    else q('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, json_encode($v)]);
}

function reply($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function reply_error($error, $code = 400) {
    reply(['error' => $error], $code);
}

// The tablet sends the key it was paired with. One person, one key: no accounts.
function require_key() {
    $want = config('key');
    if (!$want) reply_error('not set up', 503);
    if (!hash_equals($want, $_SERVER['HTTP_X_TRENN_KEY'] ?? '')) reply_error('unauthorized', 401);
}

// JSON-only POST bodies, so a plain form on another site can't post here.
function input() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return [];
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) reply_error('json only', 415);
    $in = json_decode(file_get_contents('php://input', false, null, 0, 2_000_000), true);
    return is_array($in) ? $in : [];
}

function now_ms() {
    return (int) floor(microtime(true) * 1000);
}

function app_url() {
    return rtrim(config('app_url') ?? 'https://' . ($_SERVER['SERVER_NAME'] ?? 'trenn.dsgnr.ee'), '/');
}

// ---------- sessions ----------

// Validates and stores sessions sent by the app. The app owns everything except heart rate, which only the server fills.
function save_sessions($list) {
    $saved = 0;
    foreach (array_slice(is_array($list) ? $list : [], 0, 500) as $s) {
        if (!is_array($s)) continue;
        $id = $s['id'] ?? '';
        $items = json_encode($s['items'] ?? []);
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) continue;
        if (!in_array($s['type'] ?? '', TYPES, true)) continue;
        if (!is_int($s['started_at'] ?? null) || !is_int($s['ended_at'] ?? null) || $s['ended_at'] < $s['started_at']) continue;
        if ($s['ended_at'] - $s['started_at'] > 6 * 3600 * 1000 || strlen($items) > 20000) continue;
        $rating = in_array($s['rating'] ?? null, RATINGS, true) ? $s['rating'] : null;
        $set = is_string($s['set'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,40}$/', $s['set']) ? $s['set'] : null;
        q('INSERT INTO sessions (id, type, set_id, started_at, ended_at, rating, items, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
           ON CONFLICT(id) DO UPDATE SET rating = excluded.rating, items = excluded.items, ended_at = excluded.ended_at, updated_at = excluded.updated_at',
            [$id, $s['type'], $set, $s['started_at'], $s['ended_at'], $rating, $items, now_ms()]);
        $saved++;
    }
    return $saved;
}

function list_sessions() {
    $rows = q('SELECT id, type, set_id AS "set", started_at, ended_at, rating, items, hr FROM sessions WHERE started_at > ? ORDER BY started_at',
        [now_ms() - 400 * 86400 * 1000])->fetchAll();
    foreach ($rows as &$r) {
        if ($r['set'] === null) unset($r['set']);
        $r['started_at'] = (int) $r['started_at'];
        $r['ended_at'] = (int) $r['ended_at'];
        $r['items'] = json_decode($r['items'], true);
        $r['hr'] = $r['hr'] === null ? null : json_decode($r['hr'], true);
    }
    return $rows;
}

// Oura heart rate rows -> seconds since the session start, with average, max and min. Rows outside the session are dropped.
function hr_summary($rows, $start_ms, $end_ms) {
    $samples = [];
    foreach ($rows as $r) {
        $t = strtotime($r['timestamp'] ?? '') * 1000;
        if ($t >= $start_ms && $t <= $end_ms && is_numeric($r['bpm'] ?? null)) $samples[] = [intdiv($t - $start_ms, 1000), (int) $r['bpm']];
    }
    usort($samples, fn($a, $b) => $a[0] <=> $b[0]);
    if (!$samples) return ['samples' => []];
    $bpm = array_column($samples, 1);
    return ['samples' => $samples, 'avg' => (int) round(array_sum($bpm) / count($bpm)), 'max' => max($bpm), 'min' => min($bpm)];
}

// Fills heart rate for recently finished sessions. The ring only reaches Oura when the phone syncs, so empty answers are retried
// every 15 minutes; after two days the session is marked as having none.
function fill_hr($limit = 3) {
    $now = now_ms();
    $rows = q('SELECT id, started_at, ended_at FROM sessions WHERE hr IS NULL AND ended_at < ? AND hr_checked_at < ? ORDER BY ended_at DESC LIMIT ' . (int) $limit,
        [$now - 5 * 60000, $now - 15 * 60000])->fetchAll();
    foreach ($rows as $s) {
        $data = oura_get_all('heartrate', [
            'start_datetime' => gmdate('Y-m-d\TH:i:s', intdiv($s['started_at'], 1000)) . '+00:00',
            'end_datetime' => gmdate('Y-m-d\TH:i:s', intdiv($s['ended_at'], 1000) + 60) . '+00:00',
        ]);
        if ($data === null) return; // Oura not connected or not answering: try again on a later sync
        $hr = hr_summary($data, (int) $s['started_at'], (int) $s['ended_at']);
        $give_up = $s['ended_at'] < $now - 48 * 3600 * 1000;
        q('UPDATE sessions SET hr = ?, hr_checked_at = ? WHERE id = ?',
            [$hr['samples'] || $give_up ? json_encode($hr) : null, $now, $s['id']]);
    }
}

// Today's readiness score, asked from Oura at most every 30 minutes until it exists.
function readiness_today() {
    $today = date('Y-m-d');
    $c = kv_get('readiness');
    if ($c && $c['day'] === $today && ($c['score'] !== null || $c['checked'] > time() - 1800)) return $c['score'];
    $rows = oura_get_all('daily_readiness', ['start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+1 day'))]);
    if ($rows === null) return $c && $c['day'] === $today ? $c['score'] : null;
    $score = null;
    foreach ($rows as $r) if (($r['day'] ?? '') === $today) $score = $r['score'] ?? null;
    kv_set('readiness', ['day' => $today, 'score' => $score, 'checked' => time()]);
    return $score;
}

// ---------- photos ----------

// Folder for exercise photos, served as plain images. Uploads keep their own .htaccess so nothing but images is ever served.
function uploads_dir() {
    $dir = getenv('TRENN_UPLOADS') ?: dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess',
        "# Only images are served from here; the server names every file, so anything else was not put here by trenn.\n" .
        "<FilesMatch \"^(?![0-9a-f]{32}\\.(jpg|png|webp)$)\">\n  Require all denied\n</FilesMatch>\n");
    return $dir;
}

// Stores an uploaded photo under a random name after checking it really is an image. Returns its path for the app, or null.
// shortcut: photos of removed exercises stay on the server, fine at a few hundred KB each; clean up if the folder ever grows.
function store_photo($bytes) {
    if (!is_string($bytes) || strlen($bytes) < 100 || strlen($bytes) > 3_000_000) return null;
    $info = @getimagesizefromstring($bytes);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext || $info[0] < 16 || $info[1] < 16 || $info[0] > 6000 || $info[1] > 6000) return null;
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    file_put_contents(uploads_dir() . '/' . $name, $bytes);
    return 'uploads/' . $name;
}

// ---------- Oura ----------

function http_json($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($out) ? json_decode($out, true) : null];
}

// Exchanges a login code or a refresh token. Oura refresh tokens work once, so the new pair is saved straight away.
function oura_token_request($params) {
    [$code, $res] = http_json('POST', OURA_TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($params + [
        'client_id' => config('oura_client_id'),
        'client_secret' => config('oura_client_secret'),
    ]));
    if (!isset($res['access_token'], $res['refresh_token'])) {
        // A rejected refresh token will never work again: forget it so the app offers to reconnect. Network errors keep it.
        if (($params['grant_type'] ?? '') === 'refresh_token' && in_array($code, [400, 401], true)) kv_set('oura', null);
        return null;
    }
    $t = ['access_token' => $res['access_token'], 'refresh_token' => $res['refresh_token'], 'expires_at' => time() + (int) ($res['expires_in'] ?? 3600)];
    kv_set('oura', $t);
    return $t;
}

function oura_access_token() {
    $t = kv_get('oura');
    if (!$t) return null;
    if ($t['expires_at'] < time() + 60) $t = oura_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
    return $t['access_token'] ?? null;
}

// All rows of an Oura collection for the given range, following next_token pages. Null when Oura can't be asked.
function oura_get_all($path, $params) {
    $token = oura_access_token();
    if (!$token) return null;
    $rows = [];
    for ($page = 0; $page < 20; $page++) {
        [$code, $res] = http_json('GET', OURA_API . $path . '?' . http_build_query($params), ['Authorization: Bearer ' . $token]);
        if ($code !== 200 || !isset($res['data'])) return null;
        $rows = array_merge($rows, $res['data']);
        if (empty($res['next_token'])) break;
        $params['next_token'] = $res['next_token'];
    }
    return $rows;
}
