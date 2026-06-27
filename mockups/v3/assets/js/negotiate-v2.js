/* negotiate-v2.js — the negotiation interface (v3).
   ----------------------------------------------------------------------------
   One reusable interface for any negotiated document — an instrument of
   AGREEMENT or a BILL. Parties draft clauses, propose REDLINES (edit / add /
   strike, in their own custom language), talk it through in a back-and-forth
   thread, accept or reject each change, then agree and submit.

   Public surface:
     CGA.negotiateV2.mount(containerEl, doc, opts)

   doc (mutated in place as the negotiation proceeds):
     { kind:'agreement'|'bill', title, parties:[{name,side,role}], youSide,
       clauses:[{id,heading,text}],
       redlines:[{id,by,side,clauseId|null,kind:'edit'|'add'|'strike',text,rationale,status}],
       comments:[{by,side,when,text}], status }

   Authored jargon-light. Simulated — nothing leaves the mockup. */
(function () {
  'use strict';
  window.CGA = window.CGA || {};
  var seq = 100;

  function S() { return CGA.shellV2; }
  function esc(s) { return S().esc(s); }
  function icon(n, o) { return S().icon(n, o); }
  function pill(t, l, tip) { return S().pill(t, l, tip); }
  function badge(t, l, ic) { return S().badge(t, l, ic); }

  function sideName(doc, side) {
    var p = doc.parties.filter(function (x) { return x.side === side; })[0];
    return p ? p.name : (side === 'a' ? 'Side A' : 'Side B');
  }
  function otherSide(s) { return s === 'a' ? 'b' : 'a'; }
  function clauseById(doc, id) { return doc.clauses.filter(function (c) { return c.id === id; })[0]; }
  function statusPill(st) {
    var m = { drafting: ['info', 'Drafting'], negotiating: ['warning', 'In negotiation'], agreed: ['success', 'Agreed'], submitted: ['success', 'Submitted'] };
    var x = m[st] || ['info', st]; return pill(x[0], x[1], '');
  }

  function redlinesFor(doc, clauseId) {
    return doc.redlines.filter(function (r) { return r.clauseId === clauseId; });
  }

  function redlineHtml(doc, r) {
    var mine = r.side === doc.youSide;
    var KIND = { edit: 'Edit', add: 'Add', strike: 'Strike' };
    var actions = '';
    if (r.status === 'pending') {
      actions = mine
        ? '<button type="button" class="btn btn--ghost btn--sm" data-neg="withdraw" data-id="' + r.id + '">Withdraw</button>'
        : '<button type="button" class="btn btn--secondary btn--sm" data-neg="accept" data-id="' + r.id + '">Accept</button>' +
          '<button type="button" class="btn btn--ghost btn--sm" data-neg="reject" data-id="' + r.id + '">Reject</button>';
    }
    var tone = r.status === 'accepted' ? 'success' : (r.status === 'rejected' ? 'danger' : 'warning');
    return '<div class="rl-item rl-' + esc(r.status) + '">' +
      '<div class="cluster" style="justify-content:space-between;align-items:baseline">' +
      '<span class="eyebrow">' + esc(KIND[r.kind] || r.kind) + ' · ' + esc(sideName(doc, r.side)) + '</span>' +
      badge(tone, r.status) + '</div>' +
      (r.kind === 'strike' ? '<p class="rl-text rl-strike">' + esc(r.text) + '</p>' : '<p class="rl-text">' + esc(r.text) + '</p>') +
      (r.rationale ? '<p class="gloss">' + esc(r.rationale) + '</p>' : '') +
      (actions ? '<div class="cluster" style="gap:var(--space-2)">' + actions + '</div>' : '') +
      '</div>';
  }

  function clauseHtml(doc, c) {
    var rls = redlinesFor(doc, c.id).map(function (r) { return redlineHtml(doc, r); }).join('');
    return '<div class="neg-clause" id="neg-clause-' + esc(c.id) + '">' +
      '<h4 class="neg-clause-head">' + esc(c.heading) + '</h4>' +
      '<p class="neg-clause-text">' + esc(c.text) + '</p>' +
      (rls ? '<div class="rl-list">' + rls + '</div>' : '') +
      '</div>';
  }

  function render(doc, opts) {
    opts = opts || {};
    var kindLabel = doc.kind === 'bill' ? 'bill' : 'agreement';
    var clauseOpts = doc.clauses.map(function (c) { return '<option value="' + esc(c.id) + '">' + esc(c.heading) + '</option>'; }).join('') +
      '<option value="__new">A new clause</option>';

    var parties = doc.parties.map(function (p) {
      return '<span class="party-chip">' + esc(p.name) + ' <span class="party-role">' + esc(p.role || (p.side === doc.youSide ? 'you' : 'counterparty')) + '</span></span>';
    }).join('');

    var clauses = doc.clauses.map(function (c) { return clauseHtml(doc, c); }).join('');
    var newRls = doc.redlines.filter(function (r) { return r.clauseId == null; });
    if (newRls.length) {
      clauses += '<div class="neg-clause neg-clause--new"><h4 class="neg-clause-head">Proposed new clauses</h4>' +
        '<div class="rl-list">' + newRls.map(function (r) { return redlineHtml(doc, r); }).join('') + '</div></div>';
    }

    var comments = doc.comments.length
      ? doc.comments.map(function (m) {
          return '<div class="neg-comment"><span class="neg-comment-who">' + esc(sideName(doc, m.side)) + '</span>' +
            '<span class="neg-comment-text">' + esc(m.text) + '</span>' +
            '<span class="neg-comment-when">' + esc(m.when || 'just now') + '</span></div>';
        }).join('')
      : '<p class="gloss">No messages yet — start the back-and-forth below.</p>';

    var pending = doc.redlines.filter(function (r) { return r.status === 'pending'; }).length;
    var canSubmit = pending === 0 && doc.status !== 'submitted';
    var footer = doc.status === 'submitted'
      ? '<p class="lr-note">' + icon('check', { size: 'sm' }) + ' <span>This ' + kindLabel + ' has been submitted.</span></p>'
      : '<div class="cluster" style="gap:var(--space-2)">' +
        '<button type="button" class="btn btn--secondary" data-neg="agree">' + icon('check', { size: 'sm' }) + ' Agree on my side</button>' +
        '<button type="button" class="btn btn--primary"' + (canSubmit ? '' : ' disabled title="Resolve every open change first"') + ' data-neg="submit">' +
        icon('arrow-right', { size: 'sm' }) + ' ' + (doc.kind === 'bill' ? 'Submit to the floor' : 'Sign &amp; submit') + '</button>' +
        (pending ? '<span class="gloss">' + pending + ' change' + (pending === 1 ? '' : 's') + ' still open</span>' : '<span class="gloss">all changes resolved</span>') +
        '</div>';

    return '<div class="neg-wrap" data-neg-root>' +
      '<div class="cluster" style="justify-content:space-between;align-items:flex-start">' +
      '<span class="eyebrow">' + icon('file-text', { size: 'sm' }) + ' Negotiate this ' + kindLabel + '</span>' + statusPill(doc.status) + '</div>' +
      '<div class="agreement-parties" style="margin-block:var(--space-2)">' + parties + '</div>' +
      '<p class="gloss">You are negotiating as <strong>' + esc(sideName(doc, doc.youSide)) + '</strong>. Propose changes in your own words; the other side accepts, rejects, or counters. No clause can waive a right.</p>' +

      '<section class="neg-doc">' + clauses + '</section>' +

      '<details class="neg-propose"><summary>' + icon('plus', { size: 'sm' }) + ' Propose a change</summary>' +
      '<div class="stack" style="gap:var(--space-2);margin-block-start:var(--space-2)">' +
      '<div class="cluster" style="gap:var(--space-2)">' +
      '<div class="field" style="margin:0;flex:1 1 12rem"><label class="field-label" for="neg-target">Change</label>' +
      '<select class="select" id="neg-target">' + clauseOpts + '</select></div>' +
      '<div class="field" style="margin:0"><label class="field-label" for="neg-kind">How</label>' +
      '<select class="select" id="neg-kind"><option value="edit">Edit the language</option><option value="add">Add a clause</option><option value="strike">Strike it</option></select></div>' +
      '</div>' +
      '<div class="field" style="margin:0"><label class="field-label" for="neg-text">Your language</label>' +
      '<textarea class="field-input" id="neg-text" rows="2" placeholder="Write the change in your own words…"></textarea></div>' +
      '<div class="field" style="margin:0"><label class="field-label" for="neg-why">Why (optional)</label>' +
      '<input class="field-input" id="neg-why" type="text" placeholder="A short reason" /></div>' +
      '<div class="cluster"><button type="button" class="btn btn--secondary btn--sm" data-neg="propose">' + icon('plus', { size: 'sm' }) + ' Add this change</button></div>' +
      '</div></details>' +

      '<section class="neg-talk"><h3>Discussion</h3><div class="neg-comments" data-neg-comments>' + comments + '</div>' +
      '<div class="cluster" style="gap:var(--space-2);margin-block-start:var(--space-2)">' +
      '<input class="field-input" id="neg-msg" type="text" placeholder="Add to the discussion…" autocomplete="off" />' +
      '<button type="button" class="btn btn--secondary btn--sm" data-neg="comment">' + icon('arrow-right', { size: 'sm' }) + ' Send</button></div></section>' +

      '<div class="neg-footer">' + footer + '<p class="gloss" style="margin-block-start:var(--space-1)">Simulated — nothing leaves this mockup.</p></div>' +
      '</div>';
  }

  function mount(el, doc, opts) {
    if (!el) return;
    function paint() { el.innerHTML = render(doc, opts); }
    function findR(id) { return doc.redlines.filter(function (r) { return String(r.id) === String(id); })[0]; }
    paint();
    el.addEventListener('click', function (ev) {
      var b = ev.target.closest ? ev.target.closest('[data-neg]') : null;
      if (!b) return;
      var act = b.getAttribute('data-neg'), id = b.getAttribute('data-id');
      if (act === 'accept' || act === 'reject') {
        var r = findR(id); if (r) r.status = act === 'accept' ? 'accepted' : 'rejected';
        if (doc.status === 'drafting') doc.status = 'negotiating';
        S().announce('Change ' + (act === 'accept' ? 'accepted' : 'rejected') + '.'); paint();
      } else if (act === 'withdraw') {
        doc.redlines = doc.redlines.filter(function (r) { return String(r.id) !== String(id); });
        S().announce('Change withdrawn.'); paint();
      } else if (act === 'propose') {
        var target = el.querySelector('#neg-target').value;
        var kind = el.querySelector('#neg-kind').value;
        var text = (el.querySelector('#neg-text').value || '').trim();
        var why = (el.querySelector('#neg-why').value || '').trim();
        if (!text) { S().announce('Write the change first.'); return; }
        doc.redlines.push({ id: ++seq, by: sideName(doc, doc.youSide), side: doc.youSide, clauseId: target === '__new' ? null : target, kind: kind, text: text, rationale: why, status: 'pending' });
        doc.status = 'negotiating';
        S().announce('Change proposed.'); paint();
      } else if (act === 'comment') {
        var msg = (el.querySelector('#neg-msg').value || '').trim();
        if (!msg) return;
        doc.comments.push({ by: sideName(doc, doc.youSide), side: doc.youSide, when: 'just now', text: msg });
        paint();
      } else if (act === 'agree') {
        doc.status = 'agreed'; S().announce('You agreed on your side.'); paint();
      } else if (act === 'submit') {
        doc.status = 'submitted'; S().announce(doc.kind === 'bill' ? 'Submitted to the floor (simulated).' : 'Signed and submitted (simulated).'); paint();
      }
    });
  }

  CGA.negotiateV2 = { mount: mount };
})();
