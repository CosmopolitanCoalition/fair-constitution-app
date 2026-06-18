#!/usr/bin/env bash
#
# Phase G (G8b / C7) — macOS double-click entry point. Finder runs a .command file in a
# Terminal; this just resolves its own directory and hands off to the shared bootstrap.sh
# (which branches on uname for Darwin). Keep it a thin wrapper so the mesh logic lives in
# ONE place. Make it executable once: chmod +x bootstrap/bootstrap.command
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$HERE/bootstrap.sh" "$@"
