<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Handlers\Concerns\ResolvesLegislativeActor;
use App\Models\Legislature;
use App\Models\User;
use App\Services\SessionService;
use Carbon\Carbon;

/**
 * F-SPK-001 — Session Call / Opening (R-10; system filings allowed —
 * first-session bootstrap and the CLK-02 posture file with a null actor,
 * which bypasses the role gate per the engine's system-filing rule).
 *
 * A human filer must be the chamber's Speaker (their member row =
 * legislatures.speaker_id) — R-10 derivation lands with the chamber-ops
 * scope; the fact check here is authoritative either way.
 */
class SessionCall implements FormHandler
{
    use ResolvesLegislativeActor;

    public function __construct(private readonly SessionService $sessions)
    {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'session.called';
    }

    public function requiredRoles(): array
    {
        return ['R-10'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislature = Legislature::query()->find($payload['legislature_id'] ?? null);

        if ($legislature === null) {
            throw new ConstitutionalViolation('Unknown legislature.', 'Art. II §2 · as implemented');
        }

        $calledBy = null;

        if ($actor !== null) {
            $calledBy = $this->currentMemberOf($actor, (string) $legislature->id);

            if ((string) $legislature->speaker_id !== (string) $calledBy->id) {
                throw new ConstitutionalViolation(
                    'Sessions are called by the chamber\'s Speaker (or the system).',
                    'Art. II §3'
                );
            }
        }

        $session = $this->sessions->call(
            legislature: $legislature,
            calledBy: $calledBy,
            scheduledFor: isset($payload['scheduled_for']) ? Carbon::parse($payload['scheduled_for']) : null,
            openNow: (bool) ($payload['open_now'] ?? false),
        );

        return [
            'session_id'      => (string) $session->id,
            'session_no'      => $session->session_no,
            'status'          => $session->status,
            'scheduled_for'   => $session->scheduled_for?->toIso8601String(),
            'serving_at_open' => $session->serving_at_open,
            'quorum_required' => $session->quorum_required,
            'called_by'       => $calledBy?->id !== null ? (string) $calledBy->id : 'system',
            'jurisdiction_id' => (string) $legislature->jurisdiction_id,
        ];
    }
}
