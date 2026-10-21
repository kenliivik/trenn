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

check(oura_access_token() === null && daily_scores() === [], 'without Oura, heart rate and day scores stay empty');
$days = merge_daily(
    [['day' => '2026-10-21', 'score' => 81, 'temperature_deviation' => 0.6123], ['day' => '2026-10-20', 'score' => 90, 'temperature_deviation' => null], ['score' => 5]],
    [['day' => '2026-10-21', 'score' => 79], ['day' => '2026-10-19', 'score' => 70]]);
check($days === [
    '2026-10-19' => ['sleep' => 70],
    '2026-10-20' => ['readiness' => 90, 'temp' => null],
    '2026-10-21' => ['readiness' => 81, 'temp' => 0.61, 'sleep' => 79],
], 'readiness, temperature and sleep are merged per day, oldest first');
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

// Oura workouts -> outdoor sessions
$el = list_sessions()[0]; // the elliptical session saved at the top, 30 min ago for 20 min
$iso = fn($ms) => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . '+00:00';
$ride = ['id' => '9a8b-7c6d', 'activity' => 'cycling', 'label' => null, 'intensity' => 'hard', 'distance' => 23456.7, 'calories' => 612.4,
         'start_datetime' => $iso($t0 - 5 * 3600000), 'end_datetime' => $iso($t0 - 3 * 3600000)];
$dupe = ['id' => 'dupe-1', 'activity' => 'elliptical', 'start_datetime' => $iso($el['started_at'] + 60000), 'end_datetime' => $iso($el['ended_at'])];
$ski = ['id' => 'ski-1', 'activity' => 'downhill_skiing', 'label' => 'Ski day', 'start_datetime' => $iso($t0 - 30 * 3600000), 'end_datetime' => $iso($t0 - 22 * 3600000)];
store_workouts([$ride, $dupe, $ski, ['id' => 'broken', 'activity' => 'x']]);
$by = array_column(list_sessions(), null, 'id');
check(isset($by['oura-9a8b-7c6d']) && $by['oura-9a8b-7c6d']['type'] === 'outdoor', 'an Oura workout becomes an outdoor session');
check($by['oura-9a8b-7c6d']['items'][0] === ['id' => 'activity', 'name' => 'Cycling', 'source' => 'oura', 'activity' => 'cycling', 'intensity' => 'hard', 'distance' => 23457, 'calories' => 612],
    'activity name, intensity, distance and calories are kept');
check($by['oura-9a8b-7c6d']['ended_at'] - $by['oura-9a8b-7c6d']['started_at'] === 2 * 3600000, 'its exact start and end are kept');
check(!isset($by['oura-dupe-1']), 'a workout overlapping a session logged in trenn is skipped');
check(($by['oura-ski-1']['items'][0]['name'] ?? '') === 'Ski day', 'the label from the Oura app wins over the activity type');
check(!isset($by['oura-broken']), 'a workout without times is skipped');
save_sessions([['id' => 'oura-9a8b-7c6d', 'type' => 'outdoor', 'started_at' => $by['oura-9a8b-7c6d']['started_at'],
    'ended_at' => $by['oura-9a8b-7c6d']['ended_at'], 'rating' => 'easy', 'items' => $by['oura-9a8b-7c6d']['items']]]);
store_workouts([$ride]);
check(array_column(list_sessions(), null, 'id')['oura-9a8b-7c6d']['rating'] === 'easy', 'importing again keeps the rating given in the app');

// Everyday movement stays out; older imports of it are cleaned up; a walk logged by hand stays.
save_sessions([['id' => 'oura-old-walk', 'type' => 'outdoor', 'started_at' => $t0 - 50 * 3600000, 'ended_at' => $t0 - 49 * 3600000,
    'items' => [['id' => 'activity', 'name' => 'Walking', 'source' => 'oura']]],
    ['id' => 'manual-walk-1', 'type' => 'outdoor', 'started_at' => $t0 - 60 * 3600000, 'ended_at' => $t0 - 59 * 3600000,
    'items' => [['id' => 'activity', 'name' => 'Walking', 'source' => 'manual']]]]);
store_workouts([
    ['id' => 'walk-2', 'activity' => 'walking', 'label' => 'Evening stroll', 'start_datetime' => $iso($t0 - 40 * 3600000), 'end_datetime' => $iso($t0 - 39 * 3600000)],
    ['id' => 'yard-1', 'activity' => 'yardwork', 'start_datetime' => $iso($t0 - 45 * 3600000), 'end_datetime' => $iso($t0 - 44 * 3600000)],
]);
$by = array_column(list_sessions(), null, 'id');
check(!isset($by['oura-walk-2']) && !isset($by['oura-yard-1']), 'walking and yardwork from Oura are not imported, even with a label');
check(!isset($by['oura-old-walk']), 'walks imported before the filter are removed');
check(isset($by['manual-walk-1']) && isset($by['oura-9a8b-7c6d']), 'a walk logged by hand and other imports stay');

$many = [];
for ($i = 0; $i < 2000; $i++) $many[] = ['timestamp' => gmdate('Y-m-d\TH:i:s', intdiv($start, 1000) + $i * 19) . '+00:00', 'bpm' => 100 + $i % 50];
$long = hr_summary($many, $start, $start + 2000 * 19000);
check(count($long['samples']) === 400 && $long['max'] === 149 && $long['min'] === 100, 'a long day keeps exact numbers and a 400-point curve');

kv_set('library', ['sets' => [['id' => 'main', 'name' => 'Main', 'exercises' => ['goblet-squat']]]]);
check(kv_get('library')['sets'][0]['exercises'] === ['goblet-squat'], 'the exercise library round-trips');

echo "all API tests passed\n";
