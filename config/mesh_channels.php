<?php

/*
| The Channel Catalog (Mesh Roles & Channels of Trust ★13). The operator-facing copy for each capability
| channel — its label + a plain "what this lets the box do" line — that the federation console's Role Board
| renders. The closed vocabulary + the governed/self-asserted split live on App\Models\InstanceCapability
| (one source of truth); this is only the human-readable layer over it. A channel with no entry here still
| works — it just renders with its raw slug.
*/

return [

    'mesh.member' => [
        'label' => 'Mesh member',
        'what' => 'Join the mesh and sync public records two-way with trusted peers.',
    ],
    'mirror' => [
        'label' => 'Mirror',
        'what' => 'Mirror a cluster read-only (authoritative for nothing) — a cold standby.',
    ],
    'etl' => [
        'label' => 'Geodata / ETL',
        'what' => 'Host the geodata archive and run the boundary + population loader.',
    ],
    'broker.dns' => [
        'label' => 'DNS broker',
        'what' => 'Create subdomains under a mesh naming root (Cloudflare DNS) for peers.',
    ],
    'broker.tls' => [
        'label' => 'TLS broker',
        'what' => 'Issue TLS certificates for mesh names (ACME / Let’s Encrypt).',
    ],
    'client.serve' => [
        'label' => 'Client host',
        'what' => 'Serve the embedded web/app client bundle to browsers.',
    ],
    'authority.grant' => [
        'label' => 'Granting authority',
        'what' => 'Mint signed capability grants for a jurisdiction subtree (a trust anchor).',
    ],
    'matrix.homeserver' => [
        'label' => 'Matrix homeserver',
        'what' => 'Host the Matrix social homeserver (the public square’s message engine).',
    ],
    'voice.sfu' => [
        'label' => 'Voice / video SFU',
        'what' => 'Host the LiveKit selective-forwarding unit for voice + video.',
    ],

];
