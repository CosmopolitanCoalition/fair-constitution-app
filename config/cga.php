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

];
