<?php

namespace App\Services\Provision;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\Department;
use App\Models\Executive;
use App\Models\Legislature;
use App\Services\AuditService;
use App\Services\Demo\Stages\GovernanceStage;
use App\Services\ElectionLifecycleService;
use App\Services\InstitutionScaleService;
use Illuminate\Support\Facades\DB;

/**
 * One legislature's unit of Step 4 work (Wave 6, items 5 and 6):
 *
 *   1. THE SEAT (ruling seat-mint-owner A): one general election and its
 *      races through ElectionLifecycleService::scheduleGeneral, after the
 *      board landed in the shell batch. A zero-seat chamber is skipped
 *      (ruling zero-seat-chambers A); a fully blocked race plan files as
 *      review with the plan's reasons and mints no orphan election row.
 *   2. COMMITTEES to K(S) and DEPARTMENTS to D(P) as SYSTEM ACTS (ruling
 *      sub-institutions-path B): F-LEG-009 and F-LEG-016 filed by the system
 *      actor through the engine, recorded on the chain, no chamber vote. The
 *      five mandatory department kinds come first.
 *
 * Idempotent: an existing scheduled election is adopted, existing committees
 * and departments count toward their targets. The manifest of what the unit
 * minted lands on the ledger row; the rollback reads it.
 */
class LegislatureUnitProcessor
{
    public function __construct(
        private readonly ElectionLifecycleService $lifecycle,
        private readonly ConstitutionalEngine $engine,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{status:string, manifest:array<string,mixed>, reason:?string}
     */
    public function process(string $legislatureId, ?callable $beat = null): array
    {
        $legislature = Legislature::query()->with('jurisdiction')->find($legislatureId);
        if ($legislature === null) {
            return ['status' => 'skipped', 'manifest' => [], 'reason' => 'legislature row gone'];
        }

        $manifest = ['seat' => null, 'committees' => [], 'departments' => []];
        $review   = null;

        // ONE ENTRY PER LEGISLATURE (operator ruling 2026-09-06): the founding
        // acts buffer into one hash-chained entry, taking the global append
        // lock once instead of ~20 times. Each section is checkpointed so a
        // rolled-back filing's buffered acts are dropped. commitBatch always
        // runs in the finally.
        $this->audit->beginBatch();
        try {
            // ── 1. The seat ────────────────────────────────────────────────
            $mark = $this->audit->batchMark();
            try {
                $seat = $this->seat($legislature);
                $manifest['seat'] = $seat;
                if (($seat['blocked'] ?? false) === true) {
                    $review = 'seat: '.$seat['reason'];
                }
            } catch (\Throwable $e) {
                $this->audit->batchTruncate($mark);
                $review = 'seat: '.self::short($e);
            }
            if ($beat !== null) {
                $beat('seat');
            }

            // ── 2. Committees to K(S) ──────────────────────────────────────
            $mark = $this->audit->batchMark();
            try {
                $manifest['committees'] = $this->committees($legislature);
            } catch (\Throwable $e) {
                $this->audit->batchTruncate($mark);
                $review = ($review !== null ? $review.' | ' : '').'committees: '.self::short($e);
            }
            if ($beat !== null) {
                $beat('committees');
            }

            // ── 3. Departments to D(P) ─────────────────────────────────────
            $mark = $this->audit->batchMark();
            try {
                $manifest['departments'] = $this->departments($legislature);
            } catch (\Throwable $e) {
                $this->audit->batchTruncate($mark);
                $review = ($review !== null ? $review.' | ' : '').'departments: '.self::short($e);
            }
        } finally {
            $this->audit->commitBatch(
                module: 'jurisdictions',
                event: 'legislature_founded',
                ref: 'WF-JUR-01',
                jurisdictionId: (string) $legislature->jurisdiction_id,
            );
        }

        return [
            'status'   => $review === null ? 'done' : 'review',
            'manifest' => $manifest,
            'reason'   => $review,
        ];
    }

    /** @return array<string,mixed> */
    private function seat(Legislature $legislature): array
    {
        if ((int) $legislature->total_seats <= 0) {
            return ['skipped' => 'zero seats'];
        }

        $hasBoard = DB::table('election_boards')
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
        if (! $hasBoard) {
            return ['blocked' => true, 'reason' => 'no active election board (shell missing)'];
        }

        $plan = $this->lifecycle->racePlan($legislature);
        if ($plan['fully_blocked'] ?? false) {
            $reasons = [];
            foreach ($plan['kinds'] as $kind => $spec) {
                if (($spec['mode'] ?? '') === 'blocked') {
                    $reasons[] = $kind.': '.($spec['reason'] ?? 'blocked');
                }
            }

            return ['blocked' => true, 'reason' => implode('; ', $reasons) ?: 'race plan fully blocked'];
        }

        $election = $this->lifecycle->scheduleGeneral($legislature);
        $races    = (int) DB::table('election_races')->where('election_id', $election->id)->count();

        $out = ['election_id' => (string) $election->id, 'races' => $races, 'status' => $election->status];
        if ($plan['blocked'] ?? false) {
            $blocked = [];
            foreach ($plan['kinds'] as $kind => $spec) {
                if (($spec['mode'] ?? '') === 'blocked') {
                    $blocked[$kind] = $spec['reason'] ?? 'blocked';
                }
            }
            $out['partially_blocked'] = $blocked;
        }

        return $out;
    }

    /** @return list<string> committee ids minted by this unit */
    private function committees(Legislature $legislature): array
    {
        $seatsTotal = (int) $legislature->total_seats;
        $target     = InstitutionScaleService::committeeTarget($seatsTotal);
        if ($target < 1) {
            return [];
        }

        $existing = DB::table('committees')
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->all();
        if (count($existing) >= $target) {
            return [];
        }

        $seats  = max(1, min(GovernanceStage::SEATS_PER_COMMITTEE, $seatsTotal));
        $minted = [];
        foreach (GovernanceStage::COMMITTEE_NAMES as $name) {
            if (count($existing) + count($minted) >= $target) {
                break;
            }
            if (in_array($name, $existing, true)) {
                continue;
            }
            $result = $this->engine->file('F-LEG-009', null, [
                'legislature_id'  => (string) $legislature->id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'name'            => $name,
                'purpose'         => "Standing committee on {$name}.",
                'seats'           => $seats,
                'system_act'      => true,
            ]);
            $minted[] = (string) ($result->recorded['committee_id'] ?? '');
        }

        return array_values(array_filter($minted));
    }

    /** @return list<string> department ids minted by this unit */
    private function departments(Legislature $legislature): array
    {
        $population = (int) ($legislature->jurisdiction?->population ?? 0);
        $target     = InstitutionScaleService::departmentTarget($population);
        if ($target < 1) {
            return [];
        }

        $executive = Executive::query()
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first();
        if ($executive === null) {
            throw new \RuntimeException('no executive to oversee a department (shell missing)');
        }

        $held = DB::table('departments')
            ->where('jurisdiction_id', $legislature->jurisdiction_id)
            ->where('status', '!=', Department::STATUS_DISSOLVED)
            ->whereNull('deleted_at')
            ->pluck('kind')
            ->all();
        $existing = count($held);
        if ($existing >= $target) {
            return [];
        }

        $plan = [];
        foreach (GovernanceStage::DEPT_NAMES as $kind => $name) {
            if (! in_array($kind, $held, true)) {
                $plan[] = ['kind' => $kind, 'name' => $name];
            }
        }
        $i = 1;
        while ($existing + count($plan) < $target) {
            $plan[] = ['kind' => Department::KIND_OTHER, 'name' => "Agency {$i}"];
            $i++;
        }

        $minted = [];
        foreach ($plan as $dept) {
            if ($existing + count($minted) >= $target) {
                break;
            }
            $result = $this->engine->file('F-LEG-016', null, [
                'legislature_id'  => (string) $legislature->id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'executive_id'    => (string) $executive->id,
                'name'            => $dept['name'],
                'kind'            => $dept['kind'],
                'charter'         => [
                    'function_text'             => "Carries the {$dept['name']} function of the executive.",
                    'powers_text'               => 'As chartered at the founding of this jurisdiction.',
                    'reporting_interval_months' => 6,
                ],
                'owner_seats' => 1,
                'nominees'    => [],
                'system_act'  => true,
            ]);
            $minted[] = (string) ($result->recorded['department_id'] ?? '');
        }

        return array_values(array_filter($minted));
    }

    private static function short(\Throwable $e): string
    {
        return mb_substr(get_class($e).': '.$e->getMessage(), 0, 400);
    }
}
