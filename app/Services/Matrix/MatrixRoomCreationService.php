<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixRoom;

/**
 * Phase K-3 (K3-E) — the structural constant that survives the legitimacy flip: every PUBLIC commons
 * room is created in room version 12 with the CGA appservice as the SOLE immutable creator and a power
 * map NO HUMAN can reach. Per MSC4289 the v12 creator's power is infinite, immutable, and unencodable
 * in m.room.power_levels — strictly stronger than "the appservice holds the only PL≥50". So no human —
 * physical operator or seated judge — ever holds a Matrix power level in a public room; the carve-outs
 * are emitted by the appservice (as the creator), gated off-Matrix by derived office roles (K3-I).
 *
 * The room version is queried LIVE and v12 is REQUIRED — the homeserver-side moderation analogue of
 * the G-VER constitutional-version join-gate. Public rooms are UNENCRYPTED (the appservice must read
 * content to apply M-2/M-4) + world_readable.
 */
class MatrixRoomCreationService
{
    private const REQUIRED_VERSION = '12';

    public function __construct(private MatrixClientService $client) {}

    /**
     * Idempotently create a public commons room for a CGA entity. Returns the existing live row if one
     * is already bound (the reconciler's job in K3-F builds on this); otherwise creates + records it.
     */
    public function createPublicCommonsRoom(
        string $entityType,
        string $entityId,
        ?string $spaceType,
        string $roomType,
        string $title,
        ?string $alias = null,
    ): MatrixRoom {
        $existing = MatrixRoom::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            // space_type is null for a Space — `where(…, null)` never matches NULL, so a re-run
            // would miss the existing row and hit the (NULLS NOT DISTINCT) unique. Use whereNull.
            ->when(
                $spaceType === null,
                fn ($q) => $q->whereNull('space_type'),
                fn ($q) => $q->where('space_type', $spaceType),
            )
            ->whereNull('tombstoned_at')
            ->whereNotNull('matrix_room_id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // NEVER hardcode the version — require v12 LIVE. Refuse to stand a public commons on a
        // homeserver that cannot offer the immutable-creator guarantee.
        $versions = $this->client->roomVersions();
        if (! in_array(self::REQUIRED_VERSION, $versions['available'], true)) {
            throw new ConstitutionalViolation(
                'A public commons room requires room version 12 (immutable sole creator); this homeserver does not offer it.',
                'Art. I'
            );
        }

        $created = $this->client->createRoom(
            $this->buildCommonsRoomBody($title, $alias, $roomType === MatrixRoom::ROOM_SPACE)
        );

        return MatrixRoom::query()->create([
            'matrix_room_id' => $created['room_id'],
            'matrix_alias'   => $created['room_alias'] ?? ($alias !== null ? '#'.$alias.':'.config('matrix.server_name') : null),
            'room_type'      => $roomType,
            'room_version'   => self::REQUIRED_VERSION,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'space_type'     => $spaceType,
            'is_public'      => true,
        ]);
    }

    /**
     * The createRoom body. EVERY power-level field is set explicitly (never rely on the disputed
     * invite default): ban/kick/redact/state_default/invite = 100 (unreachable — no human is listed),
     * events_default/users_default = 0 (members may post; membership is residency-gated by the
     * appservice in K3-G), and users = {} so the only power in the room is the v12 creator's implicit,
     * unencodable, infinite power held by the appservice. Public + world_readable + unencrypted.
     */
    public function buildCommonsRoomBody(string $title, ?string $alias, bool $isSpace = false): array
    {
        $body = [
            'room_version' => self::REQUIRED_VERSION,
            'preset'       => 'public_chat',
            'visibility'   => 'public',
            'name'         => $title,
            'power_level_content_override' => [
                'ban'            => 100,
                'kick'           => 100,
                'redact'         => 100,
                'invite'         => 100,
                'state_default'  => 100,
                'events_default' => 0,
                'users_default'  => 0,
                'users'          => (object) [],   // NO human holds a power level (Art. I §5.1)
                'notifications'  => ['room' => 100],
                'events'         => [
                    'm.room.name'               => 100,
                    'm.room.topic'              => 100,
                    'm.room.avatar'             => 100,
                    'm.room.canonical_alias'    => 100,
                    'm.room.power_levels'       => 100,
                    'm.room.history_visibility' => 100,
                    'm.room.server_acl'         => 100,
                    'm.room.tombstone'          => 100,
                    'm.room.encryption'         => 100,   // public rooms stay unencrypted
                ],
            ],
            'initial_state' => [
                ['type' => 'm.room.history_visibility', 'state_key' => '', 'content' => ['history_visibility' => 'world_readable']],
                ['type' => 'm.room.guest_access', 'state_key' => '', 'content' => ['guest_access' => 'forbidden']],
            ],
        ];

        // A jurisdiction Space (m.space) is also a v12 appservice-sole-creator room — the power-clamp
        // applies identically; only the create-event type differs.
        if ($isSpace) {
            $body['creation_content'] = ['type' => 'm.space'];
        }

        if ($alias !== null) {
            $body['room_alias_name'] = $alias;
        }

        return $body;
    }
}
