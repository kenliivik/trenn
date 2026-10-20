// Training targets and the rules that suggest changing them. Pure functions: used by index.html, tested by tests/app_test.mjs.
(function (root) {
  // Rep ranges per kettlebell/bodyweight exercise, easiest first. Level 1 is where the app started.
  var LADDERS = {
    'incline-pushup': ['6–8', '8–10', '10–12', '12–15', '15–20'],
    'goblet-squat':   ['6–8', '8–10', '10–12', '12–15', '15–20'],
    'kb-halos':       ['3 + 3', '5 + 5', '6 + 6', '8 + 8', '10 + 10'],
    'kb-windmill':    ['3', '5', '6', '8', '10'],
    'kb-deadlift':    ['8–10', '10–12', '12–15', '15–20', '20–25']
  };
  var START_LEVEL = 1;
  // Timed exercises: seconds, with the step the −/+ buttons use.
  var TIMED = {
    'jump-rope':  { type: 'rope',  start: 40,      step: 15,  min: 15,  max: 600 },
    'elliptical': { type: 'ellip', start: 20 * 60, step: 300, min: 300, max: 7200 }
  };
  var LOW_READINESS = 65;
  var HR_DROP = 5; // bpm lower at the same elliptical time = fitter

  function level(targets, id) {
    var l = targets && targets[id];
    return typeof l === 'number' ? Math.max(0, Math.min(LADDERS[id].length - 1, l)) : START_LEVEL;
  }
  function seconds(targets, id) {
    var t = TIMED[id], s = targets && targets[id];
    return typeof s === 'number' ? Math.max(t.min, Math.min(t.max, s)) : t.start;
  }
  // What a target looks like on screen: "10–12" or 1500 (seconds).
  function show(targets, id) {
    return LADDERS[id] ? LADDERS[id][level(targets, id)] : seconds(targets, id);
  }

  // Every exercise of a workout type, one step easier (dir -1) or harder (+1). Exercises already at the end are left out.
  function step(type, targets, dir) {
    var out = [];
    if (type === 'kb') {
      Object.keys(LADDERS).forEach(function (id) {
        var from = level(targets, id), to = from + dir;
        if (to >= 0 && to < LADDERS[id].length) out.push({ id: id, from: LADDERS[id][from], to: LADDERS[id][to], value: to });
      });
    } else {
      Object.keys(TIMED).forEach(function (id) {
        var t = TIMED[id];
        if (t.type !== type) return;
        var from = seconds(targets, id), to = Math.max(t.min, Math.min(t.max, from + dir * t.step));
        if (to !== from) out.push({ id: id, from: from, to: to, value: to });
      });
    }
    return out;
  }

  function avg(list) { return list.reduce(function (a, b) { return a + b; }, 0) / list.length; }

  // One suggestion for a workout type, or null. Evidence only counts after the last change of that type's targets,
  // and a suggestion that was declined is not shown again.
  //   sessions: [{id, type, started_at, rating, items:[{id, seconds}], hr:{avg}}]   readiness: today's score or null
  function suggest(type, sessions, targets, readiness, declined, today) {
    declined = declined || {};
    var since = (targets && targets.changedAt && targets.changedAt[type]) || 0;
    var mine = sessions.filter(function (s) { return s.type === type && s.started_at > since; })
      .sort(function (a, b) { return a.started_at - b.started_at; });
    var rated = mine.filter(function (s) { return s.rating; });
    var last = rated[rated.length - 1], prev = rated[rated.length - 2];
    var pick = function (s) { return s && !declined[s.key] && s.changes.length ? s : null; };

    if (readiness !== null && readiness !== undefined && readiness < LOW_READINESS) {
      return pick({ key: 'light-' + type + '-' + today, kind: 'light', temporary: true,
        text: 'Readiness ' + readiness + ' today. Go one step lighter?', changes: step(type, targets, -1) });
    }
    if (last && last.rating === 'hard') {
      return pick({ key: 'down-' + last.id, kind: 'down',
        text: 'Last session felt hard. Step back one level?', changes: step(type, targets, -1) });
    }
    if (last && prev && last.rating === 'easy' && prev.rating === 'easy') {
      return pick({ key: 'up-' + last.id, kind: 'up',
        text: 'Two easy sessions in a row. Ready to step up?', changes: step(type, targets, 1) });
    }
    if (type === 'ellip') {
      // Same elliptical time, clearly lower heart rate over the last two sessions = room for more.
      var now = seconds(targets, 'elliptical');
      var same = mine.filter(function (s) {
        var it = (s.items || [])[0];
        return s.hr && s.hr.avg && it && Math.abs((it.seconds || 0) - now) < 60;
      });
      if (same.length >= 4) {
        var recent = same.slice(-2), before = same.slice(-4, -2);
        var drop = avg(before.map(function (s) { return s.hr.avg; })) - avg(recent.map(function (s) { return s.hr.avg; }));
        if (drop >= HR_DROP && recent.every(function (s) { return s.rating !== 'hard'; })) {
          return pick({ key: 'hr-up-' + recent[1].id, kind: 'up',
            text: 'Heart rate is ' + Math.round(drop) + ' bpm lower at the same time. Go longer?', changes: step(type, targets, 1) });
        }
      }
    }
    return null;
  }

  var api = { LADDERS: LADDERS, TIMED: TIMED, LOW_READINESS: LOW_READINESS, level: level, seconds: seconds, show: show, step: step, suggest: suggest };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.TrennSuggest = api;
})(this);
