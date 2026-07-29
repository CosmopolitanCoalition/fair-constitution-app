<?php

namespace Tests\Feature;

use App\Http\Controllers\Executive\DepartmentController;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * DepartmentController::overseesCgcs — the department→CGC forward link (V3 §8
 * punch; V3_GAP_MATRIX:255-256). Oversight is modeled at the EXECUTIVE level
 * (organizations.overseen_by_executive_id), so a department lists the CGCs
 * chartered under ITS executive in ITS jurisdiction — the exact reverse of the
 * working CGC→department link CgcController::oversight already draws.
 *
 * This pins the reverse join: a CGC under a DIFFERENT executive, a non-CGC org,
 * and a CGC in another jurisdiction must all be EXCLUDED, and a department with
 * no executive must never IS-NULL-match every un-overseen CGC.
 *
 * Runs on the guarded live-pg connection inside a rolled-back transaction.
 */
class DepartmentOversightTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_dept_oversight';

    public function test_it_lists_only_cgcs_under_this_departments_executive(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction();
            $otherJid = $this->jurisdiction();
            // overseesCgcs reads only executive_id + jurisdiction_id and queries
            // organizations — it never joins executives or persists the
            // department. organizations.overseen_by_executive_id carries no FK,
            // so both ids are fabricated and the department stays in memory.
            $executiveId = (string) Str::uuid();
            $otherExecutiveId = (string) Str::uuid();
            $agent = $this->user();

            $department = $this->department($jid, $executiveId);

            $mine = $this->cgc($jid, $executiveId, $agent, 'Clean Water CGC');   // INCLUDED
            $this->cgc($jid, $otherExecutiveId, $agent, 'Other Exec CGC');       // EXCLUDED — different executive
            $this->org($jid, $executiveId, $agent, false, 'A Business');         // EXCLUDED — not a CGC
            $this->cgc($otherJid, $executiveId, $agent, 'Wrong Jur CGC');        // EXCLUDED — another jurisdiction

            $result = $this->callOverseesCgcs($department);

            $this->assertCount(1, $result, 'exactly the one CGC under this executive in this jurisdiction');
            $this->assertSame('Clean Water CGC', $result[0]['name']);
            $this->assertSame("/organizations/{$mine->id}", $result[0]['href']);
        });
    }

    public function test_a_department_with_no_executive_lists_nothing(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction();
            $agent = $this->user();
            $this->cgc($jid, null, $agent, 'Unassigned CGC'); // overseen_by_executive_id NULL

            // A null executive must NOT become `IS NULL` and sweep every
            // un-overseen CGC — the guard returns nothing outright.
            $this->assertSame([], $this->callOverseesCgcs($this->department($jid, null)));
        });
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function callOverseesCgcs(Department $department): array
    {
        $method = new ReflectionMethod(DepartmentController::class, 'overseesCgcs');
        $method->setAccessible(true);

        return $method->invoke(app(DepartmentController::class), $department);
    }

    private function jurisdiction(): string
    {
        $id = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Oversight Pin',
            'slug' => 'oversight-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 2,
            'population' => 100_000,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function department(string $jurisdictionId, ?string $executiveId): Department
    {
        // Unsaved on purpose — overseesCgcs reads its attributes, never a row,
        // so we sidestep the departments table's charter_law_id/etc. constraints.
        $department = new Department();
        $department->jurisdiction_id = $jurisdictionId;
        $department->executive_id = $executiveId;

        return $department;
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Oversight Agent '.Str::random(5),
            'email' => 'oversight-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function cgc(string $jid, ?string $executiveId, User $agent, string $name): Organization
    {
        return $this->org($jid, $executiveId, $agent, true, $name);
    }

    private function org(string $jid, ?string $executiveId, User $agent, bool $isCgc, string $name): Organization
    {
        $org = Organization::create([
            'jurisdiction_id' => $jid,
            'type' => $isCgc ? Organization::TYPE_COMMON_GOOD_CORP : Organization::TYPE_BUSINESS,
            'structure' => Organization::STRUCTURE_STOCK,
            'name' => $name,
            'slug' => Str::lower(Str::slug($name).'-'.Str::random(6)),
            'status' => Organization::STATUS_ACTIVE,
            'is_active' => true,
            'is_registered' => true,
            'is_cgc' => $isCgc,
            'registered_at' => now(),
            'agent_user_id' => (string) $agent->getKey(),
            'registered_by_user_id' => (string) $agent->getKey(),
            'registered_via_form' => $isCgc ? 'F-LEG-019' : 'F-IND-012',
            'worker_count' => 0,
        ]);

        // Set oversight directly — bypasses any fillable guard on the column.
        DB::table('organizations')->where('id', $org->id)->update([
            'overseen_by_executive_id' => $executiveId,
        ]);

        return $org->refresh();
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
