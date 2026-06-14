<?php

namespace App\Services\Federation;

use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Partition bundles for an authority flip (Phase F, WF-JUR-08 · "export bundle =
 * seed"). Produces a SIGNED MANIFEST describing a jurisdiction subtree — its
 * descendant ids + a checkpoint anchor + table counts — that the receiving
 * instance verifies before assuming authority.
 *
 * Authority transfer is manifest-based: peers in a federation share a common
 * seed (the setup export), so the receiver already holds the subtree rows and
 * only flips `authoritative_server_id`. Moving the full row DATA for a subtree
 * the peer does NOT have reuses MapDataExportService's pg_dump plumbing (a
 * follow-up; not needed for shared-seed peers).
 */
class PartitionExportService
{
    /**
     * Every jurisdiction id in the subtree rooted at $rootId (root included),
     * via a single recursive CTE over parent_id.
     *
     * @return array<int,string>
     */
    public function descendants(string $rootId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE sub AS (
                SELECT id FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT j.id FROM jurisdictions j
                    JOIN sub ON j.parent_id = sub.id
                WHERE j.deleted_at IS NULL
            )
            SELECT id FROM sub',
            [$rootId]
        );

        return array_map(fn ($r) => (string) $r->id, $rows);
    }

    /**
     * The signed-bundle manifest for a subtree.
     *
     * @param  array<int,string>  $descendantIds
     * @return array<string,mixed>
     */
    public function buildManifest(string $rootId, array $descendantIds, int $checkpointAuditSeq): array
    {
        return [
            'root_jurisdiction_id' => $rootId,
            'descendant_ids' => array_values($descendantIds),
            'descendant_count' => count($descendantIds),
            'checkpoint_audit_seq' => $checkpointAuditSeq,
            'schema_version' => (string) config('cga.schema_version', '1'),
            'table_counts' => $this->tableCounts($descendantIds),
            'exported_at' => now()->toIso8601String(),
        ];
    }

    /** sha256 of the canonical manifest — the bundle integrity anchor. */
    public function checksum(array $manifest): string
    {
        return hash('sha256', AuditService::canonicalJson($manifest));
    }

    /**
     * The string an exporter signs / an importer verifies:
     *   checksum | checkpoint_audit_seq | root_jurisdiction_id
     */
    public function signingPayload(array $manifest): string
    {
        return $this->checksum($manifest)
            .'|'.(int) ($manifest['checkpoint_audit_seq'] ?? 0)
            .'|'.(string) ($manifest['root_jurisdiction_id'] ?? '');
    }

    /**
     * Counts of the institution rows scoped to the subtree (informational —
     * lets the receiver sanity-check the bundle it is about to assume).
     *
     * @param  array<int,string>  $descendantIds
     * @return array<string,int>
     */
    private function tableCounts(array $descendantIds): array
    {
        if ($descendantIds === []) {
            return [];
        }

        $counts = [];
        foreach (['jurisdictions', 'legislatures', 'executives', 'judiciaries', 'organizations'] as $table) {
            $column = $table === 'jurisdictions' ? 'id' : 'jurisdiction_id';
            $counts[$table] = (int) DB::table($table)
                ->whereIn($column, $descendantIds)
                ->whereNull('deleted_at')
                ->count();
        }

        return $counts;
    }
}
