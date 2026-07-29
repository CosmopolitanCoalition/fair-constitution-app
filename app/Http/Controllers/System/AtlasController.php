<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\InstanceClass;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * The Atlas — the public world-metrics surface (ATLAS_DESIGN.md, lane 4).
 *
 * "A live heartbeat of the whole game": one read-only screen for the whole
 * world — a living map plus the vital signs of representation, justice, the
 * executive, organizations, the economy, people and the mesh, with reach &
 * legitimacy aggregated to the planet at the centre.
 *
 * ⚑ IT IS A MIRROR, NOT A CONTROL PANEL (CI-1 — a gauge, never a lever). There
 * is no governance action on this page, by design. Modelled on ReachController,
 * which lives by the same rule.
 *
 * ⚑ THIS CONTROLLER NEVER COUNTS THE WORLD. Every aggregate comes from the
 * nightly `world_stats` rollup. Computing the vital signs live is the ~75-second
 * `SimConsoleController::world()` aggregate — impossible per page load — and a
 * live headcount would also break k-anonymity by handing an observer sub-minute
 * resolution on numbers the snapshot deliberately publishes once a day. The
 * bounded, indexed reads below (one home-place snapshot, that place's ≤30-night
 * series, a capped list of map points, the peer directory) are point lookups,
 * not world aggregates — the distinction the pin enforces.
 *
 * ⚑ A MISSING FIGURE IS A GAP, NEVER A ZERO. Nulls flow to the page untouched
 * and render as an em-dash. That is what makes this surface honest before the
 * rollup has ever run: with no `world_stats` table at all the Atlas renders a
 * complete, truthful screen of gaps rather than a world of zeros.
 */
class AtlasController extends Controller
{
    /** Hard caps on the map layers. The map is orientation, not a census. */
    private const MAX_PLACES = 500;
    private const MAX_ORGS = 200;
    private const MAX_NODES = 200;
    private const MAX_PEOPLE = 2000;

    /** Nights of reach history behind the home-place spark. */
    private const SPARK_NIGHTS = 30;

    public function index(Request $request)
    {
        $latest = $this->latestRollup();
        $domains = $latest['domains'];

        $home = $this->homePlace($request);

        return Inertia::render('System/Atlas', [
            'surface' => SurfaceMeta::for('system/atlas'),
            'generatedAt' => $latest['as_of_date'],
            'instance' => [
                'synthetic' => InstanceClass::isScaleDemo(),
                'label' => InstanceClass::isScaleDemo()
                    ? 'These are the vital signs of a demonstration world, not a live civilization.'
                    : null,
            ],

            'hero' => $this->hero($domains),
            'world' => $domains['world'] ?? [],
            'reach' => $this->reach($domains, $home),
            'representation' => $domains['representation'] ?? [],
            'executive' => $domains['executive'] ?? [],
            'judiciary' => $domains['judiciary'] ?? [],
            'organizations' => $domains['organizations'] ?? [],
            'economy' => $domains['economy'] ?? [],
            'people' => $domains['people'] ?? [],
            'mesh' => $domains['mesh'] ?? [],

            'map' => $this->map(),
            'trends' => $this->trends(),
            'ctas' => $this->ctas($domains),
            'directory' => $this->directory(),

            // The page's one mutating control. Until the opt-in write path
            // exists the button renders honestly unavailable rather than
            // pretending: opt-out is the default and nothing here fakes it.
            'optIn' => [
                'available' => false,
                'on' => false,
                'url' => null,
                'note' => 'Putting yourself on the map is not wired up yet. When it is, you will '
                    .'appear as a single approximate, nameless pixel — and never before you ask.',
            ],
            'privacy' => [
                'note' => 'Appearing on the map is opt-in. A person shows as a single pixel snapped to a '
                    .'coarse grid — an approximate place, never a real coordinate, never a name. Where you '
                    .'actually are is private, like a ballot.',
                'rails' => [
                    'Opt-in only — nobody is placed on the map without choosing to be',
                    'A single pixel, snapped to a coarse grid — approximate, never precise',
                    'No name, no link from a person-pixel — identity stays private',
                    'Being on the map confers no vote, no seat, no advantage',
                ],
            ],
        ]);
    }

    /**
     * The latest nightly rollup, or an empty shape when there is none.
     *
     * `Schema::hasTable` is deliberate, not defensive clutter: the Atlas ships
     * ahead of its rollup table and must render truthfully in that window. No
     * rollup is not a world of zeros — it is a world we have not measured yet.
     *
     * @return array{as_of_date:?string, domains:array<string,mixed>}
     */
    private function latestRollup(): array
    {
        if (! Schema::hasTable('world_stats')) {
            return ['as_of_date' => null, 'domains' => []];
        }

        $row = DB::table('world_stats')->orderByDesc('as_of_date')->first(['as_of_date', 'domains']);

        if ($row === null) {
            return ['as_of_date' => null, 'domains' => []];
        }

        return [
            'as_of_date' => (string) $row->as_of_date,
            'domains' => $this->decode($row->domains),
        ];
    }

    /** @return array<string,mixed> */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The hero band. Every figure is lifted from the rollup — nothing is
     * recomputed here, so the hero can never disagree with the card below it.
     *
     * @param  array<string,mixed>  $d
     * @return array<string,?int>
     */
    private function hero(array $d): array
    {
        return [
            'nodesAlive' => $d['mesh']['alive'] ?? null,
            'verifiedResidents' => $d['reach']['verifiedTotal'] ?? null,
            'electionsOpen' => $d['representation']['electionsOpen'] ?? null,
            'seatsOpen' => $d['representation']['seatsOpen'] ?? null,
            'candidatesStanding' => $d['representation']['candidates'] ?? null,
            'jurisdictions' => $d['world']['jurisdictions'] ?? null,
        ];
    }

    /**
     * The reach card: the planet totals from the rollup, plus the viewer's own
     * place read straight from `legitimacy_snapshots` — the same rows, and the
     * same suppression decision, that the Reach surface renders.
     *
     * ⚑ `ratio_micro` is integer MILLIONTHS, and it is NOT clamped on write: an
     * over-unity ratio (the `capped` state — more verified residents than the
     * population estimate admits) is disclosed rather than silently trimmed. We
     * pass the honest percentage through; the page clamps only the dial's arc.
     * A suppressed or unmeasurable night carries a NULL ratio, which stays null
     * all the way to the em-dash.
     *
     * @param  array<string,mixed>  $d
     * @param  array{id:string,name:string}|null  $home
     * @return array<string,mixed>
     */
    private function reach(array $d, ?array $home): array
    {
        $card = $d['reach'] ?? [];

        $card['home'] = $home === null ? null : $this->homeGauge($home);
        $card['places'] = $this->placePicker();
        $card['pickerUrl'] = route('atlas.index');

        return $card;
    }

    /**
     * The viewer's home place: their residency-confirmed jurisdiction, or Earth.
     * Q3 ruling (a) — still a PLACE gauge, never a per-person score. An explicit
     * `?place=` wins so the small picker can look anywhere.
     *
     * `residency_confirmations` carries no `deleted_at` and no `status` (a
     * verified convention exception) — liveness is `is_active`.
     *
     * @return array{id:string,name:string}|null
     */
    private function homePlace(Request $request): ?array
    {
        $asked = $request->query('place');

        if (is_string($asked) && $asked !== '') {
            $row = DB::table('jurisdictions')->where('id', $asked)->whereNull('deleted_at')->first(['id', 'name']);

            if ($row !== null) {
                return ['id' => (string) $row->id, 'name' => (string) $row->name];
            }
        }

        if ($userId = Auth::id()) {
            $row = DB::table('residency_confirmations as rc')
                ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
                ->where('rc.user_id', $userId)
                ->where('rc.is_active', true)
                ->whereNull('j.deleted_at')
                ->orderByDesc('j.adm_level')
                ->first(['j.id', 'j.name']);

            if ($row !== null) {
                return ['id' => (string) $row->id, 'name' => (string) $row->name];
            }
        }

        $earth = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->orderBy('adm_level')
            ->first(['id', 'name']);

        return $earth === null ? null : ['id' => (string) $earth->id, 'name' => (string) $earth->name];
    }

    /**
     * One place's gauge + its ≤30-night curve. Two indexed point reads, never a
     * world aggregate. Suppressed nights carry no point at all rather than a
     * zero — a gap is honest, a zero is a lie about the place.
     *
     * @param  array{id:string,name:string}  $home
     * @return array<string,mixed>
     */
    private function homeGauge(array $home): array
    {
        $latest = DB::table('legitimacy_snapshots')
            ->where('jurisdiction_id', $home['id'])
            ->orderByDesc('as_of_date')
            ->first(['state', 'ratio_micro', 'population_provenance', 'population_year']);

        $series = DB::table('legitimacy_snapshots')
            ->where('jurisdiction_id', $home['id'])
            ->orderByDesc('as_of_date')
            ->limit(self::SPARK_NIGHTS)
            ->get(['as_of_date', 'ratio_micro'])
            ->reverse()
            ->values();

        return [
            'id' => $home['id'],
            'name' => $home['name'],
            'state' => $latest->state ?? null,
            'reachPct' => $this->pctFromMicro($latest->ratio_micro ?? null),
            'provenance' => $latest->population_provenance ?? null,
            'populationYear' => $latest->population_year !== null ? (int) $latest->population_year : null,
            'snapshots' => $series
                ->map(fn ($r) => [
                    'date' => (string) $r->as_of_date,
                    'reachPct' => $this->pctFromMicro($r->ratio_micro),
                ])
                ->all(),
        ];
    }

    /** Millionths → percent, preserving null and never clamping the value. */
    private function pctFromMicro(mixed $micro): ?float
    {
        return $micro === null ? null : round(((int) $micro) / 10_000, 4);
    }

    /**
     * A short, bounded list for the picker — the same shape and cap the Reach
     * surface uses.
     *
     * @return list<array{id:string,name:string}>
     */
    private function placePicker(): array
    {
        return DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->orderBy('adm_level')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(fn ($j) => ['id' => (string) $j->id, 'name' => (string) $j->name])
            ->values()
            ->all();
    }

    /**
     * The map layers — bounded point reads, hard-capped. This is a picture for
     * orientation, not a census: it plots the most prominent places rather than
     * every jurisdiction, because a 955k-place world cannot be drawn and should
     * not be attempted.
     *
     * The planet itself is not a dot on its own map, so `adm_level = 0` never
     * plots. People are opt-in only, and there is no opt-in store yet, so the
     * people layer is legitimately empty — the default is off, not on.
     *
     * @return array<string,list<mixed>>
     */
    private function map(): array
    {
        // `jurisdictions` stores a PostGIS `centroid` point (SRID 4326), NOT
        // lat/lng columns — ST_X is longitude, ST_Y is latitude.
        $places = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->where('adm_level', '>=', 1)
            ->whereNotNull('centroid')
            ->orderBy('adm_level')
            ->limit(self::MAX_PLACES)
            ->selectRaw('name, adm_level, ST_X(centroid) AS lng, ST_Y(centroid) AS lat')
            ->get()
            ->map(fn ($j) => [
                'name' => (string) $j->name,
                'tier' => (int) $j->adm_level,
                'lat' => (float) $j->lat,
                'lng' => (float) $j->lng,
            ])
            ->values()
            ->all();

        return [
            'places' => $places,
            // Organizations keep no coordinate of their own yet, and a peer node
            // carries none at all (`federation_peers` has no geometry). Both
            // layers therefore render honestly EMPTY rather than guessing a
            // position — a made-up pin on a map about orientation would be the
            // one thing this surface must not do. The toggles still show, at 0.
            'orgs' => [],
            'people' => [],
            'nodes' => [],
        ];
    }

    /**
     * Growth — twelve monthly points per series, taken as the LAST rollup row in
     * each of the last twelve months. Downsampling here (365 rows at most) keeps
     * the page a snapshot read; nothing walks the world to draw a spark.
     *
     * @return array{monthsBack:int, series:array<string,list<?int>>}
     */
    private function trends(): array
    {
        $empty = ['monthsBack' => 12, 'series' => []];

        if (! Schema::hasTable('world_stats')) {
            return $empty;
        }

        $rows = DB::table('world_stats')
            ->orderByDesc('as_of_date')
            ->limit(365)
            ->get(['as_of_date', 'domains']);

        if ($rows->isEmpty()) {
            return $empty;
        }

        // One representative row per month, oldest first: the last night of each
        // month is the month's figure.
        $byMonth = [];

        foreach ($rows as $row) {
            $month = substr((string) $row->as_of_date, 0, 7);
            $byMonth[$month] ??= $this->decode($row->domains);
        }

        ksort($byMonth);
        $months = array_slice($byMonth, -12, 12, true);

        $paths = [
            'verifiedResidents' => ['reach', 'verifiedTotal'],
            'nodes' => ['mesh', 'nodes'],
            'jurisdictions' => ['world', 'jurisdictions'],
            'candidates' => ['representation', 'candidates'],
            'organizations' => ['organizations', 'total'],
            'onMapOptIns' => ['world', 'mapOptIns'],
        ];

        $series = [];

        foreach ($paths as $key => [$domain, $metric]) {
            $series[$key] = array_values(array_map(
                fn (array $d) => $d[$domain][$metric] ?? null,
                $months,
            ));
        }

        return ['monthsBack' => 12, 'series' => $series];
    }

    /**
     * "What needs people right now" — links, never actions. A link to the
     * candidacy form is navigation; the Atlas itself still does nothing.
     *
     * Each card is suppressed unless the rollup actually has a figure for it, so
     * the list never invites anyone to a thing we cannot count.
     *
     * @param  array<string,mixed>  $d
     * @return list<array<string,mixed>>
     */
    private function ctas(array $d): array
    {
        $rep = $d['representation'] ?? [];
        $ju = $d['judiciary'] ?? [];
        $out = [];

        if (($rep['seatsOpen'] ?? 0) > 0) {
            $out[] = [
                'icon' => 'landmark', 'tone' => 'danger',
                'text' => $rep['seatsOpen'].' seats are open in a legislature — anyone resident may stand.',
                'cta' => 'Stand for office', 'href' => '/elections',
            ];
        }

        if (($rep['electionsOpen'] ?? 0) > 0) {
            $out[] = [
                'icon' => 'vote', 'tone' => 'info',
                'text' => $rep['electionsOpen'].' elections are open right now — the approval phase is the moment to weigh in.',
                'cta' => 'Find your ballot', 'href' => '/elections',
            ];
        }

        if (($rep['petitionsGathering'] ?? 0) > 0) {
            $out[] = [
                'icon' => 'file-text', 'tone' => 'warning',
                'text' => $rep['petitionsGathering'].' petitions are gathering signatures toward a referendum.',
                'cta' => 'See petitions', 'href' => '/civic/petitions',
            ];
        }

        if (($ju['constitutionalChallenges'] ?? 0) > 0) {
            $out[] = [
                'icon' => 'scale', 'tone' => 'info',
                'text' => $ju['constitutionalChallenges'].' constitutional challenges are before a court.',
                'cta' => 'Open the docket', 'href' => '/judiciary/cases',
            ];
        }

        $out[] = [
            'icon' => 'globe', 'tone' => 'info',
            'text' => 'Run a node and keep the mesh alive — it confers no power, only stewardship.',
            'cta' => 'Run a node', 'href' => '/federation',
        ];

        return $out;
    }

    /**
     * The node directory. Volunteer-run: keeping the world online buys no vote
     * and no seat, and this table never shows a node's internals or keys.
     *
     * @return list<array<string,mixed>>
     */
    private function directory(): array
    {
        if (! Schema::hasTable('federation_peers')) {
            return [];
        }

        // The real column vocabulary: `name`, `url`, `status` (discovered →
        // handshake → trust_established → syncing → … → departed), `relation`
        // (sovereign | host | mirror), `last_synced_seq`. There is no `label`,
        // no `role` and no self-flag — "this node" is the peer whose server_id
        // matches our own, from the instance_settings singleton.
        $ownServerId = Schema::hasTable('instance_settings')
            ? DB::table('instance_settings')->whereNull('deleted_at')->orderBy('created_at')->value('server_id')
            : null;

        return DB::table('federation_peers')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(self::MAX_NODES)
            ->get(['server_id', 'name', 'url', 'status', 'relation', 'last_synced_seq'])
            ->map(fn ($p) => [
                'label' => (string) ($p->name ?? 'node'),
                'name' => (string) ($p->url ?? ''),
                'place' => null,
                'operator' => null,
                'operatorHref' => null,
                'status' => (string) ($p->status ?? ''),
                'role' => (string) ($p->relation ?? ''),
                'residents' => null,
                'uptimePct' => null,
                'syncSeq' => $p->last_synced_seq !== null ? (int) $p->last_synced_seq : null,
                'self' => $ownServerId !== null && (string) $p->server_id === (string) $ownServerId,
            ])
            ->values()
            ->all();
    }
}
