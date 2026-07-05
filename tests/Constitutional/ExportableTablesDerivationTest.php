<?php

namespace Tests\Constitutional;

use App\Services\MapDataExportService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Workstream C pin — MapDataExportService::deriveExportableTables() replaced a
 * hand-maintained 20-entry curated list as the CHOOSER source (and the allowlist
 * that gates an operator's explicit tables[] selection). These pins guard the
 * three invariants the derived list must always hold so export/restore ordering
 * stays correct as tables are added to the schema:
 *
 *   1. SUPERSET — the derived set contains every curated TABLES entry, in the
 *      same relative order at the front (the curated chain is pinned as the
 *      topo seed). A plain default export is therefore unchanged.
 *   2. DENYLIST — framework / infra / privacy / credential / key-material tables
 *      never appear, and neither does anything matching the key-material name
 *      patterns.
 *   3. TOPO ORDER — every table lands after all tables it FK-references WITHIN
 *      the set, EXCEPT where a genuine FK cycle exists (the live schema has real
 *      non-deferrable cycles — bills↔laws, boards↔board_seats, elections↔vacancies,
 *      terms↔appointments, …; no linear order can satisfy those, which is exactly
 *      why the DEFAULT export/restore order stays the curated, cycle-free TABLES
 *      and the derived order drives only the chooser + opt-in full selection).
 *      This test asserts the ONLY forward-edge violations are two-way (cyclic)
 *      edges — a one-directional child-before-parent would be a real bug.
 *
 * Live-pg posture: deriveExportableTables introspects information_schema +
 * pg_constraint, so this runs against the real Postgres (sqlite has neither).
 */
class ExportableTablesDerivationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_export_derive';

    public function test_derived_set_is_a_superset_of_the_curated_tables_in_pinned_order(): void
    {
        $this->onLivePg(function () {
            $derived = app(MapDataExportService::class)->deriveExportableTables();

            // Superset: every curated table is present.
            $missing = array_values(array_diff(MapDataExportService::TABLES, $derived));
            $this->assertSame([], $missing, 'derived set must contain every curated TABLES entry');

            // And it is strictly larger — the whole point of the change is that it
            // reflects the full portable suite, not the hand-curated 20.
            $this->assertGreaterThan(
                count(MapDataExportService::TABLES),
                count($derived),
                'derived set should expose more than the curated 20 tables'
            );

            // Pinned prefix: the curated chain leads the derived list in its exact
            // proven order, so the FK-safe default order is preserved verbatim.
            $prefix = array_slice($derived, 0, count(MapDataExportService::TABLES));
            $this->assertSame(
                MapDataExportService::TABLES,
                $prefix,
                'the curated TABLES chain must be the leading prefix of the derived order'
            );
        });
    }

    public function test_denylisted_and_key_material_tables_are_excluded(): void
    {
        $this->onLivePg(function () {
            $svc     = app(MapDataExportService::class);
            $derived = $svc->deriveExportableTables();

            // Explicit denylist entries never appear.
            foreach (MapDataExportService::DERIVE_DENYLIST as $denied) {
                $this->assertNotContains(
                    $denied,
                    $derived,
                    "denylisted table [{$denied}] must not appear in the export set"
                );
            }

            // Neither does anything matching the key-material name patterns.
            foreach ($derived as $t) {
                $this->assertFalse(
                    $svc->isDeniedFromExport($t),
                    "derived set leaked a denied table [{$t}]"
                );
                foreach (MapDataExportService::DERIVE_DENY_PATTERNS as $pattern) {
                    $this->assertDoesNotMatchRegularExpression(
                        $pattern,
                        $t,
                        "derived set leaked a key-material table [{$t}] matching {$pattern}"
                    );
                }
            }

            // Concretely: the credential/identity tables the operator flagged.
            foreach (['users', 'location_pings', 'operator_accounts', 'operator_devices', 'mesh_operator_keys', 'oidc_signing_keys'] as $sensitive) {
                $this->assertNotContains($sensitive, $derived);
            }
        });
    }

    public function test_derived_order_is_topological_except_for_genuine_fk_cycles(): void
    {
        $this->onLivePg(function () {
            $derived = app(MapDataExportService::class)->deriveExportableTables();
            $pos     = array_flip($derived);
            $set     = array_flip($derived);

            // Child → parent FK edges within the exported set.
            $edges = DB::connection(self::LIVE_CONNECTION)->select(
                "SELECT DISTINCT c.conrelid::regclass::text AS child,
                        c.confrelid::regclass::text AS parent
                   FROM pg_constraint c
                   JOIN pg_namespace n ON n.oid = c.connamespace
                  WHERE c.contype = 'f'
                    AND n.nspname = 'public'
                    AND c.conrelid <> c.confrelid"
            );

            $bare = static function (string $regclass): string {
                $name = $regclass;
                if (($dot = strrpos($name, '.')) !== false) {
                    $name = substr($name, $dot + 1);
                }
                return trim($name, '"');
            };

            // In-set child → parent adjacency.
            $adj = [];   // adj[child] = [parent, …]
            foreach (array_keys($set) as $t) {
                $adj[$t] = [];
            }
            foreach ($edges as $e) {
                $child  = $bare($e->child);
                $parent = $bare($e->parent);
                if ($child === $parent) {
                    continue;
                }
                if (! isset($set[$child]) || ! isset($set[$parent])) {
                    continue;
                }
                $adj[$child][$parent] = true;
            }

            // Strongly-connected components (Tarjan): two tables share an SCC iff a
            // directed FK cycle passes through both. Any child-before-parent edge
            // inside one SCC is an UNAVOIDABLE cycle arc — no linear order can fix it.
            $sccId = $this->stronglyConnectedComponents($adj);

            // A violation (child emitted before its FK parent) is acceptable ONLY if:
            //   (a) child + parent are in the same SCC — a genuine FK cycle, or
            //   (b) the child is in the curated prefix — the pin deliberately front-
            //       loads the curated chain ahead of any non-curated parent.
            // Anything else means the topo sort is actually broken.
            $curatedPrefix = array_flip(MapDataExportService::TABLES);
            $badViolations = [];
            foreach ($adj as $child => $parents) {
                foreach (array_keys($parents) as $parent) {
                    if ($pos[$child] >= $pos[$parent]) {
                        continue;   // parent already precedes child — fine
                    }
                    $sameCycle  = $sccId[$child] === $sccId[$parent];
                    $pinForced  = isset($curatedPrefix[$child]);
                    if (! $sameCycle && ! $pinForced) {
                        $badViolations[] = "{$child} emitted before parent {$parent} (no cycle, not pin-forced)";
                    }
                }
            }

            $this->assertSame(
                [],
                $badViolations,
                "every child-before-parent must be a genuine FK cycle or a curated-pin front-load:\n".implode("\n", $badViolations)
            );

            // Sanity: within the acyclic curated prefix, every parent precedes its
            // child — the default export order is a valid topological order.
            $curatedPos = array_flip(MapDataExportService::TABLES);
            foreach ($adj as $child => $parents) {
                foreach (array_keys($parents) as $parent) {
                    if (isset($curatedPos[$child]) && isset($curatedPos[$parent])) {
                        $this->assertLessThan(
                            $curatedPos[$child],
                            $curatedPos[$parent],
                            "curated table {$child} must come after its curated parent {$parent}"
                        );
                    }
                }
            }
        });
    }

    /**
     * Tarjan's SCC — returns a map table => componentId. Two tables carry the same
     * componentId iff a directed cycle passes through both.
     *
     * @param  array<string, array<string, true>>  $adj  child → { parent: true }
     * @return array<string, int>
     */
    private function stronglyConnectedComponents(array $adj): array
    {
        $index    = [];
        $low      = [];
        $onStack  = [];
        $stack    = [];
        $comp     = [];
        $counter  = 0;
        $compId   = 0;

        // Iterative Tarjan (recursion could blow the stack on 168 nodes chained deep).
        $strongConnect = function (string $start) use (&$adj, &$index, &$low, &$onStack, &$stack, &$comp, &$counter, &$compId, &$strongConnect): void {
            $callStack = [[$start, 0]];
            while ($callStack !== []) {
                [$v, $pi] = $callStack[count($callStack) - 1];

                if ($pi === 0) {
                    $index[$v]   = $counter;
                    $low[$v]     = $counter;
                    $counter++;
                    $stack[]     = $v;
                    $onStack[$v] = true;
                }

                $neighbors = array_keys($adj[$v] ?? []);
                if ($pi < count($neighbors)) {
                    $callStack[count($callStack) - 1][1] = $pi + 1;
                    $w = $neighbors[$pi];
                    if (! isset($index[$w])) {
                        $callStack[] = [$w, 0];
                    } elseif (! empty($onStack[$w])) {
                        $low[$v] = min($low[$v], $index[$w]);
                    }
                    continue;
                }

                // Done with v's neighbors — propagate lowlink to the parent frame.
                array_pop($callStack);
                if ($callStack !== []) {
                    $p        = $callStack[count($callStack) - 1][0];
                    $low[$p]  = min($low[$p], $low[$v]);
                }

                if ($low[$v] === $index[$v]) {
                    do {
                        $w           = array_pop($stack);
                        $onStack[$w] = false;
                        $comp[$w]    = $compId;
                    } while ($w !== $v);
                    $compId++;
                }
            }
        };

        foreach (array_keys($adj) as $v) {
            if (! isset($index[$v])) {
                $strongConnect($v);
            }
        }
        return $comp;
    }

    /**
     * Run $body with the live pg connection set default and always rolled back.
     * (deriveExportableTables is read-only, but keep the guarded posture for
     * parity with the other live pins.)
     */
    private function onLivePg(callable $body): void
    {
        $conn     = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
