<?php

namespace App\Services\Social;

use App\Models\Bill;
use App\Models\Candidacy;
use App\Models\CommitteeMeeting;
use App\Models\Petition;
use App\Models\ReferendumQuestion;
use App\Models\SocialProfile;
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
     * The currently-live governance objects for a jurisdiction's halls — one live subforum per
     * non-terminal object, with the same (type,id,title) shape across all five object kinds. Bills +
     * petitions carry a direct jurisdiction_id; committee meetings + candidacies do not, so they JOIN
     * up to the jurisdiction (committee→legislature; candidacy→election). All queries are Eloquent so
     * the SoftDeletes scope auto-excludes deleted rows. A candidacy's title is the candidate's
     * PSEUDONYM (never the legal name — Art. I).
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

        // Referendum questions — a direct jurisdiction_id; live = not yet decided.
        ReferendumQuestion::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNotIn('status', [
                ReferendumQuestion::STATUS_VOTED, ReferendumQuestion::STATUS_PASSED,
                ReferendumQuestion::STATUS_FAILED, ReferendumQuestion::STATUS_INVALIDATED,
            ])
            ->get(['id', 'question'])
            ->each(function (ReferendumQuestion $rq) use (&$objects) {
                $objects[] = [
                    'type'  => SocialSubforum::OBJECT_REFERENDUM_QUESTION,
                    'id'    => (string) $rq->id,
                    'title' => 'Referendum — '.$rq->question,
                ];
            });

        // Committee meetings — no direct jurisdiction_id; join committee→legislature. Live = scheduled/open.
        CommitteeMeeting::query()
            ->whereIn('status', [CommitteeMeeting::STATUS_SCHEDULED, CommitteeMeeting::STATUS_OPEN])
            ->whereHas('committee.legislature', fn ($q) => $q->where('jurisdiction_id', $jurisdictionId))
            ->with('committee:id,name')
            ->get(['id', 'committee_id', 'scheduled_for'])
            ->each(function (CommitteeMeeting $meeting) use (&$objects) {
                $when = $meeting->scheduled_for !== null ? ' ('.$meeting->scheduled_for->toDateString().')' : '';
                $objects[] = [
                    'type'  => SocialSubforum::OBJECT_COMMITTEE_MEETING,
                    'id'    => (string) $meeting->id,
                    'title' => 'Committee — '.((string) ($meeting->committee->name ?? 'Meeting')).$when,
                ];
            });

        // Candidacies — no direct jurisdiction_id; join election→jurisdiction. Live = a standing race.
        // The title is the candidate's PSEUDONYM, never their legal name (Art. I).
        Candidacy::query()
            ->whereNotIn('status', [
                Candidacy::STATUS_WITHDRAWN, Candidacy::STATUS_ELECTED, Candidacy::STATUS_DEFEATED,
                Candidacy::STATUS_REJECTED, Candidacy::STATUS_NON_FINALIST,
            ])
            ->whereHas('election', fn ($q) => $q->where('jurisdiction_id', $jurisdictionId))
            ->get(['id', 'user_id'])
            ->each(function (Candidacy $candidacy) use (&$objects) {
                $objects[] = [
                    'type'  => SocialSubforum::OBJECT_CANDIDACY,
                    'id'    => (string) $candidacy->id,
                    'title' => 'Candidacy — '.$this->pseudonymFor((string) $candidacy->user_id),
                ];
            });

        return $objects;
    }

    /** The candidate's pseudonymous handle, NEVER their legal name (the K-1 displayFor rail). */
    private function pseudonymFor(string $userId): string
    {
        $profile = SocialProfile::query()->where('user_id', $userId)->first();

        if (! empty($profile?->display_name)) {
            return (string) $profile->display_name;
        }
        if (! empty($profile?->handle)) {
            return '@'.$profile->handle;
        }

        return 'Resident-'.substr(hash('sha256', $userId), 0, 8);
    }
}
