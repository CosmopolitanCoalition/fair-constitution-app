<?php

namespace App\Services;

use App\Models\TranslationVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase N (lane 5) — the human half of translation.
 *
 * Reads the machine's catalogs off disk, joins them to the verdicts people have
 * recorded, and answers two questions the coverage percentage cannot:
 *
 *   what still needs a person?      -> queue()
 *   what state is each language in? -> matrix()
 *
 * SIX KINDS OF CONTENT, NOT ONE NUMBER (mockups/v3/translation, `tr.modalities`).
 * A language that is 99% on interface strings and has no dubbed video is not
 * "99% translated" in any sense a person cares about. The matrix reports each
 * kind separately for exactly that reason, and the overall figure is the mean
 * of the six — so four empty kinds visibly drag it down instead of hiding
 * behind the one kind we happen to measure well.
 *
 * THE FIVE-STATE LIFECYCLE, kept apart on purpose (`tr.states`):
 *
 *   none        no translation exists
 *   ai_draft    a machine wrote it and nobody has read it        (CARRIED)
 *   in_review   at least one person has, but not enough of them
 *   verified    QUORUM readers of the language agree             (REVIEWED)
 *   published   the interface actually renders it                (WIRED)
 *
 * Nothing here ever reports `published`: wiring a page to use a key is the
 * interface lane's work, and this service has no way to observe it. Saying
 * "verified" when we mean "live" is the exact confusion the five states exist
 * to prevent, so the state is withheld rather than guessed.
 */
class TranslationReviewService
{
    private const LOCALES_DIR = 'resources/js/i18n/locales';
    private const META_DIR    = 'resources/js/i18n/meta';
    private const GLOSSARY    = 'resources/js/i18n/glossary/term-base.json';

    /**
     * The six kinds of content a language must cover, verbatim from the design
     * (mockups/v3/assets/js/fixtures-translation.js, `modalities`).
     *
     * `measurable` marks the two this lane can actually count today. The other
     * four are real work owned elsewhere; they are reported as `none` with a
     * stated reason rather than left blank — a blank cell reads as "fine".
     */
    public const MODALITIES = [
        ['id' => 'ui', 'label' => 'Interface', 'icon' => 'sliders',
            'basis' => 'Keyed chrome strings — menus, buttons, labels.',
            'source' => 'i18n string dictionary', 'measurable' => true],
        ['id' => 'pages', 'label' => 'Page copy', 'icon' => 'file-text',
            'basis' => 'The body text on every screen.',
            'source' => 'page-folder namespaces', 'measurable' => true],
        // Icons are the app's own set (Components/Ui/Icon.vue). Where the
        // mockup names one the app does not ship — volume, captions,
        // help-circle — the nearest shipped glyph stands in rather than
        // rendering a blank.
        ['id' => 'audio', 'label' => 'Video audio', 'icon' => 'play',
            'basis' => 'Narration dub per video (.m4a track).',
            'source' => '{Subject}-{Language}.m4a', 'measurable' => false,
            'why' => 'Narration dubs are produced outside the app and are not wired to it yet.'],
        ['id' => 'captions', 'label' => 'Captions', 'icon' => 'message-square',
            'basis' => 'Subtitles per video (.vtt track).',
            'source' => '{Subject}-{Language}.vtt', 'measurable' => false,
            'why' => 'Caption tracks are produced outside the app and are not wired to it yet.'],
        ['id' => 'education', 'label' => 'Education', 'icon' => 'graduation-cap',
            'basis' => 'Lesson scripts and knowledge checks.',
            'source' => 'learn module content', 'measurable' => false,
            'why' => 'The lesson corpus has not been extracted into catalogs yet.'],
        ['id' => 'help', 'label' => 'Help & guides', 'icon' => 'book-open',
            'basis' => 'SOPs, guides, and ticket templates.',
            'source' => 'sop + support copy', 'measurable' => false,
            'why' => 'No separate help corpus exists yet — help copy still lives inside page namespaces.'],
    ];

    /** The lifecycle, in order, for the legend (`tr.states`). */
    public const STATES = [
        ['id' => 'none', 'label' => 'Not started', 'tone' => 'neutral',
            'desc' => 'No translation exists yet.'],
        ['id' => 'ai_draft', 'label' => 'AI draft', 'tone' => 'info',
            'desc' => 'Machine first round — awaiting human eyes.'],
        ['id' => 'in_review', 'label' => 'In review', 'tone' => 'warning',
            'desc' => 'Readers are reviewing and correcting it.'],
        ['id' => 'verified', 'label' => 'Community-verified', 'tone' => 'success',
            'desc' => 'Enough readers of this language agree.'],
        ['id' => 'published', 'label' => 'Published', 'tone' => 'success',
            'desc' => 'Live in the interface.'],
    ];

    /**
     * Namespaces that are interface chrome rather than page body copy.
     *
     * `c_*` are the shared-component namespaces the extractor mints; `chrome`
     * is the shell itself; `registry` is the nav/journey labels. Everything
     * else is a page folder, which is page copy by definition.
     */
    private const UI_NAMESPACES = ['chrome', 'components', 'registry'];

    /**
     * The namespace term verdicts are filed under.
     *
     * A term is verified through the same table, the same quorum and the same
     * who/when record as a string — it is the same act, and the more
     * consequential one. The leading underscore keeps it clear of the catalog
     * namespaces, which are all real files on disk.
     */
    public const GLOSSARY_NAMESPACE = '_glossary';

    /**
     * The review queue for one locale: the machine's proposal, the English
     * beside it, and where each string sits in the lifecycle.
     *
     * Ordered worst-first — a string the machine refused to ship needs a person
     * more than one it merely guessed at.
     *
     * @return array{items: list<array<string,mixed>>, counts: array<string,int>,
     *               by_modality: array<string, array<string,int>>}
     */
    public function queue(string $locale, ?string $modality = null, int $limit = 50): array
    {
        $source  = $this->catalog('en');
        $target  = $this->catalog($locale);
        $meta    = $this->meta($locale);
        $srcMeta = $this->meta('en');

        $verdicts = $this->verdictsFor($locale);
        $me       = (string) (auth()->id() ?? '');

        $items  = [];
        $counts = ['none' => 0, 'ai_draft' => 0, 'in_review' => 0, 'verified' => 0, 'quarantined' => 0];
        $byMod  = [];

        foreach ($source as $ns => $keys) {
            $mod = $this->modalityOf($ns);
            $byMod[$mod] ??= ['none' => 0, 'ai_draft' => 0, 'in_review' => 0, 'verified' => 0, 'quarantined' => 0];

            foreach ($keys as $key => $english) {
                if (! is_string($english)) {
                    continue;
                }

                $machine = $target[$ns][$key] ?? null;
                $status  = $meta[$ns][$key]['status'] ?? null;
                $hash    = $srcMeta[$ns][$key]['source_hash'] ?? hash('sha256', $english);

                // Only verdicts cast against THIS English text count. A source
                // string that changed since takes its approvals with it.
                $live = array_filter(
                    $verdicts["{$ns}:{$key}"] ?? [],
                    fn (array $v): bool => $v['source_hash'] === $hash,
                );
                $agreeing = count(array_filter(
                    $live,
                    fn (array $v): bool => $v['verdict'] !== TranslationVerification::VERDICT_REJECTED,
                ));

                $state = $this->state($machine, $agreeing);
                $counts[$state]++;
                $byMod[$mod][$state]++;
                if ($status === 'review') {
                    $counts['quarantined']++;
                    $byMod[$mod]['quarantined']++;
                }

                // Counting is whole-corpus; the queue itself is one modality.
                if ($modality !== null && $mod !== $modality) {
                    continue;
                }

                // A verified string is not a question. Keep it out of the queue.
                if ($state === 'verified') {
                    continue;
                }

                $mine = null;
                foreach ($live as $v) {
                    if ($v['verified_by'] === $me) {
                        $mine = $v['verdict'];
                    }
                }

                $items[] = [
                    'namespace'   => $ns,
                    'modality'    => $mod,
                    'key'         => $key,
                    'english'     => $english,
                    'machine'     => $machine,
                    'source_hash' => $hash,
                    'state'       => $state,
                    'verifiers'   => $agreeing,
                    'needed'      => TranslationVerification::QUORUM,
                    // Why the machine pass refused to ship it, when it did.
                    'quarantine_reason' => $status === 'review'
                        ? ($meta[$ns][$key]['reason'] ?? 'flagged')
                        : null,
                    'provider'   => $meta[$ns][$key]['provider'] ?? null,
                    'my_verdict' => $mine,
                ];
            }
        }

        usort($items, function (array $a, array $b): int {
            $ra = $this->rank($a);
            $rb = $this->rank($b);

            return $ra <=> $rb ?: strcmp($a['key'], $b['key']);
        });

        return [
            'items'       => $limit > 0 ? $this->window($items, $limit) : [],
            'counts'      => $counts,
            'by_modality' => $byMod,
        ];
    }

    /**
     * One language's row of the matrix: a cell per kind of content.
     *
     * @return array{code: string, cells: array<string, array<string,mixed>>,
     *               overall: int, verifiers: int}
     */
    public function row(string $locale, ?array $queue = null): array
    {
        $queue ??= $this->queue($locale, null, 0);
        $byMod  = $queue['by_modality'];
        $people = $this->verifierCount($locale);

        $cells = [];
        $sum   = 0;

        foreach (self::MODALITIES as $m) {
            if (! $m['measurable']) {
                $cells[$m['id']] = [
                    'state' => 'none', 'pct' => 0, 'verifiers' => 0,
                    'carried' => 0, 'verified' => 0, 'total' => 0,
                    'why' => $m['why'],
                ];

                continue;
            }

            $c = $byMod[$m['id']] ?? [];
            $total = array_sum(array_intersect_key($c, array_flip(['none', 'ai_draft', 'in_review', 'verified'])));
            $carried = $total - ($c['none'] ?? 0);
            $verified = $c['verified'] ?? 0;
            $reviewing = $c['in_review'] ?? 0;

            $pct = $total > 0 ? (int) round(($carried / $total) * 100) : 0;
            $sum += $pct;

            $cells[$m['id']] = [
                // `published` is never reported — see the class docblock.
                'state' => match (true) {
                    $carried === 0                 => 'none',
                    $verified > 0 && $verified >= $total => 'verified',
                    $verified > 0 || $reviewing > 0 => 'in_review',
                    default                        => 'ai_draft',
                },
                'pct'       => $pct,
                'verifiers' => $people,
                'carried'   => $carried,
                'verified'  => $verified,
                'total'     => $total,
                'why'       => null,
            ];
        }

        return [
            'code'      => $locale,
            'cells'     => $cells,
            // The mean of all six, so the four unmeasured kinds are visible in
            // the headline instead of being quietly excluded from it.
            'overall'   => (int) round($sum / count(self::MODALITIES)),
            'verifiers' => $people,
        ];
    }

    /**
     * The full matrix: every language against the six kinds.
     *
     * @param  list<string>  $locales
     */
    public function matrix(array $locales): array
    {
        $rows = [];
        foreach ($locales as $locale) {
            $q = $this->queue($locale, null, 0);
            $row = $this->row($locale, $q);
            $row['quarantined'] = $q['counts']['quarantined'] ?? 0;

            // Once per locale — terms() reads the glossary AND queries the
            // verdicts, so calling it twice to get two counts costs double.
            $terms = $this->terms($locale);
            $row['terms']       = count(array_filter($terms, fn (array $t): bool => $t['settled']));
            $row['terms_total'] = count($terms);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The constitutional term base for a locale.
     *
     * Its own list, apart from the string queue, because these constrain
     * everything else: settle "quorum" once and every string carrying it is
     * consistent; leave it unsettled and every string guesses separately.
     *
     * SETTLED MEANS A PERSON SETTLED IT. A rendering a machine proposed is a
     * draft like any other — calling it settled because the field is non-empty
     * would be the same lie as calling a translated string "done", in the one
     * place where it would then propagate into every string that uses the term.
     */
    public function terms(string $locale): array
    {
        $glossary = $this->json(base_path(self::GLOSSARY)) ?? [];
        $verdicts = $this->verdictsFor($locale);
        $me       = (string) (auth()->id() ?? '');
        $out      = [];

        foreach ($glossary as $term => $entry) {
            if (str_starts_with($term, '_') || ! is_array($entry)) {
                continue;
            }

            $rendering = $entry['translations'][$locale] ?? null;
            $rendering = $rendering === '' ? null : $rendering;

            /*
             * A term's whole content IS its rendering, so the verdict is bound
             * to the rendering as well as the term. Re-word the term and every
             * approval of the old wording falls away — the same rule strings
             * follow for their English source.
             */
            $hash = hash('sha256', $term . "\0" . (string) $rendering);

            $live = array_filter(
                $verdicts[self::GLOSSARY_NAMESPACE . ':' . $term] ?? [],
                fn (array $v): bool => $v['source_hash'] === $hash,
            );
            $agreeing = count(array_filter(
                $live,
                fn (array $v): bool => $v['verdict'] !== TranslationVerification::VERDICT_REJECTED,
            ));

            $mine = null;
            foreach ($live as $v) {
                if ($v['verified_by'] === $me) {
                    $mine = $v['verdict'];
                }
            }

            $out[] = [
                'term'        => $term,
                'namespace'   => self::GLOSSARY_NAMESPACE,
                'rendering'   => $rendering,
                'context'     => $entry['context'] ?? null,
                'pos'         => $entry['pos'] ?? null,
                'spoken'      => $entry['spoken'] ?? null,
                'source_hash' => $hash,
                'state'       => $this->state($rendering, $agreeing),
                'verifiers'   => $agreeing,
                'needed'      => TranslationVerification::QUORUM,
                'my_verdict'  => $mine,
                'settled'     => $agreeing >= TranslationVerification::QUORUM,
            ];
        }

        return $out;
    }

    /**
     * Who has actually done this work, by count of strings settled.
     *
     * Real rows, not a leaderboard fixture: an empty list means nobody has
     * verified this language yet, which is the honest answer today.
     */
    public function contributors(string $locale, int $limit = 12): array
    {
        $rows = TranslationVerification::query()
            ->where('locale', $locale)
            ->selectRaw('verified_by, count(*) as n, max(verified_at) as last_at')
            ->groupBy('verified_by')
            ->orderByDesc('n')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $users = User::withTrashed()
            ->whereIn('id', $rows->pluck('verified_by'))
            ->get(['id', 'name', 'display_name'])
            ->keyBy('id');

        return $rows->map(function ($r) use ($users): array {
            $u = $users->get($r->verified_by);

            return [
                'handle'   => $u?->display_name ?: ($u?->name ?: 'a verifier'),
                'verified' => (int) $r->n,
                'last_at'  => $r->last_at,
            ];
        })->all();
    }

    /** Record one verdict. A person may change their own; that is an update. */
    public function record(array $data, string $userId): TranslationVerification
    {
        return DB::transaction(fn (): TranslationVerification => TranslationVerification::updateOrCreate(
            [
                'locale'      => $data['locale'],
                'namespace'   => $data['namespace'],
                'message_key' => $data['key'],
                'verified_by' => $userId,
            ],
            [
                'id'            => (string) Str::uuid(),
                'source_hash'   => $data['source_hash'],
                'verdict'       => $data['verdict'],
                'machine_text'  => $data['machine'] ?? null,
                'verified_text' => $data['text'] ?? null,
                'note'          => $data['note'] ?? null,
                'verified_at'   => now(),
            ],
        ));
    }

    /* ── internals ─────────────────────────────────────────────────────────── */

    /**
     * Where a string sits in the work order. Lower comes first.
     *
     * Closest-to-settled first: a string one reader short of quorum needs one
     * person, an untouched draft needs three, so finishing what is started
     * settles more strings per reader than starting new ones. Before both come
     * the strings with no draft at all and the ones the machine itself refused
     * to ship — there is nothing there for a reader to correct.
     *
     * Deliberately BLIND to whether you personally ruled on a string. Ranking
     * your own verdicts away would move a row the instant you clicked it, and
     * a row that vanishes on click reads as an error rather than as work done.
     * Your rows keep their place; `window()` is what stops them crowding out
     * the work you can still do.
     */
    private function rank(array $item): int
    {
        if ($item['state'] === 'none') {
            return 0;
        }
        if ($item['quarantine_reason'] !== null) {
            return 1;
        }

        return $item['state'] === 'in_review' ? 2 : 3;
    }

    /**
     * The visible slice: `$limit` strings THIS reader can still act on, plus
     * any of their own verdicts that fall among them, in place.
     *
     * Counting only actionable rows toward the limit is the whole trick. A
     * plain `array_slice` would fill the window with strings you have already
     * ruled on — you cannot vote twice — and the page would slowly stop
     * offering you work while appearing full.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function window(array $items, int $limit): array
    {
        $out = [];
        $actionable = 0;

        foreach ($items as $item) {
            if ($item['my_verdict'] === null) {
                if ($actionable >= $limit) {
                    break;
                }
                $actionable++;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function modalityOf(string $ns): string
    {
        return str_starts_with($ns, 'c_') || in_array($ns, self::UI_NAMESPACES, true)
            ? 'ui'
            : 'pages';
    }

    private function state(?string $machine, int $agreeing): string
    {
        if ($machine === null || $machine === '') {
            return 'none';
        }
        if ($agreeing >= TranslationVerification::QUORUM) {
            return 'verified';
        }

        return $agreeing > 0 ? 'in_review' : 'ai_draft';
    }

    private function verifierCount(string $locale): int
    {
        return TranslationVerification::query()
            ->where('locale', $locale)
            ->distinct()
            ->count('verified_by');
    }

    /** @return array<string, list<array{verdict:string, source_hash:string, verified_by:string}>> */
    private function verdictsFor(string $locale): array
    {
        $out = [];
        TranslationVerification::query()
            ->where('locale', $locale)
            ->select(['namespace', 'message_key', 'verdict', 'source_hash', 'verified_by'])
            ->chunk(2000, function ($rows) use (&$out): void {
                foreach ($rows as $r) {
                    $out["{$r->namespace}:{$r->message_key}"][] = [
                        'verdict'     => $r->verdict,
                        'source_hash' => $r->source_hash,
                        'verified_by' => (string) $r->verified_by,
                    ];
                }
            });

        return $out;
    }

    /** @return array<string, array<string, string>> namespace => key => message */
    private function catalog(string $locale): array
    {
        return $this->readTree(base_path(self::LOCALES_DIR . "/{$locale}"));
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function meta(string $locale): array
    {
        return $this->readTree(base_path(self::META_DIR . "/{$locale}"));
    }

    /**
     * Read a locale's JSON tree once per request.
     *
     * The matrix walks every locale and each walk needs the English source
     * again; without the memo that is 33 files re-parsed per language for no
     * new information.
     *
     * @var array<string, array>
     */
    private array $trees = [];

    private function readTree(string $dir): array
    {
        if (array_key_exists($dir, $this->trees)) {
            return $this->trees[$dir];
        }
        if (! is_dir($dir)) {
            return $this->trees[$dir] = [];
        }
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $out[basename($file, '.json')] = $this->json($file) ?? [];
        }

        return $this->trees[$dir] = $out;
    }

    /** @var array<string, array|null> */
    private array $files = [];

    private function json(string $path): ?array
    {
        if (array_key_exists($path, $this->files)) {
            return $this->files[$path];
        }
        if (! is_file($path)) {
            return $this->files[$path] = null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->files[$path] = (is_array($decoded) ? $decoded : null);
    }
}
