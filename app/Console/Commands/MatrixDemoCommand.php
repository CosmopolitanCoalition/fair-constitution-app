<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\OperatorAccount;
use App\Models\SocialProfile;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\Matrix\ModerationFlipService;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\RoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase K-3 (K3-M) — the standing, browsable Matrix-commons demo. It orchestrates the built K3 pieces
 * end-to-end: the topology reconciler (a jurisdiction → m.space + #square always, #halls iff seated), a
 * sealed testimony (Plane B → Plane A via F-SOC-002), and the LEGITIMACY FLIP (a seated jurisdiction's
 * judicial-attested carve-out vs. a bootstrap jurisdiction's operator-relay carve-out — two
 * matrix_carveout_log rows discriminating attestation_id set vs NULL).
 *
 *   php artisan matrix:demo --fresh        seed against the LIVE homeserver (best-effort)
 *   php artisan matrix:demo --offline      seed only the Plane-A artifacts (no homeserver round-trip)
 *
 * It is an INTEGRATION seeder (it talks to fcw_matrix) but never hard-fails on a down homeserver: the
 * Matrix topology/posts are best-effort, while the testimony seal + the flip log are pure Plane-A and
 * always land. Ends with audit:verify.
 */
class MatrixDemoCommand extends Command
{
    protected $signature = 'matrix:demo {--fresh : reseed} {--offline : skip the homeserver round-trips}';

    protected $description = 'Seed a standing Matrix-commons demo (topology + testimony + the legitimacy flip) on San Marino.';

    private const SAN_MARINO_SLUG = 'smr-1-san-marino';
    private const RESIDENT_MARKER = 'k3demo';

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly SocialTopologyReconcilerService $topology,
        private readonly ModerationFlipService $flip,
        private readonly AttestationService $attestations,
        private readonly InstanceIdentityService $identity,
        private readonly RoleService $roles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        config(['queue.default' => 'sync']);
        $this->identity->ensureIdentity();
        $offline = (bool) $this->option('offline');

        $seated = Jurisdiction::query()->where('slug', self::SAN_MARINO_SLUG)->whereNull('deleted_at')->first();
        if ($seated === null) {
            $this->error('San Marino ('.self::SAN_MARINO_SLUG.') not found — seed the instance first.');

            return self::FAILURE;
        }
        $seatedId = (string) $seated->id;

        if (! $this->isSeated($seatedId)) {
            $this->error('San Marino has no SEATED legislature — run `elections:demo` / `institutions:demo-d` first.');

            return self::FAILURE;
        }

        // A bootstrap jurisdiction (no seated legislature) for the operator-relay side of the flip.
        $bootstrapId = (string) DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('legislatures')
                ->whereColumn('legislatures.jurisdiction_id', 'jurisdictions.id')
                ->where('status', 'active')->whereNull('deleted_at'))
            ->value('id');

        // Stage 1 — Matrix topology (best-effort; skipped --offline). #square always, #halls iff seated.
        if (! $offline) {
            try {
                $this->topology->reconcileJurisdiction($seatedId, true);
                if ($bootstrapId !== '') {
                    $this->topology->reconcileJurisdiction($bootstrapId, false);
                }
                $this->line('Provisioned Matrix topology (Spaces + #square always, #halls iff seated).');
            } catch (Throwable $e) {
                $this->warn('Homeserver unreachable — Matrix topology skipped; the Plane-A artifacts still seed. ('.$e->getMessage().')');
            }
        } else {
            $this->line('--offline: skipping the homeserver round-trips (Plane-A artifacts only).');
        }

        // Stage 2 — a sealed testimony (Plane B → Plane A), via the F-SOC-002 Matrix-origin path.
        $alice = $this->demoResident($seatedId, 'alice', 'Aurelia Demo');
        $eventId = '$k3demo-testimony-'.Str::random(8);
        $testimony = $this->engine->file('F-SOC-002', $alice, [
            'matrix_event_id'  => $eventId,
            'matrix_room_id'   => '!k3demo-halls:'.config('matrix.server_name'),
            'body_snapshot'    => 'For the record: the live commons should keep #halls minutes public.',
            'actor_display'    => '@'.($this->handleOf($alice)),
            'jurisdiction_id'  => $seatedId,
            'origin_server_ts' => 1700000000000,
        ])->recorded;
        $this->line('Sealed a #halls testimony into the append-only register (record '.$testimony['record_id'].').');

        // Stage 3 — the LEGITIMACY FLIP, two carve-out log rows discriminating the authority basis.
        $judicial = $this->demoJudicialAttestation($alice);
        $dJudicial = $this->flip->resolve($seatedId, 'm1_judicial', $judicial, null);
        $this->flip->logFlip($dJudicial, '!k3demo-halls:'.config('matrix.server_name'), $eventId);
        $this->line('Logged a SEATED judicial-attested carve-out (attestation_id set).');

        if ($bootstrapId !== '') {
            $operator = $this->demoOperator();
            $dOperator = $this->flip->resolve($bootstrapId, 'm2_rights', null, $operator);
            if ($dOperator->permitted) {
                $this->flip->logFlip($dOperator, '!k3demo-square:'.config('matrix.server_name'), '$k3demo-relay');
                $this->line('Logged a BOOTSTRAP operator-relay carve-out (attestation_id NULL) — the flip, demonstrated.');
            }
            // The carve-out log row stands on its own (no operator FK); the EPHEMERAL demo operator is
            // force-deleted so it never inflates the global de-facto operator board (Meter A counts
            // active operators — a stray demo operator would skew a real upgrade-consent threshold).
            $operator->forceDelete();
        }

        $this->call('audit:verify');

        $this->newLine();
        $this->info('Standing K-3 Matrix-commons demo seeded.');
        $this->line('  Browse the live commons:  /civic/commons/square   and   /civic/commons/halls');
        $this->line('  The flip is visible in the public register (kind=moderation_flip) + matrix_carveout_log.');

        return self::SUCCESS;
    }

    private function isSeated(string $jurisdictionId): bool
    {
        return Legislature::query()->where('jurisdiction_id', $jurisdictionId)
            ->where('status', Legislature::STATUS_ACTIVE)->whereNull('deleted_at')->exists();
    }

    private function demoResident(string $jurisdictionId, string $slug, string $displayName): User
    {
        $email = self::RESIDENT_MARKER.'-'.$slug.'@demo.invalid';
        $user = User::query()->where('email', $email)->first()
            ?? User::create([
                'name' => $displayName.' (demo)', 'email' => $email,
                'password' => Str::random(40), 'terms_accepted_at' => now(),
            ]);

        SocialProfile::query()->firstOrCreate(['user_id' => (string) $user->id], ['handle' => $slug.'-k3demo']);

        $has = DB::table('residency_confirmations')->where('user_id', (string) $user->id)
            ->where('jurisdiction_id', $jurisdictionId)->where('is_active', true)->exists();
        if (! $has) {
            DB::table('residency_confirmations')->insert([
                'id' => (string) Str::uuid(), 'user_id' => (string) $user->id, 'jurisdiction_id' => $jurisdictionId,
                'days_confirmed' => 30, 'confirmed_at' => now(), 'is_active' => true, 'depth' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->roles->flush();

        return $user;
    }

    private function handleOf(User $user): string
    {
        return (string) (SocialProfile::query()->where('user_id', (string) $user->id)->value('handle') ?? 'resident');
    }

    private function demoJudicialAttestation(User $user): StandingAttestation
    {
        $att = new StandingAttestation([
            'id' => (string) Str::uuid(),
            'subject_user_id' => (string) $user->getKey(),
            'device_public_key' => 'k3demo-dpk',
            'issuer_server_id' => $this->identity->serverId(),
            'roles' => ['R-19'],
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $att->signature = $this->identity->sign($this->attestations->attestationCanonical($att));
        $att->save();

        return $att;
    }

    private function demoOperator(): OperatorAccount
    {
        // Clear any leftover from a crashed prior run, then mint a FRESH active operator. It is
        // EPHEMERAL — force-deleted right after the flip (see handle) so it never counts as board.
        OperatorAccount::withTrashed()->where('username', 'k3demo-operator')->forceDelete();

        return OperatorAccount::create([
            'server_id' => (string) Str::uuid(), 'username' => 'k3demo-operator',
            'password' => Str::random(40), 'status' => OperatorAccount::STATUS_ACTIVE,
        ]);
    }
}
