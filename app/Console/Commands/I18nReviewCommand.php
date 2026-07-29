<?php

namespace App\Console\Commands;

use App\Models\TranslationVerification;
use App\Models\User;
use App\Services\TranslationReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase N (lane 5) — the CLI half of /system/translations/review/{locale}.
 *
 * UI↔CLI PARITY. The web surface can record a verdict; so must the terminal.
 * The reader-of-language gate travels WITH the capability — parity of what you
 * can DO, never parity of who may do it. A verdict cast here is the same act,
 * through the same service, the same quorum, and the same who/when record as one
 * cast from the page. This is not a back door around the gate: the identical
 * rule the controller enforces (a reader of the language, or the operator) is
 * enforced here too, so a person who cannot read the language cannot rubber-stamp
 * it from a shell any more than from the browser.
 */
class I18nReviewCommand extends Command
{
    protected $signature = 'i18n:review
        {locale : Target locale code (e.g. es, ar) — must be a registered locale}
        {namespace? : Catalog namespace (e.g. c_education), or _glossary for a term}
        {key? : The message key — or the glossary term when namespace is _glossary}
        {verdict? : approved | edited | rejected}
        {--user= : Acting reader — user id, email, or name (required to record)}
        {--text= : Replacement wording (required for an "edited" verdict)}
        {--note= : Optional note filed with the verdict}
        {--list : Print the worst-first queue head for this locale instead of recording}
        {--modality=ui : With --list, which modality to show (ui|pages)}
        {--dry-run : Validate and show what would be recorded, but write nothing}';

    protected $description = 'Record a translation verdict from the terminal (reader-of-language gated), or --list the queue.';

    public function handle(TranslationReviewService $review): int
    {
        $locale   = (string) $this->argument('locale');
        $registry = config('locales.locales', []);
        if (! isset($registry[$locale])) {
            $this->error("Unknown locale [{$locale}] — not in config/locales.php.");

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->showQueue($review, $locale);
        }

        $namespace = (string) $this->argument('namespace');
        $key       = (string) $this->argument('key');
        $verdict   = (string) $this->argument('verdict');
        if ($namespace === '' || $key === '' || $verdict === '') {
            $this->error('namespace, key and verdict are required to record a verdict — or pass --list to browse.');

            return self::FAILURE;
        }
        if (! in_array($verdict, TranslationVerification::VERDICTS, true)) {
            $this->error('verdict must be one of: ' . implode(', ', TranslationVerification::VERDICTS));

            return self::FAILURE;
        }

        // Resolve and GATE the acting reader — the same rule the controller uses.
        $user = $this->resolveUser((string) $this->option('user'));
        if ($user === null) {
            $this->error('--user is required and must resolve to a real account (id, email, or name).');

            return self::FAILURE;
        }
        if (! $this->canVerify($user, $locale)) {
            $this->error("[{$user->id}] does not read [{$locale}] — only readers of the language (or the operator) may verify it.");
            $this->line('  A person grants themselves this by naming the language on their record; it takes nothing from anyone.');

            return self::FAILURE;
        }

        $found = $review->locate($locale, $namespace, $key);
        if ($found === null) {
            $this->error("No such source string: [{$namespace}]:[{$key}] does not exist in en.");

            return self::FAILURE;
        }

        $machine = $found['machine'];
        $text    = $this->option('text');

        // Mirror the controller exactly: an edit that changes nothing is an
        // approval (recording it as an edit would claim a correction that never
        // happened); an edit with no wording is an error.
        if ($verdict === TranslationVerification::VERDICT_EDITED) {
            if (trim((string) $text) === '') {
                $this->error('An "edited" verdict needs --text with the wording you want instead.');

                return self::FAILURE;
            }
            if (trim((string) $text) === trim((string) $machine)) {
                $verdict = TranslationVerification::VERDICT_APPROVED;
                $this->line('  (--text matches the draft exactly — recording this as an approval, not an edit.)');
            }
        }

        $this->line("  locale     {$locale}");
        $this->line("  where      {$namespace}:{$key}");
        $this->line('  english    ' . $this->clip((string) $found['english']));
        $this->line('  draft      ' . ($machine === null ? '(none — this string has no machine draft)' : $this->clip($machine)));
        if ($found['quarantine_reason'] !== null) {
            $this->warn("  flagged    the machine refused to ship this: {$found['quarantine_reason']}");
        }
        $line = "  verdict    {$verdict}";
        if ($verdict === TranslationVerification::VERDICT_EDITED) {
            $line .= '  ->  ' . $this->clip((string) $text);
        }
        $this->line($line);

        if ($this->option('dry-run')) {
            $this->info('  dry run — nothing written.');

            return self::SUCCESS;
        }

        $review->record([
            'locale'      => $locale,
            'namespace'   => $namespace,
            'key'         => $key,
            'source_hash' => $found['source_hash'],
            'verdict'     => $verdict,
            'machine'     => $machine,
            'text'        => $verdict === TranslationVerification::VERDICT_EDITED ? (string) $text : null,
            'note'        => $this->option('note'),
        ], (string) $user->id);

        $after = $review->locate($locale, $namespace, $key);
        $this->info("  recorded. {$namespace}:{$key} [{$locale}] is now "
            . strtoupper((string) $after['state'])
            . " ({$after['agreeing']}/" . TranslationVerification::QUORUM . ' readers agree).');

        return self::SUCCESS;
    }

    private function showQueue(TranslationReviewService $review, string $locale): int
    {
        $modality = (string) $this->option('modality');
        $q        = $review->queue($locale, $modality, 20);
        $c        = $q['counts'];

        $this->info("Queue for [{$locale}] · modality {$modality} — worst first (none → quarantined → in_review → ai_draft)");
        $this->line("  none {$c['none']}   ai_draft {$c['ai_draft']}   in_review {$c['in_review']}   verified {$c['verified']}   quarantined {$c['quarantined']}");

        $rows = [];
        foreach ($q['items'] as $it) {
            $rows[] = [
                $it['state'],
                $it['namespace'],
                $this->clip((string) $it['key'], 38),
                $it['machine'] === null ? '—' : $this->clip((string) $it['machine'], 44),
                "{$it['verifiers']}/{$it['needed']}",
            ];
        }
        $this->table(['state', 'ns', 'key', 'draft', 'agree'], $rows);

        return self::SUCCESS;
    }

    /**
     * Readers of the language, plus the operator — the controller's canVerify(),
     * verbatim. `locale` is the interface they chose; `languages` is what they
     * told us they read. Either is evidence enough.
     */
    private function canVerify(User $user, string $locale): bool
    {
        if ($user->is_operator) {
            return true;
        }
        if ((string) $user->locale === $locale) {
            return true;
        }
        $languages = $user->languages ?? [];

        return is_array($languages) && in_array($locale, $languages, true);
    }

    /**
     * Resolve --user by id, email, or name. The id column is a uuid, so a
     * non-uuid reference is never compared against it — Postgres would reject the
     * cast, not merely miss.
     */
    private function resolveUser(string $ref): ?User
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        if (Str::isUuid($ref)) {
            return User::query()->find($ref);
        }

        return User::query()
            ->where('email', $ref)
            ->orWhere('display_name', $ref)
            ->orWhere('name', $ref)
            ->first();
    }

    private function clip(string $s, int $max = 68): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
    }
}
