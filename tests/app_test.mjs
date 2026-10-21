// Runs in GitHub Actions before every deploy: node tests/app_test.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const S = createRequire(import.meta.url)('../suggest.js');

let n = 0;
const t = (what, fn) => { fn(); n++; console.log('ok:', what); };

const KB_EX = [
  { id: 'incline-pushup', kind: 'reps', reps: '8–10' },
  { id: 'goblet-squat', kind: 'reps', reps: '8–10' },
  { id: 'kb-halos', kind: 'reps', reps: '5 + 5' },
  { id: 'kb-windmill', kind: 'reps', reps: '5' },
  { id: 'kb-deadlift', kind: 'reps', reps: '10–12' }
];
const ELLIP = { id: 'elliptical', kind: 'time', seconds: 1200, step: 300, min: 300, max: 7200 };
const ROPE = { id: 'jump-rope', kind: 'time', seconds: 40, step: 15, min: 15, max: 600 };
const KB = { key: 'kb:main', type: 'kb', set: 'main', exercises: KB_EX };
const EL = { key: 'ellip', type: 'ellip', exercises: [ELLIP] };
const RP = { key: 'rope', type: 'rope', exercises: [ROPE] };

const kb = (id, at, rating, set) => ({ id, type: 'kb', set, started_at: at, rating, items: [] });
const el = (id, at, secs, hrAvg, rating = 'right') => ({ id, type: 'ellip', started_at: at, rating, items: [{ id: 'elliptical', seconds: secs }], hr: hrAvg ? { avg: hrAvg } : null });

t('reps are written one way', () => {
  assert.equal(S.normReps('8-10'), '8–10');
  assert.equal(S.normReps(' 8 – 10 '), '8–10');
  assert.equal(S.normReps('5+5'), '5 + 5');
  assert.equal(S.normReps('AMRAP'), 'AMRAP');
});

t('values off the scale are slotted in, so they can still progress', () => {
  const s = S.scaleFor('9–11');
  assert.equal(s[s.indexOf('9–11') + 1], '10–12');
  assert.equal(S.scaleFor('AMRAP'), null);
});

t('defaults are what the exercise says', () => {
  assert.equal(S.current(KB_EX[1], {}), '8–10');
  assert.equal(S.current(KB_EX[2], {}), '5 + 5');
  assert.equal(S.current(ELLIP, {}), 1200);
});

t('old ladder-position targets are read as reps', () => {
  assert.equal(S.current(KB_EX[1], { 'goblet-squat': 2 }), '10–12');
  assert.equal(S.current(KB_EX[3], { 'kb-windmill': 0 }), '3');
});

t('no history, no suggestion', () => assert.equal(S.suggest(KB, [], {}, 80, {}, 'd'), null));

t('two easy sessions suggest stepping the whole set up', () => {
  const s = S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, {}, 'd');
  assert.equal(s.kind, 'up');
  assert.equal(s.changes.length, 5);
  assert.deepEqual(s.changes.find(c => c.id === 'goblet-squat'), { id: 'goblet-squat', from: '8–10', to: '10–12', value: '10–12' });
  assert.equal(s.changes.find(c => c.id === 'kb-halos').to, '6 + 6');
});

t('easy then right is not enough', () => assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'right')], {}, 80, {}, 'd'), null));

t('unrated sessions in between do not break a run of easy ones', () => {
  assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('x', 2, null), kb('b', 3, 'easy')], {}, 80, {}, 'd').kind, 'up');
});

t('a hard session suggests stepping down', () => {
  const s = S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'hard')], {}, 80, {}, 'd');
  assert.equal(s.kind, 'down');
  assert.equal(s.changes.find(c => c.id === 'kb-deadlift').to, '8–10');
});

t('low readiness wins and is temporary', () => {
  const s = S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 58, {}, 'd');
  assert.equal(s.kind, 'light');
  assert.equal(s.temporary, true);
});

t('unknown readiness does not block progress', () => assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, null, {}, 'd').kind, 'up'));

t('a declined suggestion is not shown again', () => {
  const s = S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, {}, 'd');
  assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, { [s.key]: 1 }, 'd'), null);
});

t('evidence from before the last target change does not count', () => {
  const targets = { changedAt: { 'kb:main': 2 } };
  assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy'), kb('c', 3, 'easy')], targets, 80, {}, 'd'), null);
});

t('at the top of every scale there is nothing to suggest', () => {
  const top = { 'incline-pushup': '25–30', 'goblet-squat': '25–30', 'kb-halos': '30 + 30', 'kb-windmill': '30', 'kb-deadlift': '25–30' };
  assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], top, 80, {}, 'd'), null);
});

t('sets keep their own evidence; old sessions belong to the first set', () => {
  const other = { key: 'kb:b', type: 'kb', set: 'b', exercises: KB_EX };
  const list = [kb('a', 1, 'easy'), kb('b', 2, 'easy')]; // no set field = recorded before sets existed
  assert.equal(S.suggest(KB, list, {}, 80, {}, 'd').kind, 'up');
  assert.equal(S.suggest(other, list, {}, 80, {}, 'd'), null);
  assert.equal(S.suggest(other, [kb('c', 3, 'easy', 'b'), kb('d', 4, 'easy', 'b')], {}, 80, {}, 'd').kind, 'up');
});

t('timed exercises inside a set step by their own amount', () => {
  const plank = { id: 'plank', kind: 'time', seconds: 45 };
  assert.deepEqual(S.step([plank], {}, 1), [{ id: 'plank', from: 45, to: 60, value: 60 }]);
  assert.deepEqual(S.step([plank], { plank: 15 }, -1), []);
});

t('exercises without a rep scale are left alone', () => {
  assert.deepEqual(S.step([{ id: 'x', kind: 'reps', reps: 'AMRAP' }], {}, 1), []);
});

t('elliptical steps by 5 minutes and stays within limits', () => {
  assert.deepEqual(S.step([ELLIP], {}, 1), [{ id: 'elliptical', from: 1200, to: 1500, value: 1500 }]);
  assert.deepEqual(S.step([ELLIP], { elliptical: 300 }, -1), []);
  assert.deepEqual(S.step([ROPE], {}, -1), [{ id: 'jump-rope', from: 40, to: 25, value: 25 }]);
});

t('lower heart rate at the same elliptical time suggests going longer', () => {
  const list = [el('a', 1, 1200, 140), el('b', 2, 1200, 138), el('c', 3, 1200, 132), el('d', 4, 1200, 131)];
  const s = S.suggest(EL, list, {}, 80, {}, 'd');
  assert.equal(s.kind, 'up');
  assert.match(s.text, /8 bpm lower/);
});

t('heart rate at a different time or a hard session does not count', () => {
  assert.equal(S.suggest(EL, [el('a', 1, 1200, 140), el('b', 2, 1200, 138), el('c', 3, 1500, 132), el('d', 4, 1500, 131)], {}, 80, {}, 'd'), null);
  assert.equal(S.suggest(EL, [el('a', 1, 1200, 140), el('b', 2, 1200, 138), el('c', 3, 1200, 132), el('d', 4, 1200, 131, 'hard')], {}, 80, {}, 'd').kind, 'down');
});

t('other workout types do not affect each other', () => {
  assert.equal(S.suggest(RP, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, {}, 'd'), null);
});

const H = 3600000, NOW = 100 * H;
const out = (id, endH, hours, rating, intensity) => ({ id, type: 'outdoor', started_at: endH * H - hours * H, ended_at: endH * H, rating,
  items: [{ id: 'activity', name: 'Cycling', intensity }] });

t('a hard outdoor session yesterday suggests a lighter day, for any workout type', () => {
  const s = S.suggest(KB, [out('o', 90, 1, 'hard')], {}, 80, {}, 'd', NOW);
  assert.equal(s.kind, 'light');
  assert.equal(s.temporary, true);
  assert.match(s.text, /Hard cycling/);
  assert.equal(S.suggest(EL, [out('o', 90, 1, 'hard')], {}, 80, {}, 'd', NOW).kind, 'light');
});

t('Oura calling it hard counts until you rate it; a long one counts too', () => {
  assert.equal(S.suggest(KB, [out('o', 90, 1, null, 'hard')], {}, 80, {}, 'd', NOW).kind, 'light');
  assert.equal(S.suggest(KB, [out('o', 90, 1, 'right', 'hard')], {}, 80, {}, 'd', NOW), null);
  assert.equal(S.suggest(KB, [out('o', 90, 2.5, 'right')], {}, 80, {}, 'd', NOW).kind, 'light');
  assert.equal(S.suggest(KB, [out('o', 90, 3, 'easy')], {}, 80, {}, 'd', NOW), null);
});

t('an outdoor session more than 30 hours ago or an easy one does not', () => {
  assert.equal(S.suggest(KB, [out('o', 60, 1, 'hard')], {}, 80, {}, 'd', NOW), null);
  assert.equal(S.suggest(KB, [out('o', 90, 1, 'easy', 'hard')], {}, 80, {}, 'd', NOW), null);
});

t('declining the outdoor suggestion lets the normal rules speak', () => {
  const list = [out('o', 90, 1, 'hard'), kb('a', 1, 'easy'), kb('b', 2, 'easy')];
  const light = S.suggest(KB, list, {}, 80, {}, 'd', NOW);
  assert.equal(S.suggest(KB, list, {}, 80, { [light.key]: 1 }, 'd', NOW).kind, 'up');
});

t('raised body temperature suggests a lighter day, before anything else', () => {
  const s = S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, { readiness: 85, temp: 0.62 }, {}, 'd');
  assert.equal(s.kind, 'light');
  assert.equal(s.temporary, true);
  assert.match(s.text, /up 0\.6 °C/);
});

t('a normal or lower temperature does not', () => {
  assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, { readiness: 85, temp: 0.3 }, {}, 'd').kind, 'up');
  assert.equal(S.suggest(KB, [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, { readiness: 85, temp: -0.8 }, {}, 'd').kind, 'up');
  assert.equal(S.suggest(KB, [], {}, { readiness: 85, temp: null }, {}, 'd'), null);
});

t('heart rate zones are shares of max heart rate', () => {
  const z = S.hrZones([], 200);
  assert.deepEqual(z.map(x => [x.from, x.to]), [[0, 99], [100, 119], [120, 139], [140, 159], [160, 179], [180, null]]);
});

t('time in zones counts each reading until the next, gaps capped at a minute', () => {
  // 0-20 s at 110 (zone 1), 20-40 at 150 (zone 3), 40-340 at 185 but a 300 s gap counts 60, last reading counts nothing
  const z = S.hrZones([[0, 110], [20, 150], [40, 185], [340, 90]], 200);
  assert.deepEqual(z.map(x => x.seconds), [0, 20, 0, 20, 0, 60]);
  assert.equal(S.hrZones([[0, 120]], 200).reduce((a, x) => a + x.seconds, 0), 0);
});

console.log(`all ${n} app tests passed`);
