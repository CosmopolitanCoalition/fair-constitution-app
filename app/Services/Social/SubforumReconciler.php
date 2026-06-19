<?php

namespace App\Services\Social;

use App\Models\Bill;
use App\Models\Petition;
use App\Models\SocialSpace;
use App\Models\SocialSubforum;

/**
 * Phase K-1 — binds a halls space's object subforums to the currently-live governance objects,
 * idempotently. The partial-unique on (governing_object_type, governing_object_id) WHERE
 * deleted_at IS NULL is the idempotency key: re-running over the same object set is a no-op.
 * An object that is no longer live has its subforum ARCHIVED (never deleted — history stays
 * browsable); a re-opened object's subforum is re-opened. Pure plumbing — not a civic act, so
 * it does not route through the engine.
 */
class SubforumReconciler
{
    /**
     * @param  array<int,array{type:string,id:string,title:string}>  $liveObjects
     * @return array{created:int,reopened:int,archived:int,live:int}
     */
    public function reconcile(SocialSpace $hallsSpace, array $liveObjects): array
    {
        $created = 0;
        $reopened = 0;
        $archived = 0;
        $liveKeys = [];

        foreach ($liveObjects as $obj) {
            $liveKeys[$obj['type'].'|'.$obj['id']] = true;

            $sub = SocialSubforum::query()->firstOrNew([
                'governing_object_type' => $obj['type'],
                'governing_object_id'   => $obj['id'],
            ]);

            if (! $sub->exists) {
                $sub->forceFill([
                    'space_id' => (string) $hallsSpace->id,
                    'title'    => $obj['title'],
                    'status'   => SocialSubforum::STATUS_OPEN,
                ])->save();
                $created++;
            } elseif ($sub->status === SocialSubforum::STATUS_ARCHIVED) {
                $sub->forceFill(['status' => SocialSubforum::STATUS_OPEN])->save();
                $reopened++;
            }
        }

        // Archive object-bound subforums in this space whose object is no longer live.
        SocialSubforum::query()
            ->where('space_id', (string) $hallsSpace->id)
            ->whereNotNull('governing_object_type')
            ->where('status', SocialSubforum::STATUS_OPEN)
            ->get()
            ->each(function (SocialSubforum $sub) use ($liveKeys, &$archived) {
                if (! isset($liveKeys[$sub->governing_object_type.'|'.$sub->governing_object_id])) {
                    $sub->forceFill(['status' => SocialSubforum::STATUS_ARCHIVED])->save();
                    $archived++;
                }
            });

        return ['created' => $created, 'reopened' => $reopened, 'archived' => $archived, 'live' => count($liveKeys)];
    }

    /**
     * The currently-live governance objects for a jurisdiction's halls. K-1 binds bills +
     * petitions (the demo-relevant, halls-central objects); referendum questions, committee
     * meetings, and candidacies plug in here with the same (type,id,title,not-terminal) shape.
     *
     * @return array<int,array{type:string,id:string,title:string}>
     */
    public function gatherLiveObjects(string $jurisdictionId): array
    {
        $objects = [];

        Bill::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNotIn('status', [Bill::STATUS_ENACTED, Bill::STATUS_FAILED, Bill::STATUS_WITHDRAWN, Bill::STATUS_TABLED])
            ->get(['id', 'title'])
            ->each(function (Bill $bill) use (&$objects) {
                $objects[] = [
                    'type'  => SocialSubforum::OBJECT_BILL,
                    'id'    => (string) $bill->id,
                    'title' => 'Bill — '.$bill->title,
                ];
            });

        Petition::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNotIn('status', [
                Petition::STATUS_ADOPTED, Petition::STATUS_REJECTED, Petition::STATUS_INVALIDATED,
                Petition::STATUS_ON_BALLOT, Petition::STATUS_VALIDATED,
            ])
            ->get(['id', 'title'])
            ->each(function (Petition $petition) use (&$objects) {
                $objects[] = [
                    'type'  => SocialSubforum::OBJECT_PETITION,
                    'id'    => (string) $petition->id,
                    'title' => 'Petition — '.$petition->title,
                ];
            });

        return $objects;
    }
}
