<?php

/*
| The Role Catalog (Operator Roles & Console design ★1) — the 4 named operator-roles projected over the
| 9-channel capability substrate (config/mesh_channels.php). A role is the SET of channels it groups; its
| STATE is DERIVED from those channels' states (MeshGateService::roles()), never stored. This file is the
| ONE source of truth consumed by the console role cards, the `mesh:role` CLI, the dev role-lab, and SOP
| generation. It adds NO power, NO grant kind, NO consent path — naming is a UX layer over the channels.
|
| `channels`     — slugs from InstanceCapability::CHANNELS that this role groups.
| `petition`     — a governed flow that is NOT one of the 9 channels (the Archivist's read-write authority
|                  is the Art. V §7 petition that flips authoritative_server_id). Null when none.
| `recommended`  — the first-node default (Record Keeper — the only all-self-asserted, one-click role).
|
| NOTE: when the Identity-Broker channels collapse (broker.dns + broker.tls → one `broker` channel, Phase 4),
| update identity_broker.channels here — this catalog is the single place the role↔channel map lives.
*/

return [

    'record_keeper' => [
        'label' => 'Record Keeper',
        'what' => 'Hold the data backbone — mirror the record read-only and host the geodata.',
        'duty' => 'Keep a faithful read-only copy of the mesh and serve its heavy data to peers.',
        'channels' => ['mirror', 'etl'],
        'petition' => null,
        'recommended' => true,
    ],

    'archivist' => [
        'label' => 'Archivist',
        'what' => 'Serve the client and hold read-write authority for a jurisdiction.',
        'duty' => 'Accept and faithfully record the writes of the jurisdictions you are authoritative for.',
        'channels' => ['client.serve'],
        'petition' => 'read_write', // Art. V §7 — flips authoritative_server_id; not one of the 9 channels
        'recommended' => false,
    ],

    'social_moderator' => [
        'label' => 'Social Moderator',
        'what' => 'Host the public square + halls (Matrix) and voice/video for a jurisdiction.',
        'duty' => 'Keep the live commons available; moderation stays content-neutral and uncensorable by carve-out.',
        'channels' => ['matrix.homeserver', 'voice.sfu', 'client.serve'],
        'petition' => null,
        'recommended' => false,
    ],

    'identity_broker' => [
        'label' => 'Identity Broker',
        'what' => 'Create subdomains, issue TLS certs, and mint capability grants for the mesh.',
        'duty' => 'Issue names + certs faithfully under the naming roots you are entrusted with.',
        'channels' => ['broker.dns', 'broker.tls', 'authority.grant', 'client.serve'],
        'petition' => null,
        'recommended' => false,
    ],

];
