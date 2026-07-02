<?php

namespace Tests\Feature;

use App\Models\ForwardedWrite;
use App\Models\InstanceCapability;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * mockups-v3-wiring Phase 4 — the operator/* console suite endpoints. Pins:
 *  1. every console GET renders for an OPERATOR with its data block present;
 *  2. a citizen-only session gets the same shell with authed=false and a NULL
 *     data block (the exact /operator/operations gate — no operator data leaks);
 *  3. the role-lifecycle POSTs are refused without an operator session;
 *  4. an operator can drive qualify → establish → revoke for a self-asserted
 *     channel through the HTTP wrappers (the mesh:role parity path);
 *  5. the traveling-write receipt answers ONLY for the write's own actor —
 *     a foreign or unknown (origin, key) is a 404, indistinguishable.
 *
 * Live-pg posture (never RefreshDatabase); every mutation rolls back.
 *
 * NOTE: the Vue pages (Operator/Home … Operator/Versioning) are the next slice,
 * so component assertions pass `false` for shouldExist — the pin here is the
 * HTTP + props contract, not the client bundle.
 */
class OperatorConsoleEndpointsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_operator_console';

    /** @var array<string, array{component: string, data: string}> */
    private const PAGES = [
        '/operator' => ['component' => 'Operator/Home', 'data' => 'readiness'],
        '/operator/console' => ['component' => 'Operator/Console', 'data' => 'console'],
        '/operator/roles' => ['component' => 'Operator/Roles', 'data' => 'roles'],
        '/operator/mesh' => ['component' => 'Operator/Mesh', 'data' => 'mesh'],
        '/operator/identity' => ['component' => 'Operator/Identity', 'data' => 'identity'],
        '/operator/versioning' => ['component' => 'Operator/Versioning', 'data' => 'versioning'],
    ];

    public function test_an_operator_sees_every_console_page_with_its_data_block(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $op = app(OperatorIdentityService::class)->register('opsuite_'.Str::lower(Str::random(8)), 'correct horse battery');

            foreach (self::PAGES as $url => $page) {
                $this->be($op, 'operator')->be($citizen, 'web')->get($url)
                    ->assertOk()
                    ->assertInertia(fn (Assert $a) => $a
                        ->component($page['component'], false)
                        ->where('authed', true)
                        ->where('operator', $op->username)
                        ->has($page['data'])
                        ->has('surface'));
            }
        });
    }

    public function test_a_citizen_sees_the_shell_but_none_of_the_operator_data(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            foreach (self::PAGES as $url => $page) {
                $this->be($citizen, 'web')->get($url)
                    ->assertOk()
                    ->assertInertia(fn (Assert $a) => $a
                        ->component($page['component'], false)
                        ->where('authed', false)
                        ->where('operator', null)
                        ->where($page['data'], null));
            }
        });
    }

    public function test_role_actions_are_refused_without_an_operator_session(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();
            $before = InstanceCapability::query()->count();

            $this->be($citizen, 'web')
                ->post('/operator/roles/request', ['capability' => 'mirror'])
                ->assertStatus(302); // auth:operator bounces to login — same as the operations POSTs

            $this->assertGuest('operator');
            $this->assertSame($before, InstanceCapability::query()->count(), 'a citizen cannot establish a channel');
        });
    }

    public function test_an_operator_drives_qualify_establish_revoke_for_a_self_asserted_channel(): void
    {
        $this->onLivePg(function () {
            $op = app(OperatorIdentityService::class)->register('oprole_'.Str::lower(Str::random(8)), 'correct horse battery');
            $serverId = app(InstanceIdentityService::class)->serverId();

            // QUALIFY — the prober result is flashed back for the board to render.
            $this->actingAs($op, 'operator')
                ->post('/operator/roles/qualify', ['capability' => 'mirror'])
                ->assertRedirect()
                ->assertSessionHas('status');
            $probe = session('roles_probe');
            $this->assertTrue($probe['ok'], 'mirror is always hostable');
            $this->assertSame('mirror', $probe['capability']);

            // REQUEST a self-asserted channel — established directly, no consent.
            $this->actingAs($op, 'operator')
                ->post('/operator/roles/request', ['capability' => 'mirror'])
                ->assertRedirect()
                ->assertSessionHas('status');
            $this->assertTrue(
                InstanceCapability::query()->where('server_id', $serverId)
                    ->where('capability', 'mirror')->where('enabled', true)->exists(),
                'the channel is enabled on our manifest'
            );

            // REVOKE — always unilateral.
            $this->actingAs($op, 'operator')
                ->post('/operator/roles/revoke', ['capability' => 'mirror'])
                ->assertRedirect()
                ->assertSessionHas('status');
            $this->assertFalse(
                InstanceCapability::query()->where('server_id', $serverId)
                    ->where('capability', 'mirror')->where('enabled', true)->exists(),
                'the channel stops being advertised'
            );
        });
    }

    public function test_approve_refuses_an_unknown_proposal_with_a_flash_error(): void
    {
        $this->onLivePg(function () {
            $op = app(OperatorIdentityService::class)->register('opappr_'.Str::lower(Str::random(8)), 'correct horse battery');

            $this->actingAs($op, 'operator')
                ->post('/operator/roles/approve', ['proposal_id' => (string) Str::uuid()])
                ->assertRedirect()
                ->assertSessionHasErrors(['roles']);
        });
    }

    public function test_the_write_receipt_answers_only_for_the_writes_own_actor(): void
    {
        $this->onLivePg(function () {
            // An executed write is attributable through the audit row it sealed —
            // find one whose actor still exists as a user (live demo data).
            $attributed = DB::table('audit_log')
                ->join('users', 'users.id', '=', 'audit_log.actor_user_id')
                ->whereNull('users.deleted_at')
                ->orderByDesc('audit_log.seq')
                ->first(['audit_log.seq as seq', 'audit_log.actor_user_id as actor_id']);

            if ($attributed === null) {
                $this->markTestSkipped('no attributed audit rows on this box — cannot exercise the owner path.');
            }

            $owner = User::query()->findOrFail((string) $attributed->actor_id);
            $origin = (string) Str::uuid();
            $key = hash('sha256', 'receipt-'.Str::random(8));

            ForwardedWrite::create([
                'origin_server_id' => $origin,
                'idempotency_key' => $key,
                'form_id' => 'F-TST-000',
                'status' => ForwardedWrite::STATUS_EXECUTED,
                'audit_seq' => (int) $attributed->seq,
                'result_hash' => 'testhash',
            ]);

            // The actor sees their receipt.
            $this->be($owner, 'web')->getJson("/api/federation/write-status/{$origin}/{$key}")
                ->assertOk()
                ->assertJson([
                    'status' => 'executed',
                    'audit_seq' => (int) $attributed->seq,
                    'form_id' => 'F-TST-000',
                ]);

            // Any OTHER user gets a 404 — never someone else's filing.
            $stranger = User::query()->whereNull('deleted_at')
                ->whereKeyNot($owner->getKey())->first();
            if ($stranger !== null) {
                $this->be($stranger, 'web')->getJson("/api/federation/write-status/{$origin}/{$key}")
                    ->assertNotFound();
            }
        });
    }

    public function test_the_write_receipt_404s_for_foreign_and_undeterminable_keys(): void
    {
        $this->onLivePg(function () {
            $citizen = User::query()->whereNull('deleted_at')->firstOrFail();

            // Unknown (origin, key) — plain 404.
            $this->be($citizen, 'web')
                ->getJson('/api/federation/write-status/'.Str::uuid().'/'.hash('sha256', 'nope'))
                ->assertNotFound();

            // A REJECTED row records a citation but seals no referenced audit row —
            // its actor is not determinable, so it answers 404 (fail closed).
            $origin = (string) Str::uuid();
            $key = hash('sha256', 'rejected-'.Str::random(8));
            ForwardedWrite::create([
                'origin_server_id' => $origin,
                'idempotency_key' => $key,
                'form_id' => 'F-TST-000',
                'status' => ForwardedWrite::STATUS_REJECTED,
                'citation' => 'Art. I · as implemented',
            ]);

            $this->be($citizen, 'web')->getJson("/api/federation/write-status/{$origin}/{$key}")
                ->assertNotFound();
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        $this->withoutMiddleware(ValidateCsrfToken::class);

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
