<?php

namespace App\Jobs\Identity;

use App\Models\StandingAttestation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * G-ID housekeeping (Phase G). Prunes standing attestations whose short TTL has
 * lapsed. They already FAIL CLOSED on expiry (verifyAttestation checks it), so this
 * is purely to keep the table bounded — attestations are minted per device, per
 * hour. Soft-delete (the SoftDeletes trait) preserves the row for forensic queries
 * while excluding it from the live set.
 */
class ExpireStandingAttestationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): int
    {
        return StandingAttestation::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
