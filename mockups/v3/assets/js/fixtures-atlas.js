/* ============================================================================
   fixtures-atlas.js — authored data for THE ATLAS (the heartbeat dashboard)
   Attaches CGA.fixtures.atlas. Loads AFTER fixtures.js / fixtures-v2.js
   (and is fine alongside fixtures-econ.js / fixtures-operator.js).

   WHY THIS FILE EXISTS: the mockup world carries population, reach, institution,
   org and economy data — but NO geographic coordinates anywhere. The Atlas world
   map needs approximate lat/long for nodes, opt-in people, organizations and
   places, plus a simplified land outline to draw a recognisable Earth offline.
   Everything here is DELIBERATELY APPROXIMATE:
     - people-on-map is OPT-IN, a single pixel, snapped to a coarse 0.5° grid —
       never a real coordinate, never re-identifiable (mirrors the constitution's
       private-location guarantee: a ping is private; only a coarse opt-in dot
       ever shows).
     - node/org/place points are city-level only.
   Land outlines are simplified public-domain geography (continents as coarse
   polygons), drawn as a dotted mask — orientation, not cartography.
   ============================================================================ */
(function () {
  'use strict';
  var F = window.CGA && window.CGA.fixtures;
  if (!F) { if (window.console) console.error('fixtures-atlas: fixtures.js must load first'); return; }

  /* deterministic PRNG (mulberry32) so the people layer is STABLE across loads */
  function rng(seed) {
    return function () {
      seed |= 0; seed = seed + 0x6D2B79F5 | 0;
      var t = Math.imul(seed ^ seed >>> 15, 1 | seed);
      t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
      return ((t ^ t >>> 14) >>> 0) / 4294967296;
    };
  }
  function gaussish(r) { return (r() + r() + r() - 1.5) / 1.5; } /* ~N(0,1)-ish in [-1,1] */

  /* ---------------------------------------------------------------- LAND
     Coarse continent rings as [lng,lat]. Drawn filled + dotted; simplified for
     orientation only. Equirectangular: x = lng+180, y = 90-lat. */
  var LAND = [
    /* North America */
    [[-168,66],[-160,70],[-140,70],[-122,71],[-100,70],[-82,73],[-70,67],[-64,60],[-78,62],[-80,52],[-70,47],[-66,44],[-70,41],[-74,40],[-76,35],[-81,31],[-80,25],[-84,30],[-90,29],[-97,26],[-97,21],[-91,19],[-88,16],[-83,9],[-78,8],[-83,14],[-94,16],[-105,20],[-114,28],[-117,33],[-122,38],[-124,43],[-124,48],[-130,55],[-138,58],[-150,59],[-162,62],[-168,66]],
    /* Greenland */
    [[-46,60],[-52,66],[-52,72],[-42,77],[-28,76],[-19,72],[-23,68],[-32,62],[-42,60],[-46,60]],
    /* South America */
    [[-78,8],[-72,11],[-62,10],[-52,5],[-50,0],[-44,-2],[-37,-6],[-35,-8],[-39,-14],[-44,-23],[-49,-27],[-54,-34],[-58,-39],[-63,-42],[-66,-48],[-70,-52],[-66,-55],[-72,-53],[-74,-48],[-72,-40],[-71,-30],[-70,-22],[-71,-17],[-77,-13],[-81,-5],[-81,1],[-78,5],[-78,8]],
    /* Europe (mainland + Scandinavia) */
    [[-9,38],[-9,43],[-1,44],[-2,48],[-5,49],[1,51],[-2,53],[-5,58],[3,59],[6,62],[11,64],[16,67],[24,70],[29,71],[31,67],[28,62],[31,59],[27,56],[21,55],[19,52],[14,54],[12,47],[18,45],[14,44],[19,42],[16,40],[19,40],[13,44],[8,44],[3,43],[-2,40],[-6,37],[-9,38]],
    /* UK + Ireland */
    [[-10,52],[-8,55],[-5,58],[-2,58],[1,53],[-2,51],[-6,50],[-10,52]],
    /* Africa */
    [[-16,15],[-17,21],[-13,28],[-9,32],[-5,36],[10,37],[11,34],[20,33],[25,32],[32,31],[34,28],[37,22],[43,12],[51,12],[51,7],[48,2],[42,-1],[40,-8],[35,-15],[33,-22],[28,-30],[25,-34],[19,-35],[16,-29],[13,-18],[9,-2],[8,4],[-1,5],[-8,5],[-13,9],[-16,15]],
    /* Asia (simplified, includes India + SE peninsula) */
    [[28,42],[40,40],[46,38],[50,42],[58,40],[60,48],[68,55],[60,62],[56,68],[68,72],[82,73],[100,76],[125,73],[142,72],[160,70],[180,68],[178,62],[162,60],[150,58],[140,52],[135,46],[140,42],[130,40],[122,38],[122,30],[118,24],[110,20],[108,12],[104,1],[100,6],[98,12],[92,20],[88,21],[80,15],[77,8],[73,18],[68,24],[60,25],[57,27],[50,30],[46,36],[36,38],[30,40],[28,42]],
    /* Japan */
    [[130,31],[133,34],[137,35],[140,37],[142,40],[140,42],[138,38],[134,34],[131,31],[130,31]],
    /* SE Asian islands / Indonesia */
    [[96,5],[102,2],[108,-3],[116,-8],[124,-9],[133,-7],[142,-8],[150,-9],[141,-3],[130,-1],[118,-3],[108,-4],[100,3],[96,5]],
    /* Australia */
    [[114,-22],[113,-26],[115,-30],[123,-34],[131,-32],[138,-35],[147,-38],[151,-34],[153,-28],[148,-20],[142,-11],[136,-12],[130,-13],[124,-16],[118,-20],[114,-22]],
    /* New Zealand */
    [[167,-46],[170,-44],[173,-41],[175,-37],[178,-38],[174,-41],[171,-45],[167,-46]]
  ];

  /* --------------------------------------------------------- PEOPLE (opt-in)
     City anchors {name, lat, lng, w(=pixels), s(=spread°)}. Each spawns w
     coarse, jittered, opt-in single-pixel dots. NYC is the home jurisdiction so
     it carries the densest cluster; the rest sketch a planet that has begun to
     federate. ~520 dots total — every one opt-in and grid-snapped. */
  var ANCHORS = [
    { name: 'New York', lat: 40.7, lng: -74.0, w: 96, s: 1.4 },
    { name: 'San Marino', lat: 43.94, lng: 12.45, w: 16, s: 0.5 },
    { name: 'London', lat: 51.5, lng: -0.12, w: 34, s: 2.2 },
    { name: 'Paris', lat: 48.86, lng: 2.35, w: 22, s: 1.8 },
    { name: 'Berlin', lat: 52.52, lng: 13.4, w: 18, s: 2 },
    { name: 'Lagos', lat: 6.52, lng: 3.38, w: 26, s: 2.4 },
    { name: 'Nairobi', lat: -1.29, lng: 36.82, w: 16, s: 2.2 },
    { name: 'Cairo', lat: 30.05, lng: 31.24, w: 20, s: 2 },
    { name: 'Mumbai', lat: 19.08, lng: 72.88, w: 32, s: 2.4 },
    { name: 'Delhi', lat: 28.61, lng: 77.21, w: 28, s: 2.4 },
    { name: 'Tokyo', lat: 35.68, lng: 139.69, w: 30, s: 2 },
    { name: 'Seoul', lat: 37.57, lng: 126.98, w: 18, s: 1.6 },
    { name: 'Shanghai', lat: 31.23, lng: 121.47, w: 28, s: 2.2 },
    { name: 'Jakarta', lat: -6.21, lng: 106.85, w: 22, s: 2.2 },
    { name: 'Sao Paulo', lat: -23.55, lng: -46.63, w: 26, s: 2.6 },
    { name: 'Mexico City', lat: 19.43, lng: -99.13, w: 24, s: 2.2 },
    { name: 'Los Angeles', lat: 34.05, lng: -118.24, w: 26, s: 2.4 },
    { name: 'Chicago', lat: 41.88, lng: -87.63, w: 18, s: 2 },
    { name: 'Toronto', lat: 43.65, lng: -79.38, w: 16, s: 1.8 },
    { name: 'Sydney', lat: -33.87, lng: 151.21, w: 18, s: 2 },
    { name: 'Johannesburg', lat: -26.2, lng: 28.05, w: 16, s: 2.2 },
    { name: 'Buenos Aires', lat: -34.6, lng: -58.38, w: 16, s: 2 },
    { name: 'Bogota', lat: 4.71, lng: -74.07, w: 14, s: 2 },
    { name: 'Istanbul', lat: 41.01, lng: 28.98, w: 16, s: 1.8 },
    { name: 'Antarctic base', lat: -75.0, lng: 0.0, w: 3, s: 0.6 }
  ];

  var people = (function () {
    var r = rng(20310628), out = [], seen = {};
    ANCHORS.forEach(function (a) {
      for (var i = 0; i < a.w; i++) {
        var lng = a.lng + gaussish(r) * a.s;
        var lat = a.lat + gaussish(r) * a.s * 0.8;
        lng = Math.round(lng * 2) / 2;            /* snap to coarse 0.5° grid */
        lat = Math.round(lat * 2) / 2;            /* — privacy, never precise */
        var k = lng + ',' + lat;
        if (seen[k]) continue;                    /* one pixel per coarse cell  */
        seen[k] = 1; out.push([lng, lat]);
      }
    });
    return out;
  })();

  /* ------------------------------------------------------------------ NODES
     The mesh: operator-run servers. Grounded in the 3 fixture peers + the self
     instance (manhattan.cga.example), extended with city-level coordinates,
     residents served, uptime, and an OPERATOR (a resident — every operator is
     also a person, so their public profile is their citizen profile). Running a
     node confers NO vote and no seat — it is infrastructure, off the
     constitutional plane. */
  var nodes = [
    { key: 'manhattan', name: 'manhattan.cga.example', label: 'Manhattan node', lat: 40.71, lng: -74.01,
      relation: 'self', status: 'authoritative', jurisdiction: 'usa-3-new-york-county', residents: 41200,
      uptimePct: 99.96, version: 'cv-2031.6', syncSeq: 84113, role: 'Identity Broker',
      operator: 'amara-okafor', operatorHandle: 'manhattan-op', self: true },
    { key: 'earth-root', name: 'earth.cga.example', label: 'Box A — Earth root', lat: 46.2, lng: 6.14,
      relation: 'host', status: 'healthy', jurisdiction: 'earth-0-earth', residents: 70600,
      uptimePct: 99.99, version: 'cv-2031.6', syncSeq: 88412, role: 'Record Keeper',
      operator: 'kwame-mensah', operatorHandle: 'earth-root-op' },
    { key: 'brooklyn', name: 'brooklyn.cga.example', label: 'Brooklyn mirror', lat: 40.65, lng: -73.95,
      relation: 'mirror', status: 'syncing', jurisdiction: 'usa-3-kings-county', residents: 12400,
      uptimePct: 99.7, version: 'cv-2031.6', syncSeq: 88390, role: 'Record Keeper',
      operator: 'marcus-chen', operatorHandle: 'brooklyn-op' },
    { key: 'aurelia', name: 'aurelia.cga.example', label: 'Aurelia (sovereign)', lat: 52.37, lng: 4.9,
      relation: 'sovereign', status: 'border-settled', jurisdiction: null, residents: 5300,
      uptimePct: 98.9, version: 'cv-2031.5', syncSeq: 80244, role: 'Archivist',
      operator: 'ingrid-solberg', operatorHandle: 'aurelia-op' },
    { key: 'san-marino', name: 'titano.sm.cga.example', label: 'Monte Titano node', lat: 43.94, lng: 12.45,
      relation: 'peer', status: 'healthy', jurisdiction: 'smr-1-san-marino', residents: 1900,
      uptimePct: 99.4, version: 'cv-2031.6', syncSeq: 88401, role: 'Record Keeper',
      operator: 'yuki-tanaka', operatorHandle: 'titano-op' },
    { key: 'lagos', name: 'lagos.cga.example', label: 'Lagos node', lat: 6.52, lng: 3.38,
      relation: 'peer', status: 'healthy', jurisdiction: null, residents: 3100,
      uptimePct: 99.1, version: 'cv-2031.6', syncSeq: 88377, role: 'Social Moderator',
      operator: 'asha-okonkwo', operatorHandle: 'lagos-op' },
    { key: 'mumbai', name: 'mumbai.cga.example', label: 'Mumbai node', lat: 19.08, lng: 72.88,
      relation: 'peer', status: 'healthy', jurisdiction: null, residents: 4200,
      uptimePct: 99.5, version: 'cv-2031.6', syncSeq: 88369, role: 'Record Keeper',
      operator: 'priya-sharma', operatorHandle: 'mumbai-op' },
    { key: 'sao-paulo', name: 'sp.cga.example', label: 'Sao Paulo node', lat: -23.55, lng: -46.63,
      relation: 'peer', status: 'syncing', jurisdiction: null, residents: 2600,
      uptimePct: 98.6, version: 'cv-2031.6', syncSeq: 88210, role: 'Archivist',
      operator: 'sofia-petrova', operatorHandle: 'sp-op' },
    { key: 'tokyo', name: 'tokyo.cga.example', label: 'Tokyo node', lat: 35.68, lng: 139.69,
      relation: 'peer', status: 'healthy', jurisdiction: null, residents: 3400,
      uptimePct: 99.8, version: 'cv-2031.6', syncSeq: 88404, role: 'Identity Broker',
      operator: 'fatima-al-rashid', operatorHandle: 'tokyo-op' },
    { key: 'mcmurdo', name: 'base.aq.cga.example', label: 'Antarctic research node', lat: -75.0, lng: 0.0,
      relation: 'peer', status: 'degraded', jurisdiction: null, residents: 120,
      uptimePct: 91.2, version: 'cv-2031.5', syncSeq: 79980, role: 'Record Keeper',
      operator: 'lena-novak', operatorHandle: 'aq-op' }
  ];

  /* ----------------------------------------------------------------- ORGS
     Organisations that keep a public pin on the map. Anchored to the orgs in the
     world fixture; coordinates city-level only. */
  var orgs = [
    { id: 'commons-party', name: 'The Commons Party', type: 'political_party', lat: 40.74, lng: -73.99 },
    { id: 'green-horizon', name: 'Green Horizon Alliance', type: 'political_party', lat: 40.68, lng: -73.97 },
    { id: 'five-boroughs-chamber', name: 'Five Boroughs Chamber of Commerce', type: 'business', lat: 40.71, lng: -74.01 },
    { id: 'manhattan-water-power', name: 'Manhattan Water & Power', type: 'common_good_corp', lat: 40.78, lng: -73.97 },
    { id: 'hudson-mutual-aid', name: 'Hudson Mutual Aid', type: 'nonprofit', lat: 40.73, lng: -74.0 },
    { id: 'bluefin-logistics', name: 'Bluefin Logistics', type: 'business', lat: 40.64, lng: -74.02 }
  ];

  /* ---------------------------------------------------------------- PLACES
     Jurisdictions that keep a public profile + pin. */
  var places = [
    { slug: 'usa-3-new-york-county', name: 'New York County', tier: 3, lat: 40.78, lng: -73.97 },
    { slug: 'usa-3-kings-county', name: 'Kings County', tier: 3, lat: 40.65, lng: -73.95 },
    { slug: 'usa-3-queens-county', name: 'Queens County', tier: 3, lat: 40.73, lng: -73.79 },
    { slug: 'usa-3-bronx-county', name: 'Bronx County', tier: 3, lat: 40.84, lng: -73.87 },
    { slug: 'usa-3-richmond-county', name: 'Richmond County', tier: 3, lat: 40.58, lng: -74.15 },
    { slug: 'usa-2-new-york', name: 'New York', tier: 2, lat: 42.95, lng: -75.5 },
    { slug: 'smr-1-san-marino', name: 'San Marino', tier: 1, lat: 43.94, lng: 12.46 },
    { slug: 'smr-2-serravalle', name: 'Serravalle', tier: 2, lat: 43.97, lng: 12.48 }
  ];

  /* ---------------------------------------------------------------- GROWTH
     Authored 12-period (monthly) series for the trends the fixtures don't carry
     a history of. Each is a plain array of values, oldest → newest; the page
     draws them with the shared sparkPath() helper. The reach trend is REAL
     (legitimacy snapshots) and read separately by the page. */
  var growth = {
    monthsBack: 12,
    verifiedResidents: [9200, 13800, 18400, 23100, 28700, 34900, 41200, 48600, 55400, 61900, 67100, 70600],
    nodes: [1, 1, 2, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    jurisdictions: [1, 2, 3, 4, 5, 6, 7, 8, 8, 9, 10, 10],
    candidates: [0, 0, 2, 4, 6, 9, 12, 15, 18, 20, 22, 24],
    organizations: [1, 2, 3, 4, 5, 6, 7, 7, 8, 8, 9, 9],
    onMapOptIns: [40, 80, 130, 190, 250, 300, 360, 410, 450, 480, 505, people.length]
  };

  /* The four map layers + the privacy contract for the people layer. */
  var layers = [
    { key: 'nodes', label: 'Nodes', icon: 'globe', what: 'Operator-run servers keeping the mesh alive.' },
    { key: 'people', label: 'People', icon: 'users', what: 'Residents who chose to appear — one approximate pixel each.' },
    { key: 'orgs', label: 'Organizations', icon: 'building', what: 'Organizations that keep a public pin.' },
    { key: 'places', label: 'Places', icon: 'map-pin', what: 'Jurisdictions that keep a public profile.' }
  ];

  F.atlas = {
    land: LAND,
    anchors: ANCHORS,
    people: people,
    nodes: nodes,
    orgs: orgs,
    places: places,
    growth: growth,
    layers: layers,
    privacy: {
      note: 'Appearing on the map is opt-in. A person shows as a single pixel snapped to a coarse grid — an approximate place, never a real coordinate, never a name. Where you actually are is private, like a ballot.',
      rails: [
        'Opt-in only — nobody is placed on the map without choosing to be',
        'A single pixel, snapped to a coarse grid — approximate, never precise',
        'No name, no link from a person-pixel — identity stays private',
        'Being on the map confers no vote, no seat, no advantage'
      ]
    }
  };
}());
