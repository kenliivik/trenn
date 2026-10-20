<?php
// Runs in GitHub Actions before every deploy: php tests/api_test.php
putenv('TRENN_DATA=' . sys_get_temp_dir() . '/trenn-test-' . getmypid());
putenv('TRENN_UPLOADS=' . sys_get_temp_dir() . '/trenn-test-uploads-' . getmypid());
require __DIR__ . '/../api/lib.php';

function check($cond, $what) {
    if (!$cond) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "ok: $what\n";
}

$t0 = now_ms() - 30 * 60000;
$valid = ['id' => 'abc12345-test', 'type' => 'ellip', 'started_at' => $t0, 'ended_at' => $t0 + 20 * 60000,
          'rating' => 'easy', 'items' => [['id' => 'elliptical', 'seconds' => 1200]]];

check(save_sessions([$valid]) === 1, 'a valid session is saved');
check(save_sessions([['id' => 'x'] + $valid]) === 0, 'a short id is rejected');
check(save_sessions([['type' => 'run'] + $valid]) === 0, 'an unknown type is rejected');
check(save_sessions([['ended_at' => $t0 - 1] + $valid]) === 0, 'an end before the start is rejected');
check(save_sessions(['junk', 42, null, $valid]) === 1 && list_sessions()[0]['rating'] === 'easy', 'non-session entries are skipped');

save_sessions([['rating' => 'meh'] + $valid]);
check(list_sessions()[0]['rating'] === null, 'an unknown rating is stored as none');

save_sessions([['rating' => 'hard'] + $valid]);
$all = list_sessions();
check(count($all) === 1 && $all[0]['rating'] === 'hard', 'saving again updates instead of duplicating');
check($all[0]['items'][0]['seconds'] === 1200 && $all[0]['hr'] === null, 'items come back decoded, heart rate starts empty');

$start = strtotime('2026-10-20T10:00:00Z') * 1000;
$hr = hr_summary([
    ['timestamp' => '2026-10-20T09:59:00+00:00', 'bpm' => 70],   // before the session
    ['timestamp' => '2026-10-20T10:00:40+00:00', 'bpm' => 120],
    ['timestamp' => '2026-10-20T10:00:20+00:00', 'bpm' => 100],
    ['timestamp' => '2026-10-20T13:00:20+03:00', 'bpm' => 140], // same instant written in Estonian time
    ['timestamp' => '2026-10-20T10:30:00+00:00', 'bpm' => 90],   // after the session
], $start, $start + 20 * 60000);
check(array_column($hr['samples'], 0) === [20, 20, 40], 'samples are kept inside the session, sorted, as seconds since start');
check($hr['avg'] === 120 && $hr['max'] === 140 && $hr['min'] === 100, 'average, max and min');
check(hr_summary([], $start, $start + 1000) === ['samples' => []], 'no samples gives an empty summary');

kv_set('targets', ['goblet-squat' => 1]);
check(kv_get('targets') === ['goblet-squat' => 1], 'targets round-trip');
kv_set('targets', null);
check(kv_get('targets') === null, 'kv delete');

check(oura_access_token() === null && readiness_today() === null, 'without Oura, heart rate and readiness stay empty');
fill_hr();
check(list_sessions()[0]['hr'] === null, 'fill_hr leaves sessions alone when Oura is not connected');
check(is_file(DATA_DIR . '/.htaccess'), 'the data folder locks itself');

save_sessions([['id' => 'set-b-session', 'set' => 'b'] + $valid, ['id' => 'bad-set-session', 'set' => '../x'] + $valid]);
$by = array_column(list_sessions(), null, 'id');
check(($by['set-b-session']['set'] ?? null) === 'b', 'a session remembers its set');
check(!isset($by['bad-set-session']['set']) && !isset($by['abc12345-test']['set']), 'odd or missing set names are left empty');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAASSURBVDhPY2AYBaNgFIwCCAAABBAAAUy7RlUAAAAASUVORK5CYII=');
$path = store_photo($png);
check(is_string($path) && preg_match('#^uploads/[0-9a-f]{32}\.png$#', $path) === 1, 'a real image is stored under a random name');
check(file_get_contents(uploads_dir() . '/' . basename($path)) === $png, 'the stored file is the uploaded image');
check(store_photo('<?php echo "hi"; ?>' . str_repeat(' ', 200)) === null, 'a script posing as an image is refused');
check(store_photo(str_repeat("\x89PNG", 40)) === null, 'a fake image header is refused');
check(str_contains(file_get_contents(uploads_dir() . '/.htaccess'), 'Require all denied'), 'the photo folder only serves images');

kv_set('library', ['sets' => [['id' => 'main', 'name' => 'Main', 'exercises' => ['goblet-squat']]]]);
check(kv_get('library')['sets'][0]['exercises'] === ['goblet-squat'], 'the exercise library round-trips');

echo "all API tests passed\n";
