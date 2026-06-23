/* ============================================================================
   CGA MOCKUPS v2 — components-v2.js
   Shared renderers used across Learn, journeys, and SOP-bearing pages:
     CGA.v2c.videoPlayer(videoId)   the multi-track player (markup)
     CGA.v2c.initVideo(rootEl)      wire up every .vplayer inside rootEl
     CGA.v2c.sopPanel(sopId, opts)  the standard-operating-procedure card
     CGA.v2c.reportLink(refLabel)   the site-wide "report an issue" anchor
     CGA.v2c.stateBadge(stateId)    translation/support status pill

   The video player is a faithful MOCKUP of the cosmopolitancoalition.org
   WordPress player (functions/video_player.php): ONE silent master MP4 + N
   per-language audio (.m4a) + N caption (.vtt), a "link audio & subtitles"
   toggle, drift-correction, and remembered preferences. No real media ships in
   the prototype — the stage is a labelled placeholder.
   ============================================================================ */
(function () {
  'use strict';
  var CGA = window.CGA = window.CGA || {};
  if (!CGA.shellV2) throw new Error('components-v2.js: shell-v2.js must load first');
  if (!CGA.fixtures || !CGA.fixtures.v2) throw new Error('components-v2.js: fixtures-v2.js must load first');

  function S() { return CGA.shellV2; }
  function L() { return CGA.fixtures.v2.learn; }
  function esc(s) { return S().esc(s); }
  function icon(n, o) { return S().icon(n, o); }
  function langName(code) { var f = CGA.fixtures.v2; return f.langName ? f.langName(code) : code; }
  function langNative(code) { var f = CGA.fixtures.v2; return f.langNative ? f.langNative(code) : code; }

  var PREF_KEY = 'cga.v2.video.prefs';
  function readPrefs() {
    try { return JSON.parse(localStorage.getItem(PREF_KEY)) || {}; } catch (e) { return {}; }
  }
  function writePrefs(p) { try { localStorage.setItem(PREF_KEY, JSON.stringify(p)); } catch (e) { /* file:// */ } }

  /* a short localized sample line so captions read as real captions */
  var SAMPLE = {
    en: 'Welcome — let’s walk through this together.',
    es: 'Bienvenido: hagamos esto juntos.',
    fr: 'Bienvenue — faisons cela ensemble.',
    ar: 'مرحبًا — لنقم بهذا معًا.',
    zh: '欢迎——我们一起来看看。',
    de: 'Willkommen — gehen wir das gemeinsam durch.',
    pt: 'Bem-vindo — vamos ver isto juntos.',
    ru: 'Добро пожаловать — давайте разберёмся вместе.',
    ja: 'ようこそ。一緒に進めましょう。',
    hi: 'स्वागत है — आइए इसे साथ देखें।'
  };
  function sampleLine(code) { return SAMPLE[code] || ('Subtitles shown in ' + langNative(code) + '.'); }

  /* ------------------------------------------------------------ videoPlayer */
  function videoPlayer(videoId) {
    var v = (L().byVideo || {})[videoId];
    if (!v) return '<div class="lr-note">' + icon('alert-triangle', { size: 'sm' }) + '<div>Video <code>' + esc(videoId) + '</code> is not in the catalog.</div></div>';
    var dur = L().fmtDuration(v.seconds);

    function opts(codes) {
      return codes.map(function (c) {
        return '<option value="' + esc(c) + '">' + esc(langName(c)) + ' · ' + esc(langNative(c)) + '</option>';
      }).join('');
    }

    return '' +
      '<figure class="vplayer" data-vplayer="' + esc(v.id) + '">' +
      '<div class="vplayer-stage vposter--' + esc(v.poster) + '" role="img" ' +
        'aria-label="Video guide: ' + esc(v.title) + ' (mockup — no media in the prototype)">' +
        '<button type="button" class="vplayer-play" data-v-play aria-label="Play (mockup)">' + icon('play') + '</button>' +
        '<span class="vplayer-dur">' + esc(dur) + '</span>' +
        '<div class="vplayer-cc" data-v-ccbar>' + esc(sampleLine('en')) + '</div>' +
      '</div>' +

      '<div class="vplayer-transport">' +
        '<button type="button" class="iconbtn" data-v-play2 aria-label="Play or pause">' + icon('play') + '</button>' +
        '<span class="vplayer-time" data-v-time>0:00 / ' + esc(dur) + '</span>' +
        '<input type="range" class="vplayer-seek" data-v-seek min="0" max="100" value="0" aria-label="Seek (mockup)" />' +
        '<button type="button" class="iconbtn iconbtn--on" data-v-cc aria-pressed="true" aria-label="Captions on">' + icon('captions') + '</button>' +
      '</div>' +

      '<div class="vplayer-tracks">' +
        '<label class="vtrack"><span class="vtrack-lbl">' + icon('volume', { size: 'sm' }) + ' Audio</span>' +
          '<select class="select" data-v-audio>' + opts(v.audio) + '</select></label>' +
        '<label class="vtrack"><span class="vtrack-lbl">' + icon('captions', { size: 'sm' }) + ' Captions</span>' +
          '<select class="select" data-v-cap>' + opts(v.captions) + '</select></label>' +
        '<label class="vtrack vtrack--link"><input type="checkbox" data-v-link checked /> ' +
          '<span>Link audio &amp; subtitles</span></label>' +
      '</div>' +

      '<div class="vplayer-status" data-v-status></div>' +

      '<figcaption class="vplayer-cap">' +
        '<strong style="color:var(--gov-fg)">' + esc(v.title) + '</strong> · ' + esc(v.summary) +
        '<span class="vplayer-meta citation">' +
          'One silent master + ' + v.audio.length + ' audio · ' + v.captions.length + ' caption tracks · ' +
          'drift-corrected &gt;0.3s · your choice is remembered · ' + esc(v.subject) + '-{Language}.{ext}' +
        '</span>' +
        '<details class="vplayer-transcript"><summary>' + icon('file-text', { size: 'sm' }) + ' Transcript</summary>' +
          '<p class="gloss">' + esc(sampleLine('en')) + ' … (full transcript travels with the captions track, so it is searchable and translatable like any other modality.)</p>' +
        '</details>' +
      '</figcaption>' +
      '</figure>';
  }

  function initVideo(rootEl) {
    rootEl = rootEl || document;
    var players = rootEl.querySelectorAll('.vplayer');
    var prefs = readPrefs();
    for (var i = 0; i < players.length; i++) wireOne(players[i], prefs);
  }

  function wireOne(fig, prefs) {
    var audioSel = fig.querySelector('[data-v-audio]'),
        capSel = fig.querySelector('[data-v-cap]'),
        link = fig.querySelector('[data-v-link]'),
        cc = fig.querySelector('[data-v-cc]'),
        ccbar = fig.querySelector('[data-v-ccbar]'),
        status = fig.querySelector('[data-v-status]'),
        seek = fig.querySelector('[data-v-seek]'),
        timeEl = fig.querySelector('[data-v-time]'),
        play = fig.querySelector('[data-v-play]'),
        play2 = fig.querySelector('[data-v-play2]'),
        durTxt = (timeEl.textContent.split('/')[1] || '').trim();

    function has(sel, code) { for (var j = 0; j < sel.options.length; j++) if (sel.options[j].value === code) return true; return false; }
    function setIf(sel, code) { if (has(sel, code)) sel.value = code; }

    /* apply remembered prefs (global, like the WP player's usermeta) */
    if (prefs.audio) setIf(audioSel, prefs.audio);
    if (prefs.cap) setIf(capSel, prefs.cap);
    if (prefs.linked === false) link.checked = false;
    if (prefs.captionsOn === false) { cc.setAttribute('aria-pressed', 'false'); cc.classList.remove('iconbtn--on'); }

    function paint() {
      var capOn = cc.getAttribute('aria-pressed') === 'true';
      var aName = langName(audioSel.value), cName = langName(capSel.value);
      status.innerHTML = icon('check', { size: 'sm' }) + ' In sync · audio <strong>' + esc(aName) + '</strong>' +
        ' · captions ' + (capOn ? '<strong>' + esc(cName) + '</strong>' : '<span class="gloss">off</span>') +
        (link.checked ? ' · <span class="gloss">linked</span>' : ' · <span class="gloss">unlinked</span>');
      ccbar.textContent = capOn ? sampleLine(capSel.value) : '';
      ccbar.style.visibility = capOn ? 'visible' : 'hidden';
      ccbar.setAttribute('dir', (CGA.fixtures.v2.byLang && CGA.fixtures.v2.byLang[capSel.value] && CGA.fixtures.v2.byLang[capSel.value].dir) || 'ltr');
      writePrefs({ audio: audioSel.value, cap: capSel.value, linked: link.checked, captionsOn: capOn });
    }

    audioSel.addEventListener('change', function () {
      if (link.checked && has(capSel, audioSel.value)) capSel.value = audioSel.value;
      paint();
    });
    capSel.addEventListener('change', function () {
      if (link.checked && has(audioSel, capSel.value)) audioSel.value = capSel.value;
      paint();
    });
    link.addEventListener('change', function () {
      if (link.checked && has(capSel, audioSel.value)) capSel.value = audioSel.value;
      paint();
    });
    cc.addEventListener('click', function () {
      var on = cc.getAttribute('aria-pressed') === 'true';
      cc.setAttribute('aria-pressed', on ? 'false' : 'true');
      cc.classList.toggle('iconbtn--on', !on);
      cc.setAttribute('aria-label', on ? 'Captions off' : 'Captions on');
      paint();
    });

    function togglePlay() {
      var playing = fig.getAttribute('data-playing') === 'true';
      fig.setAttribute('data-playing', playing ? 'false' : 'true');
      [play, play2].forEach(function (b) {
        b.innerHTML = playing ? icon('play') : icon('pause');
        b.setAttribute('aria-label', playing ? 'Play' : 'Pause');
      });
    }
    play.addEventListener('click', togglePlay);
    play2.addEventListener('click', togglePlay);

    seek.addEventListener('input', function () {
      var total = (L().byVideo[fig.getAttribute('data-vplayer')] || {}).seconds || 0;
      var at = Math.round(total * (seek.value / 100));
      timeEl.textContent = L().fmtDuration(at) + ' / ' + durTxt;
    });

    paint();
  }

  /* --------------------------------------------------------------- sopPanel */
  function scopePill(scope) {
    return scope === 'operator'
      ? '<span class="pill pill--planned" title="off the constitutional plane">Operator</span>'
      : '<span class="pill pill--live">Anyone</span>';
  }
  function sopPanel(sopId, opts) {
    opts = opts || {};
    var sop = (L().bySop || {})[sopId];
    if (!sop) return '';
    var s = S();
    var steps = '<ol class="sop-steps">' + sop.steps.map(function (st) {
      return '<li><span class="sop-do">' + esc(st.do) + '</span>' +
        '<span class="sop-detail">' + esc(st.detail) + '</span>' +
        (st.cite ? '<span class="citation">' + esc(st.cite) + '</span>' : '') + '</li>';
    }).join('') + '</ol>';

    var links = [];
    if (sop.videoId && !opts.hideVideo) links.push('<a class="form-chip" href="' + s.hrefV2('learn/lesson.html?sop=' + sop.id) + '">' + icon('play', { size: 'sm' }) + ' Watch the guide</a>');
    if (sop.journeyId) links.push('<a class="form-chip" href="' + s.hrefV2('journeys/journey.html?id=' + sop.journeyId) + '">' + icon('arrow-right', { size: 'sm' }) + ' Open the journey</a>');
    if (sop.v1) links.push('<a class="form-chip" href="' + s.hrefV1(sop.v1) + '">' + icon('external-link', { size: 'sm' }) + ' The formal screen <span class="v1-tag">v1</span></a>');
    links.push(reportLink(sop.title));

    var issues = '';
    if (sop.issues && sop.issues.length) {
      issues = '<p class="sop-issues citation">Known issue' + (sop.issues.length > 1 ? 's' : '') + ': ' +
        sop.issues.map(function (id) { return '<a href="' + s.hrefV2('support/ticket.html?id=' + id) + '">' + esc(id.replace('ticket-', '#')) + '</a>'; }).join(' · ') + '</p>';
    }

    return '<section class="sop" aria-label="Standard procedure: ' + esc(sop.title) + '">' +
      '<div class="cluster" style="justify-content:space-between">' +
      '<span class="eyebrow">' + icon('list-checks', { size: 'sm' }) + ' Standard procedure</span>' +
      scopePill(sop.scope) + '</div>' +
      '<div class="cluster" style="gap:var(--space-2)"><h3 class="sop-title">' + esc(sop.title) + '</h3>' +
      '<span class="tag-chip">' + esc(sop.module) + '</span></div>' +
      '<p class="gloss">' + esc(sop.summary) + '</p>' +
      steps +
      '<div class="cluster" style="gap:var(--space-1)">' + links.join(' ') + '</div>' +
      issues +
      '</section>';
  }

  /* ------------------------------------------------------------- reportLink */
  function reportLink(refLabel) {
    var rel = 'support/report.html' + (refLabel ? '?ref=' + encodeURIComponent(refLabel) : '');
    return '<a class="form-chip form-chip--report" href="' + S().hrefV2(rel) + '">' + icon('flag', { size: 'sm' }) + ' Report an issue</a>';
  }

  /* ------------------------------------------------------------ stateBadge */
  var TONE_PILL = { idle: 'planned', info: 'info', warn: 'wait', good: 'pass', live: 'live', danger: 'closed' };
  function stateBadge(tone, label, tip) { return S().pill(TONE_PILL[tone] || 'planned', label, tip); }

  CGA.v2c = {
    videoPlayer: videoPlayer, initVideo: initVideo,
    sopPanel: sopPanel, reportLink: reportLink, stateBadge: stateBadge,
    sampleLine: sampleLine
  };
})();
