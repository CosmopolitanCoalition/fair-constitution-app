<?php

namespace App\Services\Setup;

use App\Services\Mirror\MirrorJoinKeyService;
use App\Support\GameMode;
use RuntimeException;

/**
 * Renders a one-file, distributable deploy script the founding operator can hand
 * a colleague. Two kinds:
 *
 *   solo — a self-contained start script that founds a FRESH world. It pre-bakes
 *          this box's SHARE-SAFE .env choices as defaults (game mode + host ports)
 *          and NOTHING secret — no APP_KEY, no DB credentials, no signing keys. A
 *          fresh run downloads the code, brings the stack up, and reaches its OWN
 *          /setup so the colleague founds their own world of the same shape.
 *
 *   join — pre-bakes THIS box's FEDERATION_SELF_URL as the host to join plus a
 *          FRESHLY-MINTED single-use join key, so a fresh run auto-joins THIS
 *          world and the whole game replicates in as a read-only mirror.
 *
 * Security posture: the ONLY secret a package ever carries is a join key, and a
 * join key is a purpose-built, revocable, single-use adoption credential (minted
 * per download) — never an APP_KEY, DB password, or private signing key. A solo
 * package carries no secret at all.
 *
 * The scripts are pure-ASCII. In particular the .ps1 must contain NO non-ASCII
 * bytes: a BOM-less UTF-8 em-dash (or any multibyte char) breaks Windows
 * PowerShell 5.1 string parsing, which is the interpreter a novice on stock
 * Windows runs.
 */
class DeployPackageService
{
    public function __construct(private readonly MirrorJoinKeyService $joinKeys) {}

    /**
     * @return array{body:string, filename:string}
     */
    public function render(string $os, string $kind): array
    {
        $os = strtolower(trim($os));
        $kind = strtolower(trim($kind));

        if (! in_array($os, ['windows', 'unix'], true)) {
            throw new RuntimeException("Unknown OS '{$os}' — expected 'windows' or 'unix'.");
        }
        if (! in_array($kind, ['solo', 'join'], true)) {
            throw new RuntimeException("Unknown kind '{$kind}' — expected 'solo' or 'join'.");
        }

        $vars = $this->baseVars();

        if ($kind === 'join') {
            $vars = array_merge($vars, $this->joinVars());
        }

        $body = $os === 'windows'
            ? $this->renderWindows($kind, $vars)
            : $this->renderUnix($kind, $vars);

        // Belt-and-suspenders: a Windows script must be pure-ASCII (PowerShell 5.1
        // string parsing chokes on stray multibyte bytes). This can only trip if a
        // baked value (an instance name, a self-URL) carried a non-ASCII byte; we
        // strip it rather than ship a script that won't parse on the target box.
        if ($os === 'windows') {
            $body = $this->toAscii($body);
        }

        $ext = $os === 'windows' ? 'ps1' : 'sh';
        $filename = "cga-{$kind}-{$os}.{$ext}";

        return ['body' => $body, 'filename' => $filename];
    }

    /**
     * Share-safe defaults pulled from THIS box. Ports + game mode are safe to
     * bake; nothing here is a secret.
     */
    private function baseVars(): array
    {
        return [
            'REPO'        => 'CosmopolitanCoalition/fair-constitution-app',
            'BRANCH'      => 'main',
            'GAME_MODE'   => GameMode::current() ?? GameMode::PRODUCTION,
            'NGINX_PORT'  => (string) (env('NGINX_HOST_PORT') ?: 8080),
            'PG_PORT'     => (string) (env('POSTGRES_HOST_PORT') ?: 5432),
            'VITE_PORT'   => (string) (env('VITE_HOST_PORT') ?: 5173),
        ];
    }

    /**
     * Join-only vars: this box's peer-reachable address as the host, and a
     * freshly-minted single-use join key. Throws a clear, actionable message if
     * either is unavailable — the operator must set their peer address before a
     * join package can point anyone at this world.
     */
    private function joinVars(): array
    {
        $selfUrl = trim((string) config('cga.federation_self_url'));

        if ($selfUrl === '') {
            throw new RuntimeException(
                'Set your peer-reachable address (operator profile) before generating join packages.'
            );
        }

        try {
            // A single-use key that expires in 7 days — enough for a colleague to
            // run the script, not a standing credential. The plaintext is shown
            // (baked) exactly once, here; only its Argon2id hash is stored.
            [$plaintext] = $this->joinKeys->mint(
                maxUses: 1,
                expiresAt: now()->addDays(7),
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not mint a join key for this package: '.$e->getMessage()
            );
        }

        return [
            'HOST_URL' => $selfUrl,
            'JOIN_KEY' => $plaintext,
        ];
    }

    // ── Unix (.sh) ─────────────────────────────────────────────────────────

    private function renderUnix(string $kind, array $vars): string
    {
        $header = $this->unixCommon($kind, $vars);

        $deploy = $kind === 'join'
            ? $this->tpl(
                './deploy.sh --nginx-port "{NGINX_PORT}" --pg-port "{PG_PORT}" --vite-port "{VITE_PORT}" --join "{HOST_URL}" --key "{JOIN_KEY}"',
                $vars,
            )
            : $this->tpl(
                './deploy.sh --nginx-port "{NGINX_PORT}" --pg-port "{PG_PORT}" --vite-port "{VITE_PORT}"',
                $vars,
            );

        $tail = $kind === 'join'
            ? $this->tpl(<<<'SH'
say "This box is now a read-only mirror of {HOST_URL}."
say "The whole game replicates in over the next few minutes (watch: docker compose logs -f --tail 20)."
SH, $vars)
            : $this->tpl(<<<'SH'
PORT="$(grep -E '^[[:space:]]*NGINX_HOST_PORT=' .env | tail -1 | cut -d= -f2 | tr -d '[:space:]' || true)"
[ -n "${PORT:-}" ] || PORT="{NGINX_PORT}"
say "Ready. Open http://localhost:$PORT/setup to found your world."
say "Suggested game mode for this world: {GAME_MODE}  (pick it on the first setup screen)."
if command -v xdg-open >/dev/null 2>&1; then xdg-open "http://localhost:$PORT/setup" >/dev/null 2>&1 || true
elif command -v open >/dev/null 2>&1; then open "http://localhost:$PORT/setup" || true
fi
SH, $vars);

        return $header."\n".$deploy."\n\n".$tail."\n";
    }

    private function unixCommon(string $kind, array $vars): string
    {
        $title = $kind === 'join'
            ? 'Join an existing Cosmopolitan Governance App world (macOS / Linux / Raspberry Pi)'
            : 'Start a fresh Cosmopolitan Governance App world (macOS / Linux / Raspberry Pi)';

        $intro = $kind === 'join'
            ? 'Downloads the app, starts it in Docker, and auto-joins the world it was generated for as a read-only mirror.'
            : 'Downloads the app, starts it in Docker, and opens your own setup page so you found your own world.';

        return $this->tpl(<<<SH
#!/usr/bin/env bash
# {$title}
#
# {$intro}
# Generated by a founding operator. Pre-baked with share-safe defaults only
# (host ports{$this->soloModeNote($kind)}) - NO secrets beyond a single-use join key.
# Safe to run again; it reuses what exists.

set -euo pipefail

REPO="{REPO}"
BRANCH="{BRANCH}"

say()  { printf '\033[36m%s\033[0m\n' "\$*"; }
fail() { printf '\n\033[31m%s\033[0m\n' "\$*" >&2; exit 1; }

# 1. Docker present + running.
command -v docker >/dev/null 2>&1 || fail "Docker is not installed. Install Docker (Desktop on Mac; https://get.docker.com on Linux/Pi), then run this again."
docker info >/dev/null 2>&1 || fail "Docker is installed but not running. Start it and run this again."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is missing (Debian/Ubuntu/Pi: sudo apt install -y docker-compose-plugin), then run this again."

# 2. Find or download the app code.
if [ -f docker-compose.yml ]; then
  APP_DIR="\$(pwd)"
  say "Using the app code in this folder: \$APP_DIR"
else
  APP_DIR="\$HOME/fair-constitution-app"
  if [ -f "\$APP_DIR/docker-compose.yml" ]; then
    say "Found the app at \$APP_DIR"
  elif command -v git >/dev/null 2>&1; then
    say "Downloading the app to \$APP_DIR ..."
    git clone --depth 1 -b "\$BRANCH" "https://github.com/\$REPO.git" "\$APP_DIR"
  else
    say "Downloading the app to \$APP_DIR (no git - using a ZIP) ..."
    command -v unzip >/dev/null 2>&1 || fail "Need git or unzip installed (e.g. sudo apt install -y git), then run this again."
    tmp="\$(mktemp -d)"
    curl -fsSL "https://github.com/\$REPO/archive/refs/heads/\$BRANCH.zip" -o "\$tmp/app.zip"
    unzip -q "\$tmp/app.zip" -d "\$tmp"
    mv "\$tmp/fair-constitution-app-\$BRANCH" "\$APP_DIR"
    rm -rf "\$tmp"
  fi
fi
cd "\$APP_DIR"

# 3. Deploy. The one-command deploy writes a FRESH application key + its OWN
#    federation identity in ITS OWN database, so this box is never the same
#    identity as the one that generated this script.
say "Starting the app. The FIRST run downloads and builds everything (10-30 min); later starts take seconds..."
SH, $vars);
    }

    private function soloModeNote(string $kind): string
    {
        // Only the solo script advertises a suggested game mode as a bake-time
        // default (game mode is a setup-screen choice, not an .env value).
        return $kind === 'solo' ? ', suggested game mode' : '';
    }

    // ── Windows (.ps1) ─────────────────────────────────────────────────────

    private function renderWindows(string $kind, array $vars): string
    {
        $header = $this->windowsCommon($kind, $vars);

        $deploy = $kind === 'join'
            ? $this->tpl(
                './deploy.ps1 -NginxPort {NGINX_PORT} -PgPort {PG_PORT} -VitePort {VITE_PORT} -Join "{HOST_URL}" -Key "{JOIN_KEY}"',
                $vars,
            )
            : $this->tpl(
                './deploy.ps1 -NginxPort {NGINX_PORT} -PgPort {PG_PORT} -VitePort {VITE_PORT}',
                $vars,
            );

        $tail = $kind === 'join'
            ? $this->tpl(<<<'PS'
Say "This box is now a read-only mirror of {HOST_URL}."
Say "The whole game replicates in over the next few minutes (watch: docker compose logs -f --tail 20)."
PS, $vars)
            : $this->tpl(<<<'PS'
$port = {NGINX_PORT}
$portLine = Select-String -Path '.env' -Pattern '^\s*NGINX_HOST_PORT=(\d+)' | Select-Object -First 1
if ($portLine) { $port = [int]$portLine.Matches[0].Groups[1].Value }
$url = "http://localhost:$port/setup"
Say "Ready. Opening $url to found your world."
Say "Suggested game mode for this world: {GAME_MODE}  (pick it on the first setup screen)."
Start-Process $url
PS, $vars);

        return $header."\n".$deploy."\n\n".$tail."\n";
    }

    private function windowsCommon(string $kind, array $vars): string
    {
        $title = $kind === 'join'
            ? 'Join an existing Cosmopolitan Governance App world (Windows 10/11)'
            : 'Start a fresh Cosmopolitan Governance App world (Windows 10/11)';

        $intro = $kind === 'join'
            ? 'Downloads the app, starts it in Docker, and auto-joins the world it was generated for as a read-only mirror.'
            : 'Downloads the app, starts it in Docker, and opens your own setup page so you found your own world.';

        // NOTE: pure-ASCII only. render() also strips non-ASCII as a backstop.
        return $this->tpl(<<<PS
# {$title}
#
# {$intro}
# Generated by a founding operator. Pre-baked with share-safe defaults only -
# NO secrets beyond a single-use join key. Works in Windows PowerShell 5.1 and 7.
# Safe to run again; it reuses what exists.

\$ErrorActionPreference = 'Stop'
\$ProgressPreference = 'SilentlyContinue'
try {
    [Net.ServicePointManager]::SecurityProtocol = `
        [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
} catch { }

\$Repo   = '{REPO}'
\$Branch = '{BRANCH}'

function Say([string]\$msg) { Write-Host \$msg -ForegroundColor Cyan }

# 1. Docker present + running.
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is not installed. Install Docker Desktop, open it, wait for "Engine running", then run this again.'
}
cmd /c "docker info >nul 2>&1"
if (\$LASTEXITCODE -ne 0) {
    throw 'Docker is installed but not running. Open Docker Desktop, wait for "Engine running", then run this again.'
}

# 2. Find or download the app code.
if (Test-Path (Join-Path (Get-Location) 'docker-compose.yml')) {
    \$AppDir = (Get-Location).Path
    Say "Using the app code in this folder: \$AppDir"
} else {
    \$AppDir = Join-Path \$HOME 'fair-constitution-app'
    if (Test-Path (Join-Path \$AppDir 'docker-compose.yml')) {
        Say "Found the app at \$AppDir"
    } else {
        Say "Downloading the app to \$AppDir ..."
        \$zip = Join-Path \$env:TEMP 'fair-constitution-app.zip'
        Invoke-WebRequest -UseBasicParsing `
            -Uri "https://github.com/\$Repo/archive/refs/heads/\$Branch.zip" -OutFile \$zip
        \$extract = Join-Path \$env:TEMP ('cga-extract-' + [Guid]::NewGuid().ToString('N'))
        Expand-Archive -Path \$zip -DestinationPath \$extract -Force
        Move-Item (Join-Path \$extract "fair-constitution-app-\$Branch") \$AppDir
        Remove-Item \$zip -Force -ErrorAction SilentlyContinue
        Remove-Item \$extract -Recurse -Force -ErrorAction SilentlyContinue
    }
}
Set-Location \$AppDir

# 3. Deploy. The one-command deploy writes a FRESH application key + its OWN
#    federation identity in ITS OWN database, so this box is never the same
#    identity as the one that generated this script.
Say 'Starting the app. The FIRST run downloads and builds everything (10-30 min); later starts take seconds...'
PS, $vars);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Render {PLACEHOLDER} tokens from $vars via literal str_replace. */
    private function tpl(string $template, array $vars): string
    {
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{'.$key.'}';
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $template);
    }

    /** Drop any non-ASCII byte (keeps CR/LF/TAB). Windows PowerShell 5.1 safety. */
    private function toAscii(string $s): string
    {
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s);
    }
}
