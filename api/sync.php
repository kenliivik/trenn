<?php
// The app's only data endpoint: it sends new or changed sessions, its targets and its exercise library,
// and gets back the full history, heart rate filled in from Oura, and today's readiness.
require __DIR__ . '/lib.php';
require_key();
$in = input();

save_sessions($in['sessions'] ?? []);
foreach (['targets' => 20000, 'declined' => 20000, 'library' => 300000] as $k => $max) {
    if (isset($in[$k]) && is_array($in[$k]) && strlen(json_encode($in[$k])) < $max) kv_set($k, $in[$k]);
}
import_workouts();
fill_hr();

$oura = kv_get('oura');
$days = daily_scores();
reply([
    'sessions' => list_sessions(),
    'targets' => kv_get('targets') ?? (object) [],
    'declined' => kv_get('declined') ?? (object) [],
    'library' => kv_get('library'),
    'readiness' => $days[date('Y-m-d')]['readiness'] ?? null,
    'days' => (object) $days,
    'oura' => $oura !== null,
    'oura_workouts' => $oura !== null && str_contains($oura['scope'] ?? '', 'workout'),
]);
