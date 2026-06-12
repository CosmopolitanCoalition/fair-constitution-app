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

];
