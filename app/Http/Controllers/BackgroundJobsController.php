<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * B4 (operator ruling 2026-08-29): the background job monitor's feed — every
 * long-running workstream the box currently carries, as one cheap read.
 * Public read (government is public); each stream is label + done/total so
 * the floating widget can render honest bars with no per-stream knowledge.
 */
class BackgroundJobsController extends Controller
{
    public function active(): JsonResponse
    {
        $streams = [];

        $as = DB::table('autoscale_runs')
            ->whereNotIn('status', ['done', 'failed'])
            ->orderByDesc('created_at')->first();
        if ($as !== null) {
            if ($as->status === 'sizing' || $as->status === 'queued') {
                $streams[] = [
                    'key'   => 'autoscale-sizing',
                    'label' => 'Sizing every legislature',
                    'done'  => (int) $as->sized_parents,
                    'total' => null,
                    'state' => $as->status,
                ];
            } else {
                $streams[] = [
                    'key'   => 'autoscale-singles',
                    'label' => 'Leaf councils (at-large districts)',
                    'done'  => (int) $as->singles_done,
                    'total' => (int) $as->singles_total,
                    'state' => $as->status,
                ];
                $streams[] = [
                    'key'   => 'autoscale-sweeps',
                    'label' => 'District-map sweeps',
                    'done'  => (int) $as->sweeps_done,
                    'total' => (int) $as->sweeps_total,
                    'state' => $as->status,
                ];
            }
        }

        $gr = DB::table('geodata_runs')
            ->whereNotIn('status', ['done', 'failed', 'abandoned'])
            ->orderByDesc('created_at')->first();
        if ($gr !== null) {
            $streams[] = [
                'key'   => 'geodata',
                'label' => 'Geodata ingestion ('.$gr->phase.')',
                'done'  => (int) $gr->items_done,
                'total' => (int) $gr->items_total,
                'state' => $gr->status,
            ];
        }

        foreach (DB::select("
            SELECT m.id, m.name,
                   COUNT(*) FILTER (WHERE i.status IN ('done','review')) AS done,
                   COUNT(*) AS total
              FROM map_scope_items i
              JOIN legislature_district_maps m ON m.id = i.map_id
             GROUP BY m.id, m.name
            HAVING COUNT(*) FILTER (WHERE i.status IN ('pending','running')) > 0
        ") as $pile) {
            $streams[] = [
                'key'   => 'map-pile-'.$pile->id,
                'label' => 'Map sweep — '.$pile->name,
                'done'  => (int) $pile->done,
                'total' => (int) $pile->total,
                'state' => 'running',
            ];
        }

        foreach (DB::select("
            SELECT b.root_id, j.name,
                   COUNT(*) FILTER (WHERE b.status IN ('done','review')) AS done,
                   COUNT(*) AS total
              FROM subtree_boot_items b
              JOIN jurisdictions j ON j.id = b.root_id
             GROUP BY b.root_id, j.name
            HAVING COUNT(*) FILTER (WHERE b.status IN ('pending','running')) > 0
        ") as $boot) {
            $streams[] = [
                'key'   => 'boot-'.$boot->root_id,
                'label' => 'Activating — '.$boot->name.' + children',
                'done'  => (int) $boot->done,
                'total' => (int) $boot->total,
                'state' => 'running',
            ];
        }

        return response()->json(['streams' => $streams]);
    }
}
