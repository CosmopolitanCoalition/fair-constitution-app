<?php

namespace App\Console\Commands;

use App\Models\ResidencyClaim;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * civic:travelling — the CLI twin of POST /civic/relocation/travelling
 * (UI↔CLI parity). "I'm travelling, keep my residency" is an audited
 * standing declaration, not a form (there is no F-ID for it, by design —
 * RelocationController §B.14), so the CLI mirrors the SAME audit append the
 * web action performs rather than filing through the engine.
 *
 *   php artisan civic:travelling --user=<uuid|email>
 *
 * Changes nothing about residency or any association; it records on the
 * hash-chained audit log that a sustained away-pattern (if one ever forms)
 * should be read as travel. A CLI has no current user, so --user names the
 * actor (the dev:board-seat idiom).
 */
class CivicTravellingCommand extends Command
{
    protected $signature = 'civic:travelling {--user= : the resident — email or user UUID}';

    protected $description = "Declare travel (keep residency) for a user — the CLI half of POST /civic/relocation/travelling";

    public function handle(AuditService $audit): int
    {
        $ref = (string) $this->option('user');
        if ($ref === '') {
            $this->error('Give --user=<email|uuid>.');

            return self::FAILURE;
        }

        $user = Str::isUuid($ref)
            ? User::query()->find($ref)
            : User::query()->where('email', $ref)->first();

        if ($user === null) {
            $this->error("No such user: {$ref}.");

            return self::FAILURE;
        }

        $active = ResidencyClaim::query()
            ->where('user_id', (string) $user->getKey())
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->first();

        // Byte-for-byte the web action's append — same module/event/ref/payload,
        // so the two entry points are indistinguishable on the audit chain.
        $audit->append(
            module: 'residency',
            event: 'relocation.travelling_declared',
            payload: [
                'note' => 'Resident marked the away pattern as travel — detection resets; '
                    . 'residency and every association stay exactly as they are.',
            ],
            ref: 'WF-CIV-03',
            actorId: (string) $user->getKey(),
            jurisdictionId: $active?->jurisdiction_id !== null ? (string) $active->jurisdiction_id : null,
        );

        $this->info('Marked as travel — nothing changes. Residency and every association stay active; '
            .'the system only asks again if a new sustained pattern forms (Art. V §1 · CLK-05).');

        return self::SUCCESS;
    }
}
