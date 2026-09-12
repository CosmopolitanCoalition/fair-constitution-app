<?php

namespace App\Support;

use App\Models\Bill;

/** Shared read context for a bill's record and public discussion. */
final class BillWorkspace
{
    public static function for(Bill $bill): array
    {
        // Both callers already load these relationships for the selected bill.
        $legislature = $bill->legislature;
        $place = $legislature?->jurisdiction;
        $sponsor = $bill->sponsor?->user;

        return [
            'id' => (string) $bill->id,
            'title' => $bill->title,
            'status' => $bill->status,
            'sponsor' => $sponsor?->display_name ?: null,
            'introducedAt' => $bill->introduced_at?->toIso8601String(),
            'actType' => $bill->act_type,
            'recordHref' => "/bills/{$bill->id}",
            'discussionHref' => "/bills/{$bill->id}/conversation",
            'billsHref' => $legislature ? "/legislatures/{$legislature->id}/bills" : null,
            'chamberHref' => $legislature ? "/legislatures/{$legislature->id}/chamber" : null,
            'place' => $place ? [
                'id' => (string) $place->id,
                'name' => $place->name,
                'href' => '/jurisdictions/'.rawurlencode($place->slug),
            ] : null,
        ];
    }
}
