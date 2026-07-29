<?php

namespace App\Http\Middleware;

use App\Support\GameMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the playtest controls (DEV_TIME_AND_ROLE_CONTROLS.md §4).
 *
 * These take the SAME gate as the rest of the dev toolbox — local env,
 * `cga.impersonation` — PLUS the world's own founding choice. RULED
 * 2026-07-28 (V3_SYNTHESIS_PLAN.md §10 item 4): Demo mode unlocks the
 * clocks. A founder who chose Demo (sandbox) mode at setup gets working
 * time controls with NO .env edit — the setup choice IS the switch. The
 * `cga.dev_time` key still exists as a red flag (launch:assert-clean and
 * deploy.sh both treat an explicitly-set key as a launch blocker) but it
 * cannot open a real world: sandbox mode is required regardless of the key.
 *
 * The federation refusal is REFINED by the same ruling, not removed. Full
 * Faith & Credit means a peer takes this node's records ON TRUST — it does
 * not re-derive them — so a mesh containing any REAL node must never
 * time-travel. But demo instances are EXPECTED to peer for full-scale
 * multibox testing, so the refusal keys on "connected to any NON-demo
 * node", never on "peered at all". A node counts as demo ONLY when its
 * signed handshake declared it; anything undeclared reads as real, which
 * is the fail-closed direction.
 *
 * DEMO-MESH TIME COORDINATION (flagged to lane 2, deliberately NOT built
 * here): when a demo mesh time-travels, per-node advances skew shared
 * deadlines — two nodes advancing independently disagree about what is
 * due. A demo mesh should advance time through ONE coordinating node (or
 * explicitly assert skew tolerance). This gate decides only WHETHER a
 * node may advance; ordering an advance across a mesh is lane 2's
 * multibox work.
 *
 * 404 rather than 403, matching DevToolsEnabled: a disabled control must be
 * indistinguishable from one that was never built.
 */
class DevTimeControlsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::refusalReason() === null, 404);

        return $next($request);
    }

    /**
     * The same gate, as a callable check — because the console commands
     * (`dev:clock-fire`, `dev:clock-advance`) do the identical damage and never
     * pass through middleware. One implementation, so the CLI and the HTTP
     * routes can never drift apart on what is allowed.
     *
     * Returns NULL when the controls may run, or one plain sentence saying why
     * not. A command can print that; the middleware just 404s.
     */
    public static function refusalReason(): ?string
    {
        if (! app()->environment('local')) {
            return 'Playtest controls run only in the local environment.';
        }

        if (! config('cga.impersonation', true)) {
            return 'The dev toolbox is switched off (cga.impersonation).';
        }

        if (! GameMode::isSandbox()) {
            // RULED 2026-07-28 (§10 item 4): the founding choice is the switch.
            // The env key cannot substitute for it — a world founded in
            // production mode never gets time controls, key or no key.
            if (config('cga.dev_time', false)) {
                return 'This world is not in sandbox mode. Time controls are refused on a real world — '
                    .'CGA_DEV_TIME cannot override the founding choice.';
            }

            return 'Playtest time controls are off. They switch on when a world is founded in Demo '
                .'(sandbox) mode — the setup choice is the switch, no env key needed.';
        }

        if (self::connectedToAnyNonDemoNode()) {
            return 'This instance is connected to a node that has not declared itself a demo — a mirror '
                .'host, a peer, or the authority for records this node holds. Time controls are refused: '
                .'a node that real nodes trust must never time-travel, because a peer takes its records '
                .'on trust and cannot tell a played timeline from a lived one. A mesh made only of '
                .'declared demo instances may.';
        }

        return null;
    }

    /**
     * True when this instance is connected to any node NOT known to be a demo
     * — as a mirror of it, as a federation peer, or by holding records it is
     * authoritative for.
     *
     * REFINED (ruling 2026-07-28, §10 item 4): demo instances are EXPECTED to
     * peer for full-scale multibox testing, so a peer that AFFIRMATIVELY
     * declared itself a demo in its signed handshake does not refuse the
     * controls. Today that declaration is `instance_class = scale_demo`
     * (advertised in the signed identity payload, persisted to the peer row's
     * metadata; class-scoped federation already guarantees a scale_demo
     * instance holds ONLY scale_demo peers). The rail also honours a declared
     * `game_mode = sandbox`, so the moment the handshake carries game mode
     * (flagged to lane 2), sandbox dev meshes light up with no change here.
     * Anything undeclared — including every row minted by the class-blind
     * mirror-adoption exchange — reads as REAL.
     *
     * Soft-deleted and departed peers still count: their chains already hold
     * whatever they took from us on trust.
     *
     * Deliberately fails CLOSED: if the check itself errors we treat the node
     * as connected to a real one, because the cost of being wrong in the
     * permissive direction is a fabricated record inside somebody else's
     * chain of trust.
     */
    private static function connectedToAnyNonDemoNode(): bool
    {
        try {
            // Server ids that PROVED demo-ness in their signed handshake.
            // Includes soft-deleted rows on purpose: a departed demo peer can
            // still vouch for the records it was once authoritative for.
            $demoServers = Schema::hasTable('federation_peers')
                ? DB::table('federation_peers')
                    ->where(function ($q) {
                        $q->whereRaw("metadata->>'instance_class' = ?", ['scale_demo'])
                            ->orWhereRaw("metadata->>'game_mode' = ?", ['sandbox']);
                    })
                    ->pluck('server_id')
                : collect();

            // `mirror_of_server_id` is the instance-level authority flag: set
            // means this node MIRRORS another and is authoritative for
            // nothing. A mirror of a DECLARED DEMO host is part of a demo
            // mesh; a mirror of anything else sits inside a real chain of
            // trust. (The per-record `authoritative_server_id` the charter
            // describes lives on the synced rows, not here.)
            if (Schema::hasTable('instance_settings')) {
                $settings = DB::table('instance_settings')->first();

                if ($settings !== null && ! empty($settings->mirror_of_server_id)
                    && ! $demoServers->contains($settings->mirror_of_server_id)) {
                    return true;
                }
            }

            // Any peer that never declared demo-ness — including soft-deleted
            // and departed rows, exactly as strict as the pre-ruling check was
            // for undeclared peers.
            if (Schema::hasTable('federation_peers')) {
                $undeclared = DB::table('federation_peers')
                    ->where(function ($q) {
                        $q->whereRaw("coalesce(metadata->>'instance_class', '') <> ?", ['scale_demo'])
                            ->whereRaw("coalesce(metadata->>'game_mode', '') <> ?", ['sandbox']);
                    })
                    ->exists();

                if ($undeclared) {
                    return true;
                }
            }

            // ADDED for the time controls specifically. The instance-level flags
            // above answer "is this node a mirror?"; this answers "does this node
            // hold any record somebody else is authoritative for?" — which is the
            // one that matters when the action is MOVING PER-JURISDICTION
            // DEADLINES. Advancing a jurisdiction whose authority lives elsewhere
            // would rewrite another node's schedule, and Full Faith & Credit means
            // they would take it on trust. Fine when that authority is a declared
            // demo (its schedule is played, same as ours); refused otherwise.
            if (Schema::hasTable('jurisdictions')) {
                $foreign = DB::table('jurisdictions')
                    ->whereNotNull('authoritative_server_id')
                    ->whereNull('deleted_at')
                    ->whereNotIn('authoritative_server_id', $demoServers)
                    ->exists();

                if ($foreign) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return true;
        }
    }
}
