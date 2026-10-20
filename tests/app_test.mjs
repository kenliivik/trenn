// Runs in GitHub Actions before every deploy: node tests/app_test.mjs
import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const S = createRequire(import.meta.url)('../suggest.js');

let n = 0;
const t = (what, fn) => { fn(); n++; console.log('ok:', what); };
const kb = (id, at, rating) => ({ id, type: 'kb', started_at: at, rating, items: [] });
const el = (id, at, secs, hrAvg, rating = 'right') => ({ id, type: 'ellip', started_at: at, rating, items: [{ id: 'elliptical', seconds: secs }], hr: hrAvg ? { avg: hrAvg } : null });

t('defaults are the original targets', () => {
  assert.equal(S.show({}, 'goblet-squat'), '8–10');
  assert.equal(S.show({}, 'kb-halos'), '5 + 5');
  assert.equal(S.show({}, 'elliptical'), 1200);
  assert.equal(S.show({}, 'jump-rope'), 40);
});

t('no history, no suggestion', () => assert.equal(S.suggest('kb', [], {}, 80, {}, 'd'), null));

t('two easy sessions suggest stepping every kettlebell exercise up', () => {
  const s = S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, {}, 'd');
  assert.equal(s.kind, 'up');
  assert.equal(s.changes.length, 5);
  assert.deepEqual(s.changes.find(c => c.id === 'goblet-squat'), { id: 'goblet-squat', from: '8–10', to: '10–12', value: 2 });
});

t('easy then right is not enough', () => assert.equal(S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'right')], {}, 80, {}, 'd'), null));

t('unrated sessions in between do not break a run of easy ones', () => {
  assert.equal(S.suggest('kb', [kb('a', 1, 'easy'), kb('x', 2, null), kb('b', 3, 'easy')], {}, 80, {}, 'd').kind, 'up');
});

t('a hard session suggests stepping down', () => {
  const s = S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'hard')], {}, 80, {}, 'd');
  assert.equal(s.kind, 'down');
  assert.equal(s.changes.find(c => c.id === 'kb-deadlift').to, '8–10');
});

t('low readiness wins and is temporary', () => {
  const s = S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 58, {}, 'd');
  assert.equal(s.kind, 'light');
  assert.equal(s.temporary, true);
});

t('unknown readiness does not block progress', () => assert.equal(S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, null, {}, 'd').kind, 'up'));

t('a declined suggestion is not shown again', () => {
  const s = S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, {}, 'd');
  assert.equal(S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, { [s.key]: 1 }, 'd'), null);
});

t('evidence from before the last target change does not count', () => {
  const targets = { changedAt: { kb: 2 } };
  assert.equal(S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy'), kb('c', 3, 'easy')], targets, 80, {}, 'd'), null);
});

t('at the top of every ladder there is nothing to suggest', () => {
  const top = Object.fromEntries(Object.entries(S.LADDERS).map(([id, l]) => [id, l.length - 1]));
  assert.equal(S.suggest('kb', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], top, 80, {}, 'd'), null);
});

t('elliptical steps by 5 minutes and stays within limits', () => {
  assert.deepEqual(S.step('ellip', {}, 1), [{ id: 'elliptical', from: 1200, to: 1500, value: 1500 }]);
  assert.deepEqual(S.step('ellip', { elliptical: 300 }, -1), []);
  assert.deepEqual(S.step('rope', {}, -1), [{ id: 'jump-rope', from: 40, to: 25, value: 25 }]);
});

t('lower heart rate at the same elliptical time suggests going longer', () => {
  const list = [el('a', 1, 1200, 140), el('b', 2, 1200, 138), el('c', 3, 1200, 132), el('d', 4, 1200, 131)];
  const s = S.suggest('ellip', list, {}, 80, {}, 'd');
  assert.equal(s.kind, 'up');
  assert.match(s.text, /8 bpm lower/);
});

t('heart rate at a different time or a hard session does not count', () => {
  assert.equal(S.suggest('ellip', [el('a', 1, 1200, 140), el('b', 2, 1200, 138), el('c', 3, 1500, 132), el('d', 4, 1500, 131)], {}, 80, {}, 'd'), null);
  assert.equal(S.suggest('ellip', [el('a', 1, 1200, 140), el('b', 2, 1200, 138), el('c', 3, 1200, 132), el('d', 4, 1200, 131, 'hard')], {}, 80, {}, 'd').kind, 'down');
});

t('other workout types do not affect each other', () => {
  assert.equal(S.suggest('rope', [kb('a', 1, 'easy'), kb('b', 2, 'easy')], {}, 80, {}, 'd'), null);
});

console.log(`all ${n} app tests passed`);
