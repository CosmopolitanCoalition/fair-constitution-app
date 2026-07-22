<?php

/*
|--------------------------------------------------------------------------
| CGA application configuration (WI-4+)
|--------------------------------------------------------------------------
|
| Home for Cosmopolitan Governance App toggles that are OPERATIONAL, not
| constitutional — constitutional values live in constitutional_settings
| (amendable) or the hardened rule registry (never configurable).
|
| Later keys land here with their work items: clock cadence (WI-6),
| activation defaults (WI-7).
|
*/

return [

    /*
    | Full-scale autoscale (2026-07-18): deepest adm level to size and
    | district-map when "Accept Map Data" starts the run. Default = 6 (TRUE
    | ALL SCALE — every jurisdiction, adm6 villages included, per operator
    | ruling). Lower it only for staged tests via districting:autoscale
    | --adm-max=N; the acceptance path always runs full depth.
    */
    'autoscale_adm_max' => env('CGA_AUTOSCALE_ADM_MAX', 6),

    /*
    | Pull engine (2026-07-19). autoscale_precompute: 'upfront' (default)
    | gates sweep-scope claims behind the run-level sibling-adjacency
    | precompute pass (each parent's borders computed once instead of once
    | per sweep); 'lazy' opens the gate immediately and lets sweeps
    | write-back adjacency as they go — the escape hatch if the precompute
    | pass ever misbehaves. autoscale_singles_workers caps how many pull
    | workers may run set-based singles batches concurrently (the
    | statements are PG-heavy; the rest of the pool overlaps on precompute
    | and sweeps).
    */
    'autoscale_precompute' => env('CGA_AUTOSCALE_PRECOMPUTE', 'upfront'),
    'autoscale_singles_workers' => env('CGA_AUTOSCALE_SINGLES_WORKERS', 4),

    /*
    | Heavy-lane cap override (2026-07-22). 0 = the formula
    | (max(1, ceil(0.2 × workers)) — the operator's 20% ruling). Set 1 when
    | the frontier holds extreme-multipart monsters (Falkland Islands, Cabo
    | de Hornos archipelagos) whose grid work OOMs under 2-concurrent-heavy
    | memory pressure: one monster at a time gets the headroom to FINISH
    | instead of cycling through kill → 30-min stale reclaim → redo forever.
    */
    'autoscale_heavy_cap' => env('CGA_AUTOSCALE_HEAVY_CAP', 0),

    /*
    | Dev impersonation + dev tooling (WI-4). The /dev/* routes (user
    | impersonation, ping simulator) are registered only in the local
    | environment AND gated at runtime by this flag — flipping it to false
    | 404s them instantly without a route-cache rebuild. They never exist
    | outside APP_ENV=local regardless of this value.
    */
    'impersonation' => env('CGA_IMPERSONATION', true),

    /*
    | CLK-06 critical-population fallback (WI-6). The activation threshold
    | resolves per jurisdiction at evaluation time:
    | constitutional_settings.critical_population_threshold (own row →
    | ancestor walk) → this value. Dev default 1 — a single verified
    | resident activates a jurisdiction. Production tiers (player
    | population pegged against real population per jurisdiction, owner
    | ruling #15) land in a later phase.
    */
    'critical_population_default' => env('CGA_CRITICAL_POPULATION', 1),

    /*
    | Election demo compression (WI-B7). False (default) = constitutional
    | phase windows (approval_min_days / ranked_window_days resolved per
    | jurisdiction). A positive integer N compresses every phase boundary
    | to N minutes from now for live demos — read as an int by
    | ElectionLifecycleService::defaultDates() (minute spacing) and as a
    | bool by the F-ELB-001 handler (skips the approval_min_days floor).
    | Compression is CONFIG, never data: no election row ever records that
    | its windows were compressed-by-right — re-running without the env
    | var restores constitutional timing everywhere.
    */
    'election_demo_compression' => env('CGA_ELECTION_DEMO_COMPRESSION', false),

    /*
    | Position-tag vocabulary (FE-B3). The fixed chip-toggle set offered by
    | F-IND-011/F-CAN-001 (PHASE_B_DESIGN_frontend.md §B.2 — "fixed
    | vocabulary (config)"). Operational chrome, not constitutional: the
    | engine's only rails on tags are length counts (CampaignProfileSetup),
    | never content. Vocabulary ported from the mockup fixture set.
    */
    'position_tag_vocabulary' => [
        'budget', 'climate', 'education', 'health', 'housing', 'mutual-aid',
        'parks', 'small-business', 'transit', 'water', 'zoning',
    ],

    /*
    | Federation schema version (Phase F). Exchanged at handshake and stamped
    | on every synced payload; peers refuse Full-Faith-&-Credit sync across a
    | schema-version mismatch (canonical-JSON shapes must agree byte-for-byte
    | for cross-instance hash verification to hold). Bump when an
    | audit/public-record payload shape changes in a non-back-compatible way.
    */
    'schema_version' => env('CGA_SCHEMA_VERSION', '1'),

    /*
    | App release tag (Phase G, G-VER). Human-readable deploy/provenance string
    | (e.g. a `git describe` output) — which CODE a jurisdiction's elections ran
    | under, exchanged at handshake. PROVENANCE ONLY; it never gates counting (the
    | derived constitutional_version does). Null when unset.
    */
    'app_release' => env('CGA_APP_RELEASE'),

    /*
    | Federation self-URL (Phase F). The externally-reachable base URL this
    | instance advertises to peers at handshake so they can call back (sync
    | pushes, heartbeats, authority flips). In the two-instance demo:
    |   worktree (fcw)  → http://host.docker.internal:8081
    |   main (fc)       → http://host.docker.internal:8080
    | Null when federation is not deployed.
    */
    'federation_self_url' => env('FEDERATION_SELF_URL'),

    /*
    | Peer-signature replay window in seconds (Phase F). A signed peer request
    | whose X-Federation-Timestamp is older/newer than this is rejected before
    | the signature is even checked.
    */
    'federation_replay_window' => env('CGA_FEDERATION_REPLAY_WINDOW', 300),

    /*
    | CLK-20 federation heartbeat interval in minutes (Phase F). Each fire pings
    | trusted peers, opportunistically pushes our FF&C tail, and re-arms for the
    | next interval. Operational cadence, not constitutional.
    */
    'federation_heartbeat_minutes' => env('CGA_FEDERATION_HEARTBEAT_MINUTES', 5),

    /*
    | Cold-sync paging (Phase G). A fresh mirror pulls a peer's full corpus in
    | bounded, signed pages. `page_size` is the puller's per-request ask;
    | `page_max` is the producer's hard cap (the GET /audit-tail body never
    | exceeds this many entries — the body-size-failure fix).
    */
    'federation_sync_page_size' => env('CGA_FEDERATION_SYNC_PAGE_SIZE', 500),
    'federation_sync_page_max' => env('CGA_FEDERATION_SYNC_PAGE_MAX', 1000),
    // How many cold-sync pages CLK-20 drains per peer per heartbeat tick.
    'federation_cold_pages_per_tick' => env('CGA_FEDERATION_COLD_PAGES_PER_TICK', 5),

    /*
    | Geodata SEED bytes transport (roles-campaign Phase 0b). A joining mirror range-pulls
    | the host's geodata-foundation tarball in bounded, resumable byte pages, BEFORE the
    | audit drain. `page_bytes` is the puller's per-request ask; `page_max_bytes` is the
    | producer's hard cap (a peer can never demand an unbounded slab). 8 MB default keeps a
    | page well inside the federation HTTP timeout even on a slow link.
    */
    'federation_seed_page_bytes' => env('CGA_FEDERATION_SEED_PAGE_BYTES', 8 * 1024 * 1024),
    'federation_seed_page_max_bytes' => env('CGA_FEDERATION_SEED_PAGE_MAX_BYTES', 16 * 1024 * 1024),

    /*
    | Seed transport (seed paginated redesign). How a joining mirror loads the geodata
    | FOUNDATION before the audit drain:
    |   'tarball'   (default, legacy) — pull one pg_dump tarball + pg_restore it (opaque,
    |               non-resumable mid-table). The byte-page transport above.
    |   'paginated' — drain each foundation table in bounded, signed KEYSET pages, UPSERTing
    |               per page with a resumable per-table cursor (FoundationServeService donor +
    |               FoundationDrainService joiner). Visible per-table progress, crash-resumable,
    |               and non-destructive (UPSERT — never clears the identity-safe / append-only
    |               tables). `foundation_page_max_bytes` caps a single page body (geoms vary
    |               from bytes to multi-MB), independent of the per-table row cap.
    | The SAME signed rows travel either way; only the framing differs. DEFAULT is 'paginated' (the
    | visible/resumable/non-destructive path); the tarball path stays one release as the fallback —
    | set CGA_FEDERATION_SEED_TRANSPORT=tarball (or flip it in the operator Operations console) to
    | revert without a code change.
    */
    'federation_seed_transport' => env('CGA_FEDERATION_SEED_TRANSPORT', 'paginated'),
    'federation_foundation_page_max_bytes' => env('CGA_FEDERATION_FOUNDATION_PAGE_MAX_BYTES', 16 * 1024 * 1024),
    // The joiner's per-page row ASK; the donor caps it to each table's optimal size + byte-trims.
    'federation_foundation_page_rows' => env('CGA_FEDERATION_FOUNDATION_PAGE_ROWS', 1000),
    // On a COLD paginated drain, drop the heavy secondary indexes (the ~447 MB geom GiST + secondary
    // btrees) for the bulk UPSERT and rebuild them per-table on completion — the main speed-up over a
    // live-index load. Operability toggle: set false to keep indexes live (slower, but no DDL).
    'federation_foundation_drop_indexes' => env('CGA_FEDERATION_FOUNDATION_DROP_INDEXES', true),

    /*
    | WAN resilience (Phase G, G8b). LAN tolerates a 20s S2S timeout; a real WAN
    | link (mobile uplink, tunnel jitter, a slow onion hop) needs more — so the
    | per-request timeout is configurable. Cold-sync page fetches (idempotent GETs)
    | additionally RETRY transient failures (connection resets, 5xx) with exponential
    | backoff before propagating, so a brief blip never aborts a multi-hour backfill.
    | A 4xx is a definitive answer and is never retried. Operators on high-latency
    | links should also lower federation_cold_pages_per_tick (a WAN page round-trip is
    | seconds, not milliseconds) and may raise federation_replay_window if a node's
    | clock is badly skewed.
    */
    'federation_http_timeout_seconds' => env('CGA_FEDERATION_HTTP_TIMEOUT_SECONDS', 60),
    'federation_cold_retry_attempts' => env('CGA_FEDERATION_COLD_RETRY_ATTEMPTS', 3),
    'federation_cold_retry_backoff_ms' => env('CGA_FEDERATION_COLD_RETRY_BACKOFF_MS', 500),

    /*
    | Transport (Phase G, G8). The SAME SIGNED bytes travel over any channel —
    | https, a Headscale tailnet, a Tor .onion, or sneakernet. A `.onion` endpoint
    | routes through `federation_socks_proxy` (a local Tor daemon, e.g.
    | socks5h://127.0.0.1:9050); everything else is reached directly unless a global
    | `federation_proxy` is set. Both null by default — existing behaviour unchanged.
    */
    'federation_socks_proxy' => env('CGA_FEDERATION_SOCKS_PROXY'),
    'federation_proxy' => env('CGA_FEDERATION_PROXY'),

    /*
    | Multiplex survival mesh (Phase G, G8b). A peer is ONE identity reachable over
    | a SET of transports; MultiplexClient tries them best-first until one survives.
    | A transport that throws a connection/timeout error N consecutive times
    | (failure_threshold) trips its circuit OPEN — subsequent calls skip it (fail
    | fast, no wasted timeout) until cooldown_seconds elapse, when the ladder treats it
    | as half-open (a single probe, sorted below healthy channels) and a delivery
    | re-closes it. An HTTP RESPONSE of any status (even a 4xx refusal) means
    | the channel delivered bytes and is healthy — only a transport-level failure
    | counts against the circuit. All operational state, never constitutional.
    | secure_transport_first makes the hardened overlays (onion/yggdrasil) sort ABOVE
    | clearnet — the DEFAULT secure/private path for redundancy + integrity, so a blocked
    | or surveilled https endpoint is never even the first hop.
    */
    'federation_transport_failure_threshold' => env('CGA_FEDERATION_TRANSPORT_FAILURE_THRESHOLD', 3),
    'federation_transport_circuit_cooldown_seconds' => env('CGA_FEDERATION_TRANSPORT_CIRCUIT_COOLDOWN_SECONDS', 60),
    'federation_secure_transport_first' => env('CGA_FEDERATION_SECURE_TRANSPORT_FIRST', false),
    // CLK-20 maintenance probe (a cheap GET /identity over a cooled circuit) uses a
    // SHORT timeout — it must never spend the 60s tail-delivery budget, so even many
    // dead rungs across peers cannot stall the heartbeat for minutes.
    'federation_probe_timeout_seconds' => env('CGA_FEDERATION_PROBE_TIMEOUT_SECONDS', 5),

    /*
    | Zero-foreknowledge auto-discovery (roles campaign). A fresh node (no peers, no
    | host address) finds an existing federation two ways, both of which only LOCATE
    | nodes serving the public GET /.well-known/cga-federation descriptor — the signed
    | adopt handshake + authority checks that actually admit a mirror are unchanged, and
    | NO secret is ever published.
    |
    |  • FRONT DOOR (suspenders) — `bootstrap_urls`: the public canonical entry point(s),
    |    defaulting to https://worldofstatecraft.org (the broker naming-root). A node with
    |    zero LAN and zero config still discovers the canonical federation. A bootstrap host
    |    may also vouch for other entry points via the descriptor's `known_federations`.
    |  • LAN SWEEP (belt) — an OPT-IN, operator-triggered probe of the operator's OWN private
    |    subnet (RFC1918, capped at a /24) for the same descriptor. This is how Box B finds
    |    Box A on a LAN "from jump". Bounded to private ranges + the well-known path only.
    |
    | These are PUBLIC addresses, not secrets. The Cloudflare token, join keys, and any
    | Box-specific instructions are never part of discovery.
    */
    'federation_bootstrap_urls' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CGA_FEDERATION_BOOTSTRAP_URLS', 'https://worldofstatecraft.org'))
    ))),
    'federation_lan_discovery' => env('CGA_FEDERATION_LAN_DISCOVERY', true),
    'federation_lan_discovery_ports' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CGA_FEDERATION_LAN_DISCOVERY_PORTS', '8080,8081'))
    ))),

    /*
    | Geodata origin (Phase G, G3c — decision N3). The upstream that serves the
    | signed geospatial-dataset MANIFEST channel (WorldPop rasters + geoBoundaries
    | — large + license-bound, so deliberately OFF the audit-tail sync a mirror
    | pulls). 'self' = this instance publishes its own manifests from the ETL
    | archive; a URL = pull manifests from that upstream; null (default) = no
    | geodata channel (a fresh mirror ingests no geodata, choosing its posture at
    | adoption). The manifest records dataset/version/sha256/license/size + an
    | ORIGIN signature; the raster BYTES transport lands with Phase H (the first
    | runtime raster consumer).
    */
    'geodata_origin' => env('CGA_GEODATA_ORIGIN'),

    /*
    | Broker channel (Mesh Roles & Channels of Trust ★9-11). When a box adopts the
    | broker.dns / broker.tls role, it runs the SAME cert-broker issuance core Box C
    | (LAMP) runs — sourcing authority_keys from the gossiped broker_authorizations.
    | The Cloudflare token lives ONLY here (per-domain or the shared services.cloudflare
    | token); it never federates, never appears in a grant or response. 'domains' is the
    | multi-domain map: each naming root carries its own zone (token defaults to the
    | shared one). acme.provider: 'stub' = offline self-signed (wiring); 'lego' = real
    | Let's Encrypt via DNS-01 (the rig leg). store_dsn defaults to this box's PostgreSQL.
    */
    'broker' => [
        'domains' => [
            // 'worldofstatecraft.org' => ['cloudflare_zone_id' => env('CGA_BROKER_ZONE_ID'), 'a_record_proxied' => false],
        ],
        'request_ttl' => env('CGA_BROKER_REQUEST_TTL', 120),
        'store_dsn' => env('CGA_BROKER_STORE_DSN', ''),
        'tls_path' => env('CGA_BROKER_TLS_PATH', storage_path('app/mesh-tls')),
        // Broker-LOCAL state files, atomically written 0600, that the FF&C sync NEVER touches and that NEVER
        // federate: the encrypted per-domain Cloudflare token (credentials_path), and the trusted-broker
        // failover trust lists — accept_from / share_with (failover_path). Both are config-overridable so a
        // test isolates them from the operator's real files.
        'credentials_path' => env('CGA_BROKER_CREDENTIALS_PATH', storage_path('app/broker/credentials.json')),
        'failover_path' => env('CGA_BROKER_FAILOVER_PATH', storage_path('app/broker/failover.json')),
        'acme' => [
            'provider' => env('CGA_BROKER_ACME_PROVIDER', 'stub'),
            'email' => env('CGA_BROKER_ACME_EMAIL', ''),
            'lego_bin' => env('CGA_BROKER_LEGO_BIN', 'lego'),
            'staging' => env('CGA_BROKER_ACME_STAGING', true),
            // DNS-01 zone-detection + propagation resolver. Inside Docker the embedded resolver
            // (127.0.0.11) SERVFAILs lego's SOA lookups; an explicit reachable resolver fixes it
            // (Docker Desktop: the host gateway, e.g. 192.168.65.7:53; elsewhere a public 1.1.1.1:53).
            'dns_resolvers' => env('CGA_BROKER_DNS_RESOLVERS', '1.1.1.1:53'),
            // Whether to SKIP lego's DNS propagation check. Default FALSE: lego confirms propagation via
            // the configured dns_resolvers above (reliable — it WAITS for the TXT, avoiding a validation
            // race). Set TRUE only where no resolver is reachable for the check (then LE's own validators
            // are the sole verification, with a small propagation-race risk).
            'dns_disable_cp' => env('CGA_BROKER_DNS_DISABLE_CP', false),
        ],
    ],

];
