# Matrix (Plane B)

The live commons (text/voice/video) runs on a **Matrix** homeserver (Synapse on the dev build) with
an in-Laravel **appservice** and a **MAS / OIDC** identity bridge. One CGA instance = one homeserver.
Players never authenticate to Matrix directly — the node does, via the appservice token, and only the
derived pseudonym (`@u-<handle>`) ever crosses to Matrix.

## Knobs

| Knob | Where | Tier | Notes |
|---|---|---|---|
| Homeserver impl | `MATRIX_IMPL` (`config/matrix.php`) | restart | `synapse` (dev/default) or `dendrite`. Set by `deploy.sh` per arch. |
| **Server name** | `MATRIX_DOMAIN` | **locked** | The `@user:<server_name>` domain. **Pinned by peers at S2S** — changing it orphans every existing identity. Set once, at deploy. |
| Synapse URL (internal) | `MATRIX_SYNAPSE_URL` | restart | Docker-internal client/admin API the appservice uses. |
| Mesh-facing homeserver URL | `MATRIX_HOMESERVER_URL` | restart | Operator **opt-in** to host Plane B for the mesh; drives the `matrix.homeserver` channel. Blank = not offered (channel stays needs-config). |
| MAS issuer | `MATRIX_MAS_ISSUER` | restart | Public issuer browsers/clients reach. |
| OIDC issuer (game) | `APP_URL` (`oidc.issuer`) | restart | The game's public base URL — the upstream OIDC provider MAS pins. |
| Appservice ID | `appservice.id` (`cga`) | restart | Static. |
| Synapse admin token | `MATRIX_ADMIN_TOKEN` | restart (secret) | Operator-supplied; enables the M-5 media byte-delete. `null` on the dev stack. |
| Appservice `as_token` / `hs_token` | `MATRIX_AS_TOKEN` / `MATRIX_HS_TOKEN` | restart (secret) | Must match `docker/matrix/appservice/registration.yaml`. |
| OIDC client secret | `OIDC_MAS_CLIENT_SECRET` | restart (secret) | The game↔MAS shared secret. |

The four secrets ship as `cga_dev_*` placeholders; the console flags them **"dev default — rotate"**.

## Rotating the secrets

`php artisan matrix:setup` regenerates the appservice tokens, the OIDC client secret, and the LiveKit
secrets **in sync** across all the config files for a real deployment, then recreate the affected
containers. (Hand-editing one side desynchronises the appservice ↔ Synapse handshake.)

## Hosting Plane B for the mesh

To offer your homeserver to the mesh (so peers can S2S-federate and a light node's traveling player can
reach it):

1. Set `MATRIX_HOMESERVER_URL` to this box's mesh-reachable URL and recreate the app.
2. Establish the **`matrix.homeserver`** channel (console → Matrix section, or
   `mesh:role qualify/request/approve matrix.homeserver`).

## Notes

- `server_name` and the federation `self_url` are the two values peers pin — treat both as **locked**.
- Media moderation: the M-S hash-scan admission floor ships with an **empty** hash list (privacy rail);
  the operator sideloads the access-controlled list under their own legal credentials. Matching is fully
  offline.
