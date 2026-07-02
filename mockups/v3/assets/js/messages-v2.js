/* messages-v2.js — the ephemeral messaging layer (v3).
   ----------------------------------------------------------------------------
   A "group" is a transient conversation — a party/raid, not a standing guild.
   The same toolkit (text · file · voice · video · share) serves a 1:1 DM and a
   multi-person party. A party that wants to last can coalesce into a standing
   organization. This is the social layer's direct + group messaging.

   Public surface:
     CGA.messagesV2.inbox()           -> innerHTML for the conversation list
     CGA.messagesV2.thread(convId)    -> innerHTML for one conversation
     CGA.messagesV2.wire(rootEl)      -> wire composer + toolkit (simulated)

   Authored jargon-light. */
(function () {
  'use strict';
  window.CGA = window.CGA || {};
  var ME = 'amara';

  function S() { return CGA.shellV2; }
  function G() { return CGA.fixtures.v2.groups; }
  function esc(s) { return S().esc(s); }
  function icon(n, o) { return S().icon(n, o); }
  function pill(t, l, tip) { return S().pill(t, l, tip); }
  function hrefV2(r, q) { return S().hrefV2(r, q); }

  function nameOf(h) {
    if (h === ME) return 'You';
    if (h && h.indexOf('u-') === 0) return '@' + h;
    return h ? h.charAt(0).toUpperCase() + h.slice(1) : '?';
  }
  function initialsOf(h) {
    if (h && h.indexOf('u-') === 0) return h.slice(2, 4).toUpperCase();
    return (h || '?').slice(0, 2).toUpperCase();
  }
  function convById(id) {
    var list = G().conversations || [];
    return list.filter(function (c) { return c.id === id; })[0] || list[0];
  }
  function lastMsg(c) { return (c.messages || [])[(c.messages || []).length - 1] || { from: '', text: '' }; }
  function toolByKey(k) { return (G().toolkit || []).filter(function (t) { return t.key === k; })[0] || { label: k, icon: 'file-text' }; }

  /* ---- the inbox: DMs + parties ---------------------------------------- */
  function inbox() {
    var list = G().conversations || [];
    var rows = list.map(function (c) {
      var lm = lastMsg(c);
      var preview = (lm.from === ME ? 'You: ' : (c.kind === 'party' ? nameOf(lm.from) + ': ' : '')) +
        (lm.attach ? toolByKey(lm.attach.type).label + ' · ' + lm.attach.label : lm.text);
      var sub = c.kind === 'party'
        ? icon('users', { size: 'sm' }) + ' Party · ' + (c.members != null ? c.members + ' people' : (c.participants || []).length + ' people')
        : icon('user', { size: 'sm' }) + ' Direct message';
      return '<a class="conv-row' + (c.unread ? ' conv-row--unread' : '') + '" href="' + hrefV2('groups/group-detail.html?id=' + encodeURIComponent(c.id)) + '">' +
        '<span class="conv-avatar" aria-hidden="true">' + esc(c.kind === 'party' ? initialsOf(c.title.replace(/\s/g, '')) : initialsOf(c.with || c.title)) + '</span>' +
        '<span class="conv-main">' +
        '<span class="conv-top"><strong>' + esc(c.title) + '</strong>' +
        (c.live ? ' <span class="pill pill--live"><span class="dotlive" aria-hidden="true"></span>live</span>' : '') +
        '<span class="conv-when">' + esc(c.when) + '</span></span>' +
        '<span class="conv-sub">' + sub + '</span>' +
        '<span class="conv-preview">' + esc(preview) + '</span></span>' +
        (c.unread ? '<span class="conv-badge" aria-label="' + c.unread + ' unread">' + c.unread + '</span>' : '') +
        '</a>';
    }).join('');

    return '<div class="cluster" style="justify-content:space-between;align-items:center;margin-block-end:var(--space-3)">' +
      '<p class="gloss" style="margin:0">Your direct messages and parties — talk, files, voice, and video.</p>' +
      '<a class="btn btn--primary btn--sm" href="' + hrefV2('groups/group-create.html') + '">' + icon('plus', { size: 'sm' }) + ' New message or party</a>' +
      '</div>' +
      '<section class="card" style="padding:0" aria-label="Conversations"><div class="msg-inbox">' + rows + '</div></section>' +
      '<div class="lr-note" style="margin-block-start:var(--space-3)"><div>' + icon('shield', { size: 'sm' }) + '</div>' +
      '<div><strong style="color:var(--gov-fg)">A party is just people talking.</strong> It is temporary, it grants no governance power, and it is private to its members — nobody else can read it. If a party wants to last, it can become an <a href="' + hrefV2('organizations/org-registry.html#register-h') + '">organization</a> — but it never has to.</div></div>';
  }

  /* ---- one conversation: thread + toolkit ------------------------------ */
  function attachChip(att) {
    var t = toolByKey(att.type);
    return '<span class="msg-attach">' + icon(t.icon, { size: 'sm' }) + ' ' + esc(att.label) + '</span>';
  }
  function thread(convId) {
    var c = convById(convId);
    if (!c) return '<p class="gloss">No conversation.</p>';
    var isParty = c.kind === 'party';

    var bubbles = (c.messages || []).map(function (m) {
      var mine = m.from === ME;
      return '<div class="msg-bubble' + (mine ? ' msg-bubble--mine' : '') + '">' +
        (isParty && !mine ? '<span class="msg-from">' + esc(nameOf(m.from)) + '</span>' : '') +
        (m.text ? '<span class="msg-text">' + esc(m.text) + '</span>' : '') +
        (m.attach ? attachChip(m.attach) : '') +
        '<span class="msg-when">' + esc(m.when) + '</span></div>';
    }).join('');

    var toolkit = (G().toolkit || []).map(function (t) {
      return '<button type="button" class="msg-tool" data-tool="' + esc(t.key) + '" title="' + esc(t.label) + '">' + icon(t.icon, { size: 'sm' }) + ' <span>' + esc(t.label) + '</span></button>';
    }).join('');

    var liveBar = isParty
      ? '<div class="cluster" style="gap:var(--space-2);margin-block-start:var(--space-2)">' +
        '<a class="btn btn--secondary btn--sm" href="' + hrefV2('shared/live-room.html?variant=group') + '">' + icon('volume', { size: 'sm' }) + ' Voice / video room ' + icon('arrow-right', { size: 'sm' }) + '</a>' +
        '</div>'
      : '';

    var head =
      '<div class="cluster" style="justify-content:space-between;align-items:flex-start;gap:var(--space-2)">' +
      '<div class="cluster" style="gap:var(--space-2);align-items:center">' +
      '<span class="conv-avatar" aria-hidden="true">' + esc(isParty ? initialsOf(c.title.replace(/\s/g, '')) : initialsOf(c.with || c.title)) + '</span>' +
      '<div class="stack" style="gap:0"><h1 style="font-size:var(--text-lg);color:var(--gov-fg);margin:0">' + esc(c.title) + '</h1>' +
      '<span class="gloss">' + (isParty ? icon('users', { size: 'sm' }) + ' Party · ' + (c.members != null ? c.members + ' people' : (c.participants || []).length + ' people') + (c.ephemeral ? ' · temporary' : '') : icon('user', { size: 'sm' }) + ' Direct message') + '</span></div>' +
      '</div>' +
      (c.live ? '<span class="pill pill--live"><span class="dotlive" aria-hidden="true"></span>live now</span>' : '') +
      '</div>' + liveBar;

    var members = isParty
      ? '<section class="card card--inset" aria-labelledby="mem-h"><h2 id="mem-h" style="font-size:var(--text-base)">' + icon('users', { size: 'sm' }) + ' In this party</h2>' +
        '<div class="cluster" style="gap:var(--space-2);flex-wrap:wrap">' +
        (c.participants || []).map(function (h) { return '<span class="party-chip">' + esc(nameOf(h)) + '</span>'; }).join('') + '</div>' +
        '<p class="gloss" style="margin-block-start:var(--space-2)">Anyone here can add or leave at any time. When the last person leaves, the party is gone.</p>' +
        '<div class="cluster" style="margin-block-start:var(--space-2)"><a class="btn btn--ghost btn--sm" href="' + hrefV2('organizations/org-registry.html#register-h') + '">' + icon('building', { size: 'sm' }) + ' Make this a standing organization</a></div></section>'
      : '';

    return '<section class="card msg-thread-card">' + head +
      '<div class="msg-thread" data-msg-thread>' + bubbles + '</div>' +
      '<div class="msg-composer">' +
      '<div class="msg-toolkit">' + toolkit + '</div>' +
      '<div class="cluster" style="gap:var(--space-2)">' +
      '<input class="field-input" id="msg-input" type="text" placeholder="Write a message…" autocomplete="off" />' +
      '<button type="button" class="btn btn--primary btn--sm" id="msg-send">' + icon('arrow-right', { size: 'sm' }) + ' Send</button>' +
      '</div><p class="gloss" style="margin:0">Simulated — nothing leaves this mockup.</p></div>' +
      '</section>' + members +
      '<p style="margin-block-start:var(--space-3)"><a href="' + hrefV2('groups/groups-home.html') + '">' + icon('arrow-right', { size: 'sm', cls: 'icon--directional' }) + ' All messages</a></p>';
  }

  /* ---- wiring (simulated) ---------------------------------------------- */
  function wire(root) {
    root = root || document;
    var threadEl = root.querySelector('[data-msg-thread]');
    var input = root.querySelector('#msg-input');
    function append(html) {
      if (!threadEl) return;
      var div = document.createElement('div');
      div.innerHTML = html;
      threadEl.appendChild(div.firstChild);
      threadEl.scrollTop = threadEl.scrollHeight;
    }
    function send() {
      if (!input || !input.value.trim()) return;
      var now = new Date();
      var hh = now.getHours(), mm = ('0' + now.getMinutes()).slice(-2);
      append('<div class="msg-bubble msg-bubble--mine"><span class="msg-text">' + esc(input.value.trim()) + '</span><span class="msg-when">' + hh + ':' + mm + '</span></div>');
      input.value = '';
      S().announce('Message sent (simulated).');
    }
    var sendBtn = root.querySelector('#msg-send');
    if (sendBtn) sendBtn.addEventListener('click', send);
    if (input) input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); send(); } });

    root.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('[data-tool]') : null;
      if (!b) return;
      var t = toolByKey(b.getAttribute('data-tool'));
      if (b.getAttribute('data-tool') === 'text') { if (input) input.focus(); return; }
      append('<div class="msg-bubble msg-bubble--mine"><span class="msg-attach">' + icon(t.icon, { size: 'sm' }) + ' ' + esc(t.label) + ' attached</span><span class="msg-when">now</span></div>');
      S().announce(t.label + ' attached (simulated).');
    });
  }

  CGA.messagesV2 = { inbox: inbox, thread: thread, wire: wire };
})();
