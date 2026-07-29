<?php

namespace Tests\Feature;

use App\Models\TranslationVerification;
use App\Models\User;
use App\Services\RoleService;
use App\Services\TranslationReviewService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Phase N (lane 5) — UI↔CLI parity for the translation verdict.
 *
 * The web surface (TranslationReviewControllerTest / TranslationReviewTest)
 * already pins the RULE — verification is gated to readers of the language, an
 * edit that changes nothing is an approval, a verdict binds to its source hash.
 * These pins prove the terminal enforces the SAME rule through the SAME service:
 * capability parity that never becomes an exposure hole. A shell is not a way
 * around the gate.
 *
 * Same posture as TranslationReviewTest: live pg on a guarded connection,
 * everything in one rolled-back transaction, SKIP when pg is unreachable.
 */
class I18nReviewCommandTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_i18n_review_cli';

    private const WHOLE_QUEUE = 100000;

    public function test_a_reader_of_the_language_records_a_verdict_from_the_cli(): void
    {
        $this->onLivePg(function (): void {
            $item   = $this->firstQueueItem('es');
            $reader = $this->aUser('CLI Reader', 'es');

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'approved',
                '--user'    => $reader->email,
            ]);

            $this->assertSame(0, $code, 'A reader of the language may record a verdict.');
            $this->assertSame(1, TranslationVerification::where('locale', 'es')
                ->where('verified_by', $reader->id)->count());
            $this->assertSame('in_review', $this->stateOf('es', $item),
                'One verdict moves a pristine draft to in_review.');
        });
    }

    public function test_someone_who_does_not_read_the_language_is_refused(): void
    {
        $this->onLivePg(function (): void {
            $item     = $this->firstQueueItem('es');
            $outsider = $this->aUser('English Only', 'en');   // reads en, not es

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'approved',
                '--user'    => $outsider->email,
            ]);

            $this->assertSame(1, $code, 'The gate refuses a non-reader at the CLI too.');
            // Scoped to THIS outsider: the shared es world may carry other verdicts;
            // what this proves is that the person who cannot read it wrote nothing.
            $this->assertSame(0, TranslationVerification::where('locale', 'es')
                ->where('verified_by', $outsider->id)->count());
        });
    }

    public function test_naming_the_language_is_all_it_takes_at_the_cli(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');
            // Interface is English; grants themselves the right by listing es.
            $reader = $this->aUser('Bilingual CLI', 'en', ['en', 'es']);

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'approved',
                '--user'    => $reader->email,
            ]);

            $this->assertSame(0, $code);
            $this->assertSame(1, TranslationVerification::where('locale', 'es')
                ->where('verified_by', $reader->id)->count());
        });
    }

    public function test_the_operator_can_unstick_a_locale_nobody_has_joined(): void
    {
        $this->onLivePg(function (): void {
            $item     = $this->firstQueueItem('es');
            $operator = $this->aUser('Operator', 'en');
            $operator->forceFill(['is_operator' => true])->save();

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'approved',
                '--user'    => $operator->email,
            ]);

            $this->assertSame(0, $code, 'The operator may always verify — someone must be able to unstick a fresh locale.');
            $this->assertSame(1, TranslationVerification::where('locale', 'es')
                ->where('verified_by', $operator->id)->count());
        });
    }

    public function test_an_edit_that_changes_nothing_is_recorded_as_an_approval(): void
    {
        $this->onLivePg(function (): void {
            $item   = $this->firstQueueItem('es');
            $reader = $this->aUser('Idle CLI Editor', 'es');

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'edited',
                '--user'    => $reader->email,
                '--text'    => $item['machine'],   // identical to the draft
            ]);

            $this->assertSame(0, $code);
            $this->assertSame(
                TranslationVerification::VERDICT_APPROVED,
                TranslationVerification::where('locale', 'es')
                    ->where('verified_by', $reader->id)->value('verdict'),
                'An edit that changed nothing must not claim a correction that never happened.',
            );
        });
    }

    public function test_an_edit_with_no_wording_is_refused(): void
    {
        $this->onLivePg(function (): void {
            $item   = $this->firstQueueItem('es');
            $reader = $this->aUser('Empty CLI Editor', 'es');

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'edited',
                '--user'    => $reader->email,
                '--text'    => '   ',
            ]);

            $this->assertSame(1, $code);
            $this->assertSame(0, TranslationVerification::where('locale', 'es')
                ->where('verified_by', $reader->id)->count());
        });
    }

    public function test_dry_run_validates_but_writes_nothing(): void
    {
        $this->onLivePg(function (): void {
            $item   = $this->firstQueueItem('es');
            $reader = $this->aUser('Careful CLI', 'es');

            $code = Artisan::call('i18n:review', [
                'locale'    => 'es',
                'namespace' => $item['namespace'],
                'key'       => $item['key'],
                'verdict'   => 'approved',
                '--user'    => $reader->email,
                '--dry-run' => true,
            ]);

            $this->assertSame(0, $code);
            $this->assertSame(0, TranslationVerification::where('locale', 'es')
                ->where('verified_by', $reader->id)->count(),
                'A dry run must not record anything.');
        });
    }

    public function test_an_unknown_locale_fails_cleanly(): void
    {
        $this->onLivePg(function (): void {
            $code = Artisan::call('i18n:review', [
                'locale'    => 'zzz',
                'namespace' => 'c_achievements',
                'key'       => 'whatever',
                'verdict'   => 'approved',
                '--user'    => $this->aUser('Nobody', 'en')->email,
            ]);

            $this->assertSame(1, $code);
        });
    }

    public function test_list_prints_the_queue_without_a_user(): void
    {
        $this->onLivePg(function (): void {
            $code = Artisan::call('i18n:review', ['locale' => 'es', '--list' => true]);
            $this->assertSame(0, $code, 'Browsing the queue is a read — it needs no verdict and no user.');
            $this->assertStringContainsString('worst first', Artisan::output());
        });
    }

    /* ── helpers (mirrors TranslationReviewTest) ──────────────────────────────── */

    private function firstQueueItem(string $locale): array
    {
        foreach (app(TranslationReviewService::class)->queue($locale, 'ui', self::WHOLE_QUEUE)['items'] as $item) {
            if ($item['state'] !== 'ai_draft') {
                continue;
            }
            $touched = TranslationVerification::where('locale', $locale)
                ->where('namespace', $item['namespace'])
                ->where('message_key', $item['key'])
                ->exists();
            if (! $touched) {
                return $item;
            }
        }

        $this->fail("No pristine carried (ai_draft, unverified) string in [{$locale}] — the catalogs are missing.");
    }

    private function stateOf(string $locale, array $item): string
    {
        $q = (new TranslationReviewService())->queue($locale, 'ui', self::WHOLE_QUEUE);
        foreach ($q['items'] as $it) {
            if ($it['key'] === $item['key'] && $it['namespace'] === $item['namespace']) {
                return $it['state'];
            }
        }

        return 'verified';   // dropped from the queue means settled
    }

    private function aUser(string $name, string $locale = 'en', ?array $languages = null): User
    {
        return User::create([
            'name'              => $name,
            'email'             => 'i18n-cli-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
            'locale'            => $locale,
            'languages'         => $languages ?? [$locale],
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
