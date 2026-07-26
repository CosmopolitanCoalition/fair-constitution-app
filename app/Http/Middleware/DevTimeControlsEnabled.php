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
 * `cga.impersonation`, sandbox game mode — PLUS their own key,
 * `cga.dev_time`, because they are strictly more dangerous than
 * impersonation. Impersonation READS the world from someone else's seat;
 * these WRITE constitutional acts and move deadlines.
 *
 * The federation refusal is the one that matters most and is not optional.
 * Full Faith & Credit means a peer takes this node's records ON TRUST — it
 * does not re-derive them. A node other nodes trust must never be able to
 * manufacture a vote or move a deadline, because the fabrication would
 * propagate as history. So: any peer, any federation, or an
 * `authoritative_server_id` pointing elsewhere, and this refuses outright.
 * That check runs even in local sandbox, because a laptop can be peered.
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

        if (! config('cga.dev_time', false)) {
            return 'Playtest time controls are off. Set CGA_DEV_TIME=true to enable them (local sandbox only).';
        }

        if (! GameMode::isSandbox()) {
            return 'This world is not in sandbox mode. Time controls are refused on a real world.';
        }

        if (self::connectedToAnyPeer()) {
            return 'This instance is federated, mirrors another, or holds a peer. Time controls are '
                .'refused: a node that other nodes trust must never be able to time-travel, because '
                .'a peer takes its records on trust and cannot tell a played timeline from a lived one.';
        }

        return null;
    }

    /**
     * True when this instance is federated, holds any peer, or is not its own
     * authority. Deliberately fails CLOSED: if the check itself errors we
     * treat the node as connected, because the cost of being wrong in the
     * permissive direction is a fabricated record inside somebody else's
     * chain of trust.
     */
    private static function connectedToAnyPeer(): bool
    {
        try {
            // `mirror_of_server_id` is the instance-level authority flag: set
            // means this node MIRRORS another and is authoritative for
            // nothing. (The per-record `authoritative_server_id` the charter
            // describes lives on the synced rows, not here.)
            if (Schema::hasTable('instance_settings')) {
                $settings = DB::table('instance_settings')->first();

                if ($settings !== null && ! empty($settings->mirror_of_server_id)) {
                    return true;
                }
            }

            if (Schema::hasTable('federation_peers') && DB::table('federation_peers')->exists()) {
                return true;
            }

            // ADDED for the time controls specifically. The instance-level flags
            // above answer "is this node a mirror?"; this answers "does this node
            // hold any record somebody else is authoritative for?" — which is the
            // one that matters when the action is MOVING PER-JURISDICTION
            // DEADLINES. Advancing a jurisdiction whose authority lives elsewhere
            // would rewrite another node's schedule, and Full Faith & Credit means
            // they would take it on trust.
            if (Schema::hasTable('jurisdictions')) {
                $foreign = DB::table('jurisdictions')
                    ->whereNotNull('authoritative_server_id')
                    ->whereNull('deleted_at')
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
