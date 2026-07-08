<?php

namespace App\Console\Commands;

use App\Services\Geodata\GeodataRemediationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Geodata repair manifest — APPLY (replay).
 *
 * Replays a manifest produced by geodata:repairs-export against this box's
 * jurisdictions data. Idempotent: a rule whose target state already matches
 * (matched by target_slug + action + params) is SKIPPED, so re-running the
 * same manifest — or running it on a box where the operator already made
 * some of the same repairs by hand — is safe.
 *
 * Runs through GeodataRemediationService, so the repair window (setup
 * incomplete + map not accepted) applies here exactly as it does in the UI,
 * and every replayed rule lands in this box's own geodata_repairs ledger.
 *
 * Usage:
 *   php artisan geodata:repairs-apply /archive/repairs.json
 *   php artisan geodata:repairs-apply /archive/repairs.json --dry-run
 */
class GeodataRepairsApplyCommand extends Command
{
    protected $signature = 'geodata:repairs-apply
                            {file : Manifest JSON produced by geodata:repairs-export}
                            {--dry-run : Classify each rule (apply/skip) without writing}';

    protected $description = 'Replay a geodata repair manifest (idempotent — matching state is skipped)';

    public function handle(GeodataRemediationService $remediation): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Manifest not found: {$path}");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest) || ! isset($manifest['repairs']) || ! is_array($manifest['repairs'])) {
            $this->error('Not a geodata repair manifest (missing "repairs" array).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            'Replaying %d rule(s) from %s%s',
            count($manifest['repairs']),
            $path,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        $applied = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($manifest['repairs'] as $i => $rule) {
            $action = (string) ($rule['action'] ?? '');
            $slug   = (string) ($rule['target_slug'] ?? '');
            $params = is_array($rule['params'] ?? null) ? $rule['params'] : [];
            $result = is_array($rule['result'] ?? null) ? $rule['result'] : [];
            $label  = sprintf('#%d %s %s', $i + 1, $action ?: '(no action)', $slug);

            try {
                if ($this->alreadyApplied($action, $slug, $params, $result)) {
                    $skipped++;
                    $this->line("  SKIP    {$label} — target state already matches");
                    continue;
                }

                if ($dryRun) {
                    $applied++;
                    $this->line("  WOULD   {$label}");
                    continue;
                }

                $note = trim(($params['note'] ?? '') . ' [replayed via geodata:repairs-apply]');
                match ($action) {
                    'reparent'             => $remediation->reparent(null, $slug, (string) $params['new_parent_slug'], $note, null),
                    'synthesize_anchor'    => $remediation->synthesizeAnchor(null, (string) $params['parent_slug'], (string) $params['name'], (array) $params['child_slugs'], $note, null),
                    'merge_chain'          => $remediation->mergeChain(null, (array) $params['chain_slugs'], $note, null),
                    'prune'                => $remediation->softPrune(null, $slug, (bool) ($params['cascade'] ?? false), $note, null),
                    'recompute_population' => $remediation->recomputePopulation(null, $slug, (string) $params['method'], $note, null),
                    default                => throw new \InvalidArgumentException("Unknown action [{$action}]."),
                };

                $applied++;
                $this->line("  APPLIED {$label}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  FAILED  {$label} — {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d applied, %d skipped, %d failed.',
            $dryRun ? 'Dry run' : 'Done',
            $applied,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Idempotency probe — does this box's current state already reflect the
     * rule? Matched by target_slug + action + params (never by uuid: ids
     * differ across boxes; slugs are the shared vocabulary).
     */
    private function alreadyApplied(string $action, string $slug, array $params, array $result): bool
    {
        switch ($action) {
            case 'reparent':
                // Target already sits under the recorded new parent.
                $row = DB::selectOne('
                    SELECT p.slug AS parent_slug
                    FROM jurisdictions j
                    LEFT JOIN jurisdictions p ON p.id = j.parent_id
                    WHERE j.slug = ? AND j.deleted_at IS NULL
                ', [$slug]);

                return $row !== null && $row->parent_slug === ($params['new_parent_slug'] ?? null);

            case 'synthesize_anchor':
                // STATE-based, not slug-based: anchor slugs are GENERATED per
                // box (uniqueSlug suffixes on collision), so the recorded slug
                // can diverge on replay — probing it would re-apply and mint a
                // duplicate anchor every run. Applied = the recorded children
                // already share ONE live synthetic_repair parent that itself
                // sits under the recorded parent_slug.
                $childSlugs = array_values((array) ($params['child_slugs'] ?? []));
                if ($childSlugs === []) {
                    return false;
                }
                $arr = '{' . implode(',', array_map(
                    fn ($s) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $s) . '"',
                    $childSlugs
                )) . '}';
                $probe = DB::selectOne('
                    SELECT COUNT(*)::int                    AS live_children,
                           COUNT(DISTINCT c.parent_id)::int AS distinct_parents,
                           MIN(a.source)                    AS anchor_source,
                           MIN(gp.slug)                     AS grandparent_slug
                    FROM jurisdictions c
                    LEFT JOIN jurisdictions a  ON a.id = c.parent_id AND a.deleted_at IS NULL
                    LEFT JOIN jurisdictions gp ON gp.id = a.parent_id
                    WHERE c.slug = ANY(CAST(? AS text[])) AND c.deleted_at IS NULL
                ', [$arr]);

                return $probe !== null
                    && (int) $probe->live_children === count($childSlugs)
                    && (int) $probe->distinct_parents === 1
                    && $probe->anchor_source === 'synthetic_repair'
                    && $probe->grandparent_slug === ($params['parent_slug'] ?? null);

            case 'merge_chain':
                // Every lower chain member is already gone (merged away).
                $lower = array_slice((array) ($params['chain_slugs'] ?? []), 1);
                if ($lower === []) {
                    return false;
                }
                $live = DB::table('jurisdictions')
                    ->whereIn('slug', $lower)
                    ->whereNull('deleted_at')
                    ->count();

                return $live === 0;

            case 'prune':
                // Target is already soft-deleted (or never existed here).
                return ! DB::table('jurisdictions')
                    ->where('slug', $slug)
                    ->whereNull('deleted_at')
                    ->exists();

            case 'recompute_population':
                // Population already carries the manual_repair stamp AND the
                // recorded value — a differing value means this box's data
                // diverged, so the rule re-applies (recomputing from local data).
                if (! array_key_exists('new_population', $result)) {
                    return false;
                }
                $row = DB::table('jurisdictions')
                    ->where('slug', $slug)
                    ->whereNull('deleted_at')
                    ->first(['population', 'population_assigned_via']);

                return $row !== null
                    && $row->population_assigned_via === 'manual_repair'
                    && (int) $row->population === (int) $result['new_population'];

            default:
                return false;
        }
    }
}
