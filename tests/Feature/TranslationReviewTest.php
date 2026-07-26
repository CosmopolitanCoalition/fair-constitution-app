<?php

namespace Tests\Feature;

use App\Models\TranslationVerification;
use App\Models\User;
use App\Services\RoleService;
use App\Services\TranslationReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Phase N (lane 5) — the human half of translation.
 *
 * What these pins protect is not the UI, it is the honesty of the numbers:
 *
 *   - "translated" never silently means "verified", and neither ever means
 *     "live in the interface". Three different facts, three states.
 *   - one language is six numbers, not one, so a dubbed-video claim can never
 *     ride on an interface-string percentage.
 *   - verification is by QUORUM of people who READ the language. Not one
 *     person, not a title, and never the machine grading itself.
 *   - an approval belongs to the English it was given against. Change the
 *     source and the approval goes with it.
 *
 * The JourneysTest posture: live pg on a guarded connection, everything in one
 * rolled-back transaction, SKIP when pg is unreachable.
 */
class TranslationReviewTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_translation_review';

    /* ── the numbers ──────────────────────────────────────────────────────── */

    public function test_a_language_is_six_numbers_not_one(): void
    {
        $this->onLivePg(function (): void {
            $row = app(TranslationReviewService::class)->row('es');

            $this->assertSame(
                ['ui', 'pages', 'audio', 'captions', 'education', 'help'],
                array_keys($row['cells']),
                'The six kinds of content are the unit of measurement.',
            );

            // The four we do not produce are reported as not-started WITH A
            // REASON, never left blank. A blank cell reads as "fine".
            foreach (['audio', 'captions', 'education', 'help'] as $kind) {
                $this->assertSame('none', $row['cells'][$kind]['state']);
                $this->assertNotEmpty($row['cells'][$kind]['why'], "[{$kind}] must say why it is empty.");
            }
        });
    }

    public function test_overall_is_the_mean_of_all_six_so_the_empty_kinds_count(): void
    {
        $this->onLivePg(function (): void {
            $row = app(TranslationReviewService::class)->row('es');
            $mean = array_sum(array_column($row['cells'], 'pct')) / 6;

            $this->assertSame((int) round($mean), $row['overall']);

            // The specific lie this prevents: interface at 100% reading as "done".
            $this->assertLessThan(
                100,
                $row['overall'],
                'With four kinds unproduced, no language may report as fully translated.',
            );
        });
    }

    public function test_carried_is_never_reported_as_verified(): void
    {
        $this->onLivePg(function (): void {
            $row = app(TranslationReviewService::class)->row('es');

            // Nobody has verified anything in this transaction, so a locale
            // whose catalog is complete still reads as a machine draft.
            $this->assertSame('ai_draft', $row['cells']['ui']['state']);
            $this->assertSame(0, $row['cells']['ui']['verified']);
            $this->assertGreaterThan(0, $row['cells']['ui']['carried']);
        });
    }

    public function test_published_is_never_claimed_because_nothing_here_can_observe_it(): void
    {
        $this->onLivePg(function (): void {
            $svc = app(TranslationReviewService::class);

            foreach (['es', 'ar', 'en'] as $locale) {
                foreach ($svc->row($locale)['cells'] as $kind => $cell) {
                    $this->assertNotSame(
                        'published',
                        $cell['state'],
                        "[{$locale}.{$kind}] wiring a page to use a key is not observable here.",
                    );
                }
            }
        });
    }

    /* ── the verdicts ─────────────────────────────────────────────────────── */

    public function test_quorum_not_one_person_settles_a_string(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');

            // One short of quorum is still a question.
            for ($i = 1; $i < TranslationVerification::QUORUM; $i++) {
                $this->recordVerdict($item, $this->aUser("Reader {$i}", 'es'));
            }
            $this->assertSame('in_review', $this->stateOf('es', $item));

            $this->recordVerdict($item, $this->aUser('Reader last', 'es'));
            $this->assertSame('verified', $this->stateOf('es', $item));
        });
    }

    public function test_a_rejection_never_counts_toward_agreement(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');

            for ($i = 0; $i < TranslationVerification::QUORUM; $i++) {
                $this->recordVerdict($item, $this->aUser("Dissenter {$i}", 'es'), 'rejected');
            }

            $this->assertSame(
                'ai_draft',
                $this->stateOf('es', $item),
                'Three people saying it is wrong must not read as three people agreeing.',
            );
        });
    }

    public function test_an_approval_dies_with_the_english_it_was_given_against(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');

            for ($i = 0; $i < TranslationVerification::QUORUM; $i++) {
                $this->recordVerdict($item, $this->aUser("Reader {$i}", 'es'));
            }
            $this->assertSame('verified', $this->stateOf('es', $item));

            // The English changed underneath them. Nobody read the new wording,
            // so carrying the approval forward would misreport who checked what.
            DB::table('translation_verifications')
                ->where('locale', 'es')
                ->where('message_key', $item['key'])
                ->update(['source_hash' => hash('sha256', 'some other english')]);

            $this->assertSame('ai_draft', $this->stateOf('es', $item));
        });
    }

    public function test_one_person_gets_one_verdict_and_may_change_it(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');
            $user = $this->aUser('Mind Changer', 'es');

            $this->recordVerdict($item, $user, 'approved');
            $this->recordVerdict($item, $user, 'rejected');

            $this->assertSame(1, TranslationVerification::query()
                ->where('locale', 'es')
                ->where('message_key', $item['key'])
                ->where('verified_by', $user->id)
                ->count(), 'Changing your mind is an update, not a second vote.');

            $this->assertSame('ai_draft', $this->stateOf('es', $item));
        });
    }

    public function test_a_string_you_ruled_on_stays_in_front_of_you(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');
            $user = $this->aUser('Steady Hand', 'es');
            $this->be($user);

            $this->recordVerdict($item, $user);

            $items = (new TranslationReviewService())->queue('es', 'ui', 50)['items'];
            $mine = array_values(array_filter(
                $items,
                fn (array $i): bool => $i['key'] === $item['key'] && $i['namespace'] === $item['namespace'],
            ));

            // A row that vanishes the instant you click it reads as an error,
            // not as work done. It stays, carrying your verdict.
            $this->assertCount(1, $mine, 'Your own verdict must not remove the row from view.');
            $this->assertSame('approved', $mine[0]['my_verdict']);
            $this->assertSame('in_review', $mine[0]['state']);
        });
    }

    public function test_your_own_verdicts_never_crowd_out_work_you_can_still_do(): void
    {
        $this->onLivePg(function (): void {
            $user = $this->aUser('Prolific', 'es');
            $this->be($user);

            $limit = 12;
            foreach (array_slice((new TranslationReviewService())->queue('es', 'ui', $limit)['items'], 0, $limit) as $item) {
                $this->recordVerdict($item, $user);
            }

            $items = (new TranslationReviewService())->queue('es', 'ui', $limit)['items'];
            $actionable = array_filter($items, fn (array $i): bool => $i['my_verdict'] === null);

            // The window counts only rows this reader can still act on, so a
            // page never fills with your own settled verdicts while appearing
            // to still be offering you work.
            $this->assertCount($limit, $actionable);
            $this->assertGreaterThan($limit, count($items), 'Your own rows ride along in place.');
        });
    }

    /* ── the terms ────────────────────────────────────────────────────────── */

    public function test_a_machine_rendering_of_a_term_is_a_draft_not_a_settlement(): void
    {
        $this->onLivePg(function (): void {
            $terms = app(TranslationReviewService::class)->terms('es');
            $worded = array_values(array_filter($terms, fn (array $t): bool => $t['rendering'] !== null));

            $this->assertNotEmpty($worded, 'Spanish should carry machine renderings to test against.');

            foreach ($worded as $t) {
                // The whole point: a non-empty field is not a person's judgement.
                // Getting this wrong propagates into every string using the term.
                $this->assertFalse($t['settled'], "[{$t['term']}] a rendering nobody read is not settled.");
                $this->assertSame('ai_draft', $t['state']);
            }
        });
    }

    public function test_a_term_settles_by_the_same_quorum_as_a_string(): void
    {
        $this->onLivePg(function (): void {
            $term = $this->firstWordedTerm('es');

            for ($i = 0; $i < TranslationVerification::QUORUM; $i++) {
                $this->recordTermVerdict($term, $this->aUser("Term reader {$i}", 'es'));
            }

            $after = $this->termNamed('es', $term['term']);
            $this->assertTrue($after['settled']);
            $this->assertSame('verified', $after['state']);
            $this->assertSame(TranslationVerification::QUORUM, $after['verifiers']);
        });
    }

    public function test_rewording_a_term_unsettles_every_verdict_given_against_the_old_wording(): void
    {
        $this->onLivePg(function (): void {
            $term = $this->firstWordedTerm('es');

            for ($i = 0; $i < TranslationVerification::QUORUM; $i++) {
                $this->recordTermVerdict($term, $this->aUser("Term reader {$i}", 'es'));
            }
            $this->assertTrue($this->termNamed('es', $term['term'])['settled']);

            // Someone changes the rendering. Nobody has read the new one, so
            // the old approvals cannot vouch for it.
            DB::table('translation_verifications')
                ->where('namespace', TranslationReviewService::GLOSSARY_NAMESPACE)
                ->where('message_key', $term['term'])
                ->update(['source_hash' => hash('sha256', $term['term']."\0".'una redacción distinta')]);

            $after = $this->termNamed('es', $term['term']);
            $this->assertFalse($after['settled']);
            $this->assertSame(0, $after['verifiers']);
        });
    }

    public function test_a_term_with_no_wording_reads_as_nothing_yet(): void
    {
        $this->onLivePg(function (): void {
            $terms = app(TranslationReviewService::class)->terms('fr');
            $blank = array_values(array_filter($terms, fn (array $t): bool => $t['rendering'] === null));

            $this->assertNotEmpty($blank, 'French is the locale with an unfilled term base.');
            foreach ($blank as $t) {
                $this->assertSame('none', $t['state']);
                $this->assertFalse($t['settled']);
            }
        });
    }

    /* ── who may verify ───────────────────────────────────────────────────── */

    public function test_verification_is_gated_to_readers_of_the_language(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');

            // Reads English only. May LOOK — this is not a secret — but may not rule.
            $outsider = $this->aUser('English Reader', 'en');

            $this->actingAs($outsider)
                ->get('/system/translations/review/es')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('System/TranslationReview')
                    ->where('canVerify', false));

            $this->actingAs($outsider)
                ->post('/system/translations/review/es', $this->payload($item))
                ->assertSessionHasErrors('verdict');

            $this->assertSame(0, TranslationVerification::where('locale', 'es')->count());
        });
    }

    public function test_naming_the_language_is_all_it_takes_to_help(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');

            // The gate follows the reader, not a title: a person grants it to
            // themselves by saying they read the language.
            $reader = $this->aUser('Bilingual', 'en', ['en', 'es']);

            $this->actingAs($reader)
                ->post('/system/translations/review/es', $this->payload($item))
                ->assertSessionHasNoErrors();

            $this->assertSame(1, TranslationVerification::where('locale', 'es')->count());
        });
    }

    public function test_an_edit_that_changes_nothing_is_recorded_as_an_approval(): void
    {
        $this->onLivePg(function (): void {
            $item = $this->firstQueueItem('es');
            $reader = $this->aUser('Idle Editor', 'es');

            $this->actingAs($reader)->post('/system/translations/review/es', $this->payload($item, [
                'verdict' => 'edited',
                'text'    => $item['machine'],
            ]))->assertSessionHasNoErrors();

            $this->assertSame(
                TranslationVerification::VERDICT_APPROVED,
                TranslationVerification::where('locale', 'es')->value('verdict'),
                'Recording an edit that changed nothing would claim a correction that never happened.',
            );
        });
    }

    public function test_an_unknown_language_is_a_404_not_an_empty_page(): void
    {
        $this->onLivePg(function (): void {
            $this->actingAs($this->aUser('Curious', 'en'))
                ->get('/system/translations/review/zzz')
                ->assertNotFound();
        });
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    private function firstQueueItem(string $locale): array
    {
        $items = app(TranslationReviewService::class)->queue($locale, 'ui', 1)['items'];
        $this->assertNotEmpty($items, "No reviewable strings in [{$locale}] — the catalogs are missing.");

        return $items[0];
    }

    private function firstWordedTerm(string $locale): array
    {
        foreach (app(TranslationReviewService::class)->terms($locale) as $t) {
            if ($t['rendering'] !== null) {
                return $t;
            }
        }

        $this->fail("No worded terms in [{$locale}].");
    }

    private function termNamed(string $locale, string $name): array
    {
        foreach ((new TranslationReviewService())->terms($locale) as $t) {
            if ($t['term'] === $name) {
                return $t;
            }
        }

        $this->fail("Term [{$name}] vanished from the base.");
    }

    private function recordTermVerdict(array $term, User $user, string $verdict = 'approved'): void
    {
        app(TranslationReviewService::class)->record([
            'locale'      => 'es',
            'namespace'   => $term['namespace'],
            'key'         => $term['term'],
            'source_hash' => $term['source_hash'],
            'verdict'     => $verdict,
            'machine'     => $term['rendering'],
            'text'        => null,
        ], (string) $user->id);
    }

    private function stateOf(string $locale, array $item): string
    {
        // A fresh service instance: the per-request catalog memo must not be
        // able to carry a stale verdict count across an assertion.
        $q = (new TranslationReviewService())->queue($locale, 'ui', 500);

        foreach ($q['items'] as $it) {
            if ($it['key'] === $item['key'] && $it['namespace'] === $item['namespace']) {
                return $it['state'];
            }
        }

        // Absent from the queue means settled — the queue drops verified strings.
        return 'verified';
    }

    private function recordVerdict(array $item, User $user, string $verdict = 'approved'): void
    {
        app(TranslationReviewService::class)->record([
            'locale'      => 'es',
            'namespace'   => $item['namespace'],
            'key'         => $item['key'],
            'source_hash' => $item['source_hash'],
            'verdict'     => $verdict,
            'machine'     => $item['machine'],
            'text'        => null,
        ], (string) $user->id);
    }

    private function payload(array $item, array $overrides = []): array
    {
        return array_merge([
            'namespace'   => $item['namespace'],
            'key'         => $item['key'],
            'source_hash' => $item['source_hash'],
            'verdict'     => 'approved',
            'machine'     => $item['machine'],
        ], $overrides);
    }

    private function aUser(string $name, string $locale = 'en', ?array $languages = null): User
    {
        return User::create([
            'name'              => $name,
            'email'             => 'translation-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
            'locale'            => $locale,
            'languages'         => $languages ?? [$locale],
        ]);
    }

    private function onLivePg(callable $body): void
    {
        // These pins exercise the route handlers, not the CSRF layer.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

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
