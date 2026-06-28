<?php

// Phase K-3 "The Mesh Commons" — Matrix homeserver + appservice config.
// The homeserver (Synapse on the dev build; Dendrite is the Pi candidate via MATRIX_IMPL)
// runs as a SEPARATE container; the CGA talks to it over the Matrix HTTP APIs. One CGA
// instance = one homeserver. Everything here is read by the in-Laravel appservice.

return [

    // synapse (dev default, feature-complete) | dendrite (Pi candidate, rig spike).
    // deploy.sh/.ps1 set the arch default; never hardcode the homeserver image elsewhere.
    'impl' => env('MATRIX_IMPL', 'synapse'),

    // The homeserver's server_name (the @user:<server_name> domain). Defaults to the APP_URL
    // host so a fresh dev box needs no extra config; a deployment sets MATRIX_DOMAIN explicitly.
    'server_name' => env(
        'MATRIX_DOMAIN',
        parse_url((string) env('APP_URL', 'http://localhost:8080'), PHP_URL_HOST) ?: 'localhost'
    ),

    // Internal URL the appservice uses to reach the homeserver's client/admin API (Docker DNS).
    'synapse_url' => env('MATRIX_SYNAPSE_URL', 'http://matrix:8008'),

    // K3-N P1 — a Synapse ADMIN access token, supplied by the OPERATOR (rig). NULL on the dev stack, so
    // the M-5 byte-DELETE is DEFERRED honestly (a redaction strips content but does NOT destroy media
    // bytes — only the admin media-DELETE does). Never expose this token to a client.
    'admin_token' => env('MATRIX_ADMIN_TOKEN'),

    // The host port Synapse is published on (dev convenience / curl checks). Behind nginx in prod.
    'host_port' => (int) env('MATRIX_HOST_PORT', 8008),

    // .well-known delegation (served dynamically by WellKnownController — nginx cannot env-substitute).
    // m.server must name where the federation API is reachable: the CGA nginx, which proxies /_matrix/.
    'well_known' => [
        // null => computed as "<server_name>:<APP_URL port>" at request time.
        'delegate_server' => env('MATRIX_DELEGATE_SERVER'),
        'client_base_url' => env('APP_URL', 'http://localhost:8080'),
    ],

    // The appservice registration. as_token = the appservice→Synapse credential; hs_token = what
    // Synapse sends TO the appservice (verified by VerifyMatrixAppService). These DEV defaults MUST
    // match docker/matrix/appservice/registration.yaml; `php artisan matrix:setup` regenerates both
    // in sync for a real deployment (same dev-secret pattern as the repo's APP_KEY).
    'appservice' => [
        'id'               => 'cga',
        'sender_localpart' => 'cga-appservice',
        'as_token'         => env('MATRIX_AS_TOKEN', 'cga_dev_as_token_3f9a1c7e5b2d4860a1f6c8e02b7d9043'),
        'hs_token'         => env('MATRIX_HS_TOKEN', 'cga_dev_hs_token_8d2e4b6a0c1f3957e84a2d6b09c5f718'),
    ],

    // MAS (Matrix Authentication Service) — the OIDC provider Synapse delegates auth to (K3-C).
    'mas' => [
        // Public issuer — what browsers/clients reach (and what .well-known advertises).
        'issuer'   => env('MATRIX_MAS_ISSUER', 'http://localhost:8090/'),
        // Internal endpoint — how Synapse (and the appservice) reach MAS over the Docker network.
        'endpoint' => env('MATRIX_MAS_URL', 'http://mas:8080/'),
    ],

    // The GAME-as-OIDC-PROVIDER (K3-C — identity bridge flows GAME → Matrix, operator-ratified 2026-06-27).
    // The game is a SMALL OIDC provider serving exactly ONE relying party: MAS, configured upstream→game.
    // One CGA login mints the Matrix session; the homeserver never sees a password; only the pseudonym
    // (@u-<handle>) ever crosses to Matrix (the de-anon stays a judicial carve-out in the game DB).
    'oidc' => [
        // The issuer = the game's public base URL (what .well-known/openid-configuration advertises and what
        // MAS pins as the upstream issuer). MAS reaches it over the Docker network at the internal URL.
        'issuer'       => rtrim((string) env('APP_URL', 'http://localhost:8080'), '/'),
        'internal_url' => rtrim((string) env('OIDC_INTERNAL_URL', (string) env('APP_URL', 'http://nginx:80')), '/'),

        // The single registered relying party: MAS. The secret is the game↔MAS shared client secret (dev
        // default follows the repo's dev-secret pattern; `php artisan matrix:setup` regenerates it). The
        // redirect_uri is MAS's upstream callback — finalized in K3-C.3 once the MAS provider ULID exists.
        'client' => [
            'id'            => env('OIDC_MAS_CLIENT_ID', 'mas-upstream'),
            'secret'        => env('OIDC_MAS_CLIENT_SECRET', 'cga_dev_oidc_mas_secret_9b3f1a7c5e2d4806b1f9c8e04a7d2630'),
            // MAS's upstream callback = <MAS public_base>/upstream/callback/<provider-id> (verified empirically
            // against MAS v1.19 — NOT the /upstream/oauth2/callback path some docs show). The dev default
            // matches the provider id in docker/matrix/mas/config.yaml; matrix:setup regenerates both sides in
            // sync for a deployment. The game validates redirect_uri by EXACT match.
            'redirect_uris' => array_values(array_filter(array_map('trim', explode(
                ',',
                (string) env('OIDC_MAS_REDIRECT_URIS', 'http://localhost:8090/upstream/callback/01HZX4GA7JK9MNP8QRSTVWXYZ2')
            )))),
        ],

        // Short-lived by design (mirrors AttestationService's discipline): an auth code is single-use +
        // seconds-lived; the id_token is minted at the token endpoint and consumed immediately by MAS.
        'code_ttl'     => (int) env('OIDC_CODE_TTL', 60),
        'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', 300),
    ],

    // LiveKit (Element Call SFU) for voice/video (K3-J). Dev-stack only; Pi A/V deferred to scaling.
    // The api_key/secret DEV defaults match docker/livekit dev config (the dev-secret pattern, like the
    // appservice as_token); `php artisan matrix:setup` regenerates real secrets for a deployment. The
    // appservice mints HS256 join tokens over api_secret — never expose the secret to a client.
    'livekit' => [
        'url'        => env('LIVEKIT_URL', 'http://livekit:7880'),
        // The EXTERNALLY-reachable SFU URL a remote browser dials (typically wss://…) — distinct from the
        // Docker-internal `url`. A capable peer returns THIS with a cross-node voice token (the mixed
        // environment); a light node's traveling player dials it directly so media bypasses its home node.
        'public_url' => env('LIVEKIT_PUBLIC_URL', env('LIVEKIT_URL', 'http://livekit:7880')),
        'api_key'    => env('LIVEKIT_API_KEY', 'cga_dev_livekit_key'),
        'api_secret' => env('LIVEKIT_API_SECRET', 'cga_dev_livekit_secret_5c1d8e3a9f47026b'),
    ],

    // M-S — the proactive, content-neutral media-scan admission floor (K3-I.4). The ONLY input is a
    // configured known-illegal HASH list (+ the media's own hash); there is NO semantic / ML classifier
    // in the admission path. The default list ships EMPTY (the privacy rail — no media leaves the box):
    // the OPERATOR sideloads the actual access-controlled list under their own legal credentials, and
    // matching is fully OFFLINE (works on a LAN / air-gapped box). Cloud scanners / IWF-NCMEC list
    // integration are operator-config / rig-gated.
    'scan' => [
        // A comma-separated list of known-illegal media hashes (lowercased). Empty by default.
        'local_hashes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MATRIX_SCAN_LOCAL_HASHES', ''))
        ))),
    ],

    // K3-K — in-conversation translation. The DEFAULT provider is the fully-offline local stub
    // (isCloud()=false). The TranslationGate's PRIVACY RAIL forbids a CLOUD provider on a PRIVATE room
    // no matter what is configured here. The full NLLB-tail + Haiku-tier-1 hybrid router is Phase N.
    'translation' => [
        'provider'      => env('MATRIX_TRANSLATION_PROVIDER', 'local-stub'), // 'local-stub' | a cloud id (Phase N)
        'default_target' => env('MATRIX_TRANSLATION_DEFAULT_TARGET', 'en'),
    ],
];
