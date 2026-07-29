<?php

namespace App\Jobs;

use App\Services\InstitutionProvisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The queued real run behind the /building "Provision missing institutions"
 * operator action (the UI twin of institutions:provision). Provisioning is
 * planet-scale and set-based/chunked, so it must NEVER run inline on the HTTP
 * request — this runs it off the request, and the /building 2s poll animates the
 * bars as rows land. One step, or the whole STEPS set. Idempotent + resumable
 * (partial unique indexes), so a re-dispatch is safe.
 */
class ProvisionInstitutionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Set-based + chunked; may run long at planet scale. */
    public int $timeout = 0;

    public function __construct(public readonly ?string $step = null)
    {
    }

    public function handle(InstitutionProvisionService $service): void
    {
        $steps = $this->step !== null ? [$this->step] : InstitutionProvisionService::STEPS;

        foreach ($steps as $step) {
            $service->provisionStep($step, null, audit: true);
        }
    }
}
