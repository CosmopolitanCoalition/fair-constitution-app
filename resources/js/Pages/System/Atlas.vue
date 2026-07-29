<script setup>
/**
 * System/Atlas — the public world-metrics surface (ATLAS_DESIGN.md, lane 4).
 *
 * "A live heartbeat of the whole game." A port of mockups/v3/atlas.html: a
 * living map plus the vital signs of representation, justice, the executive,
 * organizations, the economy, people, and the mesh — with reach & legitimacy,
 * aggregated to the planet, at the centre.
 *
 * ── The one hard rail: CI-1, A GAUGE NEVER A LEVER ──────────────────────────
 * Everything here is display-only. Nothing on this page changes a vote, a seat
 * or a right, and nothing on it is ever consulted on a rights path. Three
 * consequences live in this file:
 *
 *  1. `dash()` renders null as an em-dash, NEVER as 0. A figure the rollup
 *     withheld (k-anonymity floor 5, complementary suppression) is a GAP, not
 *     a zero — reading it as zero would leak the very count that was
 *     suppressed, and would let an observer difference two nights to defeat
 *     k-anonymity. Every number on this page goes through it.
 *  2. Nothing is computed live. The controller hands us one nightly rollup
 *     row; there is no branch anywhere on this page that counts the world.
 *  3. No governance action. The layer toggles are a display filter and the
 *     place picker is a GET; the single mutating control is the personal
 *     map opt-in, which is a privacy preference on the viewer's own record —
 *     no vote, no seat, no advantage (ATLAS_DESIGN §5).
 *
 * Reach ships LIVE here, unlike the mockup, which marked it `planned` before
 * legitimacy_snapshots existed — §4 calls the reach card the spine and the
 * snapshots are built. Only the economy and people cards render `planned`
 * (operator ruling 2026-07-29, Q4 option (a)).
 */
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Icon from '@/Components/Ui/Icon.vue';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, default: null },
    /** ISO date of the nightly rollup this page is reading; null = none yet. */
    generatedAt: { type: String, default: null },
    /** Honest posture: a synthetic world never masquerades as a live one. */
    instance: { type: Object, default: () => ({ synthetic: false, label: null }) },
    hero: { type: Object, default: () => ({}) },
    world: { type: Object, default: () => ({}) },
    reach: { type: Object, default: () => ({}) },
    representation: { type: Object, default: () => ({}) },
    executive: { type: Object, default: () => ({}) },
    judiciary: { type: Object, default: () => ({}) },
    organizations: { type: Object, default: () => ({}) },
    economy: { type: Object, default: () => ({}) },
    people: { type: Object, default: () => ({}) },
    mesh: { type: Object, default: () => ({}) },
    /** Map layers. People are opt-in pixels; places/orgs/nodes are public. */
    map: { type: Object, default: () => ({ places: [], orgs: [], people: [], nodes: [] }) },
    /** 12 monthly points per series, downsampled from the daily rollup rows. */
    trends: { type: Object, default: () => ({ series: {} }) },
    ctas: { type: Array, default: () => [] },
    directory: { type: Array, default: () => [] },
    /** { available, on, note } — flips to available when the write path lands. */
    optIn: { type: Object, default: () => ({ available: false, on: false, note: null }) },
    privacy: { type: Object, default: () => ({ note: '', rails: [] }) },
});

/* ── formatting ─────────────────────────────────────────────────────────────
   dash() IS the suppression rail — see the header. Keep every figure on this
   page behind it; a bare {{ n }} would print a suppressed null as blank and a
   zero-coerced null as a lie. */
function dash(n) {
    return n == null ? '—' : Number(n).toLocaleString();
}

// Population density at a glance: 7.99B / 245.0M / 12K / 369 — the same
// rendering the District Mapper and Jurisdictions/Show use.
function formatPop(n) {
    if (n == null) return '—';
    if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(1) + 'B';
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1_000) return (n / 1_000).toFixed(0) + 'K';
    return n.toLocaleString();
}

function pct(n, places = 2) {
    return n == null ? '—' : Number(n).toFixed(places) + '%';
}

const admLabels = {
    0: 'World',
    1: 'Country',
    2: 'State / Province',
    3: 'County / District',
    4: 'ADM 4',
    5: 'ADM 5',
    6: 'ADM 6',
};

function admLabel(level) {
    return admLabels[level] ?? `ADM ${level}`;
}

/* ── the living map ─────────────────────────────────────────────────────────
   Equirectangular: the viewBox is in DEGREES, one unit = one degree, so a
   lng/lat pair needs no projection maths beyond an origin shift. Land is a
   simplified outline drawn for orientation — it is not geodata and nothing is
   measured from it. Positions are city-level and approximate by design: this
   is orientation, not surveillance. */
function px(lng) {
    return lng + 180;
}

function py(lat) {
    return 90 - lat;
}

const LAND = [
    /* North America */
    [[-168, 66], [-160, 70], [-140, 70], [-122, 71], [-100, 70], [-82, 73], [-70, 67], [-64, 60], [-78, 62], [-80, 52], [-70, 47], [-66, 44], [-70, 41], [-74, 40], [-76, 35], [-81, 31], [-80, 25], [-84, 30], [-90, 29], [-97, 26], [-97, 21], [-91, 19], [-88, 16], [-83, 9], [-78, 8], [-83, 14], [-94, 16], [-105, 20], [-114, 28], [-117, 33], [-122, 38], [-124, 43], [-124, 48], [-130, 55], [-138, 58], [-150, 59], [-162, 62], [-168, 66]],
    /* Greenland */
    [[-46, 60], [-52, 66], [-52, 72], [-42, 77], [-28, 76], [-19, 72], [-23, 68], [-32, 62], [-42, 60], [-46, 60]],
    /* South America */
    [[-78, 8], [-72, 11], [-62, 10], [-52, 5], [-50, 0], [-44, -2], [-37, -6], [-35, -8], [-39, -14], [-44, -23], [-49, -27], [-54, -34], [-58, -39], [-63, -42], [-66, -48], [-70, -52], [-66, -55], [-72, -53], [-74, -48], [-72, -40], [-71, -30], [-70, -22], [-71, -17], [-77, -13], [-81, -5], [-81, 1], [-78, 5], [-78, 8]],
    /* Europe (mainland + Scandinavia) */
    [[-9, 38], [-9, 43], [-1, 44], [-2, 48], [-5, 49], [1, 51], [-2, 53], [-5, 58], [3, 59], [6, 62], [11, 64], [16, 67], [24, 70], [29, 71], [31, 67], [28, 62], [31, 59], [27, 56], [21, 55], [19, 52], [14, 54], [12, 47], [18, 45], [14, 44], [19, 42], [16, 40], [19, 40], [13, 44], [8, 44], [3, 43], [-2, 40], [-6, 37], [-9, 38]],
    /* UK + Ireland */
    [[-10, 52], [-8, 55], [-5, 58], [-2, 58], [1, 53], [-2, 51], [-6, 50], [-10, 52]],
    /* Africa */
    [[-16, 15], [-17, 21], [-13, 28], [-9, 32], [-5, 36], [10, 37], [11, 34], [20, 33], [25, 32], [32, 31], [34, 28], [37, 22], [43, 12], [51, 12], [51, 7], [48, 2], [42, -1], [40, -8], [35, -15], [33, -22], [28, -30], [25, -34], [19, -35], [16, -29], [13, -18], [9, -2], [8, 4], [-1, 5], [-8, 5], [-13, 9], [-16, 15]],
    /* Asia (simplified, includes India + SE peninsula) */
    [[28, 42], [40, 40], [46, 38], [50, 42], [58, 40], [60, 48], [68, 55], [60, 62], [56, 68], [68, 72], [82, 73], [100, 76], [125, 73], [142, 72], [160, 70], [180, 68], [178, 62], [162, 60], [150, 58], [140, 52], [135, 46], [140, 42], [130, 40], [122, 38], [122, 30], [118, 24], [110, 20], [108, 12], [104, 1], [100, 6], [98, 12], [92, 20], [88, 21], [80, 15], [77, 8], [73, 18], [68, 24], [60, 25], [57, 27], [50, 30], [46, 36], [36, 38], [30, 40], [28, 42]],
    /* Japan */
    [[130, 31], [133, 34], [137, 35], [140, 37], [142, 40], [140, 42], [138, 38], [134, 34], [131, 31], [130, 31]],
    /* SE Asian islands / Indonesia */
    [[96, 5], [102, 2], [108, -3], [116, -8], [124, -9], [133, -7], [142, -8], [150, -9], [141, -3], [130, -1], [118, -3], [108, -4], [100, 3], [96, 5]],
    /* Australia */
    [[114, -22], [113, -26], [115, -30], [123, -34], [131, -32], [138, -35], [147, -38], [151, -34], [153, -28], [148, -20], [142, -11], [136, -12], [130, -13], [124, -16], [118, -20], [114, -22]],
    /* New Zealand */
    [[167, -46], [170, -44], [173, -41], [175, -37], [178, -38], [174, -41], [171, -45], [167, -46]],
];

function ringD(ring) {
    return 'M' + ring.map((p) => `${px(p[0]).toFixed(1)} ${py(p[1]).toFixed(1)}`).join(' L ') + ' Z';
}

const landPath = computed(() => LAND.map(ringD).join(' '));

const graticule = computed(() => {
    const lines = [];
    for (let lng = -150; lng <= 150; lng += 30) {
        lines.push({ x1: px(lng), y1: py(82), x2: px(lng), y2: py(-78) });
    }
    for (let lat = -60; lat <= 60; lat += 30) {
        lines.push({ x1: 0, y1: py(lat), x2: 360, y2: py(lat) });
    }
    return lines;
});

// Place dots are styled per tier and the stylesheet carries t1..t3 only — the
// planet itself is not a dot on its own map, so adm_level 0 never plots and
// anything deeper than 3 renders at the deepest available tone.
const placeDots = computed(() =>
    (props.map.places ?? [])
        .filter((p) => (p.tier ?? 0) >= 1)
        .map((p) => ({ ...p, tone: Math.min(p.tier, 3) })),
);

const LAYERS = [
    { key: 'nodes', label: 'Nodes', icon: 'globe' },
    { key: 'people', label: 'People', icon: 'users' },
    { key: 'orgs', label: 'Organizations', icon: 'building' },
    { key: 'places', label: 'Places', icon: 'map-pin' },
];

const layerOn = reactive({ nodes: true, people: true, orgs: true, places: true });

function layerCount(key) {
    return (props.map[key] ?? []).length;
}

// The REAL federation_peers.status vocabulary (App\Models\FederationPeer) —
// not the mockup's invented authoritative/healthy/degraded set.
const NODE_TONES = {
    discovered: { tone: 'info', label: 'Discovered' },
    handshake: { tone: 'wait', label: 'Handshake' },
    trust_established: { tone: 'live', label: 'Trusted' },
    syncing: { tone: 'wait', label: 'Syncing' },
    conflict_resolution: { tone: 'wait', label: 'Resolving conflicts' },
    border_settled: { tone: 'info', label: 'Border settled' },
    merged: { tone: 'info', label: 'Merged' },
    departed: { tone: 'closed', label: 'Departed' },
};

function nodeTone(status) {
    return NODE_TONES[status] ?? { tone: 'info', label: status || 'unknown' };
}

function nodeTitle(n) {
    return [
        n.label,
        n.operator ? `operator ${n.operator}` : null,
        `${dash(n.residents)} residents`,
        n.uptimePct == null ? null : `${n.uptimePct}% up`,
    ]
        .filter(Boolean)
        .join(' · ');
}

/* ── sparkline (the sanctioned plotter, shared with the legitimacy surface) ── */
function sparkPath(arr, w = 200, h = 44) {
    const vals = (arr ?? []).filter((v) => v != null);
    if (vals.length < 2) return '';
    const min = Math.min(...vals);
    const max = Math.max(...vals);
    const range = max - min || 1;
    return vals
        .map((v, i) => {
            const x = (i / (vals.length - 1)) * w;
            const y = h - ((v - min) / range) * (h - 6) - 3;
            return `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`;
        })
        .join(' ');
}

/* ── the reach dial — the one bespoke gauge ─────────────────────────────────
   A place gauge, never a per-person score (CI-1). A place whose snapshot is
   suppressed contributes to "places gauged" but not to a number, so the dial
   renders an honest gap rather than a 0%. */
const DIAL_R = 42;
const DIAL_C = 2 * Math.PI * DIAL_R;

const home = computed(() => props.reach?.home ?? null);
const homeMeasured = computed(() => home.value != null && home.value.reachPct != null);

const dialDash = computed(() => {
    if (!homeMeasured.value) return `0 ${DIAL_C.toFixed(1)}`;
    const p = Math.max(0, Math.min(100, home.value.reachPct));
    const on = (p / 100) * DIAL_C;
    return `${on.toFixed(1)} ${(DIAL_C - on).toFixed(1)}`;
});

const homeSpark = computed(() => sparkPath((home.value?.snapshots ?? []).map((s) => s.reachPct)));

// Q3 ruling (a): the viewer's residency-confirmed place, with a small picker.
// Changing it is a GET — a different place to look at, not an action.
const placeChoice = ref(home.value?.id ?? null);

function pickPlace(id) {
    if (!id) return;
    router.get(props.reach.pickerUrl ?? '/atlas', { place: id }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

/* ── the nine domain cards ──────────────────────────────────────────────────
   Tile values arrive pre-formatted so each card can mix counts, populations
   and percentages; every one of them went through dash()/formatPop()/pct(). */
const domains = computed(() => {
    const w = props.world ?? {};
    const byAdm = w.byAdmLevel ?? {};
    const r = props.reach ?? {};
    const rep = props.representation ?? {};
    const ex = props.executive ?? {};
    const ju = props.judiciary ?? {};
    const or = props.organizations ?? {};
    const ec = props.economy ?? {};
    const pe = props.people ?? {};
    const me = props.mesh ?? {};

    return [
        {
            key: 'world',
            title: 'The world',
            icon: 'globe',
            accent: 'tier-planetary',
            tiles: [
                { n: dash(w.jurisdictions), label: 'jurisdictions' },
                { n: dash(byAdm[0]), label: admLabel(0) },
                { n: dash(byAdm[1]), label: admLabel(1) },
                { n: dash(byAdm[2]), label: admLabel(2) },
                { n: dash(byAdm[3]), label: admLabel(3) },
                { n: formatPop(w.earthPopulation), label: 'people on Earth' },
                { n: formatPop(w.modeledPopulation), label: 'in modeled places' },
                { n: dash(w.civicActive), label: 'civic-active' },
            ],
        },
        {
            key: 'reach',
            title: 'Reach & legitimacy',
            icon: 'bar-chart',
            accent: 'cc-gold-400',
            tiles: [
                { n: dash(r.verifiedTotal), label: 'verified residents', tone: 'success' },
                { n: dash(r.measuredPlaces), label: 'measured places' },
                { n: homeMeasured.value ? pct(home.value.reachPct) : '—', label: `reach · ${home.value?.name ?? 'your place'}` },
                { n: dash(r.placesGauged), label: 'places gauged' },
            ],
        },
        {
            key: 'representation',
            title: 'Representation',
            icon: 'landmark',
            accent: 'tier-national',
            tiles: [
                { n: dash(rep.legislatures), label: 'legislatures' },
                { n: dash(rep.seats), label: 'seats' },
                { n: dash(rep.seatsFilled), label: 'seats filled', tone: 'success' },
                { n: dash(rep.seatsOpen), label: 'seats open', tone: 'danger' },
                { n: dash(rep.electionsOpen), label: 'elections open' },
                { n: dash(rep.seatsUp), label: 'seats up for election' },
                { n: dash(rep.candidates), label: 'candidates standing' },
                { n: dash(rep.petitionsGathering), label: 'petitions gathering', tone: 'warning' },
                { n: dash(rep.committees), label: 'committees' },
                { n: dash(rep.bills), label: 'bills in flight' },
            ],
        },
        {
            key: 'executive',
            title: 'The executive',
            icon: 'briefcase',
            accent: 'wong-orange',
            tiles: [
                { n: dash(ex.departments), label: 'departments' },
                { n: dash(ex.governorSeats), label: 'governor seats' },
                { n: dash(ex.workerSeats), label: 'worker-elected seats', tone: 'success' },
                { n: dash(ex.civilServiceWorkers), label: 'civil-service workers' },
                { n: dash(ex.emergencyPowersActive), label: 'emergency powers active', tone: 'danger' },
                { n: dash(ex.emergencyDaysLeft), label: 'days left on it' },
            ],
        },
        {
            key: 'judiciary',
            title: 'The judiciary',
            icon: 'scale',
            accent: 'wong-purple',
            tiles: [
                { n: dash(ju.courts), label: 'courts' },
                { n: dash(ju.casesOpen), label: 'cases open' },
                { n: dash(ju.constitutionalChallenges), label: 'constitutional challenges' },
                { n: dash(ju.juriesSeated), label: 'juries seated' },
                { n: dash(ju.remedyWindows), label: 'remedy windows running', tone: 'warning' },
                { n: '5+', label: 'judges per race' },
            ],
        },
        {
            key: 'organizations',
            title: 'Organizations',
            icon: 'building',
            accent: 'wong-skyblue',
            tiles: [
                { n: dash(or.total), label: 'organizations' },
                { n: dash(or.politicalParties), label: 'political parties' },
                { n: dash(or.businesses), label: 'businesses' },
                { n: dash(or.nonprofits), label: 'nonprofits' },
                { n: dash(or.commonGoodCorps), label: 'common-good corps' },
                { n: dash(or.endorsements), label: 'endorsements made' },
                { n: dash(or.workersRepresented), label: 'workers represented' },
                { n: dash(or.publicDomainWorks), label: 'public-domain works' },
            ],
        },
        {
            key: 'economy',
            title: 'The economy',
            icon: 'refresh-cw',
            accent: 'wong-green',
            planned: true,
            lead: 'Money is an abstract unit of account — no payment rails, no custody. A wallet is private, like a ballot.',
            tiles: [
                { n: dash(ec.mintedThisCycle), label: 'minted this cycle' },
                { n: formatPop(ec.stipendRecipients), label: 'stipend recipients' },
                { n: dash(ec.stipendFloor), label: 'stipend floor' },
                { n: dash(ec.marketVolumeToday), label: 'market volume today' },
                { n: dash(ec.publicBudget), label: 'public budget' },
                { n: dash(ec.openAgreements), label: 'open agreements' },
                { n: dash(ec.jointLedgers), label: 'joint ledgers' },
                { n: dash(ec.marketListings), label: 'market listings' },
            ],
        },
        {
            key: 'people',
            title: 'People & achievements',
            icon: 'users',
            accent: 'tier-municipal',
            planned: true,
            lead: 'Individual achievements are private by default and confer no governance advantage — hard-separated from votes, seats, and money.',
            tiles: [
                { n: dash(pe.verifiedResidents), label: 'verified residents', tone: 'success' },
                { n: dash(pe.namedRoleHolders), label: 'named role-holders' },
                { n: dash(pe.organizations), label: 'organizations' },
                { n: dash(pe.registeredAdvocates), label: 'registered advocates' },
                { n: dash(pe.achievementTracks), label: 'achievement tracks' },
                {
                    n: pe.achievementsEarned == null ? '—' : `${dash(pe.achievementsEarned)} / ${dash(pe.achievementsTotal)}`,
                    label: 'achievements earned',
                },
            ],
        },
        {
            key: 'mesh',
            title: 'The servers carrying the world',
            icon: 'shield',
            accent: 'cc-blue-400',
            lead: 'Volunteer-run — keeping the world online buys no vote and no seat. If one node survives, the world survives.',
            tiles: [
                { n: dash(me.nodes), label: 'nodes' },
                { n: dash(me.alive), label: 'alive now', tone: 'success' },
                { n: dash(me.connectedPeers), label: 'connected to each other' },
                {
                    n: me.onLatest == null ? '—' : `${dash(me.onLatest)} / ${dash(me.onLatestOf)}`,
                    label: 'on the latest version',
                },
                { n: dash(me.transportsUp), label: 'ways to reach each other' },
                { n: me.caughtUp ?? '—', label: 'caught up on the record' },
            ],
        },
    ];
});

/* ── growth trends ─────────────────────────────────────────────────────────
   Twelve monthly points per series, downsampled by the controller from the
   daily rollup rows — the Atlas never walks the world to draw these. */
const TREND_ROWS = [
    { key: 'verifiedResidents', label: 'Verified residents' },
    { key: 'nodes', label: 'Nodes on the mesh' },
    { key: 'jurisdictions', label: 'Jurisdictions live' },
    { key: 'candidates', label: 'Candidates standing' },
    { key: 'organizations', label: 'Organizations' },
    { key: 'onMapOptIns', label: 'On-the-map opt-ins' },
];

const trendRows = computed(() =>
    TREND_ROWS.map((row) => {
        const arr = (props.trends?.series?.[row.key] ?? []).filter((v) => v != null);
        const last = arr.length ? arr[arr.length - 1] : null;
        const delta = arr.length > 1 ? last - arr[0] : null;
        return {
            ...row,
            arr,
            last,
            delta,
            path: sparkPath(arr),
            up: delta == null || delta >= 0,
        };
    }),
);

function deltaText(d) {
    if (d == null) return '—';
    return (d > 0 ? '+' : '') + dash(d);
}

/* ── the map opt-in: the page's ONE mutating control ────────────────────────
   A personal privacy preference on the viewer's own record — a single
   approximate, nameless, grid-snapped pixel. Opt-out is the default, and being
   on the map confers no vote, no seat, no advantage. Until the write path
   lands the control renders honestly unavailable rather than pretending. */
const optInBusy = ref(false);
const optInSaid = ref(null);

function putMeOnTheMap() {
    if (!props.optIn.available || optInBusy.value) return;
    optInBusy.value = true;
    router.post(
        props.optIn.url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                optInSaid.value = 'You now appear as a single approximate pixel — grid-snapped, no name attached.';
            },
            onFinish: () => {
                optInBusy.value = false;
            },
        },
    );
}

const heroStats = computed(() => {
    const h = props.hero ?? {};
    return [
        { n: dash(h.nodesAlive), label: 'nodes alive', tone: 'success' },
        { n: dash(h.verifiedResidents), label: 'verified residents' },
        { n: dash(h.electionsOpen), label: 'elections open' },
        { n: dash(h.seatsOpen), label: 'seats open', tone: 'danger' },
        { n: dash(h.candidatesStanding), label: 'candidates standing' },
        { n: dash(h.jurisdictions), label: 'jurisdictions' },
    ];
});
</script>

<template>
    <PageScaffold :surface="surface" title="The Atlas">
        <template #intro>
            One screen for the whole game: a living map of every node, place, organization, and willing
            resident — and the vital signs of representation, justice, the economy, and the mesh. The
            health of the world, and of everyone playing it.
        </template>

        <!-- Honest posture. A synthetic world never masquerades as a live civilization. -->
        <div v-if="instance.synthetic" class="banner banner--demo">
            <div>
                <span class="banner-title">A simulated world.</span>
                {{ instance.label || 'These are the vital signs of a demonstration instance, not a live civilization.' }}
            </div>
        </div>

        <div class="atlas-hero-stats">
            <div
                v-for="s in heroStats"
                :key="s.label"
                class="stat atlas-hero-stat"
                :class="s.tone ? `atlas-hero-stat--${s.tone}` : null"
            >
                <span class="stat-number">{{ s.n }}</span>
                <span class="stat-label">{{ s.label }}</span>
            </div>
        </div>

        <!-- ── the living map ──────────────────────────────────────────── -->
        <section class="card atlas-map-card" aria-labelledby="atlas-map-h">
            <div class="cluster" style="justify-content: space-between; align-items: flex-start; gap: var(--space-3)">
                <div>
                    <span class="eyebrow" id="atlas-map-h"><Icon name="globe" size="sm" /> The living map</span>
                    <p class="gloss" style="margin: var(--space-1) 0 0">
                        Every node, place, organization, and opt-in resident on one Earth. Approximate
                        positions only — this is orientation, not surveillance.
                    </p>
                </div>
                <span v-if="mesh.nodes != null" class="pill pill--live">
                    <span class="dotlive" aria-hidden="true"></span>
                    {{ dash(mesh.alive) }} of {{ dash(mesh.nodes) }} nodes alive
                </span>
            </div>

            <div class="atlas-controls cluster" role="group" aria-label="Map layers">
                <button
                    v-for="ly in LAYERS"
                    :key="ly.key"
                    type="button"
                    class="atlas-toggle"
                    :class="[`atlas-toggle--${ly.key}`, { 'is-on': layerOn[ly.key] }]"
                    :aria-pressed="String(layerOn[ly.key])"
                    @click="layerOn[ly.key] = !layerOn[ly.key]"
                >
                    <span class="atlas-key" aria-hidden="true"></span>
                    <Icon :name="ly.icon" size="sm" />
                    {{ ly.label }}
                    <span class="atlas-toggle-n">{{ dash(layerCount(ly.key)) }}</span>
                </button>
            </div>

            <div
                class="atlas-map-wrap"
                :class="{
                    'atlas-map-wrap--hide-nodes': !layerOn.nodes,
                    'atlas-map-wrap--hide-people': !layerOn.people,
                    'atlas-map-wrap--hide-orgs': !layerOn.orgs,
                    'atlas-map-wrap--hide-places': !layerOn.places,
                }"
            >
                <svg
                    class="atlas-map"
                    viewBox="0 8 360 162"
                    preserveAspectRatio="xMidYMid meet"
                    aria-hidden="true"
                    focusable="false"
                >
                    <defs>
                        <pattern id="atlas-dots" width="2.6" height="2.6" patternUnits="userSpaceOnUse">
                            <circle cx="1.3" cy="1.3" r="0.42" class="atlas-landdot" />
                        </pattern>
                        <clipPath id="atlas-land">
                            <path v-for="(ring, i) in LAND" :key="i" :d="ringD(ring)" />
                        </clipPath>
                    </defs>

                    <g class="atlas-grat">
                        <line
                            v-for="(g, i) in graticule"
                            :key="i"
                            :x1="g.x1"
                            :y1="g.y1"
                            :x2="g.x2"
                            :y2="g.y2"
                            class="atlas-gline"
                        />
                    </g>

                    <rect x="0" y="8" width="360" height="162" fill="url(#atlas-dots)" clip-path="url(#atlas-land)" />
                    <path :d="landPath" class="atlas-landline" />

                    <g class="atlas-layer atlas-layer--places">
                        <circle
                            v-for="(p, i) in placeDots"
                            :key="`pl-${i}`"
                            :cx="px(p.lng).toFixed(2)"
                            :cy="py(p.lat).toFixed(2)"
                            r="1.4"
                            class="atlas-place"
                            :class="`atlas-place--t${p.tone}`"
                        >
                            <title>{{ p.name }}</title>
                        </circle>
                    </g>

                    <g class="atlas-layer atlas-layer--orgs">
                        <rect
                            v-for="(o, i) in map.orgs"
                            :key="`og-${i}`"
                            :x="(px(o.lng) - 1.1).toFixed(2)"
                            :y="(py(o.lat) - 1.1).toFixed(2)"
                            width="2.2"
                            height="2.2"
                            rx="0.4"
                            class="atlas-org"
                            :transform="`rotate(45 ${px(o.lng).toFixed(2)} ${py(o.lat).toFixed(2)})`"
                        >
                            <title>{{ o.name }}</title>
                        </rect>
                    </g>

                    <!-- Opt-in pixels. One anonymous dot each, grid-snapped upstream;
                         no name, no link, nothing to click through to a person. -->
                    <g class="atlas-layer atlas-layer--people">
                        <circle
                            v-for="(pp, i) in map.people"
                            :key="`pe-${i}`"
                            :cx="px(pp[0]).toFixed(1)"
                            :cy="py(pp[1]).toFixed(1)"
                            r="0.5"
                            class="atlas-person"
                        />
                    </g>

                    <g class="atlas-layer atlas-layer--nodes">
                        <g
                            v-for="(n, i) in map.nodes"
                            :key="`nd-${i}`"
                            class="atlas-node-g"
                            :class="`atlas-node-g--${nodeTone(n.status).tone}`"
                        >
                            <circle :cx="px(n.lng).toFixed(2)" :cy="py(n.lat).toFixed(2)" r="3" class="atlas-node-pulse" />
                            <circle :cx="px(n.lng).toFixed(2)" :cy="py(n.lat).toFixed(2)" r="1.7" class="atlas-node">
                                <title>{{ nodeTitle(n) }}</title>
                            </circle>
                        </g>
                    </g>
                </svg>
            </div>

            <div
                class="cluster"
                style="justify-content: space-between; gap: var(--space-3); margin-block-start: var(--space-2)"
            >
                <p class="citation" style="margin: 0">
                    Land is a simplified outline drawn for orientation. Positions are city-level and approximate.
                </p>
                <button
                    type="button"
                    class="btn btn--primary btn--sm"
                    :disabled="!optIn.available || optIn.on || optInBusy"
                    @click="putMeOnTheMap"
                >
                    <Icon name="map-pin" size="sm" />
                    {{ optIn.on ? 'You are on the map' : 'Put yourself on the map' }}
                </button>
            </div>
            <p role="status" class="gloss" style="margin: var(--space-1) 0 0">
                {{ optInSaid || (!optIn.available ? optIn.note : '') }}
            </p>

            <div class="banner banner--info" style="margin-block-start: var(--space-3)">
                <Icon name="lock" size="sm" />
                <div><strong>Opt-in &amp; approximate.</strong> {{ privacy.note }}</div>
            </div>
        </section>

        <!-- ── vital signs ────────────────────────────────────────────── -->
        <h2 class="atlas-section-h">Vital signs</h2>

        <div class="grid-2 atlas-grid">
            <section v-for="d in domains" :key="d.key" class="card atlas-domain">
                <div class="card-title atlas-domain-head">
                    <span class="eyebrow" :style="{ color: `var(--${d.accent})` }">
                        <Icon :name="d.icon" size="sm" /> {{ d.title }}
                    </span>
                    <span v-if="d.planned" class="pill pill--planned">Planned</span>
                </div>

                <p v-if="d.lead" class="gloss atlas-domain-lead">{{ d.lead }}</p>

                <div class="stat-tiles atlas-tiles">
                    <div
                        v-for="t in d.tiles"
                        :key="t.label"
                        class="stat-tile"
                        :class="t.tone ? `atlas-tile--${t.tone}` : null"
                    >
                        <span class="n">{{ t.n }}</span>
                        <span class="atlas-tile-l">{{ t.label }}</span>
                    </div>
                </div>

                <!-- The reach gauge rides inside its card. A place gauge, never a
                     per-person score; a suppressed place shows a gap, not a zero. -->
                <div v-if="d.key === 'reach'" class="atlas-reach">
                    <div class="atlas-dial-wrap">
                        <svg class="atlas-dial" viewBox="0 0 100 100" aria-hidden="true">
                            <circle cx="50" cy="50" r="42" class="atlas-dial-bg" />
                            <circle
                                cx="50"
                                cy="50"
                                r="42"
                                class="atlas-dial-val"
                                :stroke-dasharray="dialDash"
                                transform="rotate(-90 50 50)"
                            />
                        </svg>
                        <div class="atlas-dial-c">
                            <span class="atlas-dial-n">{{ homeMeasured ? pct(home.reachPct, 1) : '—' }}</span>
                            <span class="atlas-dial-l">reach · {{ home?.name ?? 'your place' }}</span>
                        </div>
                    </div>

                    <div class="atlas-reach-side">
                        <p class="gloss" style="margin: 0 0 var(--space-2)">
                            Reach is the share of a place that is verified and present. It is a
                            <strong>display-only transparency gauge — never a governance input</strong>, and
                            never a per-person score.
                        </p>

                        <svg
                            v-if="homeSpark"
                            class="legit-spark atlas-spark"
                            viewBox="0 0 200 44"
                            preserveAspectRatio="none"
                            role="img"
                            :aria-label="`Reach over 30 nights, ${home?.name ?? 'your place'}`"
                        >
                            <path :d="homeSpark" />
                        </svg>
                        <p v-else class="gloss" style="margin: 0">
                            Not enough measured nights yet to draw a trend. A withheld night is a gap, never a zero.
                        </p>

                        <p v-if="homeMeasured" class="citation" style="margin: var(--space-1) 0 0">
                            30 nights · {{ home.provenance }} {{ home.populationYear }}
                        </p>

                        <label
                            v-if="(reach.places ?? []).length > 1"
                            class="gloss"
                            style="display: block; margin-block-start: var(--space-2)"
                        >
                            Look at another place
                            <select
                                v-model="placeChoice"
                                style="margin-inline-start: var(--space-1)"
                                @change="pickPlace(placeChoice)"
                            >
                                <option v-for="p in reach.places" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div v-if="d.key === 'mesh' && mesh.health" class="atlas-mesh-health">
                    <span class="health-line">
                        <span class="health-dot" :class="`health-dot--${mesh.health}`"></span>
                        Health: <strong>{{ mesh.health }}</strong>
                        <template v-if="mesh.lastSync"> · everyone last compared notes {{ mesh.lastSync }}</template>
                    </span>
                </div>
            </section>
        </div>

        <!-- ── growth ─────────────────────────────────────────────────── -->
        <section class="card atlas-trends" aria-labelledby="atlas-trends-h">
            <div class="card-title">
                <span class="eyebrow" id="atlas-trends-h"><Icon name="bar-chart" size="sm" /> Growth — the last year</span>
            </div>

            <div class="grid-2">
                <div v-for="row in trendRows" :key="row.key" class="atlas-trend">
                    <div class="atlas-trend-top">
                        <span class="atlas-trend-label">{{ row.label }}</span>
                        <span class="atlas-trend-n">
                            {{ dash(row.last) }}
                            <span class="atlas-trend-d" :class="{ 'atlas-trend-d--down': !row.up }">
                                <Icon :name="row.up ? 'arrow-up' : 'arrow-down'" size="sm" />{{ deltaText(row.delta) }}
                            </span>
                        </span>
                    </div>
                    <svg
                        v-if="row.path"
                        class="legit-spark atlas-spark"
                        viewBox="0 0 200 44"
                        preserveAspectRatio="none"
                        role="img"
                        :aria-label="`${row.label} over the last year`"
                    >
                        <path :d="row.path" />
                    </svg>
                </div>
            </div>

            <p class="lr-note" style="margin-block-start: var(--space-3)">
                <Icon name="info" size="sm" />
                <span>
                    Growth and reach are shown to celebrate participation and keep the network honest — never as
                    a lever on anyone’s rights.
                </span>
            </p>
        </section>

        <!-- ── what needs people ──────────────────────────────────────── -->
        <section v-if="ctas.length" class="card atlas-ctas" aria-labelledby="atlas-ctas-h">
            <div class="card-title">
                <span class="eyebrow" id="atlas-ctas-h"><Icon name="bell" size="sm" /> What needs people right now</span>
            </div>
            <ul class="atlas-cta-list">
                <li v-for="(c, i) in ctas" :key="i" class="atlas-cta" :class="`atlas-cta--${c.tone}`">
                    <Icon :name="c.icon" size="sm" />
                    <span class="atlas-cta-t">{{ c.text }}</span>
                    <a class="btn btn--secondary btn--sm" :href="c.href">
                        {{ c.cta }} <Icon name="arrow-right" size="sm" />
                    </a>
                </li>
            </ul>
        </section>

        <!-- ── nodes & operators ──────────────────────────────────────── -->
        <section v-if="directory.length" class="card atlas-dir" aria-labelledby="atlas-dir-h">
            <div class="card-title">
                <span class="eyebrow" id="atlas-dir-h"><Icon name="globe" size="sm" /> Nodes &amp; operators</span>
            </div>
            <p class="gloss">
                The servers keeping the mesh alive, and the residents who run them. Open an operator to reach
                their public profile.
            </p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Node</th>
                            <th>Where</th>
                            <th>Operator</th>
                            <th>Status</th>
                            <th>Role</th>
                            <th>Residents</th>
                            <th>Uptime</th>
                            <th>Sync</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="n in directory" :key="n.label">
                            <td>
                                <strong style="color: var(--gov-fg)">{{ n.label }}</strong>
                                <span v-if="n.self" class="badge badge--info">this node</span>
                                <span class="citation" style="display: block">{{ n.name }}</span>
                            </td>
                            <td>{{ n.place || '—' }}</td>
                            <td>
                                <a v-if="n.operatorHref" :href="n.operatorHref">{{ n.operator }}</a>
                                <template v-else>{{ n.operator || '—' }}</template>
                            </td>
                            <td>
                                <span class="pill" :class="`pill--${nodeTone(n.status).tone}`">
                                    {{ nodeTone(n.status).label }}
                                </span>
                            </td>
                            <td>{{ n.role || '—' }}</td>
                            <td class="mono">{{ dash(n.residents) }}</td>
                            <td class="mono">{{ n.uptimePct == null ? '—' : `${n.uptimePct}%` }}</td>
                            <td class="mono">{{ dash(n.syncSeq) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p v-if="generatedAt" class="citation">
            Vital signs from the nightly world rollup · {{ generatedAt }}. The Atlas reads a snapshot, never a
            live count of the world.
        </p>
        <div v-else class="banner banner--info">
            <Icon name="info" size="sm" />
            <div>
                <strong>No rollup yet.</strong> The nightly world snapshot has not run on this instance, so the
                vital signs read as gaps rather than zeros.
            </div>
        </div>
    </PageScaffold>
</template>
