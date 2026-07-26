<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use Tests\TestCase;

/**
 * A citation must be EXACT for the machine and LEGIBLE for the citizen, and
 * those are not the same string.
 *
 * `· as implemented` marks the difference between what an article SAYS and
 * how this engine APPLIES it — 119 uses across 52 files. Lane 6 caught it
 * reading like an internal note in copy a citizen reads, which it does. The
 * wrong fix is to delete it: that makes the app assert "Art. I says this"
 * where Art. I does not literally say it, which is a TRUTHFULNESS change
 * wearing a copy tidy's clothes. On an app whose premise is that citations
 * are exact, that is the wrong direction.
 *
 * So the distinction survives and only the register changes. This pin exists
 * because the next person to find that phrase ugly will reach for `str_replace`
 * on 119 call sites, and the failure would be silent: refusals would still
 * render, still cite an article, and quietly overclaim what the article says.
 */
class CitationRegisterTest extends TestCase
{
    /** The machine value is exact — the audit chain and the 422 read it. */
    public function test_the_stored_citation_keeps_the_exact_marker(): void
    {
        $e = new ConstitutionalViolation('Blocked.', 'Art. II §2 · as implemented');

        $this->assertSame(
            'Art. II §2 · as implemented',
            $e->citation,
            'The stored citation must not be rewritten — pins, logs and the audit chain assert on it.'
        );
    }

    /** The person-facing value says the same thing in words. */
    public function test_the_person_facing_citation_still_distinguishes_implementation_from_text(): void
    {
        $e = new ConstitutionalViolation('Blocked.', 'Art. II §2 · as implemented');

        $rendered = $e->citationForAPerson();

        $this->assertStringNotContainsString('· as implemented', $rendered, 'the marker is code register');
        $this->assertStringContainsString('Art. II §2', $rendered, 'the article must survive');

        // THE PROPERTY: a reader must still be told this is the app's
        // application of the article, not the article's words.
        $this->assertStringContainsString(
            'as this app applies it',
            $rendered,
            'Removing the qualifier would make the app claim the article says something it does not.'
        );
    }

    /** A plain citation is left alone — most are already legible. */
    public function test_a_literal_citation_passes_through_untouched(): void
    {
        foreach (['Art. I', 'Art. III §5', 'CGA Forms Catalog (F-IND-023)'] as $citation) {
            $this->assertSame(
                $citation,
                (new ConstitutionalViolation('Blocked.', $citation))->citationForAPerson(),
                "A citation carrying no marker must render exactly as written [{$citation}]."
            );
        }
    }

    /**
     * One place, so every module reads the same. Inconsistency in
     * constitutional copy teaches that the citations are approximate.
     */
    public function test_the_render_site_uses_the_person_facing_form(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString(
            'citationForAPerson()',
            $bootstrap,
            'The web render must use the legible form.'
        );

        $this->assertStringContainsString(
            "'citation' => \$e->citation",
            $bootstrap,
            'The JSON 422 must keep the exact machine value.'
        );
    }
}
