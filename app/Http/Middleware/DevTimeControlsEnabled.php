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
        // Cheap flags first; the DB reads only if those pass.
        abort_unless(
            app()->environment('local')
                && config('cga.impersonation', true)
                && config('cga.dev_time', false)
                && GameMode::isSandbox(),
            404
        );

        abort_if($this->isConnectedToAnyPeer(), 404);

        return $next($request);
    }

    /**
     * True when this instance is federated, holds any peer, or is not its own
     * authority. Deliberately fails CLOSED: if the check itself errors we
     * treat the node as connected, because the cost of being wrong in the
     * permissive direction is a fabricated record inside somebody else's
     * chain of trust.
     */
    private function isConnectedToAnyPeer(): bool
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

            return false;
        } catch (\Throwable) {
            return true;
        }
    }
}
