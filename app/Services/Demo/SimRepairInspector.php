<?php

namespace App\Services\Demo;

use App\Models\SimRun;
use App\Services\Demo\Stages\VerifyStage;
use App\Services\InstitutionScaleService;
use App\Support\HostCapacity;
use Illuminate\Support\Facades\DB;

/** Scope-local acceptance and plan. No domain writes, no descendant/world scan. */
class SimRepairInspector
{
    public const REVISION = 3;

    public function inspect(SimRun $run, string $jurisdiction, ?\Closure $beat = null): array
    {
        $source = (string) $run->options['repair_source_run'];
        $version = (int) ($run->options['version'] ?? 1);
        $before = VerifyStage::run($jurisdiction, $source, $version, $beat);
        $out = ['verification' => $before, 'actions' => [], 'blockers' => [], 'coverage' => [], 'acceptance_gaps' => [],
            'inspector_revision' => self::REVISION, 'institution_ready' => false, 'complete' => []];
        if ($before['inactive'] ?? null) { return $out; }
        $aspects = $run->activeAspects();
        $leg = DB::table('legislatures')->where('jurisdiction_id', $jurisdiction)->whereNull('deleted_at')->first();
        $electionMetrics = DB::table('sim_items')->where('run_id', $source)->where('kind', 'election_scope')
            ->where('unit_key', $jurisdiction)->value('metrics');
        $electionId = (json_decode($electionMetrics ?? '{}', true) ?: [])['election_id'] ?? null;
        $election = $electionId ? DB::table('elections')->where('id', $electionId)->first() : null;
        $out['election_id'] = $electionId;
        $recovery = (bool) ($run->options['repair_election_recovery'] ?? false);
        $underfilled = false;
        if ($election) {
            $races = DB::table('election_races')->where('election_id', $electionId)->whereNull('deleted_at')->orderBy('id')->get(['id','seats','seat_kind']);
            $tabulated = DB::table('tabulations')->whereIn('race_id', $races->pluck('id'))->where('status', 'complete')->pluck('race_id')->flip();
            $out['race_states'] = ['uncounted_deficient' => 0, 'uncounted_sufficient' => 0, 'counted_deficient' => 0, 'counted_sufficient' => 0];
            $counts = DB::table('candidacies')->where('election_id', $electionId)
                ->whereNotIn('status', ['rejected','withdrawn'])->groupBy('race_id')->selectRaw('race_id, count(*) AS n')->pluck('n', 'race_id');
            foreach ($races as $race) {
                $count = (int) ($counts[$race->id] ?? 0);
                $state = ($tabulated->has($race->id) ? 'counted_' : 'uncounted_').($count < (int) $race->seats ? 'deficient' : 'sufficient');
                $out['race_states'][$state]++;
                if ($count < (int) $race->seats + 1) {
                    $out['coverage'][] = ['race_id' => $race->id, 'seats' => (int) $race->seats, 'candidates' => $count, 'seat_kind' => $race->seat_kind, 'count_state' => $state];
                }
                $underfilled = $underfilled || $count < (int) $race->seats;
            }
            if ($out['race_states']['counted_deficient'] > 0 && ! $recovery) {
                $out['blockers'][] = 'Completed counts have fewer candidates than seats: preserve recorded results; an authorized election recovery decision is required.';
            }
            if ($election->status !== 'certified') {
                $out['actions'][] = ['kind' => $recovery ? 'election_recovery' : 'election', 'target' => $electionId];
                if (! in_array($election->status, ['scheduled','approval_open'], true)) {
                    $out['blockers'][] = 'Election is beyond nomination: preserve existing counts; an authorized recovery decision is required.';
                }
            } elseif ($underfilled && ! $recovery) {
                $out['blockers'][] = 'Certified election has fewer candidates than seats: preserve certification; countback/vacancy recovery needs an authorized lifecycle decision.';
            }
        } elseif (in_array('elections', $aspects, true)) {
            $out['blockers'][] = 'Original election item has no recoverable election reference.';
        }
        $seated = $leg ? DB::table('legislature_members')->where('legislature_id', $leg->id)->whereNull('deleted_at')
            ->whereNull('vacated_at')->whereNotNull('user_id')->whereIn('status', ['elected','seated'])->select('seat_type')->get() : collect();
        if ($leg && $seated->count() < (int) $leg->total_seats && $election?->status === 'certified') {
            if ($recovery) { $out['actions'][] = ['kind' => 'election_recovery', 'target' => $electionId]; }
            else { $out['blockers'][] = 'Certified legislature still has unfilled seats; do not rewrite its term or manufacture members.'; }
        }
        if ($leg && $seated->where('seat_type', 'b')->count() < (int) $leg->type_b_seats) {
            $out['acceptance_gaps'][] = 'Type B representation below the apportioned total.';
        }
        $out['institution_ready'] = $election?->status === 'certified' && $leg && $seated->isNotEmpty()
            && $seated->count() >= (int) $leg->total_seats && $seated->where('seat_type', 'b')->count() >= (int) $leg->type_b_seats && $out['blockers'] === [];
        $out['complete']['election'] = $out['institution_ready'];
        $needsGovernment = $election && $election->status !== 'certified';
        if ($leg && in_array('governance', $aspects, true)) {
            $committees = DB::table('committees')->where('legislature_id', $leg->id)->whereNull('deleted_at')->where('status', '!=', 'dissolved')->count();
            $population = (int) DB::table('jurisdictions')->where('id', $jurisdiction)->value('population');
            $departments = DB::table('departments')->where('jurisdiction_id', $jurisdiction)->whereNull('deleted_at')->where('status', '!=', 'dissolved')->get(['id','board_id']);
            if ($departments->contains(fn ($d) => $d->board_id === null)) { $out['acceptance_gaps'][] = 'Department has no governor board.'; $needsGovernment = true; }
            $governorGaps = $departments->whereNotNull('board_id')->isEmpty() ? 0 : DB::table('board_seats')
                ->whereIn('board_id', $departments->pluck('board_id')->filter()->all())->whereNull('deleted_at')
                ->where('seat_class', 'governor')->where('status', '!=', 'seated')->count();
            $committeeTarget = InstitutionScaleService::committeeTarget((int) $leg->total_seats);
            $departmentTarget = InstitutionScaleService::departmentTarget($population);
            if ($committees < $committeeTarget) { $out['acceptance_gaps'][] = "Committees {$committees}/{$committeeTarget}."; $needsGovernment = true; }
            if ($departments->count() < $departmentTarget) { $out['acceptance_gaps'][] = 'Departments below the growth target.'; $needsGovernment = true; }
            if ($governorGaps > 0) { $out['acceptance_gaps'][] = "{$governorGaps} department governors not seated."; $needsGovernment = true; }
            if (DB::table('executives')->where('jurisdiction_id', $jurisdiction)->whereNull('deleted_at')->where('status', 'forming')->exists()) { $needsGovernment = true; }
            if ($needsGovernment) { $out['actions'][] = ['kind' => 'governance', 'target' => $leg->id]; }
            $out['complete']['governance'] = ! $needsGovernment;
            $courts = DB::table('judiciaries')->where('jurisdiction_id', $jurisdiction)->whereNull('deleted_at')->get(['id','status','min_judges']);
            $courtGapStart = count($out['acceptance_gaps']);
            $out['complete']['judiciary'] = $courts->isNotEmpty();
            foreach ($courts as $court) {
                if (! in_array($court->status, ['appointed','elected'], true)) { $out['actions'][] = ['kind' => 'judiciary', 'target' => $court->id]; $out['complete']['judiciary'] = false; }
                $badSeats = DB::table('judicial_seats as s')->where('s.judiciary_id', $court->id)->whereNull('s.deleted_at')
                    ->where(function ($q) { $q->where('s.status', '!=', 'seated')->orWhereNull('s.term_id'); })->exists();
                if ($badSeats) { $out['acceptance_gaps'][] = 'Court has unseated judges or missing terms.'; }
                $seats = DB::table('judicial_seats')->where('judiciary_id', $court->id)->whereNull('deleted_at')->where('status', 'seated')->get(['id','term_id','user_id','term_ends_on']);
                if ($seats->count() < (int) $court->min_judges) { $out['acceptance_gaps'][] = 'Actual seated judges below the court minimum.'; }
                if ($seats->isNotEmpty()) {
                    $terms = DB::table('terms')->whereIn('id', $seats->pluck('term_id')->filter())->whereNull('deleted_at')->get()->keyBy('id');
                    $armed = DB::table('clock_timers')->where('clock_id', 'CLK-09')->where('subject_type', 'term')
                        ->whereIn('subject_id', $terms->keys())->where('state', 'armed')->whereNull('deleted_at')->whereNotNull('fires_at')->pluck('subject_id')->flip();
                    foreach ($seats as $seat) {
                        $term = $terms[$seat->term_id] ?? null;
                        if (! $term || $term->status !== 'active' || $term->holder_user_id !== $seat->user_id || ! $term->ends_on
                            || (string) $term->ends_on !== (string) $seat->term_ends_on || ($term->term_class === 'civil_appointment' && ! $armed->has($term->id))) {
                            $out['acceptance_gaps'][] = 'Judge term or expiry clock does not match the occupied seat: '.$seat->id;
                        }
                    }
                }
            }
            $out['complete']['judiciary'] = $out['complete']['judiciary'] && count($out['acceptance_gaps']) === $courtGapStart;
        }
        if (in_array('civic_life', $aspects, true)) {
            $out['complete']['civics'] = ! $needsGovernment && DB::table('organizations')->where('jurisdiction_id', $jurisdiction)->whereNull('deleted_at')->where('type', 'common_good_corp')->exists();
            if (! $out['complete']['civics']) {
                $out['actions'][] = ['kind' => 'civics', 'target' => $jurisdiction];
            }
            foreach ($this->boards($jurisdiction, $beat) as $board) {
                if ($board->chair_seat_id === null) { $out['actions'][] = ['kind' => 'chair', 'target' => $board->id]; }
                else {
                    $valid = DB::table('board_seats')->where('id', $board->chair_seat_id)->where('board_id', $board->id)
                        ->whereNull('deleted_at')->where('status', 'seated')->where('is_chair', true)->exists();
                    $vote = DB::table('chamber_votes')->where('body_type', 'board')->where('body_id', $board->id)
                        ->where('vote_type', 'board_chair_elect')->where('outcome', 'adopted')->orderByDesc('opened_at')->value('rcv_record');
                    $winner = (json_decode($vote ?? '{}', true) ?: [])['winner_member_id'] ?? null;
                    if (! $valid || $winner !== $board->chair_seat_id) { $out['acceptance_gaps'][] = 'Board chair lacks current membership or adopted ranked-vote provenance: '.$board->id; }
                }
            }
        }
        $out['blockers'] = array_values(array_unique($out['blockers']));
        return $out;
    }

    public function boards(string $jurisdiction, ?\Closure $beat = null): \Generator
    {
        $cursor = null; $size = HostCapacity::sweepChunk();
        do {
            $beat && $beat();
            $ids = DB::table('organizations')->where('jurisdiction_id', $jurisdiction)->whereNull('deleted_at')
                ->when($cursor, fn ($q) => $q->where('id', '>', $cursor))->orderBy('id')->limit($size)->pluck('id');
            if ($ids->isEmpty()) { break; }
            $boards = DB::table('boards')->where('boardable_type', 'organizations')->whereIn('boardable_id', $ids)
                ->whereNull('deleted_at')->where('status', '!=', 'dissolved')->orderBy('id')->get(['id','chair_seat_id']);
            foreach ($boards as $board) { yield $board; }
            $cursor = $ids->last();
        } while ($ids->count() === $size);
    }
}
