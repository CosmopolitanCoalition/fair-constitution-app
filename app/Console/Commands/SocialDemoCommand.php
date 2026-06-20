<?php

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Jobs\EvaluateSocialStructureJob;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\SocialProfile;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;
use App\Models\SocialThread;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase K-1 (closeout) analogue of institutions:demo-* — persist a STANDING, BROWSABLE civic commons on
 * San Marino so the public square + halls pages render with real data instead of empty states.
 *
 *   php artisan social:demo
 *   php artisan social:demo --fresh
 *
 * It is SELF-SUFFICIENT (it mints its own tagged demo residents, so it does not hard-depend on
 * elections:demo), and it drives the REAL ConstitutionalEngine (F-SOC-001 posts + F-SOC-002 testimony) +
 * the EvaluateSocialStructureJob (square/halls provisioning + object-subforum binding). APPEND-ONLY is
 * sacrosanct: --fresh soft-deletes only the demo SOCIAL graph (threads/posts); it NEVER deletes a
 * public_record or an audit row (the sealed testimony is permanent history). A plain re-run is a reported
 * no-op. It ends with audit:verify. A seated legislature only makes it RICHER (live bill subforums); its
 * absence is a note, not a failure.
 */
class SocialDemoCommand extends Command
{
    protected $signature = 'social:demo {--fresh : soft-delete the prior demo social graph and reseed}';

    protected $description = 'Seed a standing, browsable Phase K-1 civic commons (square + halls + testimony) on San Marino.';

    private const SAN_MARINO_SLUG = 'smr-1-san-marino';

    /** The title tag that marks demo threads/posts (for idempotency + teardown). */
    private const TAG = '[K1-Demo]';

    /** The email marker that identifies the reusable demo residents. */
    private const RESIDENT_MARKER = 'k1demo';

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Fire the queued EvaluateSocialStructureJob inline so its effects are observable now.
        config(['queue.default' => 'sync']);

        $jurisdiction = Jurisdiction::query()
            ->where('slug', self::SAN_MARINO_SLUG)
            ->whereNull('deleted_at')
            ->first();

        if ($jurisdiction === null) {
            $this->error('San Marino jurisdiction ('.self::SAN_MARINO_SLUG.') not found — seed the instance first.');

            return self::FAILURE;
        }

        $jur = (string) $jurisdiction->id;
        $seated = Legislature::query()
            ->where('jurisdiction_id', $jur)
            ->where('status', Legislature::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->exists();

        if (! $seated) {
            $this->warn('Note: San Marino has no SEATED legislature — the halls will have no live-bill subforums.');
            $this->warn('      Run `institutions:demo-d` / `elections:demo` first for the full picture. Seeding the square + halls anyway.');
        }

        if ($this->option('fresh')) {
            $this->teardownSocialGraph($jur);
            $this->info('Soft-deleted the prior demo social graph (public_records + audit chain left intact — append-only).');
        } elseif ($this->demoStands($jur)) {
            $this->info('A '.self::TAG.' commons already stands on San Marino — nothing to do. Re-run with --fresh to reseed.');

            return self::SUCCESS;
        }

        // 1. Provision the square + halls spaces (+ bind object subforums to any live bills) via the job.
        EvaluateSocialStructureJob::dispatch($jur);
        $this->line('Provisioned the public square + halls (EvaluateSocialStructureJob).');

        // 2. Two reusable demo residents (residency is the ONLY posting gate — Art. I).
        $alice = $this->demoResident($jur, 'alice', 'Aurelia Demo');
        $bob = $this->demoResident($jur, 'bob', 'Bruno Demo');

        // 3. A few square posts (residency-gated, pseudonymous).
        foreach ([
            [$alice, 'Should the plaza get more shade trees?', 'Market days are brutal in July — a few more trees would help.'],
            [$bob, 'A bike lane along the ring road?', 'Safer for the kids cycling to the lyceum.'],
            [$alice, 'Longer library hours', 'Could we keep the reading room open past 18:00?'],
        ] as [$author, $title, $body]) {
            $this->engine->file('F-SOC-001', $author, [
                'jurisdiction_id' => $jur,
                'title'           => self::TAG.' '.$title,
                'body'            => $body,
            ]);
        }
        $this->line('Filed 3 public-square posts.');

        // 4. A halls thread + ONE testimony sealed from it (Plane-A civic act → append-only register).
        $opened = $this->engine->file('F-SOC-001', $alice, [
            'jurisdiction_id' => $jur,
            'space_type'      => 'halls',
            'title'           => self::TAG.' Budget — plaza widening',
            'body'            => 'For the record: I support widening the plaza in the next budget.',
        ])->recorded;

        $testimony = $this->engine->file('F-SOC-002', $alice, [
            'jurisdiction_id' => $jur,
            'thread_id'       => $opened['thread_id'],
            'post_id'         => $opened['post_id'],
        ])->recorded;
        $this->line('Sealed 1 hall testimony into the append-only register (record '.$testimony['record_id'].').');

        // 5. Re-seal the chain check — the demo never corrupts the register.
        $this->call('audit:verify');

        $square = SocialSpace::query()->where('jurisdiction_id', $jur)->where('space_type', SocialSpace::TYPE_PUBLIC_SQUARE)->first();
        $halls = SocialSpace::query()->where('jurisdiction_id', $jur)->where('space_type', SocialSpace::TYPE_HALLS)->first();
        $this->newLine();
        $this->info('Standing K-1 commons seeded on San Marino:');
        $this->line('  Square space:    '.($square?->id ?? '—'));
        $this->line('  Halls space:     '.($halls?->id ?? '—'));
        $this->line('  Browse:          /civic/square   and   /civic/halls');

        return self::SUCCESS;
    }

    /** Has a (non-deleted) demo thread already been seeded on this jurisdiction? */
    private function demoStands(string $jurisdictionId): bool
    {
        return SocialThread::query()
            ->whereIn('subforum_id', $this->jurisdictionSubforumIds($jurisdictionId))
            ->where('title', 'like', self::TAG.'%')
            ->exists();
    }

    /**
     * Soft-delete the demo SOCIAL graph only (threads + their posts). public_records, audit_log, and
     * matrix_event_snapshots are append-only and are NEVER touched — the sealed testimony is permanent.
     */
    private function teardownSocialGraph(string $jurisdictionId): void
    {
        $threadIds = SocialThread::query()
            ->whereIn('subforum_id', $this->jurisdictionSubforumIds($jurisdictionId))
            ->where('title', 'like', self::TAG.'%')
            ->pluck('id')->all();

        if ($threadIds === []) {
            return;
        }

        DB::table('social_posts')->whereIn('thread_id', $threadIds)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        SocialThread::query()->whereIn('id', $threadIds)->delete();   // soft delete (SoftDeletes)
    }

    /** A thread lives in a subforum, which lives in a space — resolve this jurisdiction's subforum ids. */
    private function jurisdictionSubforumIds(string $jurisdictionId): array
    {
        $spaceIds = SocialSpace::query()->where('jurisdiction_id', $jurisdictionId)->pluck('id')->all();
        if ($spaceIds === []) {
            return [];
        }

        return SocialSubforum::query()->whereIn('space_id', $spaceIds)->pluck('id')->all();
    }

    /** Find-or-create a reusable demo resident with an active residency association (R-03). */
    private function demoResident(string $jurisdictionId, string $slug, string $displayName): User
    {
        $email = self::RESIDENT_MARKER.'-'.$slug.'@demo.invalid';

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = User::create([
                'name'              => $displayName.' (demo)',
                'email'             => $email,
                'password'          => Str::random(40),
                'terms_accepted_at' => now(),
            ]);
        }

        // A pseudonymous handle so square/halls posts show @<handle>, never the name.
        SocialProfile::query()->firstOrCreate(
            ['user_id' => (string) $user->id],
            ['handle' => $slug.'-sanmarino', 'display_name' => $displayName]
        );

        $hasResidency = DB::table('residency_confirmations')
            ->where('user_id', (string) $user->id)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $hasResidency) {
            DB::table('residency_confirmations')->insert([
                'id'              => (string) Str::uuid(),
                'user_id'         => (string) $user->id,
                'jurisdiction_id' => $jurisdictionId,
                'days_confirmed'  => 30,
                'confirmed_at'    => now(),
                'is_active'       => true,
                'depth'           => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $this->roles->flush();

        return $user;
    }
}
