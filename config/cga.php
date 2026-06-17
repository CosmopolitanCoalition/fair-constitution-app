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
    | Transport (Phase G, G8). The SAME SIGNED bytes travel over any channel —
    | https, a Headscale tailnet, a Tor .onion, or sneakernet. A `.onion` endpoint
    | routes through `federation_socks_proxy` (a local Tor daemon, e.g.
    | socks5h://127.0.0.1:9050); everything else is reached directly unless a global
    | `federation_proxy` is set. Both null by default — existing behaviour unchanged.
    */
    'federation_socks_proxy' => env('CGA_FEDERATION_SOCKS_PROXY'),
    'federation_proxy' => env('CGA_FEDERATION_PROXY'),

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

];
