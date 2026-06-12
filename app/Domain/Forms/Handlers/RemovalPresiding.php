<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\RemovalProceeding;
use App\Models\User;
use App\Services\Legislature\OversightService;

/**
 * F-SPK-007 — Impeachment/Censure/Expulsion Presiding (chamber ops
 * §B.3/§D.3): open / advance removal proceedings.
 *
 * The Speaker presides over every removal proceeding EXCEPT their own —
 * PROTECTED rule `removal.presider` (Art. II §3); for the Speaker's own
 * case the chamber designates a substitute presider (designate_presider
 * motion), who then files this form holding only R-09. The role gate is
 * therefore ['R-10', 'R-09'] (catalog lists R-10; the designated-presider
 * branch is the documented Art. II §3 · as-implemented extension) with the
 * handler pinning that an R-09 actor IS the proceeding's designated
 * presider.
 *
 * Actions:
 *  - `open`      {legislature_id, kind, subject_member_id, presider_member_id?}
 *  - `designate` {proceeding_id, presider_member_id}
 *  - `open_vote` {proceeding_id}
 */
class RemovalPresiding implements FormHandler
{
    public function __construct(
        private readonly OversightService $oversight,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'oversight.presiding';
    }

    public function requiredRoles(): array
    {
        return ['R-10', 'R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        return match ((string) ($payload['action'] ?? '')) {
            'open'      => $this->open($actor, $payload),
            'designate' => $this->designate($actor, $payload),
            'open_vote' => $this->openVote($actor, $payload),
            default     => throw new ConstitutionalViolation(
                'F-SPK-007 actions: open, designate, open_vote.',
                'CGA Forms Catalog (F-SPK-007)'
            ),
        };
    }

    private function open(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-SPK-007');

        $subject = LegislatureMember::query()
            ->whereKey($payload['subject_member_id'] ?? null)
            ->first();

        if ($subject === null) {
            throw new ConstitutionalViolation('F-SPK-007 open requires a subject_member_id.', 'Art. II §3');
        }

        // Default presider = the Speaker — except their own case, where
        // the chamber must designate (removal.presider).
        $presider = null;

        if ($actor !== null) {
            $actorMember = ChamberActor::member($actor, (string) $legislature->id, 'F-SPK-007');

            $isSpeaker = $legislature->speaker_id !== null
                && (string) $legislature->speaker_id === (string) $actorMember->id;

            if (! $isSpeaker) {
                throw new ConstitutionalViolation(
                    'Only the Speaker opens removal proceedings; a designated presider takes over '
                    . 'AFTER designation (Art. II §3).',
                    'Art. II §3'
                );
            }

            // Speaker's own case → proceeding opens presider-less; the
            // chamber designates next.
            $presider = (string) $actorMember->id !== (string) $subject->id ? $actorMember : null;
        }

        $proceeding = $this->oversight->openProceeding(
            $legislature,
            (string) ($payload['kind'] ?? ''),
            'legislature_members',
            (string) $subject->id,
            $presider,
            openedVia: 'F-SPK-007',
        );

        return [
            'action'         => 'open',
            'proceeding_id'  => (string) $proceeding->id,
            'kind'           => $proceeding->kind,
            'subject_id'     => (string) $subject->id,
            'presider_id'    => $proceeding->presided_by_member_id !== null ? (string) $proceeding->presided_by_member_id : null,
            'status'         => $proceeding->status,
        ];
    }

    private function designate(?User $actor, array $payload): array
    {
        $proceeding = $this->proceeding($payload);

        $presider = LegislatureMember::query()
            ->whereKey($payload['presider_member_id'] ?? null)
            ->where('legislature_id', $proceeding->legislature_id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->first();

        if ($presider === null) {
            throw new ConstitutionalViolation(
                'The designated presider must be a currently serving member of this chamber.',
                'Art. II §3'
            );
        }

        $this->oversight->designatePresider($proceeding, $presider);

        return [
            'action'        => 'designate',
            'proceeding_id' => (string) $proceeding->id,
            'presider_id'   => (string) $presider->id,
            'status'        => $proceeding->status,
        ];
    }

    private function openVote(?User $actor, array $payload): array
    {
        $proceeding = $this->proceeding($payload);

        $opener = null;

        if ($actor !== null) {
            $opener = ChamberActor::member($actor, (string) $proceeding->legislature_id, 'F-SPK-007');

            // R-09 actors must BE the designated presider; the Speaker
            // presides by office (except their own case — designation
            // already excluded them there).
            $legislature = Legislature::query()->findOrFail($proceeding->legislature_id);

            $isSpeaker = $legislature->speaker_id !== null
                && (string) $legislature->speaker_id === (string) $opener->id;

            $isPresider = $proceeding->presided_by_member_id !== null
                && (string) $proceeding->presided_by_member_id === (string) $opener->id;

            if (! $isPresider && ! $isSpeaker) {
                throw new ConstitutionalViolation(
                    'Only the proceeding\'s presider (or the Speaker, where they preside) opens its vote.',
                    'Art. II §3'
                );
            }
        }

        $vote = $this->oversight->openRemovalVote($proceeding, $opener);

        return [
            'action'        => 'open_vote',
            'proceeding_id' => (string) $proceeding->id,
            'vote_id'       => (string) $vote->id,
            'status'        => $proceeding->refresh()->status,
        ];
    }

    private function proceeding(array $payload): RemovalProceeding
    {
        $proceeding = RemovalProceeding::query()->find($payload['proceeding_id'] ?? null);

        if ($proceeding === null) {
            throw new ConstitutionalViolation('F-SPK-007 requires a valid proceeding_id.', 'Art. II §3');
        }

        return $proceeding;
    }
}
