<?php

namespace Tests\Feature;

use App\Models\{Board, BoardSeat, Organization, SimRun};
use App\Services\{AuditService, PublicRecordService};
use App\Services\Demo\{RepairChairAudit, SimChairService, SimRepairService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class RepairChairAuditTest extends TestCase
{
    use DisposableRepairWorld;
    protected function setUp(): void { parent::setUp(); $this->openRepairWorld(); config(['cache.default' => 'array', 'queue.default' => 'sync']); }
    protected function tearDown(): void { RepairChairAudit::end(); $this->closeRepairWorld(); parent::tearDown(); }
    private function id(): string { return (string) Str::uuid(); }
    private function world(): array
    {
        $scope = $this->id();
        DB::table('jurisdictions')->insert(['id' => $scope, 'name' => 'Chair fixture', 'slug' => $scope, 'population' => 1000, 'adm_level' => 6]);
        $source = SimRun::create(['status' => 'done', 'phase' => 'done', 'options' => [], 'phase_timings' => []]);
        $run = SimRun::create(['status' => 'running', 'phase' => 'repairing', 'options' => ['repair_source_run' => $source->id, 'repair_version' => 1], 'phase_timings' => []]);
        return [$scope, $run];
    }
    private function board(string $scope): Board
    {
        $org = Organization::create(['name' => 'Board fixture', 'slug' => $this->id(), 'jurisdiction_id' => $scope, 'type' => 'business', 'status' => 'active']);
        $board = Board::create(['boardable_type' => 'organizations', 'boardable_id' => $org->id, 'status' => 'active', 'owner_seats' => 3, 'worker_seats' => 0]);
        for ($i = 1; $i <= 3; $i++) {
            $person = $this->id(); DB::table('users')->insert(['id' => $person, 'name' => 'Fixture', 'email' => $person.'@example.test', 'password' => 'unused', 'terms_accepted_at' => now()]);
            BoardSeat::create(['board_id' => $board->id, 'seat_class' => 'owner_elected', 'seat_no' => $i, 'holder_user_id' => $person, 'status' => 'seated']);
        }
        return $board;
    }
    private function action(SimRun $run, string $scope, Board $board, ?\Closure $perform = null): array
    {
        $service = app(SimRepairService::class);
        return (new \ReflectionMethod($service, 'action'))->invoke($service, $run, $scope, 'chair', $board->id,
            $perform ?? fn () => app(SimChairService::class)->complete($board->id));
    }
    private function checkRecords(Board $board): void
    {
        $vote = DB::table('chamber_votes')->where('body_id', $board->id)->first();
        self::assertSame('adopted', $vote->outcome);
        $casts = DB::table('vote_casts')->where('vote_id', $vote->id)->get(); self::assertCount(3, $casts);
        foreach ($casts as $cast) {
            $record = DB::table('public_records')->where('id', $cast->public_record_id)->first();
            self::assertNotNull($record); self::assertGreaterThan(0, $record->audit_seq);
            $audit = DB::table('audit_log')->where('seq', $record->audit_seq)->first();
            self::assertSame($record->id, json_decode($audit->payload, true)['record_id']);
            self::assertSame('published', $audit->event);
        }
        self::assertTrue(app(AuditService::class)->verifyChain());
    }

    public function test_real_chair_events_and_ballot_records_are_sealed_once_and_redelivery_is_noop(): void
    {
        [$scope, $run] = $this->world(); $board = $this->board($scope);
        self::assertSame('applied', $this->action($run, $scope, $board)['status']);
        $this->checkRecords($board);
        $audit = DB::table('audit_log')->count(); $records = DB::table('public_records')->count();
        self::assertTrue($this->action($run, $scope, $board)['reused']);
        self::assertSame($audit, DB::table('audit_log')->count()); self::assertSame($records, DB::table('public_records')->count());
        self::assertFalse(RepairChairAudit::active());
    }

    public function test_savepoint_rollback_discards_staged_records_and_events_and_preserves_training_visibility(): void
    {
        [$scope, $run] = $this->world(); $board = $this->board($scope);
        $out = $this->action($run, $scope, $board, function () use ($scope, $board) {
            try { DB::transaction(function () use ($scope) {
                app(PublicRecordService::class)->publish('other', 'rolled back fixture', attrs: ['jurisdiction_id' => $scope]);
                app(AuditService::class)->append('fixture', 'rollback.event', []);
                throw new \RuntimeException('savepoint');
            }); } catch (\RuntimeException $e) { self::assertSame('savepoint', $e->getMessage()); }
            app(AuditService::class)->append('education', 'education.training_completed', ['track_key' => 'fixture'], 'F-EDU-001');
            return app(SimChairService::class)->complete($board->id);
        });
        self::assertSame('applied', $out['status']); $this->checkRecords($board);
        self::assertSame(0, DB::table('audit_log')->where('event', 'rollback.event')->count());
        self::assertSame(0, DB::table('public_records')->where('title', 'rolled back fixture')->count());
        self::assertSame(1, DB::table('audit_log')->where('event', 'education.training_completed')->where('ref', 'F-EDU-001')->count());
    }

    public function test_failures_before_and_after_flush_leave_no_domain_or_audit_success(): void
    {
        [$scope, $run] = $this->world();
        foreach ([false, true] as $afterFlush) {
            $board = $this->board($scope); $audit = DB::table('audit_log')->count(); $records = DB::table('public_records')->count();
            $result = $this->action($run, $scope, $board, function () use ($board, $afterFlush) {
                app(SimChairService::class)->complete($board->id);
                if ($afterFlush) { RepairChairAudit::flush(); }
                throw new \RuntimeException('injected action failure');
            });
            self::assertSame('blocked', $result['status']); self::assertNull($board->refresh()->chair_seat_id);
            self::assertSame(0, DB::table('chamber_votes')->where('body_id', $board->id)->count());
            self::assertSame($audit, DB::table('audit_log')->count()); self::assertSame($records, DB::table('public_records')->count());
            self::assertFalse(RepairChairAudit::active());
        }
        $good = $this->board($scope); self::assertSame('applied', $this->action($run, $scope, $good)['status']); $this->checkRecords($good);
    }

    public function test_another_real_repair_commits_while_first_has_finished_ballots_but_not_audit_flush(): void
    {
        [$scope, $run] = $this->world(); $first = $this->board($scope); $second = $this->board($scope);
        DB::commit(); // Fixture only: child process must see committed boards.
        $database = DB::connection()->getDatabaseName();
        $out = $this->action($run, $scope, $first, function () use ($first, $second, $scope, $run, $database) {
            $result = app(SimChairService::class)->complete($first->id);
            // The old early global lock would block this child until timeout.
            $child = new Process(['php', base_path('tests/Support/repair_chair_process.php'), $database, $run->id, $scope, $second->id],
                base_path(), ['RUN_SIM_INDEX_PG_TESTS' => '1']);
            $child->setTimeout(10); $child->run();
            self::assertTrue($child->isSuccessful(), $child->getOutput().$child->getErrorOutput());
            self::assertSame('applied', json_decode($child->getOutput(), true)['status']);
            return $result;
        });
        self::assertSame('applied', $out['status']); $this->checkRecords($first); $this->checkRecords($second);
        $run->forceFill(['status' => 'done', 'phase' => 'done'])->save();
        DB::beginTransaction();
    }
    public function test_grouped_chairs_share_one_flush_and_rollback_together(): void
    {
        [$scope, $run] = $this->world(); $a = $this->board($scope); $b = $this->board($scope);
        $service = app(SimRepairService::class); $method = new \ReflectionMethod($service, 'chairs');
        $before = DB::table('audit_log')->count(); $calls = 0;
        try { $method->invoke($service, $run, $scope, [$a->id,$b->id], function () use (&$calls) { return ++$calls === 2; }); self::fail('Expected pause'); }
        catch (\App\Services\Demo\SimRepairPaused) {}
        self::assertNull($a->refresh()->chair_seat_id); self::assertNull($b->refresh()->chair_seat_id);
        self::assertSame($before, DB::table('audit_log')->count());
        self::assertSame(0, DB::table('chamber_votes')->whereIn('body_id',[$a->id,$b->id])->count());
        self::assertSame(['planned'], DB::table('sim_repair_receipts')->where('source_run_id',$run->options['repair_source_run'])->distinct()->pluck('status')->all());
        DB::connection()->enableQueryLog(); DB::flushQueryLog();
        $out = $method->invoke($service,$run,$scope,[$a->id,$b->id],fn()=>false);
        $queries=DB::getQueryLog(); DB::disableQueryLog(); DB::flushQueryLog();
        self::assertSame(['applied','applied'],array_column($out,'status'));
        self::assertCount(1,array_filter($queries,fn($q)=>str_contains($q['query'],'pg_advisory_xact_lock')));
        $this->checkRecords($a); $this->checkRecords($b);
        $before=DB::table('audit_log')->count();
        self::assertSame([true,true],array_column($method->invoke($service,$run,$scope,[$a->id,$b->id],fn()=>false),'reused'));
        self::assertSame($before,DB::table('audit_log')->count());
    }

    public function test_bulk_board_cast_preserves_an_existing_ballot_and_rcv_threshold(): void
    {
        [$scope,$run]=$this->world(); $board=$this->board($scope); $seats=$board->seats()->orderBy('id')->get();
        $vote=app(\App\Services\Organizations\OrgBoardService::class)->openChairElection($board);
        $cast=app(\App\Services\ChamberVoteService::class)->castBoardSeat($vote,$seats[0],null,$seats->pluck('id')->all(),'Existing','F-ORG-010');
        $saved=DB::table('vote_casts')->where('id',$cast->id)->first();
        $publication=DB::table('public_records')->where('id',$cast->public_record_id)->first();
        self::assertSame('applied',$this->action($run,$scope,$board)['status']);
        self::assertEquals($saved,DB::table('vote_casts')->where('id',$cast->id)->first());
        self::assertEquals($publication,DB::table('public_records')->where('id',$cast->public_record_id)->first());
        self::assertSame($seats[0]->id,$board->refresh()->chair_seat_id);
        $tally=$vote->tallies()->first(); self::assertSame(3,(int)$tally->present); self::assertSame(2,(int)$tally->required_yes);
        $this->checkRecords($board);
    }

    public function test_uuid7_for_all_new_audit_and_publication_paths_preserves_old_rows_and_seals(): void
    {
        [$scope,$run]=$this->world(); $board=$this->board($scope); $audit=app(AuditService::class); $records=app(PublicRecordService::class);
        $head=DB::table('audit_log')->orderByDesc('seq')->first();
        $audit->append('fixture','sync',[]); $records->publish('other','single');
        $records->publishMany([['kind'=>'other','title'=>'bulk','attrs'=>[]]]);
        $audit->beginBatch(); $audit->append('fixture','individual',[]); $audit->commitBatchIndividual();
        $audit->beginBatch(); $audit->append('fixture','aggregated',[]); $audit->commitBatch('fixture','batch');
        self::assertSame('applied',$this->action($run,$scope,$board)['status']);
        foreach(DB::table('audit_log')->where('seq','>',$head->seq)->pluck('id') as $id) { self::assertSame('7',$id[14]); }
        foreach(DB::table('public_records')->get() as $record) {
            self::assertSame('7',$record->id[14]); $entry=DB::table('audit_log')->where('seq',$record->audit_seq)->first();
            self::assertSame($record->id,json_decode($entry->payload,true)['record_id']);
        }
        self::assertEquals($head,DB::table('audit_log')->where('seq',$head->seq)->first()); self::assertTrue($audit->verifyChain());
    }

    public function test_staged_training_achievements_and_ip_preserve_visibility_and_exact_seals(): void
    {
        [$scope,$run]=$this->world(); $board=$this->board($scope);
        $user=\App\Models\User::findOrFail($board->seats()->first()->holder_user_id);
        $org=Organization::create(['name'=>'Public fixture','slug'=>$this->id(),'jurisdiction_id'=>$scope,'type'=>'common_good_corp','is_cgc'=>true,'ip_is_public_domain'=>true,'status'=>'active']);
        $out=$this->action($run,$scope,$board,function()use($user,$org){
            $audit=app(AuditService::class); $gate=app(\App\Services\Education\TrainingGateService::class); $awards=app(\App\Services\AchievementService::class);
            self::assertFalse($gate->hasCompleted($user,'fixture'));
            $audit->append('education','education.training_completed',['track_key'=>'fixture'],'F-EDU-001',$user->id);
            self::assertTrue($gate->hasCompleted($user,'fixture'));
            try { DB::transaction(function()use($user,$audit){
                $audit->append('education','education.training_completed',['track_key'=>'rolled_back'],'F-EDU-001',$user->id);
                app(\App\Services\AchievementService::class)->awardSelf($user,'ACH-ORG-013'); throw new \RuntimeException('rollback');
            }); } catch(\RuntimeException) {}
            self::assertFalse($gate->hasCompleted($user,'rolled_back')); self::assertFalse($awards->hasEarned($user,'ACH-ORG-013'));
            app(\App\Services\Organizations\CgcIpRegisterService::class)->dedicate($org,'Fixture work','software',null,'F-ORG-013',$user->id);
            self::assertTrue($awards->hasEarned($user,'ACH-ORG-013')); self::assertFalse($awards->awardSelf($user,'ACH-ORG-013'));
            self::assertSame(0,DB::table('cgc_ip_register')->where('organization_id',$org->id)->count()); return ['status'=>'done'];
        });
        self::assertSame('applied',$out['status'],json_encode($out));
        $ip=DB::table('cgc_ip_register')->where('organization_id',$org->id)->sole();
        self::assertSame('cgc_ip.dedicated',DB::table('audit_log')->where('seq',$ip->audit_seq)->value('event'));
        $record=DB::table('public_records')->where('id',$ip->published_record_id)->sole();
        self::assertSame('published',DB::table('audit_log')->where('seq',$record->audit_seq)->value('event')); self::assertNotSame($record->audit_seq,$ip->audit_seq);
        $award=DB::table('achievements')->where('user_id',$user->id)->where('award_key','ACH-ORG-013')->sole();
        self::assertSame('achievement/earned',DB::table('audit_log')->where('seq',$award->audit_seq)->value('event')); self::assertTrue(app(AuditService::class)->verifyChain());
    }

    public function test_every_repair_action_leaves_global_lock_free_during_its_work(): void
    {
        [$scope,$run]=$this->world(); DB::commit(); $cfg=DB::connection()->getConfig();
        $other=new \PDO('pgsql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'],$cfg['username'],$cfg['password']);
        foreach(['election','election_recovery','training','governance','judiciary','civics'] as $kind){
            $out=(new \ReflectionMethod(SimRepairService::class,'action'))->invoke(app(SimRepairService::class),$run,$scope,$kind,$scope,function()use($other,$kind){
                app(AuditService::class)->append('fixture',$kind,[]);
                self::assertTrue((bool)$other->query('SELECT pg_try_advisory_xact_lock('.AuditService::APPEND_LOCK_KEY.')')->fetchColumn(),$kind);
                return ['status'=>'done','holders'=>1,'trained'=>1,'failed'=>0,'unarmed'=>0];
            });
            self::assertSame('applied',$out['status'],json_encode($out));
        }
        self::assertTrue(app(AuditService::class)->verifyChain()); $run->forceFill(['status'=>'done','phase'=>'done'])->save(); unset($other); DB::beginTransaction();
    }

    public function test_large_collection_seals_all_pages_in_order(): void
    {
        [$scope,$run]=$this->world(); $board=$this->board($scope);
        $out=$this->action($run,$scope,$board,function(){
            $records=[];
            for($i=0;$i<1003;$i++) { $records[]=['kind'=>'other','title'=>'Large '.$i,'body'=>"\u{00c9}vidence",'attrs'=>['translations'=>['pl'=>"Tre\u{015b}\u{0107}"]]]; }
            app(PublicRecordService::class)->publishMany($records); return ['status'=>'done'];
        });
        self::assertSame('applied',$out['status'],json_encode($out));
        $records=DB::table('public_records')->where('title','like','Large %')->orderBy('seq')->get(); self::assertCount(1003,$records);
        foreach($records as $i=>$record){
            self::assertSame('Large '.$i,$record->title); $entry=DB::table('audit_log')->where('seq',$record->audit_seq)->first();
            self::assertSame($record->id,json_decode($entry->payload,true)['record_id']);
            self::assertSame(['pl'=>"Tre\u{015b}\u{0107}"],json_decode($record->translations,true));
        }
        self::assertTrue(app(AuditService::class)->verifyChain());
    }

    public function test_bulk_chair_reduces_queries_without_skipping_public_ballots(): void
    {
        [$scope,$run]=$this->world(); $old=$this->board($scope); $new=$this->board($scope);
        $measure=function(Board $board,bool $bulk)use($scope,$run):int{
            DB::connection()->enableQueryLog(); DB::flushQueryLog();
            $out=$this->action($run,$scope,$board,$bulk?null:function()use($board){
                $vote=app(\App\Services\Organizations\OrgBoardService::class)->openChairElection($board); $seats=$board->seats()->orderBy('id')->get();
                foreach($seats as $seat){
                    if($vote->refresh()->status!=='open'){break;}
                    if(DB::table('vote_casts')->where('vote_id',$vote->id)->where('board_seat_id',$seat->id)->exists()){continue;}
                    app(\App\Services\ChamberVoteService::class)->castBoardSeat($vote,$seat,null,$seats->pluck('id')->all(),'Simulated Step 5 full-board chair ballot.','F-ORG-010');
                }
                return ['status'=>'done'];
            });
            $n=count(DB::getQueryLog()); DB::disableQueryLog(); DB::flushQueryLog(); self::assertSame('applied',$out['status'],json_encode($out)); $this->checkRecords($board); return $n;
        };
        $before=$measure($old,false); $after=$measure($new,true); self::assertLessThan($before,$after);
        fwrite(STDOUT,"\nD016 private three-seat chair SQL: former={$before}, bulk={$after}; both retain three sealed ballots.\n");
    }

}
