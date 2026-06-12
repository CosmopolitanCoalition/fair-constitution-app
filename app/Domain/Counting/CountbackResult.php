<?php

namespace App\Domain\Counting;

/**
 * Outcome of a countback re-run (Art. II §5).
 *
 *  - $tabulation    the full deterministic re-run record (same seat
 *                   count, vacating candidacies struck)
 *  - $replacements  re-run winners who are NOT current sitting members,
 *                   in re-run election order — these fill the vacancies;
 *                   sitting members' seats are never disturbed
 *  - $failed        true when the re-run produced fewer new winners than
 *                   vacancies → vacancies.status='countback_failed',
 *                   CLK-04 special-election window arms (90–180 days)
 */
final readonly class CountbackResult
{
    /**
     * @param  list<string>  $struck
     * @param  list<string>  $sitting
     * @param  list<string>  $replacements
     */
    public function __construct(
        public CountResult $tabulation,
        public array $struck,
        public array $sitting,
        public array $replacements,
        public bool $failed,
    ) {
    }
}
