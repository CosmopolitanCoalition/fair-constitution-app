<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\CourtCase;
use App\Models\Jury;
use App\Models\JuryMember;
use App\Services\AuditService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Illuminate\Support\Facades\DB;

/**
 * The random draw from the eligible jurisdictionally-associated pool, voir-dire
 * screening, empanelment, and replacement draws on excusal
 * (PHASE_E_DESIGN_cases_juries §C). "A jury of their peers" (Art. IV §4).
 *
 * The pool is residents with an active residency confirmation in the eligible
 * jurisdiction (the R-03/R-04 substrate). The draw seed is PUBLISHED to the
 * audit chain — anyone can verify the draw. Excusal is CONFLICT-ONLY (never
 * opinion/demographics/politics). No fee field exists anywhere on this path —
 * the no-fee shield (Art. II §8) is structural.
 */
class JuryService
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly AuditService $audit,
        private readonly RoleService $roles,
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * F-JDG-002 — draw `seats + alternates` jurors at random from the eligible
     * pool, summon them, and advance the case to `jury_empaneled`. The seed is
     * published so the draw reproduces.
     */
    public function empanel(CourtCase $case, ?string $seed = null, ?int $seats = null, ?int $alternates = null): Jury
    {
        if (! $case->jury_entitled || $case->jury_waived) {
            throw new ConstitutionalViolation(
                'A jury is drawn only for a jury-entitled, un-waived criminal case (Art. IV §4).',
                'Art. IV §4'
            );
        }

        if ($case->status !== CourtCase::STATUS_PANELED) {
            throw new ConstitutionalViolation(
                'A jury empanels after the bench is seated (Art. IV §4).',
                'Art. IV §4'
            );
        }

        $eligibleJurisdictionId = (string) $case->jurisdiction_id;
        $seats ??= 12;
        $alternates ??= 2;
        $draw = $seats + $alternates;

        // The eligible pool: active jurisdictional association (R-03 substrate;
        // residency_confirmations has no soft delete — is_active IS the gate).
        $pool = DB::table('residency_confirmations')
            ->where('jurisdiction_id', $eligibleJurisdictionId)
            ->where('is_active', true)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $poolSize = count($pool);

        if ($poolSize < $draw) {
            throw new ConstitutionalViolation(
                sprintf(
                    'The eligible jury pool (%d residents) is smaller than the %d jurors to draw (Art. IV §4).',
                    $poolSize,
                    $draw
                ),
                'Art. IV §4'
            );
        }

        $seed ??= bin2hex(random_bytes(16));

        return DB::transaction(function () use ($case, $eligibleJurisdictionId, $pool, $poolSize, $seats, $alternates, $draw, $seed): Jury {
            $jury = Jury::create([
                'case_id' => (string) $case->id,
                'pool_size' => $poolSize,
                'eligible_jurisdiction_id' => $eligibleJurisdictionId,
                'seats' => $seats,
                'alternates' => $alternates,
                'draw_seed' => $seed,
                'status' => Jury::STATUS_VOIR_DIRE,
            ]);

            $drawn = $this->deterministicDraw($pool, $seed, $draw);

            foreach ($drawn as $i => $userId) {
                JuryMember::create([
                    'jury_id' => (string) $jury->id,
                    'user_id' => $userId,
                    'seat_kind' => $i < $seats ? JuryMember::SEAT_JUROR : JuryMember::SEAT_ALTERNATE,
                    'screening_status' => JuryMember::SCREENING_SUMMONED,
                ]);

                $this->roles->flushUser($userId);
            }

            // Seal the draw seed to the chain (the audit-verifiable draw).
            $this->audit->append(
                module: 'judiciary',
                event: 'jury.drawn',
                payload: [
                    'jury_id' => (string) $jury->id,
                    'case_id' => (string) $case->id,
                    'pool_size' => $poolSize,
                    'seats' => $seats,
                    'alternates' => $alternates,
                    'draw_seed' => $seed,
                    'drawn_count' => count($drawn),
                ],
                ref: 'F-JDG-002',
                jurisdictionId: (string) $case->jurisdiction_id,
            );

            $this->cases->markJuryEmpaneled($case->refresh(), (string) $jury->id);

            return $jury;
        });
    }

    /**
     * voir dire: a confirmed CONFLICT (or hardship) excuses a juror without
     * penalty and triggers a replacement draw from the unsummoned pool. Excusal
     * may NEVER be opinion/demographics/politics — only conflict | hardship
     * (the hardened gloss; the DB CHECK is the belt).
     */
    public function excuseAndReplace(JuryMember $member, string $reason): ?JuryMember
    {
        if (! in_array($reason, [JuryMember::EXCUSAL_CONFLICT, JuryMember::EXCUSAL_HARDSHIP], true)) {
            throw new ConstitutionalViolation(
                'voir dire removes CONFLICTS only — a juror is never excused for opinion, demographics, or politics (Art. IV §4).',
                'Art. IV §4'
            );
        }

        return DB::transaction(function () use ($member, $reason): ?JuryMember {
            $jury = Jury::query()->findOrFail((string) $member->jury_id);

            $member->forceFill([
                'screening_status' => JuryMember::SCREENING_EXCUSED,
                'excusal_reason' => $reason,
            ])->save();

            $this->roles->flushUser((string) $member->user_id);

            // Replacement draw: the next eligible resident not already drawn,
            // the seed advancing past the already-summoned set.
            $alreadyDrawn = JuryMember::query()
                ->where('jury_id', (string) $jury->id)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $remaining = DB::table('residency_confirmations')
                ->where('jurisdiction_id', (string) $jury->eligible_jurisdiction_id)
                ->where('is_active', true)
                ->whereNotIn('user_id', $alreadyDrawn)
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($remaining === []) {
                return null; // the pool is exhausted — the court reduces the panel
            }

            $next = $this->deterministicDraw($remaining, $jury->draw_seed.'|replace|'.$member->id, 1)[0];

            $replacement = JuryMember::create([
                'jury_id' => (string) $jury->id,
                'user_id' => $next,
                'seat_kind' => $member->seat_kind,
                'screening_status' => JuryMember::SCREENING_SUMMONED,
            ]);

            $this->roles->flushUser($next);

            $this->audit->append(
                module: 'judiciary',
                event: 'jury.replacement_drawn',
                payload: [
                    'jury_id' => (string) $jury->id,
                    'excused_member_id' => (string) $member->id,
                    'reason' => $reason,
                    'replacement_member_id' => (string) $replacement->id,
                ],
                ref: 'F-JDG-002',
            );

            return $replacement;
        });
    }

    /**
     * A deterministic, reproducible draw of $n user ids from $pool keyed by the
     * published seed — anyone re-running with the same seed gets the same draw.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    public function deterministicDraw(array $pool, string $seed, int $n): array
    {
        usort($pool, function (string $a, string $b) use ($seed): int {
            return hash('sha256', $seed.'|'.$a) <=> hash('sha256', $seed.'|'.$b);
        });

        return array_slice(array_values($pool), 0, $n);
    }
}
