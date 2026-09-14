<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Jurisdictions\LifecycleController;
use App\Models\AuditEntry;
use App\Models\BorderSettlement;
use App\Models\ChamberVote;
use App\Models\ConstituentConsent;
use App\Models\DisintermediationProcess;
use App\Models\InstanceSettings;
use App\Models\Law;
use App\Models\LawMergeResolution;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\MultiJurisdictionVote;
use App\Models\RestorationEvent;
use App\Models\UnionProcess;
use App\Models\User;
use App\Models\Verdict;
use App\Services\AuditService;
use App\Services\EnactmentService;
use App\Services\Executive\ExecutiveFormationService;
use App\Services\Jurisdictions\BorderSettlementService;
use App\Services\Jurisdictions\DisintermediationService;
use App\Services\Jurisdictions\RestorationService;
use App\Services\Jurisdictions\UnionService;
use App\Services\MultiJurisdictionVoteService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\ChamberVoteService;
use App\Support\LifecycleHistoryPager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * S2 — interjurisdictional lifecycle doors (union, disintermediation, border,
 * restoration) and the four scoped history pagers. Real services and the real
 * LifecycleHistoryPager drive a PRIVATE in-memory SQLite fixture. No live world.
 */
final class InterjurisdictionalDoorsTest extends TestCase
{
    private string $original;

    private MultiJurisdictionVoteService $mjvService;

    private UnionService $union;

    private DisintermediationService $disinter;

    private BorderSettlementService $border;

    private RestorationService $restoration;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.s2_doors_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false,
        ]);
        DB::setDefaultConnection('s2_doors_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->generic(UnionProcess::class);
        $this->generic(DisintermediationProcess::class, softDeletes: true);
        $this->generic(BorderSettlement::class, softDeletes: true);
        $this->generic(RestorationEvent::class, softDeletes: true);
        $this->generic(Law::class, softDeletes: true);
        $this->generic(LawVersion::class, softDeletes: false, timestamps: false);
        $this->generic(LawMergeResolution::class, softDeletes: false, timestamps: true);
        $this->generic(Verdict::class, softDeletes: true, timestamps: true);
        $this->generic(Legislature::class, softDeletes: true);
        $this->generic(LegislatureMember::class, softDeletes: true);
        $this->generic(User::class, softDeletes: true);
        $this->generic(InstanceSettings::class);

        // MJV: the counters must be real integers (increment/compare in SQL).
        $this->generic(MultiJurisdictionVote::class, softDeletes: true, ints: [
            'constituent_total', 'required', 'yes_count', 'no_count',
        ]);
        // ConstituentConsent: no timestamps, no soft delete.
        $this->generic(ConstituentConsent::class, softDeletes: false, timestamps: false);

        DB::connection()->getSchemaBuilder()->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->unique();
            $t->string('parent_id')->nullable();
            $t->text('name')->nullable();
            $t->text('slug')->nullable();
            $t->text('lifecycle_status')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::connection()->getSchemaBuilder()->create('jurisdiction_maps', function (Blueprint $t) {
            $t->string('id')->unique();
            $t->string('root_jurisdiction_id')->nullable();
            $t->text('name')->nullable();
            $t->text('status')->nullable();
            $t->integer('version_no')->default(0);
            $t->text('origin')->nullable();
            $t->string('origin_process_id')->nullable();
            $t->text('effective_start')->nullable();
            $t->text('effective_end')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        DB::connection()->getSchemaBuilder()->create('residency_confirmations', function (Blueprint $t) {
            $t->string('id')->unique();
            $t->string('jurisdiction_id')->nullable();
            $t->string('user_id')->nullable();
            $t->boolean('is_active')->default(true);
        });
        // The union compatibility diff (UnionService::open) reads settings.
        DB::connection()->getSchemaBuilder()->create('constitutional_settings', function (Blueprint $t) {
            $t->string('id')->unique();
            $t->string('jurisdiction_id')->nullable();
            $t->text('election_interval_months')->nullable();
            $t->text('legislature_min_seats')->nullable();
            $t->text('legislature_max_seats')->nullable();
            $t->text('voting_method')->nullable();
        });

        InstanceSettings::create(['instance_name' => 'S2 doors fixture', 'instance_class' => 'production']);

        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));

        $this->mjvService = new MultiJurisdictionVoteService($audit);
        $this->union = new UnionService($this->mjvService, $audit);
        $this->disinter = new DisintermediationService($this->mjvService, $audit);
        $this->border = new BorderSettlementService($audit);
        $this->restoration = new RestorationService($audit);

        // The generic constituent-consent arm resolves union / disintermediation
        // owners via the container.
        $this->app->instance(UnionService::class, $this->union);
        $this->app->instance(DisintermediationService::class, $this->disinter);
        $this->app->instance(AuditService::class, $audit);
    }

    protected function tearDown(): void
    {
        DB::purge('s2_doors_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    /** Build a table from a model's fillable: text/nullable, with typed overrides. */
    private function generic(string $class, bool $softDeletes = true, bool $timestamps = true, array $ints = []): void
    {
        $model = new $class;
        DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model, $softDeletes, $timestamps, $ints) {
            $t->string('id')->unique();
            foreach ($model->getFillable() as $column) {
                if ($column === 'id') {
                    continue;
                }
                if (in_array($column, $ints, true)) {
                    $t->integer($column)->default(0);
                } else {
                    $t->text($column)->nullable();
                }
            }
            if ($timestamps) {
                $t->timestamps();
            }
            if ($softDeletes) {
                $t->timestamp('deleted_at')->nullable();
            }
        });
    }

    private function id(string $prefix): string
    {
        return sprintf('%s-0000-4000-8000-%012d', substr(str_pad($prefix, 8, '0'), 0, 8), ++$this->seq);
    }

    private function jurisdiction(string $name, ?string $parentId = null): string
    {
        $id = $this->id('7a000000');
        DB::table('jurisdictions')->insert(['id' => $id, 'name' => $name, 'parent_id' => $parentId, 'lifecycle_status' => 'self_governing']);

        return $id;
    }

    private function residents(string $jurisdictionId, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('residency_confirmations')->insert([
                'id' => $this->id('7b000000'),
                'jurisdiction_id' => $jurisdictionId,
                'user_id' => $this->id('7c000000'),
                'is_active' => true,
            ]);
        }
    }

    // ── UNION ────────────────────────────────────────────────────────────────

    public function test_union_finalizes_only_when_both_meters_are_met(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $b = $this->jurisdiction('Applicant B');
        $unionId = $this->jurisdiction('The Union');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);

        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $b], [$a, $b], $unionId);

        // Before either meter: finalize refuses with the Art. V §7 citation.
        try {
            $this->union->finalize($process->refresh());
            $this->fail('Expected a ConstitutionalViolation before the meters are met.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. V §7', $e->citation);
        }

        // Fresh process for the happy path (the failed one is now FAILED).
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $b], [$a, $b], $unionId);
        $mjv = $process->constituentProcess;

        // Only the constituent meter met → maybeFinalize leaves it OPEN.
        $mjv->forceFill(['status' => MultiJurisdictionVote::STATUS_PASSED])->save();
        $this->union->maybeFinalize($mjv->refresh());
        self::assertSame(UnionProcess::STATUS_OPEN, $process->refresh()->status, 'one meter is not both');

        // Both meters met → maybeFinalize applies the union change.
        $process->forceFill(['applicant_supermajority_met' => true])->save();
        $this->union->maybeFinalize($mjv->refresh());

        $process->refresh();
        self::assertSame(UnionProcess::STATUS_PASSED, $process->status);
        self::assertSame($unionId, (string) $process->resulting_jurisdiction_id);
        self::assertSame($unionId, (string) DB::table('jurisdictions')->where('id', $a)->value('parent_id'), 'applicant reparented under the union');
        self::assertSame(1, DB::table('jurisdiction_maps')->where('root_jurisdiction_id', $unionId)->where('origin', 'union')->count(), 'a new active JurisdictionMap version records the union');
    }

    public function test_union_constituent_consent_vote_dispatches_to_the_owner_and_finalizes(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $b = $this->jurisdiction('Applicant B');
        $c = $this->jurisdiction('Applicant C');
        $unionId = $this->jurisdiction('The Union');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);

        // Three constituents: required = supermajority(3) == 3. Two prior yeses
        // are recorded, so THIS consent vote is the one that tips it to PASSED.
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $b], [$a, $b, $c], $unionId);
        $process->forceFill(['applicant_supermajority_met' => true])->save();
        $mjv = $process->constituentProcess;
        $this->mjvService->recordConsent($mjv, $b, true);
        $this->mjvService->recordConsent($mjv->refresh(), $c, true);
        self::assertSame(MultiJurisdictionVote::STATUS_OPEN, $mjv->refresh()->status, 'two of three is not yet the required unanimwith-floor supermajority');
        $consent = ConstituentConsent::query()->where('process_id', $mjv->id)->where('jurisdiction_id', $a)->firstOrFail();

        $formation = $this->formationService();
        $vote = (new ChamberVote)->forceFill([
            'id' => $this->id('7e000000'),
            'votable_type' => 'constituent_consent',
            'votable_id' => (string) $consent->id,
            'legislature_id' => (string) $leg->id,
            'outcome' => ChamberVote::OUTCOME_ADOPTED,
        ]);

        // The generic arm records the consent, the MJV passes, the
        // union_processes branch runs maybeFinalize — both meters met → PASSED.
        $formation->resolveConstituentConsentVote($vote, ChamberVote::OUTCOME_ADOPTED);

        self::assertSame(MultiJurisdictionVote::STATUS_PASSED, $mjv->refresh()->status);
        self::assertSame(UnionProcess::STATUS_PASSED, $process->refresh()->status);
        self::assertSame(1, DB::table('jurisdiction_maps')->where('origin', 'union')->count());
    }

    public function test_union_controller_finalize_refuses_a_resolved_process_cleanly(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);
        $user = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Rep']);
        $user->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $leg->id, 'user_id' => $user->id, 'status' => 'seated']);

        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $this->jurisdiction('Applicant B')], [$a], null);
        $process->forceFill(['status' => UnionProcess::STATUS_PASSED])->save();

        $controller = new LifecycleController;
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn () => $user);

        try {
            $controller->unionFinalize($request, $process->refresh(), $this->union);
            $this->fail('Expected a 422 on a resolved process.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(422, $e->getStatusCode(), 'a resolved process refuses cleanly, not 500');
        }
    }

    public function test_union_consent_by_a_non_member_is_refused(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $this->jurisdiction('Applicant B')], [$a], null);

        $controller = new LifecycleController;
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn () => null); // no seat

        try {
            $controller->unionConsent($request, $process, $this->formationService());
            $this->fail('Expected a 422 for a caller with no current seat.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
    }

    public function test_double_consent_is_refused(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $b = $this->jurisdiction('Applicant B');
        $c = $this->jurisdiction('Applicant C');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);
        // Three constituents keep the process OPEN after one decision (required
        // supermajority(3) == 3), so the SECOND decision by 'a' is the double.
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $b], [$a, $b, $c], null);
        $mjv = $process->constituentProcess;

        $this->mjvService->recordConsent($mjv, $a, true);
        try {
            $this->mjvService->recordConsent($mjv->refresh(), $a, true);
            $this->fail('Expected a ConstitutionalViolation on a second decision.');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString('already decided', $e->getMessage());
        }
    }

    public function test_union_applicant_referendum_reads_the_applicant_population(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $this->residents($a, 3); // supermajority(3) == 2
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $this->jurisdiction('Applicant B')], [$a], null);

        $this->union->markApplicantReferendum($process, 1);
        self::assertFalse($process->refresh()->applicant_supermajority_met, '1 of 3 is below supermajority');

        $this->union->markApplicantReferendum($process, 3);
        self::assertTrue($process->refresh()->applicant_supermajority_met);
    }

    // ── DISINTERMEDIATION ──────────────────────────────────────────────────────

    public function test_disintermediation_finalize_folds_laws_and_reparents(): void
    {
        $encompassing = $this->jurisdiction('Country');
        $intermediary = $this->jurisdiction('State', $encompassing);
        $constituent = $this->jurisdiction('County', $intermediary);
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $intermediary, 'status' => 'active']);

        $law = Law::create(['id' => $this->id('81000000'), 'jurisdiction_id' => $intermediary, 'legislature_id' => $leg->id,
            'act_number' => 'A-1', 'title' => 'State act', 'status' => Law::STATUS_IN_FORCE, 'current_version_no' => 1]);
        LawVersion::create(['id' => $this->id('82000000'), 'law_id' => $law->id, 'version_no' => 1, 'text' => 'original', 'text_hash' => hash('sha256', 'original'), 'source' => 'enactment']);

        $process = $this->disinter->open($leg, $intermediary, $encompassing, [$constituent]);
        $mjv = $process->constituentProcess;

        // Missing encompassing consent → finalize refuses with Art. V §8.
        $mjv->forceFill(['status' => MultiJurisdictionVote::STATUS_PASSED])->save();
        try {
            $this->disinter->finalize($process->refresh());
            $this->fail('Expected a ConstitutionalViolation without encompassing consent.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. V §8', $e->citation);
        }

        // Fresh process, both meters → merges, folds, reparents.
        $process = $this->disinter->open($leg, $intermediary, $encompassing, [$constituent]);
        $mjv = $process->constituentProcess;
        $this->mjvService->recordConsent($mjv, $constituent, true); // unanimity (1 of 1)
        $this->disinter->recordEncompassingConsent($process->refresh(), true);

        $this->disinter->finalize($process->refresh());

        $process->refresh();
        self::assertSame(DisintermediationProcess::STATUS_MERGED, $process->status);
        self::assertSame($encompassing, (string) DB::table('jurisdictions')->where('id', $constituent)->value('parent_id'), 'child re-points to the encompassing jurisdiction');
        self::assertSame(1, LawMergeResolution::query()->where('process_id', $process->id)->where('target_jurisdiction_id', $constituent)->count(), 'the act folds to the constituent');
        self::assertSame(1, Law::query()->where('jurisdiction_id', $constituent)->count(), 'the constituent inherits its own copy');
    }

    public function test_disintermediation_consent_vote_routes_to_maybeFinalize_not_executive(): void
    {
        $encompassing = $this->jurisdiction('Country');
        $intermediary = $this->jurisdiction('State', $encompassing);
        $constituent = $this->jurisdiction('County', $intermediary);
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $intermediary, 'status' => 'active']);

        $process = $this->disinter->open($leg, $intermediary, $encompassing, [$constituent]);
        $mjv = $process->constituentProcess;
        $consent = ConstituentConsent::query()->where('process_id', $mjv->id)->where('jurisdiction_id', $constituent)->firstOrFail();

        $formation = $this->formationService();
        $vote = (new ChamberVote)->forceFill([
            'id' => $this->id('7e000000'),
            'votable_type' => 'constituent_consent',
            'votable_id' => (string) $consent->id,
            'legislature_id' => (string) $leg->id,
            'outcome' => ChamberVote::OUTCOME_ADOPTED,
        ]);

        // Unanimity reached, but encompassing consent absent: the branch runs
        // maybeFinalize which declines — the process stays OPEN (never merged,
        // never misrouted into executive formation).
        $formation->resolveConstituentConsentVote($vote, ChamberVote::OUTCOME_ADOPTED);

        self::assertSame(MultiJurisdictionVote::STATUS_PASSED, $mjv->refresh()->status);
        self::assertSame(DisintermediationProcess::STATUS_OPEN, $process->refresh()->status);
    }

    // ── BORDER SETTLEMENT ────────────────────────────────────────────────────

    public function test_border_adopts_only_on_the_affected_area_supermajority(): void
    {
        $a = $this->jurisdiction('Place A');
        $b = $this->jurisdiction('Place B');
        $affected = $this->jurisdiction('Border strip');
        $this->residents($affected, 3); // supermajority(3) == 2

        $settlement = $this->border->open($a, $b, [$affected]);
        self::assertSame(3, (int) $settlement->affected_population);

        // Below the affected-area supermajority → adopt refused with Art. V §2.
        $this->border->recordReferendum($settlement->refresh(), 1);
        try {
            $this->border->adopt($settlement->refresh());
            $this->fail('Expected a ConstitutionalViolation below the affected-area supermajority.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. V §2', $e->citation);
        }

        // Fresh settlement that meets it → adopted, new map.
        $settlement = $this->border->open($a, $b, [$affected]);
        $this->border->recordReferendum($settlement->refresh(), 3);
        $this->border->adopt($settlement->refresh());

        self::assertSame(BorderSettlement::STATUS_ADOPTED, $settlement->refresh()->status);
        self::assertSame(1, DB::table('jurisdiction_maps')->where('origin', 'border')->count());
    }

    // ── RESTORATION ──────────────────────────────────────────────────────────

    public function test_restoration_confirm_reads_the_tied_case_then_cascades_in_order(): void
    {
        $j = $this->jurisdiction('Fallen place');
        $caseId = $this->id('84000000');

        // Declared with a tied case but no finding → confirm refused.
        $event = $this->restoration->declare($j, RestorationEvent::CONDITION_CAPTURED, ['e' => 1], $caseId);
        $controller = new LifecycleController;
        $user = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Rep']);
        $user->save();
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $j, 'status' => 'active']);
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $leg->id, 'user_id' => $user->id, 'status' => 'seated']);
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn () => $user);

        try {
            $controller->restorationConfirm($request, $event->refresh(), $this->restoration);
            $this->fail('Expected a ConstitutionalViolation without a judicial finding.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. VI §2', $e->citation);
        }

        // A verdict for the petitioner on the tied case IS the finding.
        Verdict::create(['id' => $this->id('85000000'), 'case_id' => $caseId, 'outcome' => Verdict::OUTCOME_FOR_PETITIONER]);
        $controller->restorationConfirm($request, $event->refresh(), $this->restoration);
        self::assertSame(RestorationEvent::STATUS_CONFIRMED, $event->refresh()->status);
        self::assertTrue($event->refresh()->judicially_confirmed);

        // Tiers run 1 → 2 → 3 in order; out of order is refused.
        try {
            $this->restoration->advanceTier($event->refresh(), 2);
            $this->fail('Expected tier 2 to be refused before tier 1.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. VI §3', $e->citation);
        }
        $this->restoration->advanceTier($event->refresh(), 1);
        $this->restoration->advanceTier($event->refresh(), 2);
        $t3 = $this->restoration->advanceTier($event->refresh(), 3);
        self::assertSame(3, (int) $t3->tier);
        self::assertSame(RestorationEvent::STATUS_RESTORING, $t3->status);

        // Complete → RESTORED (the terminal that the tier-3 fix makes reachable).
        $this->restoration->complete($event->refresh());
        self::assertSame(RestorationEvent::STATUS_RESTORED, $event->refresh()->status);
    }

    public function test_restoration_completes_only_from_tier_three_and_abandons_from_any_state(): void
    {
        $j = $this->jurisdiction('Fallen place');
        $event = $this->restoration->declare($j, RestorationEvent::CONDITION_DESTROYED, [], $this->id('84000000'));

        // Complete before confirmation / tier 3 → refused.
        try {
            $this->restoration->complete($event->refresh());
            $this->fail('Expected complete to refuse before tier 3.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. VI §3', $e->citation);
        }

        // Abandon is legal from a non-terminal state.
        $this->restoration->abandon($event->refresh());
        self::assertSame(RestorationEvent::STATUS_ABANDONED, $event->refresh()->status);

        // A second abandon (terminal) is refused.
        try {
            $this->restoration->abandon($event->refresh());
            $this->fail('Expected abandon to refuse a terminal event.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. VI §3', $e->citation);
        }
    }

    // ── AUTHORITY + PREMATURE-COMPLETION GUARDS (S2 repair) ──────────────────────

    /** A premature finalize must NOT mark the process FAILED — it leaves it OPEN. */
    public function test_union_controller_finalize_before_both_meters_leaves_the_process_open(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $b = $this->jurisdiction('Applicant B');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);
        $user = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Rep']);
        $user->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $leg->id, 'user_id' => $user->id, 'status' => 'seated']);

        // Constituent vote just opened (still OPEN), applicant referendum unmet.
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $b], [$a], null);

        $controller = new LifecycleController;
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn () => $user);
        $controller->unionFinalize($request, $process->refresh(), $this->union);

        self::assertSame(UnionProcess::STATUS_OPEN, $process->refresh()->status, 'a premature finalize never marks the union FAILED');
    }

    /** Only an APPLICANT jurisdiction may record the applicant population referendum. */
    public function test_union_applicant_referendum_is_refused_from_a_non_applicant_chamber(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $b = $this->jurisdiction('Applicant B');
        $outsider = $this->jurisdiction('Outsider');
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $outsider, 'status' => 'active']);
        $user = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Rep']);
        $user->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $leg->id, 'user_id' => $user->id, 'status' => 'seated']);

        $process = $this->union->open(UnionProcess::KIND_FORMATION, $leg, [$a, $b], [$a], null);

        $controller = new LifecycleController;
        $request = Request::create('/x', 'POST', ['yes_votes' => 5]);
        $request->setUserResolver(fn () => $user);

        try {
            $controller->unionApplicantReferendum($request, $process->refresh(), $this->union);
            $this->fail('Expected a 403 from a non-applicant chamber.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        self::assertFalse((bool) $process->refresh()->applicant_supermajority_met, 'the meter is untouched by the wrong actor');
    }

    /** Only the ENCOMPASSING chamber may record encompassing consent (Art. V §8). */
    public function test_encompassing_consent_is_refused_from_a_non_encompassing_chamber(): void
    {
        $encompassing = $this->jurisdiction('Country');
        $intermediary = $this->jurisdiction('State', $encompassing);
        $constituent = $this->jurisdiction('County', $intermediary);
        $legInter = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $intermediary, 'status' => 'active']);

        $process = $this->disinter->open($legInter, $intermediary, $encompassing, [$constituent]);

        // A seat in the INTERMEDIARY (not the encompassing jurisdiction) is refused.
        $user = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Rep']);
        $user->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $legInter->id, 'user_id' => $user->id, 'status' => 'seated']);
        $controller = new LifecycleController;
        $request = Request::create('/x', 'POST', ['consented' => true]);
        $request->setUserResolver(fn () => $user);

        try {
            $controller->disintermediationEncompassingConsent($request, $process->refresh(), $this->disinter);
            $this->fail('Expected a ConstitutionalViolation from a non-encompassing chamber.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. V §8', $e->citation);
        }
        self::assertNotTrue($process->refresh()->encompassing_consent, 'the meter is untouched by the wrong actor');

        // A seat in the ENCOMPASSING jurisdiction records it.
        $legEnc = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $encompassing, 'status' => 'active']);
        $enc = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Encompassing rep']);
        $enc->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $legEnc->id, 'user_id' => $enc->id, 'status' => 'seated']);
        $request2 = Request::create('/x', 'POST', ['consented' => true]);
        $request2->setUserResolver(fn () => $enc);
        $controller->disintermediationEncompassingConsent($request2, $process->refresh(), $this->disinter);

        self::assertTrue((bool) $process->refresh()->encompassing_consent, 'the encompassing chamber records its own consent');
    }

    /** A premature adopt must refuse (422) WITHOUT marking the settlement REJECTED. */
    public function test_border_controller_adopt_before_supermajority_refuses_and_leaves_it_open(): void
    {
        $a = $this->jurisdiction('Place A');
        $b = $this->jurisdiction('Place B');
        $affected = $this->jurisdiction('Border strip');
        $this->residents($affected, 3);
        $leg = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $affected, 'status' => 'active']);
        $user = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Rep']);
        $user->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $leg->id, 'user_id' => $user->id, 'status' => 'seated']);

        $settlement = $this->border->open($a, $b, [$affected]);

        $controller = new LifecycleController;
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn () => $user);

        try {
            $controller->borderAdopt($request, $settlement->refresh(), $this->border);
            $this->fail('Expected a 422 before the affected-area supermajority is met.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertSame(BorderSettlement::STATUS_OPEN, $settlement->refresh()->status, 'a premature adopt never marks the settlement REJECTED');
    }

    // ── HISTORY PAGING ─────────────────────────────────────────────────────────

    public function test_history_pages_43_rows_as_25_then_18_with_scoped_tokens(): void
    {
        for ($i = 0; $i < 43; $i++) {
            DB::table('union_processes')->insert([
                'id' => $this->id('86000000'),
                'kind' => UnionProcess::KIND_FORMATION,
                'status' => UnionProcess::STATUS_OPEN,
                'created_at' => now()->subMinutes(43 - $i)->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        $pager = new LifecycleHistoryPager;
        $path = '/jurisdictions/union-formation';

        $first = $pager->page(Request::create($path, 'GET'), 'union', $path, UnionProcess::query());
        self::assertCount(25, $first['page']->items());
        self::assertNotNull($first['pagination']['next']);
        self::assertNull($first['pagination']['previous']);

        parse_str((string) parse_url($first['pagination']['next'], PHP_URL_QUERY), $q);
        self::assertArrayHasKey('union_cursor', $q);

        $second = $pager->page(Request::create($path, 'GET', ['union_cursor' => $q['union_cursor']]), 'union', $path, UnionProcess::query());
        self::assertCount(18, $second['page']->items());

        // A token scoped to a DIFFERENT listing is refused on this one.
        $foreign = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode([
            'created_at' => now()->format('Y-m-d H:i:s'),
            'id' => $this->id('86000000'),
            'scope' => hash('sha256', 'lifecycle:border'),
            '_pointsToNextItems' => true,
        ])));

        try {
            $pager->page(Request::create($path, 'GET', ['union_cursor' => $foreign]), 'union', $path, UnionProcess::query());
            $this->fail('Expected a cross-scope cursor to be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('union_cursor', $e->errors());
        }
    }

    // ── S1 · JURISDICTIONS — the full one-tree lifecycle walk ────────────────────

    /**
     * Register row S1 · jurisdictions. One jurisdiction tree, walked through all
     * four interjurisdictional decisions in order: union formation, then
     * disintermediation with law merging, then affected-area border settlement,
     * then judicial restoration. Each stage carries its own required refusal:
     *   • premature union finalize (both meters unmet) leaves the process OPEN;
     *   • a wrong-body applicant referendum (a non-applicant chamber) is refused 403;
     *   • a wrong-body encompassing consent (the intermediary chamber) is refused Art. V §8.
     * Recovery from each refusal is demonstrated by driving the same stage to its
     * completed state afterwards.
     */
    public function test_full_lifecycle_walk_union_disintermediation_border_restoration_on_one_tree(): void
    {
        // ── stage 1: UNION FORMATION ────────────────────────────────────────
        $earth = $this->jurisdiction('Earth');
        $countryA = $this->jurisdiction('Country A');
        $countryB = $this->jurisdiction('Country B');
        $countryC = $this->jurisdiction('Country C');
        $outsider = $this->jurisdiction('Outsider place');
        $this->residents($countryA, 3); // applicant population 3 → supermajority(3) == 2

        $legA = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $countryA, 'status' => 'active']);
        $userA = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'A rep']);
        $userA->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $legA->id, 'user_id' => $userA->id, 'status' => 'seated']);

        // Constituent supermajority of THREE is unanimity here (required =
        // max(ceil(2·3/3), floor(3/2)+2) == 3); all three must consent.
        $process = $this->union->open(UnionProcess::KIND_FORMATION, $legA, [$countryA, $countryB], [$countryA, $countryB, $countryC], $earth);

        // REFUSAL 1 — wrong-body applicant referendum: a seat in a non-applicant
        // chamber is refused 403 and the meter is untouched.
        $legOut = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $outsider, 'status' => 'active']);
        $userOut = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Outsider rep']);
        $userOut->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $legOut->id, 'user_id' => $userOut->id, 'status' => 'seated']);
        $controller = new LifecycleController;
        $refReq = Request::create('/x', 'POST', ['yes_votes' => 5]);
        $refReq->setUserResolver(fn () => $userOut);
        try {
            $controller->unionApplicantReferendum($refReq, $process->refresh(), $this->union);
            $this->fail('Expected a 403 from a non-applicant chamber.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        self::assertFalse((bool) $process->refresh()->applicant_supermajority_met, 'the wrong actor never moves the meter');

        // REFUSAL 2 — premature finalize (neither meter met) leaves it OPEN, not FAILED.
        $finReq = Request::create('/x', 'POST');
        $finReq->setUserResolver(fn () => $userA);
        $controller->unionFinalize($finReq, $process->refresh(), $this->union);
        self::assertSame(UnionProcess::STATUS_OPEN, $process->refresh()->status, 'a premature finalize never fails a live union');

        // RECOVERY — the applicant chamber records its own referendum, then the
        // constituents consent to supermajority, then finalize applies the union.
        $refReqA = Request::create('/x', 'POST', ['yes_votes' => 3]);
        $refReqA->setUserResolver(fn () => $userA);
        $controller->unionApplicantReferendum($refReqA, $process->refresh(), $this->union);
        self::assertTrue((bool) $process->refresh()->applicant_supermajority_met);

        $mjv = $process->constituentProcess;
        $this->mjvService->recordConsent($mjv, $countryA, true);
        $this->mjvService->recordConsent($mjv->refresh(), $countryB, true);
        $this->mjvService->recordConsent($mjv->refresh(), $countryC, true); // third consent tips it to PASSED
        $this->union->maybeFinalize($mjv->refresh());

        $process->refresh();
        self::assertSame(UnionProcess::STATUS_PASSED, $process->status, 'union finalizes once both meters are met');
        self::assertSame($earth, (string) $process->resulting_jurisdiction_id);
        self::assertSame($earth, (string) DB::table('jurisdictions')->where('id', $countryA)->value('parent_id'), 'applicant A reparented under the union');
        self::assertSame($earth, (string) DB::table('jurisdictions')->where('id', $countryB)->value('parent_id'), 'applicant B reparented under the union');
        self::assertSame(1, DB::table('jurisdiction_maps')->where('root_jurisdiction_id', $earth)->where('origin', 'union')->count(), 'a union map version records the change');

        // ── stage 2: DISINTERMEDIATION + LAW MERGING ────────────────────────
        // A fresh three-level branch of the same tree: Country A (encompassing) →
        // State (intermediary) → County (constituent). The intermediary holds one
        // in-force act that must fold to its constituent.
        $state = $this->jurisdiction('State', $countryA);
        $county = $this->jurisdiction('County', $state);
        $legState = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $state, 'status' => 'active']);
        $law = Law::create(['id' => $this->id('81000000'), 'jurisdiction_id' => $state, 'legislature_id' => $legState->id,
            'act_number' => 'S-1', 'title' => 'State act', 'status' => Law::STATUS_IN_FORCE, 'current_version_no' => 1]);
        LawVersion::create(['id' => $this->id('82000000'), 'law_id' => $law->id, 'version_no' => 1, 'text' => 'original', 'text_hash' => hash('sha256', 'original'), 'source' => 'enactment']);

        $disProcess = $this->disinter->open($legState, $state, $countryA, [$county]);

        // REFUSAL 3 — wrong-body encompassing consent: the intermediary's own
        // chamber cannot consent for the encompassing jurisdiction (Art. V §8).
        $userState = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'State rep']);
        $userState->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $legState->id, 'user_id' => $userState->id, 'status' => 'seated']);
        $encReqBad = Request::create('/x', 'POST', ['consented' => true]);
        $encReqBad->setUserResolver(fn () => $userState);
        try {
            $controller->disintermediationEncompassingConsent($encReqBad, $disProcess->refresh(), $this->disinter);
            $this->fail('Expected Art. V §8 from a non-encompassing chamber.');
        } catch (ConstitutionalViolation $e) {
            self::assertSame('Art. V §8', $e->citation);
        }
        self::assertNotTrue($disProcess->refresh()->encompassing_consent, 'the wrong actor never records encompassing consent');

        // RECOVERY — the encompassing chamber consents, the sole constituent gives
        // unanimity, and finalize folds the act to the constituent and reparents.
        $legCountry = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $countryA, 'status' => 'active']);
        $userCountry = (new User)->forceFill(['id' => $this->id('7f000000'), 'name' => 'Country rep']);
        $userCountry->save();
        LegislatureMember::create(['id' => $this->id('7f000000'), 'legislature_id' => $legCountry->id, 'user_id' => $userCountry->id, 'status' => 'seated']);
        $encReq = Request::create('/x', 'POST', ['consented' => true]);
        $encReq->setUserResolver(fn () => $userCountry);
        $controller->disintermediationEncompassingConsent($encReq, $disProcess->refresh(), $this->disinter);
        self::assertTrue((bool) $disProcess->refresh()->encompassing_consent);

        $this->mjvService->recordConsent($disProcess->constituentProcess, $county, true); // unanimity 1 of 1
        $this->disinter->finalize($disProcess->refresh());

        $disProcess->refresh();
        self::assertSame(DisintermediationProcess::STATUS_MERGED, $disProcess->status);
        self::assertSame($countryA, (string) DB::table('jurisdictions')->where('id', $county)->value('parent_id'), 'the constituent re-points to the encompassing jurisdiction');
        self::assertSame(1, LawMergeResolution::query()->where('process_id', $disProcess->id)->where('target_jurisdiction_id', $county)->count(), 'a law_merge_resolutions row records the fold');
        self::assertSame(1, Law::query()->where('jurisdiction_id', $county)->count(), 'the constituent inherits its own copy of the act');

        // ── stage 3: AFFECTED-AREA BORDER SETTLEMENT ────────────────────────
        $placeP = $this->jurisdiction('Place P');
        $placeQ = $this->jurisdiction('Place Q');
        $strip = $this->jurisdiction('Border strip');
        $this->residents($strip, 3); // affected population 3 → supermajority(3) == 2

        $settlement = $this->border->open($placeP, $placeQ, [$strip]);
        self::assertSame(3, (int) $settlement->affected_population);
        $this->border->recordReferendum($settlement->refresh(), 3);
        $this->border->adopt($settlement->refresh());
        self::assertSame(BorderSettlement::STATUS_ADOPTED, $settlement->refresh()->status);
        self::assertSame(1, DB::table('jurisdiction_maps')->where('origin', 'border')->count(), 'a border map version records the boundary');

        // ── stage 4: JUDICIAL RESTORATION ───────────────────────────────────
        $fallen = $this->jurisdiction('Fallen place');
        $caseId = $this->id('84000000');
        $event = $this->restoration->declare($fallen, RestorationEvent::CONDITION_CAPTURED, ['e' => 1], $caseId);
        $this->restoration->confirm($event->refresh(), true); // the judicial finding
        self::assertSame(RestorationEvent::STATUS_CONFIRMED, $event->refresh()->status);
        $this->restoration->advanceTier($event->refresh(), 1);
        $this->restoration->advanceTier($event->refresh(), 2);
        $this->restoration->advanceTier($event->refresh(), 3);
        $this->restoration->complete($event->refresh());
        self::assertSame(RestorationEvent::STATUS_RESTORED, $event->refresh()->status, 'the tree walk ends with a restored government');
    }

    /**
     * Peer sync does not establish civic consent. Materializing a process row —
     * the same write a federation ingest would perform — leaves every consent
     * meter unmet: the union applicant/constituent meters, the disintermediation
     * encompassing/constituent meters, and the border affected-area meter all
     * start unmet. Consent flips only through the owning-body recorded methods
     * (markApplicantReferendum, recordConsent, recordEncompassingConsent,
     * recordReferendum), never by the arrival of a row.
     */
    public function test_materializing_a_process_does_not_establish_consent(): void
    {
        $a = $this->jurisdiction('Applicant A');
        $b = $this->jurisdiction('Applicant B');
        $union = $this->jurisdiction('Union');
        $legA = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $a, 'status' => 'active']);
        $unionProc = $this->union->open(UnionProcess::KIND_FORMATION, $legA, [$a, $b], [$a, $b], $union);
        self::assertFalse((bool) $unionProc->applicant_supermajority_met, 'a materialized union has no applicant consent');
        self::assertSame(MultiJurisdictionVote::STATUS_OPEN, $unionProc->constituentProcess->status, 'a materialized union has no constituent consent');

        $country = $this->jurisdiction('Country');
        $state = $this->jurisdiction('State', $country);
        $county = $this->jurisdiction('County', $state);
        $legState = Legislature::create(['id' => $this->id('7d000000'), 'jurisdiction_id' => $state, 'status' => 'active']);
        $dis = $this->disinter->open($legState, $state, $country, [$county]);
        self::assertNotTrue($dis->encompassing_consent, 'a materialized disintermediation has no encompassing consent');
        self::assertSame(MultiJurisdictionVote::STATUS_OPEN, $dis->constituentProcess->status, 'a materialized disintermediation has no constituent consent');

        $strip = $this->jurisdiction('Strip');
        $this->residents($strip, 3);
        $border = $this->border->open($this->jurisdiction('P'), $this->jurisdiction('Q'), [$strip]);
        self::assertNotTrue($border->affected_supermajority_met, 'a materialized border settlement has no affected-area consent');
        self::assertSame(BorderSettlement::STATUS_OPEN, $border->status);
    }

    private function formationService(): ExecutiveFormationService
    {
        return new ExecutiveFormationService(
            $this->app->make(AuditService::class),
            $this->createMock(EnactmentService::class),
            $this->createMock(PublicRecordService::class),
            $this->mjvService,
            $this->createMock(ChamberVoteService::class),
            $this->createMock(RoleService::class),
        );
    }
}
