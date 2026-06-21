/* ============================================================================
   CGA MOCKUPS v2 — chamber-v2.js
   The EMBODIED SPATIAL VIEW of a Live Civic Room: an SVG seating scene drawn to
   the real arrangement of the institution, so you feel like you're standing in
   the room. Actors are avatars in their actual seats; the one holding the floor
   lights up and steps to the well/podium; votes colour the seats as they come in.

   CGA.chamber.svg(st, opts) -> SVG string.  st = a live-room config (fixtures-v2
   rooms[variant]); opts = { voteActive: bool }.

   Accessibility: the <svg> is role="img" with a descriptive aria-label that names
   the institution and who currently holds the floor; individual seats are
   aria-hidden — the live-room's presence rail + #cga-live region are the
   screen-reader source of truth (turns/votes are announced there).
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  function initials(name, handle) {
    if (name) {
      var parts = name.replace(/^(Dr\.|Mr\.|Ms\.|Mrs\.)\s+/, '').split(/\s+/).filter(Boolean);
      return ((parts[0] || '')[0] || '' ) + ((parts[1] || '')[0] || '');
    }
    return (handle || '·').replace(/^u-/, '').slice(0, 2);
  }
  function nameOf(p) { return p.name || p.handleName || ('@u-' + p.handle); }
  function mxid(h) { return h && h.indexOf('u-') === 0 ? '@' + h : '@u-' + h; }

  /* one seated avatar */
  function avatar(x, y, p, opts) {
    opts = opts || {};
    var r = opts.r || 17;
    var cls = 'cseat' + (opts.chair ? ' cseat--chair' : '') + (opts.speaking ? ' cseat--speaking' : '') +
      (opts.vacant ? ' cseat--vacant' : '') + (opts.vote ? ' cseat--vote-' + opts.vote : '');
    var label = opts.vacant ? 'vacant' : (nameOf(p) + (opts.tag ? ' · ' + opts.tag : ''));
    var ini = opts.vacant ? '' : esc(initials(p.name || p.handleName, p.handle).toUpperCase());
    return '<g class="' + cls + '" transform="translate(' + x + ',' + y + ')">' +
      (opts.speaking ? '<circle class="cseat-glow" r="' + (r + 6) + '"></circle>' : '') +
      '<circle class="cseat-ring" r="' + r + '"></circle>' +
      '<text class="cseat-ini" y="0.32em" text-anchor="middle">' + ini + '</text>' +
      (opts.chair ? '<text class="cseat-badge" y="' + (r + 11) + '" text-anchor="middle">' + esc(opts.chairLabel || 'chair') + '</text>' : '') +
      (opts.seatNo ? '<text class="cseat-no" x="' + (r + 2) + '" y="' + (-r + 4) + '">' + opts.seatNo + '</text>' : '') +
      '<title>' + esc(label) + '</title></g>';
  }
  function zone(x, y, w, h, label, cls) {
    return '<g class="czone ' + (cls || '') + '"><rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" rx="8"></rect>' +
      (label ? '<text class="czone-label" x="' + (x + w / 2) + '" y="' + (y + h / 2 + 4) + '" text-anchor="middle">' + esc(label) + '</text>' : '') + '</g>';
  }
  function podium(x, y, label) {
    return '<g class="cpodium" transform="translate(' + x + ',' + y + ')"><rect x="-26" y="-14" width="52" height="28" rx="6"></rect>' +
      '<text class="czone-label" y="4" text-anchor="middle">' + esc(label || 'floor') + '</text></g>';
  }

  /* assign vote colours to the seats that have voted, in order (yes,no,abstain) */
  function voteMap(st, opts, voters) {
    var map = {};
    if (!opts.voteActive || !st.vote || !st.vote.tallies) return map;
    var t = st.vote.tallies, order = [], i;
    for (i = 0; i < (t.yes || 0); i++) order.push('yes');
    for (i = 0; i < (t.no || 0); i++) order.push('no');
    for (i = 0; i < (t.abstain || 0); i++) order.push('abstain');
    voters.forEach(function (key, idx) { if (order[idx]) map[key] = order[idx]; });
    return map;
  }

  /* ----------------------------------------------------- layouts per variant */
  function arcPoint(cx, cy, R, deg) {
    var a = deg * Math.PI / 180;
    return [cx + R * Math.cos(a), cy - R * Math.sin(a)];
  }

  function hemicycle(st, opts) {
    /* legislature / exec / townhall: members on a fanned arc facing the front */
    var members = st.presence.filter(function (p) { return p.role !== 'gallery' && p.role !== 'chair'; });
    var chair = st.presence.filter(function (p) { return p.role === 'chair'; })[0] || st.chair;
    var N = Math.max(members.length, 1);
    var cx = 400, cy = 70, R = 215;
    var span = N > 1 ? 150 : 0, start = 195;
    var voters = members.filter(function (p) { return !p.vacant; }).map(function (p) { return p.handle; });
    var vm = voteMap(st, opts, voters);
    var s = '';
    /* the speaker/president at the head */
    s += avatar(cx, cy - 6, chair, { chair: true, chairLabel: chairLabelFor(st), speaking: st.floorHolder === chair.handle });
    /* the well/podium where a recognized member addresses the chamber */
    s += podium(cx, 168, 'the floor');
    members.forEach(function (p, i) {
      var deg = N > 1 ? start + span * (i / (N - 1)) : 270;
      var pt = arcPoint(cx, cy, R, deg);
      s += avatar(pt[0], pt[1], p, {
        speaking: st.floorHolder === p.handle, vacant: p.vacant, vote: vm[p.handle],
        seatNo: p.seat || null
      });
    });
    /* the recognized member steps to the well */
    s += wellActor(st, 'the floor', cx, 138);
    return { viewBox: '0 0 800 300', body: s, sceneLabel: 'ordered chamber' };
  }

  function roundTable(st, opts, label) {
    /* exec committee / informal group: equal seats around a round table, no head */
    var members = st.presence.filter(function (p) { return p.role !== 'gallery'; });
    var cx = 400, cy = 150, R = 110;
    var voters = members.map(function (p) { return p.handle; });
    var vm = voteMap(st, opts, voters);
    var s = '<ellipse class="ctable" cx="' + cx + '" cy="' + cy + '" rx="120" ry="78"></ellipse>' +
      '<text class="czone-label" x="' + cx + '" y="' + (cy + 4) + '" text-anchor="middle">' + esc(label) + '</text>';
    var N = members.length;
    members.forEach(function (p, i) {
      var deg = 90 + 360 * (i / N);
      var pt = arcPoint(cx, cy * 1, R + 50, deg);
      pt[1] = cy - (R + 18) * Math.sin(deg * Math.PI / 180) * 0.9;
      pt[0] = cx + (R + 60) * Math.cos(deg * Math.PI / 180);
      s += avatar(pt[0], pt[1], p, { chair: p.role === 'chair', chairLabel: chairLabelFor(st), speaking: st.floorHolder === p.handle, vote: vm[p.handle] });
    });
    return { viewBox: '0 0 800 320', body: s, sceneLabel: label };
  }

  function boardTable(st, opts) {
    var workers = st.presence.filter(function (p) { return p.track === 'worker'; });
    var owners = st.presence.filter(function (p) { return p.track === 'owner'; });
    var chair = st.presence.filter(function (p) { return p.role === 'chair'; })[0] || st.chair;
    var cx = 400, cy = 160;
    var voters = st.presence.filter(function (p) { return p.role !== 'gallery'; }).map(function (p) { return p.handle; });
    var vm = voteMap(st, opts, voters);
    var s = '<rect class="ctable" x="250" y="120" width="300" height="80" rx="40"></rect>' +
      '<text class="czone-label" x="' + cx + '" y="165" text-anchor="middle">board table</text>';
    /* joint chair at the head */
    s += avatar(cx, 70, chair, { chair: true, chairLabel: 'joint chair', speaking: st.floorHolder === chair.handle });
    /* worker seats along the top edge, owner seats along the bottom edge */
    var wOther = workers.filter(function (p) { return p.role !== 'chair'; });
    var oOther = owners.filter(function (p) { return p.role !== 'chair'; });
    var workerLine = (chair.track === 'worker' ? [] : []).concat(workers.filter(function (p) { return p.handle !== chair.handle; }));
    workerLine.forEach(function (p, i) {
      var x = 300 + (i + 1) * (200 / (workerLine.length + 1));
      s += avatar(x, 105, p, { speaking: st.floorHolder === p.handle, vote: vm[p.handle], tag: 'worker' });
    });
    oOther.forEach(function (p, i) {
      var x = 300 + (i + 1) * (200 / (oOther.length + 1));
      s += avatar(x, 215, p, { speaking: st.floorHolder === p.handle, vote: vm[p.handle], tag: 'owner' });
    });
    s += '<text class="czone-tag czone-tag--worker" x="300" y="95" text-anchor="middle">worker seats</text>';
    s += '<text class="czone-tag czone-tag--owner" x="300" y="245" text-anchor="middle">owner seats</text>';
    return { viewBox: '0 0 800 290', body: s, sceneLabel: 'board table' };
  }

  function committee(st, opts) {
    var members = st.presence.filter(function (p) { return p.role !== 'gallery' && p.role !== 'floor'; });
    var floorP = st.presence.filter(function (p) { return p.role === 'floor'; })[0];
    var voters = members.map(function (p) { return p.handle; });
    var vm = voteMap(st, opts, voters);
    var s = '<rect class="ctable" x="250" y="70" width="300" height="46" rx="20"></rect>' +
      '<text class="czone-label" x="400" y="98" text-anchor="middle">committee bench</text>';
    var positions = [[300, 70], [400, 56], [500, 70]];
    members.forEach(function (p, i) {
      var pos = positions[i] || [300 + i * 100, 70];
      s += avatar(pos[0], pos[1], p, { chair: p.role === 'chair', chairLabel: 'chair', speaking: st.floorHolder === p.handle, vote: vm[p.handle] });
    });
    /* the testimony podium, facing the bench */
    s += podium(400, 215, 'testimony');
    s += wellActor(st, 'testimony', 400, 185);
    /* gallery */
    s += galleryRow(st, 270);
    return { viewBox: '0 0 800 300', body: s, sceneLabel: 'committee room' };
  }

  function courtroom(st, opts) {
    var judges = st.presence.filter(function (p) { return p.role === 'chair' || p.role === 'member'; });
    var advocates = st.presence.filter(function (p) { return p.advocate; });
    var s = '';
    /* the bench */
    s += '<rect class="ctable cbench" x="260" y="34" width="280" height="44" rx="8"></rect>' +
      '<text class="czone-label" x="400" y="60" text-anchor="middle">the bench</text>';
    var jx = [330, 400, 470];
    judges.slice(0, 3).forEach(function (p, i) {
      s += avatar(jx[i] || (330 + i * 70), 30, p, { r: 15, chair: i === 0, chairLabel: 'presiding', speaking: st.floorHolder === p.handle });
    });
    /* witness stand */
    s += zone(300, 110, 60, 40, 'witness', 'cwitness');
    /* plaintiff + defense tables */
    s += zone(150, 200, 150, 48, '', 'ctable');
    s += zone(500, 200, 150, 48, '', 'ctable');
    s += '<text class="czone-tag" x="225" y="195" text-anchor="middle">claimant</text>';
    s += '<text class="czone-tag" x="575" y="195" text-anchor="middle">respondent</text>';
    advocates.forEach(function (p) {
      var left = p.side !== 'defense';
      s += avatar(left ? 225 : 575, 224, p, { speaking: st.floorHolder === p.handle, tag: left ? 'claimant counsel' : 'respondent counsel' });
    });
    /* the lectern/well where the examining advocate stands */
    s += podium(400, 210, 'the lectern');
    s += wellActor(st, 'the lectern', 400, 178);
    /* jury box (protected, separate) */
    s += zone(690, 96, 70, 170, '', 'cjury');
    s += '<text class="czone-tag" x="725" y="90" text-anchor="middle">jury</text>';
    for (var j = 0; j < 4; j++) s += '<circle class="cseat-ring cjuror" cx="725" cy="' + (120 + j * 38) + '" r="11"></circle>';
    /* gallery */
    s += galleryRow(st, 285);
    return { viewBox: '0 0 800 330', body: s, sceneLabel: 'courtroom' };
  }

  function forumStage(st, opts) {
    var cands = st.presence.filter(function (p) { return p.candidate; });
    var chair = st.presence.filter(function (p) { return p.role === 'chair'; })[0] || st.chair;
    var s = '<rect class="cstage" x="120" y="64" width="560" height="6" rx="3"></rect>' +
      '<text class="czone-label" x="400" y="44" text-anchor="middle">the stage</text>';
    s += avatar(120, 96, chair, { chair: true, chairLabel: 'moderator', r: 14 });
    var n = cands.length;
    cands.forEach(function (p, i) {
      var x = 230 + (i) * (440 / Math.max(n - 1, 1));
      s += '<rect class="clectern' + (st.floorHolder === p.handle ? ' clectern--live' : '') + '" x="' + (x - 20) + '" y="96" width="40" height="10" rx="3"></rect>';
      s += avatar(x, 78, p, { speaking: st.floorHolder === p.handle, tag: 'candidate', r: 15 });
    });
    s += galleryRow(st, 200, 'residents in the gallery');
    return { viewBox: '0 0 800 300', body: s, sceneLabel: 'candidate forum' };
  }

  /* the actor who currently holds the floor, drawn standing at the well */
  function wellActor(st, where, x, y) {
    if (!st.floorHolder) return '';
    var p = st.presence.filter(function (q) { return q.handle === st.floorHolder; })[0];
    if (!p) p = { handle: st.floorHolder };
    return '<g class="cwell-actor"><line class="cwell-line" x1="' + x + '" y1="' + (y - 2) + '" x2="' + x + '" y2="' + (y + 30) + '"></line>' +
      avatar(x, y, p, { speaking: true, r: 18 }) +
      '<text class="cwell-name" x="' + x + '" y="' + (y - 26) + '" text-anchor="middle">' + esc(nameOf(p)) + ' has the floor</text></g>';
  }
  function galleryRow(st, y, label) {
    var gallery = st.presence.filter(function (p) { return p.role === 'gallery'; });
    if (!gallery.length) return '';
    var s = '<text class="czone-tag" x="400" y="' + (y - 8) + '" text-anchor="middle">' + esc(label || 'gallery — anyone may watch') + '</text>';
    var n = Math.min(gallery.length, 9);
    for (var i = 0; i < n; i++) {
      var x = 400 + (i - (n - 1) / 2) * 34;
      s += '<circle class="cseat-ring cgallery" cx="' + x + '" cy="' + (y + 12) + '" r="9"></circle>';
    }
    return s;
  }
  function chairLabelFor(st) {
    return ({ speaker: 'Speaker', chair: 'chair', presiding_judge: 'presiding', facilitator: 'facilitator', moderator: 'moderator' })[st.chairRole] || 'chair';
  }

  /* ----------------------------------------------------------- dispatch */
  function layout(st, opts) {
    switch (st.variant) {
      case 'legislative': return hemicycle(st, opts);
      case 'townhall': return hemicycle(st, opts);
      case 'exec': return roundTable(st, opts, 'equal-power table');
      case 'group': return roundTable(st, opts, 'a circle of chairs');
      case 'board': return boardTable(st, opts);
      case 'committee': return committee(st, opts);
      case 'court': return courtroom(st, opts);
      case 'forum': return forumStage(st, opts);
      default: return hemicycle(st, opts);
    }
  }

  CGA.chamber = {
    svg: function (st, opts) {
      opts = opts || {};
      var L = layout(st, opts);
      var holder = st.floorHolder ? st.presence.filter(function (p) { return p.handle === st.floorHolder; })[0] : null;
      var aria = 'A ' + L.sceneLabel + ' for ' + (st.title || 'the meeting') + '. ' +
        (holder ? nameOf(holder) + ' currently holds the floor.' : 'No one holds the floor right now.') +
        ' The seating reflects the real arrangement of the institution; the presence list below is the accessible roster.';
      return '<svg class="lr-chamber-svg" viewBox="' + L.viewBox + '" role="img" aria-label="' + esc(aria) + '" preserveAspectRatio="xMidYMid meet">' + L.body + '</svg>';
    }
  };
})();
