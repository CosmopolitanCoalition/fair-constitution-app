<?php

namespace App\Jobs\Federation;

use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\ClockService;
use App\Services\Federation\FederationSyncService;
use App\Services\Federation\PeerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * CLK-20 — Federation Sync Heartbeat (WF-JUR-06). On fire: ping every
 * trust_established peer (record liveness) and opportunistically push our Full
 * Faith & Credit tail, then RE-ARM for the next interval (the rolling-deadline
 * pattern, like CLK-02). A dead peer never breaks the heartbeat for the others;
 * a no-op when federation is disabled or there are no trusted peers.
 */
class FederationHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly ?string $timerId = null) {}

    public function handle(PeerService $peers, FederationSyncService $sync, ClockService $clocks): void
    {
        if (! InstanceSettings::current()->federation_enabled) {
            return; // the fire itself is already chained
        }

        $trusted = FederationPeer::query()
            ->whereNull('deleted_at')
            ->where('status', FederationPeer::STATUS_TRUST_ESTABLISHED)
            ->get();

        foreach ($trusted as $peer) {
            try {
                $peers->recordHeartbeat($peer);
                $sync->pushTo($peer);
            } catch (\Throwable $e) {
                report($e); // a dead peer must not break the heartbeat for the rest
            }
        }

        $this->rearm($clocks);
    }

    /** Re-arm the recurring heartbeat for the next interval. */
    private function rearm(ClockService $clocks): void
    {
        $minutes = max(1, (int) config('cga.federation_heartbeat_minutes', 5));

        $clocks->arm('CLK-20', firesAt: now()->addMinutes($minutes));
    }
}
