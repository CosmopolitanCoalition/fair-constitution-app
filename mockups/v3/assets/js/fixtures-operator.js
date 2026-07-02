/* ============================================================================
   CGA MOCKUPS v2 — fixtures-operator.js  (the operator plane)
   AUGMENTS CGA.fixtures.v2 with CGA.fixtures.v2.op — the data for the OPERATOR
   PLANE: the people who run the boxes that keep the mesh alive. This plane is
   OFF the constitutional plane — its vocabulary is "capability", never "role",
   so it can never collide with the citizen R-01…R-30 system (the plane wall).

   Operators are an overlapping de-facto board, ANSWERABLE to the in-game
   government: below a seated legislature the operator board (anchored to the
   R-08 election-board kind, neutral + logged) holds relay authority; the moment
   a legislature seats itself, Meter B supermajority SUPERSEDES the operator
   board automatically (a pure function of facts, never a manual mode change).

   Grounded in the as-built code (Federation.vue, InstanceCapability,
   OperatorAccount, MeshGateService, PeerUpgradeAgreementService, the G-ID
   identity services, ModerationFlipService / LegalComplianceService) and the
   operator design docs. This is the infrastructure plane the citizen game runs
   ON — added so the coder can wire Phase A→G with these layers baked in.
   ============================================================================ */
(function () {
  'use strict';
  var F = window.CGA && window.CGA.fixtures, V2 = F && F.v2;
  if (!V2) { if (window.console) console.error('fixtures-operator: fixtures-v2.js must load first'); return; }

  /* The plane wall + the operator-as-board framing. */
  var plane = {
    wall: 'Operator-plane vocabulary is "capability", never "role" — it can never collide with the citizen role system. Operator accounts have no link to citizen users.',
    board: 'Operators are an overlapping de-facto board, answerable to the in-game government. Authority is earned by population and granted by the seated government — never self-asserted. Authority ≠ leadership.',
    supersession: 'Below a seated legislature the operator board (neutral + logged) relays rights-protection. The moment a legislature seats itself, the seated government supersedes the operator board — automatically, as a pure function of facts.',
    plainNote: 'Running a node is infrastructure, not a citizen privilege. It buys you no extra vote, no seat, no say in any constitutional act.'
  };

  /* state → plain pill (the operator-console simplification). */
  var STATE_PILL = {
    established: { pill: 'live', label: 'Active' },
    qualifiable: { pill: 'wait', label: 'Ready to turn on' },
    'needs-config': { pill: 'info', label: 'Needs setup' },
    requested: { pill: 'wait', label: 'Waiting for approval' },
    lapsed: { pill: 'closed', label: 'Stopped' }
  };

  /* the 9-channel capability substrate (the closed vocabulary). */
  var channels = [
    { slug: 'mesh.member', kind: 'self', what: 'The always-on base — be a member of the mesh.', state: 'established' },
    { slug: 'mirror', kind: 'self', what: 'Keep a complete, always-synced copy of the public record.', state: 'established' },
    { slug: 'etl', kind: 'self', what: 'Host the geodata archive (rasters, boundaries) for the network.', state: 'qualifiable' },
    { slug: 'broker.dns', kind: 'governed', what: 'Edit DNS under a domain — name peers (Meter C: affects a peer’s subtree).', state: 'needs-config', meterC: true },
    { slug: 'broker.tls', kind: 'governed', what: 'Issue Let’s Encrypt certs for peers (the proof behind the name).', state: 'needs-config' },
    { slug: 'client.serve', kind: 'governed', what: 'Serve browser clients on a real certificate.', state: 'qualifiable' },
    { slug: 'authority.grant', kind: 'governed', what: 'Mint promotion / cert grants under a domain (Meter C).', state: 'lapsed', meterC: true },
    { slug: 'matrix.homeserver', kind: 'governed', what: 'Host the live rooms — the square, the halls, chat (Matrix).', state: 'requested' },
    { slug: 'voice.sfu', kind: 'governed', what: 'Host the voice / video calls.', state: 'qualifiable' }
  ];
  var byChannel = {}; channels.forEach(function (c) { byChannel[c.slug] = c; });

  /* the 4 NAMED roles — a friendly grouping over the channels (no new power). */
  var namedRoles = [
    { id: 'record_keeper', label: 'Record Keeper', channels: ['mirror', 'etl'], consent: 'self', recommended: true,
      what: 'Keeps a copy of the public record and serves the network’s maps and data.',
      duty: 'Stay synced and reachable so peers and newcomers can copy the full record from you.',
      cta: 'Establish (one click)', note: 'Both channels are self-asserted — no approval needed. The recommended first node.' },
    { id: 'archivist', label: 'Archivist', channels: ['client.serve'], consent: 'governed',
      what: 'A full peer that serves players in the browser — it holds the complete public record, and a visiting player’s action is verified and forwarded to the jurisdiction’s home authority.',
      duty: 'Serve honestly and pass every verified action along — the record you serve is the same record every peer holds.',
      cta: 'Request', note: 'Serving players is governed — requested, then approved. Every node reaches full peerage through the same one process.' },
    { id: 'social_moderator', label: 'Social Moderator', channels: ['matrix.homeserver', 'voice.sfu', 'client.serve'], consent: 'governed',
      what: 'Hosts the live social commons — the square, the halls, voice and video.',
      duty: 'Host the rooms; you hold no power to remove on viewpoint — only the four logged carve-outs and the legal floor act.',
      cta: 'Request', note: 'All governed — requested, then approved by the dual-meter (operator board, or the seated government).' },
    { id: 'identity_broker', label: 'Identity Broker', channels: ['broker.dns', 'broker.tls', 'authority.grant', 'client.serve'], consent: 'governed',
      what: 'Gives peers their names (DNS) and the certificates that prove them.',
      duty: 'Name peers truthfully; broker.dns and authority.grant touch a peer’s subtree, so peers themselves must consent (Meter C).',
      cta: 'Request', note: 'The heaviest role — pulls in Meter C (peer unanimity) on two of its channels.' }
  ];

  /* the dual-meter consent. */
  var meters = [
    { id: 'A', name: 'Meter A — the operator board', who: 'the active operators (neutral)',
      threshold: '1 operator → just you · 2 → unanimity · 3+ → two-thirds', applies: 'the bootstrap path, before a government is seated' },
    { id: 'B', name: 'Meter B — the seated government', who: 'the constituent legislatures, by supermajority (a multi-jurisdiction vote)',
      threshold: 'supermajority of constituent jurisdictions', applies: 'the moment a legislature is seated — it SUPERSEDES Meter A' },
    { id: 'C', name: 'Meter C — co-affected peers', who: 'every peer whose subtree the channel would touch',
      threshold: 'unanimity (any one peer can refuse)', applies: 'only channels that act under a peer’s zone — broker.dns, authority.grant',
      futureNote: 'Peer unanimity arrives with a later upgrade round — until then the mesh degrades safely and these channels simply wait.' }
  ];

  /* the demo operator account (plane-walled from citizen users). */
  var account = {
    username: 'manhattan-op', serverId: 'srv-7c3a…e12', status: 'active', lastLogin: '2031-06-21 09:14 UTC',
    meshOperatorId: 'op-9d1f…a7b', displayHandle: 'manhattan-op',
    devices: [
      { name: 'Pi at the depot', keyFp: 'ed25519:4f2a…91c', active: true, enrolledVia: 'key-possession' },
      { name: 'laptop (travel)', keyFp: 'ed25519:b73d…02e', active: true, enrolledVia: 'linkByProof' }
    ],
    authNote: 'Your local password signs you in on this box only — other boxes recognise you by device-key possession, never by a password.'
  };

  /* mesh readiness — the "peers & sync at a glance" rollup (6 gates). */
  var readiness = {
    rollup: 'amber',
    gates: [
      { key: 'federation_enabled', status: 'pass', label: 'Federation enabled' },
      { key: 'identity_minted', status: 'pass', label: 'Identity minted' },
      { key: 'transports_advertised', status: 'pass', label: 'Transports advertised' },
      { key: 'self_url_reachable', status: 'warn', label: 'Self-URL reachable' },
      { key: 'trusted_peer_exists', status: 'pass', label: 'A trusted peer exists' },
      { key: 'sync_applied_outbound', status: 'warn', label: 'Outbound sync applied' }
    ],
    peerCount: 3, lastSync: '2031-06-21 09:02 UTC'
  };

  /* peers (lifecycle). */
  var peers = [
    { name: 'Box A — Earth root', serverId: 'srv-1a…001', url: 'https://earth.cga.example', status: 'trust_established', relation: 'host', version: 'cv-2031.6', release: 'g-8b', heartbeat: '12s ago', syncSeq: 88412 },
    { name: 'Brooklyn peer', serverId: 'srv-2b…047', url: 'https://brooklyn.cga.example', status: 'syncing', relation: 'peer', version: 'cv-2031.6', release: 'g-8b', heartbeat: '40s ago', syncSeq: 88390 },
    { name: 'Aurelia (sovereign)', serverId: 'srv-9z…aur', url: 'https://aurelia.example', status: 'border_settled', relation: 'sovereign', version: 'cv-2031.5', release: 'g-8a', heartbeat: '5m ago', syncSeq: 51002 }
  ];

  var syncLedger = [
    { seq: 4120, dir: 'outbound', result: 'applied', range: '88401→88412', at: '09:02' },
    { seq: 4119, dir: 'inbound', result: 'applied', range: '88388→88390', at: '08:58' },
    { seq: 4118, dir: 'inbound', result: 'conflict_authoritative_wins', range: '88386→88387', at: '08:51', note: 'authority disputed → resolved authoritative-wins' }
  ];

  var transports = [
    { transport: 'https', address: 'https://manhattan.cga.example', priority: 10, state: 'established' },
    { transport: 'tailnet', address: '100.84.x.x:8443', priority: 20, state: 'established' },
    { transport: 'onion', address: '…onion', priority: 30, state: 'qualifiable' },
    { transport: 'yggdrasil', address: '2xx::…', priority: 40, state: 'lapsed' },
    { transport: 'sneakernet', address: 'USB drop @ the depot', priority: 90, state: 'lapsed' }
  ];

  /* the join-a-cluster wizard. */
  var joinWizard = [
    { step: 1, title: 'Host & key', detail: 'The host URL, plus a one-shot join-key the host operator minted (or join without a key and wait for a vouch).' },
    { step: 2, title: 'Scope & data', detail: 'The complete public record or a subtree; and whether to copy the map data (already have it · pull from the host · skip).' },
    { step: 3, title: 'What you’ll host', detail: 'Pick the channels your box will serve — keep the record, serve players, host the live rooms. Every join leads to the same full peerage.' },
    { step: 4, title: 'Review', detail: 'Confirm. Your node downloads the complete public record and joins as a full peer. Which box’s record is authoritative for each jurisdiction stays with its home authority — a bookkeeping fact, not a rank.' }
  ];

  /* DNS & certificates (the Identity Broker’s domain). */
  var broker = {
    model: 'DNS is the identity; the cert is the proof. The A-record is written BEFORE the certificate, so a cert never points at nothing and no Let’s Encrypt budget is burned on a name that failed.',
    domains: [
      { domain: 'cga.example', zone: 'zone-7f…', token: 'set (write-only, encrypted, never shown)', certs: 'per-name', wildcardBackup: false, budget: '12 of 50 this week' },
      { domain: 'statecraft.example', zone: 'zone-3a…', token: 'set', certs: 'per-name', wildcardBackup: true, budget: '3 of 50 this week', note: 'wildcard backup approved for this domain' }
    ],
    perName: 'Per-name is the default and the only ungated path — one cert for exactly one name.',
    wildcard: 'A wildcard (*.domain) is a distinct grant kind the authority must explicitly mint, plus a per-domain approval flag, plus (optionally) a higher consent bar. The client tries per-name first and only falls back to a pre-approved wildcard.',
    ddns: 'A moving node re-points its A-record with a signed update — no new cert, so no LE budget consumed.',
    providers: [ { name: 'Cloudflare', state: 'live' }, { name: 'Route53', state: 'stub — not yet implemented' }, { name: 'DigitalOcean', state: 'stub' }, { name: 'Manual', state: 'returns the record to set by hand' } ],
    budgetRails: 'Per domain: 50 certs / 7 days (the registered-domain limit). Per exact name: 5 / 7 days. The pre-flight refuses with remediation BEFORE burning an ACME attempt.'
  };

  /* G-ID identity layer. */
  var gid = {
    attestation: 'A short-lived (≤ 24h), revocable, instance-signed snapshot of a person’s derived standing, bound to a device key. Only the HOME authority attests. It carries only public standing — never credentials, locations, or ballots.',
    deviceEnrol: 'A device enrols its Ed25519 PUBLIC key — the secret never leaves the device (no escrow).',
    forwardedWrite: [
      { check: 'Attestation', detail: 'The issuer’s pinned key verifies the snapshot — fails closed on expiry, revocation, or mutation.' },
      { check: 'Action signature', detail: 'The device signed THIS exact write (form + payload + subject) — non-repudiation.' },
      { check: 'Subject', detail: 'The attested user resolves locally before the engine authorizes.' }
    ],
    sweep: 'A housekeeping job prunes lapsed attestations (already fail-closed on expiry; the sweep just bounds the table, soft-deleting for forensics).',
    forwardedActor: 'The forwarded write is the keystone: a person passing through a server that isn’t home can still act, because their standing travels as a signed, short-lived attestation and their own device signs the action itself.'
  };

  /* the operator-plane moderation + the legal floor (M-5). */
  var moderation = {
    flip: {
      below: 'BELOW the flip (no seated government): the operator board (neutral) relays rights-protection, every removal logged, with no judicial order attached so it can never be mistaken for one. Judicial removal is unavailable (no judge yet).',
      above: 'AT/ABOVE the flip (a seated legislature): only a live judicial order acts; the operator is no longer honored.',
      automatic: 'The flip is a pure function of facts — when a legislature seats itself, authority moves from operator-relay to judicial-only, with no manual mode change.'
    },
    carveouts: [
      { id: 'm1_judicial', label: 'Judicial order', who: 'a judge, once seated', logged: true },
      { id: 'm2_rights', label: 'Rights protection', who: 'operator relay (below) → judicial (above)', logged: true },
      { id: 'm3_block', label: 'Per-user block', who: 'each person, on their own screen (an ignore list)', logged: false, note: 'never a server-side action — it lives only on your own screen; never logged' },
      { id: 'm4_antispam', label: 'Content-neutral anti-spam', who: 'the system (behaviour/volume, never viewpoint)', logged: true }
    ],
    viewpointImpossible: 'Viewpoint / community-guidelines removal has NO code path. The carve-out map only knows judicial-order and rights-protection; there is no “remove for content” to invoke.',
    m5: {
      label: 'The legal floor',
      what: 'Removal of already-posted ILLEGAL material — the real-world law of the country the server sits in. Off the constitutional plane, content-neutral.',
      auth: 'An operator account by key-possession (an active account) — NOT a standing attestation.',
      basis: ['Known illegal-image match (purges the bytes — deletes, not quarantines)', 'Specific court order (redacts)', 'True threat (redacts)'],
      rails: 'A closed list, grown only by code release — never in-game. It never changes who can post; the matched-list source is recorded, never the image itself; the sealed evidence trail is append-only.',
      form: 'F-SOC-004'
    }
  };

  /* constitutional versioning / upgrade agreement (G-VER). */
  var versioning = {
    selfVersion: 'cv-2031.6',
    kinds: [
      { label: 'Constitutional version', desc: 'The hardened rules themselves. Only this kind is screened by the filter and agreed by vote.' },
      { label: 'Database shape', desc: 'How records are stored under the hood — plumbing, never rules.' },
      { label: 'App release', desc: 'New features and fixes — no rule changes at all.' }
    ],
    admissibility: 'An admissibility filter runs FIRST and is reach-independent and ungateable — a proposal that would lower proportionality or the supermajority floor is refused outright.',
    consent: 'Then the same dual-meter consent: operator board (Meter A) until a government seats, then seated supermajority (Meter B); peer-mesh unanimity (Meter C) for co-affected peers.',
    freeze: 'A game-in-progress freeze: an election or session already running pins its version so the rules can’t change mid-play.',
    peerMatch: [
      { peer: 'Box A — Earth root', version: 'cv-2031.6', match: true },
      { peer: 'Brooklyn peer', version: 'cv-2031.6', match: true },
      { peer: 'Aurelia (sovereign)', version: 'cv-2031.5', match: false, note: 'a version behind — sync refuses fail-closed until agreed' }
    ]
  };

  /* the key CLI verbs + routes (for the Advanced / SOP surface — the design contract). */
  var cli = ['federation:init', 'federation:peer:discover <url>', 'federation:peer:handshake', 'federation:cold-sync <url>', 'federation:sync:push', 'mesh:gates', 'mesh:doctor [target]', 'mesh:role [list|qualify|request|approve|revoke] <capability>', 'mesh:request-cert', 'mesh:cert-grant', 'transport:register <t> <addr>', 'transport:disable <t>'];

  V2.op = {
    plane: plane, STATE_PILL: STATE_PILL, channels: channels, byChannel: byChannel,
    namedRoles: namedRoles, meters: meters, account: account, readiness: readiness,
    peers: peers, syncLedger: syncLedger, transports: transports, joinWizard: joinWizard,
    broker: broker, gid: gid, moderation: moderation, versioning: versioning, cli: cli,
    byRole: (function () { var m = {}; namedRoles.forEach(function (r) { m[r.id] = r; }); return m; })()
  };
})();
