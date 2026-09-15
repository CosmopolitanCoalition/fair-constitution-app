<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;

/**
 * a11y:roster-ids — one real sample value per distinct route parameter name for
 * the accessibility sweep of parameterised pages.
 *
 * The parameter names come from the router (GET page routes only, machine
 * endpoints excluded by the same rule the roster uses), never a hand list. For
 * each name the command reads ONE sample value from the live database and picks
 * the column the route actually binds (a slug where the route binds slugs, an id
 * otherwise, public_id where the model looks up by public_id, a config key for
 * config-backed pages, a constant for step/locale/sub). It only SELECTs; it
 * writes nothing.
 *
 * Output (per param): {param, value|null, source: 'table.column'|'constant'|'none'}.
 * Verify a value with a GET to the substituted route from the host:
 *   tests/browser/roster/resolve-ids.mjs   (curl, 200 or 302-to-login = resolved).
 */
final class A11yRosterIdsCommand extends Command
{
    protected $signature = 'a11y:roster-ids {--json : Emit a JSON array of {param,value,source}}';

    protected $description = 'Print one live-DB sample value per distinct route parameter name (read-only).';

    /**
     * Resolver per parameter name.
     *   db     : ['db', table, column, whereLive?]  — newest sensible row's column
     *   const  : ['const', value]
     *   config : ['config', dotpath]                — array_key_first of the config array
     *   none   : ['none']                           — no recoverable value from the DB
     */
    private function resolvers(): array
    {
        return [
            'jurisdiction' => ['db', 'jurisdictions', 'slug'],
            'legislature' => ['db', 'legislatures', 'id'],
            'legislature_id' => ['db', 'legislatures', 'id'],
            'election' => ['db', 'elections', 'id'],
            'bill' => ['db', 'bills', 'id'],
            'candidacy' => ['db', 'candidacies', 'id'],
            'case' => ['db', 'cases', 'id'], // CourtCase maps the `cases` table (Case is reserved)
            'petition' => ['db', 'petitions', 'id'],
            'space' => ['db', 'social_spaces', 'id'],
            'committee' => ['db', 'committees', 'id'],
            'challenge' => ['db', 'constitutional_challenges', 'id'],
            'department' => ['db', 'departments', 'id'],
            'contract' => ['db', 'org_contracts', 'id'],
            'assistance' => ['db', 'assistance_requests', 'id'],
            'listing' => ['db', 'marketplace_listings', 'id'],
            'posting' => ['db', 'work_postings', 'id'],
            'executive' => ['db', 'executives', 'id'],
            'judiciary' => ['db', 'judiciaries', 'id'],
            'summons' => ['db', 'jury_members', 'id'],
            'module' => ['db', 'education_modules', 'key', 'live'],
            'track' => ['db', 'education_tracks', 'key', 'live'],
            'organization' => ['db', 'organizations', 'id'],
            // A second organization sample for /organizations/{organization}/cgc:
            // a CGC organization when one exists (so the CGC profile page itself
            // is swept), else the oldest organization. Not a route parameter —
            // resolve-ids.mjs substitutes it into the {organization} slot of the
            // /cgc route only.
            'organization_cgc' => ['cgc'],
            'board' => ['db', 'boards', 'id'],
            'meeting' => ['db', 'committee_meetings', 'id'],
            'ref' => ['db', 'support_reports', 'public_id'],
            'vacancy' => ['db', 'vacancies', 'id'],
            'locale' => ['const', 'es'],
            'token' => ['none'], // invite token is handle.secret; the plaintext is not stored
            'id' => ['config', 'cga.journeys'], // /journeys/{id} reads config('cga.journeys.{id}')
            'n' => ['const', '0'], // /setup/step/{n} — step 0
            'sub' => ['const', 'departments'], // /executive|judiciary|legislature/{sub?} — first real target
        ];
    }

    /** Machine (non-page) URI patterns — the same rule the roster uses. */
    private function isMachine(string $u): bool
    {
        return (bool) (
            preg_match('#^/api/#', $u) ||
            preg_match('#^/_matrix/#', $u) ||
            preg_match('#^/horizon(/|$)#', $u) ||
            preg_match('#^/oauth/#', $u) ||
            preg_match('#^/storage/#', $u) ||
            preg_match('#^/\.well-known/#', $u) ||
            $u === '/up' ||
            $u === '/federation/cluster/sync-progress' ||
            preg_match('#\.(csv|geojson|png|json)$#', $u)
        );
    }

    /** Distinct parameter names across GET page routes, in first-seen order. */
    private function pageParamNames(): array
    {
        $names = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $u = '/' . ltrim($route->uri(), '/');
            if ($this->isMachine($u)) {
                continue;
            }
            foreach ($route->parameterNames() as $p) {
                if (! in_array($p, $names, true)) {
                    $names[] = $p;
                }
            }
        }
        sort($names);

        return $names;
    }

    private function sampleFor(array $spec): array
    {
        $type = $spec[0];

        if ($type === 'const') {
            return ['value' => (string) $spec[1], 'source' => 'constant'];
        }

        if ($type === 'none') {
            return ['value' => null, 'source' => 'none'];
        }

        if ($type === 'cgc') {
            // Prefer a Common Good Corporation so the CGC profile page is the
            // one swept. Fall back to the oldest organization otherwise.
            if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'is_cgc')) {
                $q = DB::table('organizations')->whereNotNull('id')->where('is_cgc', true);
                if (Schema::hasColumn('organizations', 'deleted_at')) {
                    $q->whereNull('deleted_at');
                }
                if (Schema::hasColumn('organizations', 'created_at')) {
                    $q->orderBy('created_at');
                }
                $value = $q->value('id');
                if ($value !== null) {
                    return ['value' => (string) $value, 'source' => 'organizations.id (is_cgc)'];
                }
            }

            return $this->sampleFor(['db', 'organizations', 'id']);
        }

        if ($type === 'config') {
            $arr = config($spec[1]);
            $key = is_array($arr) ? array_key_first($arr) : null;

            return ['value' => $key === null ? null : (string) $key, 'source' => $key === null ? 'none' : 'constant'];
        }

        // db
        [, $table, $column] = $spec;
        $live = $spec[3] ?? null;
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return ['value' => null, 'source' => 'none'];
        }
        $q = DB::table($table)->whereNotNull($column);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($live !== null && Schema::hasColumn($table, 'status')) {
            $q->where('status', $live);
        }
        if (Schema::hasColumn($table, 'created_at')) {
            $q->orderBy('created_at');
        }
        $value = $q->value($column);

        return [
            'value' => $value === null ? null : (string) $value,
            'source' => $value === null ? 'none' : "{$table}.{$column}",
        ];
    }

    public function handle(): int
    {
        $resolvers = $this->resolvers();
        $rows = [];
        foreach ($this->pageParamNames() as $param) {
            if (! isset($resolvers[$param])) {
                $rows[] = ['param' => $param, 'value' => null, 'source' => 'none'];

                continue;
            }
            $s = $this->sampleFor($resolvers[$param]);
            $rows[] = ['param' => $param, 'value' => $s['value'], 'source' => $s['source']];
        }

        // Extra (non-route) sample: the CGC organization for the /cgc route.
        // resolve-ids.mjs reads it for /organizations/{organization}/cgc only.
        if (isset($resolvers['organization'])) {
            $s = $this->sampleFor($resolvers['organization_cgc']);
            $rows[] = ['param' => 'organization_cgc', 'value' => $s['value'], 'source' => $s['source']];
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->line(sprintf('%-16s %-40s %s', $r['param'], $r['value'] ?? '(null)', $r['source']));
        }

        return self::SUCCESS;
    }
}
