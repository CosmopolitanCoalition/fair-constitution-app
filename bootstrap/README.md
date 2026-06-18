# Survival-Mesh Bootstrap (Phase G · G8b / C7)

One downloadable setup that installs + configures the transports a node will offer, walks
an interactive pick of which meshes it can support, brings the app layer up (via the
existing `deploy.{sh,ps1}`), and registers the chosen transports + publishes the directory.

Three thin OS front-ends over **one shared spec** so they never drift:

| File | OS | Notes |
|---|---|---|
| `bootstrap.sh` | Linux + macOS | branches on `uname`; needs `jq` |
| `bootstrap.command` | macOS | double-click wrapper → `bootstrap.sh` |
| `bootstrap.ps1` | Windows | `winget` installers; pwsh 7+ |
| `mesh-catalog.json` | — | **the single source of truth** for transport facts |

`deploy.{sh,ps1}` remain the unchanged app-layer contract (hardened across the G-V2
deploy rounds); the bootstrap layer only adds the transport pick + register/publish around
them.

## Usage

```bash
# Linux / macOS — interactive
./bootstrap/bootstrap.sh

# non-interactive (CI / headless), pre-picked profile
./bootstrap/bootstrap.sh --non-interactive --profile public-anchor-node --prefix fc --nginx-port 8080
```

```powershell
# Windows
./bootstrap/bootstrap.ps1 -Profile public-anchor-node -Prefix fc -NginxPort 8080
```

Profiles (from `mesh-catalog.json` → `recommend`): `volunteer-home`, `censored-region`,
`air-gapped`, `public-anchor-node`. The posture questions preselect one; the operator can
still toggle each transport.

## Verify before you run (no `curl | sh`)

A governance tool must be verifiable before execution. The website hosts the three scripts
+ `mesh-catalog.json`, a `SHA256SUMS` manifest, and an Ed25519 **detached signature** of
that manifest (the same self-authenticating-artifact discipline as G9 directory entries).
Download, then:

```bash
# 1. checksums match what the site published
sha256sum -c SHA256SUMS

# 2. the manifest is signed by the project's release key (public key from the website)
#    (openssl pkeyutl or minisign, depending on the published key format)
minisign -Vm SHA256SUMS -P "$(cat cga-release.pub)"

# 3. only then run
chmod +x bootstrap/bootstrap.sh && ./bootstrap/bootstrap.sh
```

Maintainer side — regenerate the manifest after any change to a bootstrap file:

```bash
cd bootstrap
sha256sum bootstrap.sh bootstrap.command bootstrap.ps1 mesh-catalog.json > SHA256SUMS
minisign -Sm SHA256SUMS          # signs with the release secret key (offline)
```

## What it does NOT do silently

Installing Tor / Yggdrasil / Tailscale **modifies the host OS** and is the one piece
certified on the physical rig (it inherits the native-arm64/Linux fault class that produced
the G-V2 deploy blockers — amd64/Docker-Desktop cannot surface those). The scripts therefore
**guide** each host-daemon step and only run the install command on explicit confirmation;
they never install a daemon silently. The app-layer bring-up (`deploy.*`) is unchanged and
already certified.
