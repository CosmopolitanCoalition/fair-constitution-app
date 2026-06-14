<?php

namespace App\Services\Jurisdictions;

use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\CulturalInstitution;
use App\Models\Legislature;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * F-LEG-028 — Cultural Institution of State recognition (Art. V §2). The chamber
 * supermajority is enforced by the `cultural_institution` vote-type at the vote
 * stage (PROTECTED); on adoption a POWERLESS row is recorded (no legislative,
 * executive, or judicial powers exist on the schema by construction).
 */
class CulturalInstitutionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PublicRecordService $records,
    ) {}

    /** Adoption effect (ChamberActService::applyProposalAdoption). */
    public function adoptRecognition(ChamberVoteProposal $proposal, ChamberVote $vote): array
    {
        $payload = (array) $proposal->payload;
        $legislature = $proposal->legislature()->firstOrFail();

        $institution = $this->recognize(
            $legislature,
            (string) ($payload['name'] ?? ''),
            $payload['description'] ?? null,
            (string) $vote->id,
        );

        return ['cultural_institutions', (string) $institution->id];
    }

    public function recognize(Legislature $legislature, string $name, ?string $description, ?string $voteId): CulturalInstitution
    {
        return DB::transaction(function () use ($legislature, $name, $description, $voteId) {
            $institution = CulturalInstitution::create([
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'name' => $name,
                'description' => $description,
                'recognition_vote_id' => $voteId,
                'status' => CulturalInstitution::STATUS_RECOGNIZED,
            ]);

            $record = $this->records->publish(
                kind: 'act',
                title: "Cultural Institution of State recognized — {$name}",
                body: 'Recognized by chamber supermajority (Art. V §2). A Cultural Institution of State '
                    .'holds no legislative, executive, or judicial powers — this recognition is an honour '
                    .'on the public record.',
                attrs: [
                    'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                    'legislature_id' => (string) $legislature->id,
                    'via_form' => 'F-LEG-028',
                    'subject_type' => 'cultural_institutions',
                    'subject_id' => (string) $institution->id,
                ],
            );

            $institution->forceFill(['record_id' => (string) $record->id])->save();

            $this->audit->append('legislature', 'cultural_institution.recognized', [
                'cultural_institution_id' => (string) $institution->id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'name' => $name,
            ], 'F-LEG-028', null, (string) $legislature->jurisdiction_id);

            return $institution->refresh();
        });
    }
}
