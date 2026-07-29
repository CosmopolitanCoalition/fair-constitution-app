<?php

namespace Tests\Feature;

use App\Http\Controllers\Legislature\BillController;
use App\Models\Bill;
use App\Models\ConstitutionalChallenge;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * BillController's Art. IV §5 challenge feed (V3 §8 punch) — the registry chip
 * that marks a bill whose ENACTED LAW is under constitutional challenge, linking
 * the Art. IV §5 tracker. This pins the pure chip-mapping logic (no DB): the
 * grouped lookup is a plain whereIn+groupBy, but the mapping is where the null
 * handling, the live-vs-resolved distinction, and the deep-link href live.
 */
class BillChallengeFeedTest extends TestCase
{
    public function test_a_bill_with_no_enacted_law_has_no_chip(): void
    {
        $bill = $this->bill(null);
        $this->assertNull($this->chip($bill, collect()));
    }

    public function test_a_bill_whose_law_has_no_challenge_has_no_chip(): void
    {
        $bill = $this->bill('law-1');
        // The lookup is keyed on a DIFFERENT law id.
        $map = collect(['law-2' => collect([$this->challenge('c1', ConstitutionalChallenge::STATUS_FILED)])]);
        $this->assertNull($this->chip($bill, $map));
    }

    public function test_a_live_challenge_yields_an_active_chip_with_the_tracker_href(): void
    {
        $bill = $this->bill('law-1');
        $map = collect(['law-1' => collect([
            $this->challenge('lead', ConstitutionalChallenge::STATUS_UNDER_REVIEW),
            $this->challenge('other', ConstitutionalChallenge::STATUS_CLOSED),
        ])]);

        $chip = $this->chip($bill, $map);

        $this->assertNotNull($chip);
        $this->assertSame('lead', $chip['id']);
        $this->assertSame(ConstitutionalChallenge::STATUS_UNDER_REVIEW, $chip['status']);
        $this->assertSame(2, $chip['count'], 'the count spans every challenge on the law');
        $this->assertTrue($chip['active'], 'under_review is a live state');
        $this->assertSame('/constitutional-challenges/lead', $chip['href']);
    }

    public function test_a_resolved_challenge_yields_an_inactive_chip(): void
    {
        $bill = $this->bill('law-1');
        $map = collect(['law-1' => collect([$this->challenge('done', ConstitutionalChallenge::STATUS_JUDICIAL_REMEDY_APPLIED)])]);

        $chip = $this->chip($bill, $map);

        $this->assertNotNull($chip, 'a resolved challenge is still shown — it just is not active');
        $this->assertFalse($chip['active'], 'judicial_remedy_applied is terminal');
        $this->assertSame(1, $chip['count']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function chip(Bill $bill, Collection $challengesByLaw): ?array
    {
        $method = new ReflectionMethod(BillController::class, 'challengeChip');
        $method->setAccessible(true);

        return $method->invoke(app(BillController::class), $bill, $challengesByLaw);
    }

    private function bill(?string $enactedLawId): Bill
    {
        $bill = new Bill();
        $bill->enacted_law_id = $enactedLawId;

        return $bill;
    }

    private function challenge(string $id, string $status): ConstitutionalChallenge
    {
        $c = new ConstitutionalChallenge();
        $c->id = $id;
        $c->status = $status;

        return $c;
    }
}
