/* ============================================================================
   CGA MOCKUPS — demo-state.js
   Demo state {role, persona, jurisdiction, locale, dir, scenario} shared by
   every page. Load FIRST, in <head>, so lang/dir land on <html> before paint.

   Persistence model (file://-safe):
     defaults  <-  localStorage (best effort; may throw on file://)
               <-  URL query params (always win; every state is linkable)
   URL is the durable channel — shell.js rewrites internal links through
   CGA.state.link() so state travels across pages even where storage is blocked.
   ============================================================================ */
(function () {
  'use strict';
  window.CGA = window.CGA || {};

  var STORE_KEY = 'cga-demo-state-v1';

  var DEFAULTS = {
    role: 'R-04',
    persona: 'amara-okafor',
    jurisdiction: 'usa-5-plaza-midwood',
    locale: 'en',
    dir: 'auto', // 'auto' resolves from locale; 'rtl'/'ltr' force (demo-bar override)
    scenario: {
      election: 'approval',   // approval | ranked | certifying
      emergency: false,
      challenge: false,
      quorumFails: false,
      bicameral: false,
      countbackFailed: false,
      restoration: false,
      unionDrill: false
    }
  };

  /* The scenario-flag vocabulary above is FROZEN (Stage 0). Flow pages bake
     these keys into deep links from Stage 1 — extend, never rename. */

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  function readStorage() {
    try {
      var raw = window.localStorage.getItem(STORE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  function writeStorage(state) {
    try { window.localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) { /* file:// may block */ }
  }

  function parseScenario(str) {
    var sc = {};
    String(str || '').split(',').forEach(function (pair) {
      if (!pair) return;
      var kv = pair.split(':');
      var k = kv[0];
      var v = kv.length > 1 ? kv[1] : '1';
      if (k === 'election') { sc.election = v; }
      else if (k in DEFAULTS.scenario) { sc[k] = (v === '1' || v === 'true'); }
    });
    return sc;
  }

  function serializeScenario(sc) {
    var parts = [];
    if (sc.election && sc.election !== DEFAULTS.scenario.election) parts.push('election:' + sc.election);
    Object.keys(DEFAULTS.scenario).forEach(function (k) {
      if (k === 'election') return;
      if (sc[k]) parts.push(k + ':1');
    });
    return parts.join(',');
  }

  function fromUrl() {
    var out = {};
    try {
      var p = new URLSearchParams(window.location.search);
      if (p.get('role')) out.role = p.get('role');
      if (p.get('persona')) out.persona = p.get('persona');
      if (p.get('jur')) out.jurisdiction = p.get('jur');
      if (p.get('locale')) out.locale = p.get('locale');
      if (p.get('dir')) out.dir = p.get('dir');
      if (p.get('sc') !== null && p.get('sc') !== undefined) out.scenario = parseScenario(p.get('sc'));
    } catch (e) { /* very old engines */ }
    return out;
  }

  function merge(base, patch) {
    var out = clone(base);
    Object.keys(patch || {}).forEach(function (k) {
      if (k === 'scenario') {
        out.scenario = out.scenario || {};
        Object.keys(patch.scenario || {}).forEach(function (sk) { out.scenario[sk] = patch.scenario[sk]; });
      } else {
        out[k] = patch[k];
      }
    });
    return out;
  }

  var state = merge(merge(DEFAULTS, readStorage() || {}), fromUrl());
  var listeners = [];

  function resolvedDir(s) {
    if (s.dir === 'rtl' || s.dir === 'ltr') return s.dir;
    return s.locale === 'ar' ? 'rtl' : 'ltr';
  }

  function applyToHtml() {
    var el = document.documentElement;
    el.setAttribute('lang', state.locale === 'en-XA' ? 'en' : state.locale);
    el.setAttribute('dir', resolvedDir(state));
  }

  function urlParams(s, overrides) {
    var eff = merge(s, overrides || {});
    var p = new URLSearchParams();
    if (eff.role !== DEFAULTS.role) p.set('role', eff.role);
    if (eff.persona !== DEFAULTS.persona) p.set('persona', eff.persona);
    if (eff.jurisdiction !== DEFAULTS.jurisdiction) p.set('jur', eff.jurisdiction);
    if (eff.locale !== DEFAULTS.locale) p.set('locale', eff.locale);
    if (eff.dir !== DEFAULTS.dir) p.set('dir', eff.dir);
    var sc = serializeScenario(eff.scenario || {});
    if (sc) p.set('sc', sc);
    return p;
  }

  function mirrorUrl() {
    try {
      var p = urlParams(state);
      var qs = p.toString();
      var url = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
      window.history.replaceState(null, '', url);
    } catch (e) { /* file:// in some engines */ }
  }

  function notify(changed) {
    applyToHtml();
    listeners.forEach(function (fn) {
      try { fn(clone(state), changed); } catch (e) { if (window.console) console.error(e); }
    });
    try {
      document.dispatchEvent(new CustomEvent('cga:statechange', { detail: { state: clone(state), changed: changed } }));
    } catch (e) { /* CustomEvent ctor unavailable */ }
  }

  window.CGA.state = {
    DEFAULTS: clone(DEFAULTS),

    get: function (key) { return clone(state)[key]; },
    getAll: function () { return clone(state); },
    resolvedDir: function () { return resolvedDir(state); },

    set: function (patch) {
      var changed = Object.keys(patch || {});
      state = merge(state, patch);
      writeStorage(state);
      mirrorUrl();
      notify(changed);
    },

    reset: function () {
      state = clone(DEFAULTS);
      writeStorage(state);
      mirrorUrl();
      notify(['*']);
    },

    subscribe: function (fn) {
      listeners.push(fn);
      return function () { listeners = listeners.filter(function (f) { return f !== fn; }); };
    },

    /* Build an internal link that carries the current demo state (plus
       overrides) as query params — the cross-page channel on file://. */
    link: function (href, overrides) {
      var parts = String(href).split('#');
      var base = parts[0].split('?')[0];
      var qs = urlParams(state, overrides).toString();
      return base + (qs ? '?' + qs : '') + (parts[1] ? '#' + parts[1] : '');
    }
  };

  applyToHtml();
  mirrorUrl();
})();
