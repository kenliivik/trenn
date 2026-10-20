// Training targets and the rules that suggest changing them. Pure functions: used by app.js, tested by tests/app_test.mjs.
//
// An exercise (from the library the user edits) is either
//   {id, kind:'reps', reps:'8–10'}            reps written as a range, a single number, or "5 + 5" (each side/way)
//   {id, kind:'time', seconds:45, step, min, max}
// Targets hold where each exercise is now: a rep string ("10–12") or seconds.
(function (root) {
  // Rep scales, easiest first. An entered value is placed on the matching scale, so any exercise can step up or down.
  var RANGE = ['4–6', '6–8', '8–10', '10–12', '12–15', '15–20', '20–25', '25–30'];
  var SINGLE = ['2', '3', '4', '5', '6', '8', '10', '12', '15', '20', '25', '30'];
  var PAIR = SINGLE.map(function (n) { return n + ' + ' + n; });
  var LOW_READINESS = 65;
  var HR_DROP = 5; // bpm lower at the same elliptical time = fitter
  // Targets used to be ladder positions; these turn an old number back into reps.
  var OLD_LADDERS = {
    'incline-pushup': ['6–8', '8–10', '10–12', '12–15', '15–20'],
    'goblet-squat':   ['6–8', '8–10', '10–12', '12–15', '15–20'],
    'kb-halos':       ['3 + 3', '5 + 5', '6 + 6', '8 + 8', '10 + 10'],
    'kb-windmill':    ['3', '5', '6', '8', '10'],
    'kb-deadlift':    ['8–10', '10–12', '12–15', '15–20', '20–25']
  };

  // "8-10", "8 – 10" → "8–10"; "5+5" → "5 + 5". Anything else is kept as typed (and simply doesn't progress).
  function normReps(v) {
    v = String(v == null ? '' : v).trim();
    var m;
    if ((m = v.match(/^(\d+)\s*[-–—]\s*(\d+)$/))) return m[1] + '–' + m[2];
    if ((m = v.match(/^(\d+)\s*\+\s*(\d+)$/))) return m[1] + ' + ' + m[2];
    return v;
  }
  function first(v) { return parseInt(v, 10); }
  // The scale a rep value belongs to, with the value slotted in if it's not already on it.
  function scaleFor(v) {
    var scale = /–/.test(v) ? RANGE : / \+ /.test(v) ? PAIR : /^\d+$/.test(v) ? SINGLE : null;
    if (!scale) return null;
    if (scale.indexOf(v) >= 0) return scale;
    return scale.concat([v]).sort(function (a, b) { return first(a) - first(b) || a.length - b.length; });
  }

  function timeLimits(ex) {
    var s = ex.seconds || 60;
    return { step: ex.step || (s >= 300 ? 60 : 15), min: ex.min || 15, max: ex.max || 3600 };
  }

  // Where an exercise is now.
  function current(ex, targets) {
    var t = targets && targets[ex.id];
    if (ex.kind === 'time') {
      var l = timeLimits(ex);
      return typeof t === 'number' ? Math.max(l.min, Math.min(l.max, t)) : ex.seconds;
    }
    if (typeof t === 'number' && OLD_LADDERS[ex.id]) t = OLD_LADDERS[ex.id][Math.max(0, Math.min(4, t))];
    return typeof t === 'string' && t ? normReps(t) : normReps(ex.reps);
  }

  // Every exercise one step easier (dir -1) or harder (+1). Exercises at the end of their scale, or without one, are left out.
  function step(exercises, targets, dir) {
    var out = [];
    exercises.forEach(function (ex) {
      var from = current(ex, targets), to;
      if (ex.kind === 'time') {
        var l = timeLimits(ex);
        to = Math.max(l.min, Math.min(l.max, from + dir * l.step));
      } else {
        var scale = scaleFor(from), i = scale ? scale.indexOf(from) + dir : -1;
        to = scale && i >= 0 && i < scale.length ? scale[i] : from;
      }
      if (to !== from) out.push({ id: ex.id, from: from, to: to, value: to });
    });
    return out;
  }

  function avg(list) { return list.reduce(function (a, b) { return a + b; }, 0) / list.length; }

  // One suggestion for a workout group, or null. A group is a kettlebell set or a timed tile:
  //   {key:'kb:main', type:'kb', set:'main', exercises:[...]}   {key:'ellip', type:'ellip', exercises:[elliptical]}
  // Evidence only counts after the group's last target change, and a declined suggestion is not shown again.
  //   sessions: [{id, type, set, started_at, rating, items:[{id, seconds}], hr:{avg}}]   readiness: today's score or null
  function suggest(group, sessions, targets, readiness, declined, today) {
    declined = declined || {};
    var since = (targets && targets.changedAt && targets.changedAt[group.key]) || 0;
    var mine = sessions.filter(function (s) {
      return s.type === group.type && (group.type !== 'kb' || (s.set || 'main') === group.set) && s.started_at > since;
    }).sort(function (a, b) { return a.started_at - b.started_at; });
    var rated = mine.filter(function (s) { return s.rating; });
    var last = rated[rated.length - 1], prev = rated[rated.length - 2];
    var pick = function (s) { return s && !declined[s.key] && s.changes.length ? s : null; };

    if (readiness !== null && readiness !== undefined && readiness < LOW_READINESS) {
      return pick({ key: 'light-' + group.key + '-' + today, kind: 'light', temporary: true,
        text: 'Readiness ' + readiness + ' today. Go one step lighter?', changes: step(group.exercises, targets, -1) });
    }
    if (last && last.rating === 'hard') {
      return pick({ key: 'down-' + last.id, kind: 'down',
        text: 'Last session felt hard. Step back one level?', changes: step(group.exercises, targets, -1) });
    }
    if (last && prev && last.rating === 'easy' && prev.rating === 'easy') {
      return pick({ key: 'up-' + last.id, kind: 'up',
        text: 'Two easy sessions in a row. Ready to step up?', changes: step(group.exercises, targets, 1) });
    }
    if (group.type === 'ellip') {
      // Same elliptical time, clearly lower heart rate over the last two sessions = room for more.
      var now = current(group.exercises[0], targets);
      var same = mine.filter(function (s) {
        var it = (s.items || [])[0];
        return s.hr && s.hr.avg && it && Math.abs((it.seconds || 0) - now) < 60;
      });
      if (same.length >= 4) {
        var recent = same.slice(-2), before = same.slice(-4, -2);
        var drop = avg(before.map(function (s) { return s.hr.avg; })) - avg(recent.map(function (s) { return s.hr.avg; }));
        if (drop >= HR_DROP && recent.every(function (s) { return s.rating !== 'hard'; })) {
          return pick({ key: 'hr-up-' + recent[1].id, kind: 'up',
            text: 'Heart rate is ' + Math.round(drop) + ' bpm lower at the same time. Go longer?', changes: step(group.exercises, targets, 1) });
        }
      }
    }
    return null;
  }

  var api = { RANGE: RANGE, SINGLE: SINGLE, PAIR: PAIR, LOW_READINESS: LOW_READINESS,
              normReps: normReps, scaleFor: scaleFor, timeLimits: timeLimits, current: current, step: step, suggest: suggest };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.TrennSuggest = api;
})(this);
