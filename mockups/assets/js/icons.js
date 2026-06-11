/* ============================================================================
   CGA MOCKUPS — icons.js
   Lucide-style inline SVG symbol set (24x24, stroke-width 2, rounded caps) —
   the design-system README's flagged substitution. Carried as a same-document
   <symbol> sprite injected once by shell.js and referenced via
   <use href="#i-NAME"> — works on file:// and offline (no runtime CDN).
   Standalone copies live in assets/img/icons/ for no-JS / handoff use.
   Production may swap to the lucide package — see MANIFEST.md.
   ============================================================================ */
(function () {
  'use strict';
  window.CGA = window.CGA || {};

  var P = {
    'home':           '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
    'search':         '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'bell':           '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'check':          '<path d="M20 6 9 17l-5-5"/>',
    'x':              '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'plus':           '<path d="M5 12h14"/><path d="M12 5v14"/>',
    'minus':          '<path d="M5 12h14"/>',
    'info':           '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'alert-triangle': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'chevron-down':   '<path d="m6 9 6 6 6-6"/>',
    'chevron-right':  '<path d="m9 18 6-6-6-6"/>',
    'chevron-left':   '<path d="m15 18-6-6 6-6"/>',
    'arrow-right':    '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'arrow-up':       '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
    'arrow-down':     '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
    'external-link':  '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'menu':           '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
    'grip-vertical':  '<circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/>',
    'globe':          '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    'lock':           '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'clock':          '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'map-pin':        '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
    'map':            '<path d="M9 3 4 5v16l5-2 6 2 5-2V3l-5 2-6-2z"/><path d="M9 3v16"/><path d="M15 5v16"/>',
    'users':          '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user':           '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'file-text':      '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
    'scale':          '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
    'landmark':       '<path d="M3 22h18"/><path d="M6 18v-7"/><path d="M10 18v-7"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M12 2 3 7v2h18V7z"/>',
    'building':       '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M8 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/>',
    'briefcase':      '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>',
    'shield':         '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/>',
    'vote':           '<path d="m9 12 2 2 4-4"/><path d="M5 19V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v12"/><path d="M22 19H2"/>',
    'languages':      '<path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>',
    'sliders':        '<path d="M21 4h-7"/><path d="M10 4H3"/><path d="M21 12h-9"/><path d="M8 12H3"/><path d="M21 20h-5"/><path d="M12 20H3"/><path d="M14 2v4"/><path d="M8 10v4"/><path d="M16 18v4"/>',
    'book-open':      '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    'award':          '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
    'refresh-cw':     '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/>',
    'bar-chart':      '<path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/>'
  };

  /* Icons whose geometry encodes reading direction — shell.icon() adds
     .icon--directional so [dir="rtl"] flips them (a11y doc: mirror directional
     icons; never mirror search/settings/logos/clocks). */
  var DIRECTIONAL = ['chevron-right', 'chevron-left', 'arrow-right', 'external-link', 'book-open'];

  function sprite() {
    var out = '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">';
    Object.keys(P).forEach(function (name) {
      out += '<symbol id="i-' + name + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + P[name] + '</symbol>';
    });
    return out + '</svg>';
  }

  window.CGA.icons = {
    paths: P,
    names: Object.keys(P),
    directional: DIRECTIONAL,
    has: function (n) { return Object.prototype.hasOwnProperty.call(P, n); },
    spriteMarkup: sprite
  };
})();
