<?php

namespace App\Services\Social;

use App\Models\MatrixRoom;
use App\Models\SocialMembership;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\Matrix\MatrixRoomCreationService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * User-owned PRIVATE ROOMS (groups / DMs) — the "Art. I private half". A player owns a private
 * SocialSpace and invites friends; only members enter. This service owns the room lifecycle +
 * membership; it is OFF the civic plane — no testimony, no public record, no governance, gated by
 * MEMBERSHIP (never residency). The owner self-moderates (SocialMembership role=owner).
 */
class PrivateRoomService
{
    public function __construct(private MatrixRoomCreationService $rooms) {}

    /** Create a private room: a SocialSpace + the owner's membership + a provisioned Matrix room. */
    public function create(User $owner, string $name): SocialSpace
    {
        $title = trim($name) !== '' ? mb_substr(trim($name), 0, 200) : 'Private room';

        return DB::transaction(function () use ($owner, $title) {
            $space = SocialSpace::query()->create([
                'jurisdiction_id' => $this->anchorJurisdiction($owner),
                'space_type'      => SocialSpace::TYPE_GROUP,
                'title'           => $title,
                'status'          => SocialSpace::STATUS_OPEN,
                'is_private'      => true,
                'owner_user_id'   => (string) $owner->getKey(),
            ]);

            SocialMembership::query()->create([
                'space_id' => (string) $space->id,
                'user_id'  => (string) $owner->getKey(),
                'role'     => SocialMembership::ROLE_OWNER,
            ]);

            // Provision the live Matrix room (text + voice live here). Best-effort: if the homeserver is
            // down the room still exists on Plane A; comms light up when Matrix returns (roomFor re-checks).
            try {
                $this->rooms->createPrivateRoom($space->fresh(), $title);
            } catch (Throwable $e) {
                report($e);
            }

            return $space->fresh();
        });
    }

    /** Idempotently admit a user to a private room as a member — the invite-redeem hook. */
    public function admit(SocialSpace $space, User $user): SocialMembership
    {
        $existing = SocialMembership::query()
            ->where('space_id', (string) $space->id)
            ->where('user_id', (string) $user->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return SocialMembership::query()->create([
            'space_id' => (string) $space->id,
            'user_id'  => (string) $user->getKey(),
            'role'     => SocialMembership::ROLE_MEMBER,
        ]);
    }

    public function isMember(SocialSpace $space, User $user): bool
    {
        return SocialMembership::query()
            ->where('space_id', (string) $space->id)
            ->where('user_id', (string) $user->getKey())
            ->exists();
    }

    /** The live Matrix room backing a private space (null if not provisioned yet / tombstoned). */
    public function roomFor(SocialSpace $space): ?MatrixRoom
    {
        return MatrixRoom::query()
            ->where('entity_type', MatrixRoom::ENTITY_SOCIAL_SPACE)
            ->where('entity_id', (string) $space->id)
            ->whereNull('tombstoned_at')
            ->whereNotNull('matrix_room_id')
            ->first();
    }

    /** A member leaves. The owner stays (room deletion is a separate, future action). */
    public function leave(SocialSpace $space, User $user): void
    {
        if ((string) $space->owner_user_id === (string) $user->getKey()) {
            return;
        }

        SocialMembership::query()
            ->where('space_id', (string) $space->id)
            ->where('user_id', (string) $user->getKey())
            ->delete();
    }

    /**
     * social_spaces.jurisdiction_id is NOT NULL — a private room has no real jurisdiction, so anchor it
     * to the owner's deepest active residency, else the world root. The anchor is bookkeeping only; the
     * room's access is membership, never this jurisdiction.
     */
    private function anchorJurisdiction(User $owner): string
    {
        $own = DB::table('residency_confirmations as rc')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $owner->getKey())
            ->where('rc.is_active', true)
            ->whereNull('j.deleted_at')
            ->orderByDesc('j.adm_level')
            ->value('j.id');

        if ($own !== null) {
            return (string) $own;
        }

        $root = DB::table('jurisdictions')->whereNull('deleted_at')->orderBy('adm_level')->value('id');

        return (string) $root;
    }
}
