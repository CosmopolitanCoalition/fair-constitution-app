<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixRoom;
use App\Models\SocialProfile;
use App\Models\User;

/**
 * Phase K-3 (K3-H) — the testimony bridge: the ONE-WAY valve from Plane B (live Matrix) to Plane A
 * (the append-only civic record). A discussion HAPPENS in a Matrix #halls room; when a participant
 * FILES one of their messages as testimony, this snapshots that single event — body + pseudonym
 * frozen at file time — into public_records via the EXISTING F-SOC-002 path (engine-routed, sealed
 * to the hash chain in one transaction). The Matrix message stays live + editable; the CIVIC ACT is
 * the immutable snapshot. UI must read "filing", not "posting" — filing is the constitutional act.
 *
 * The own-post + halls gates are checked against GROUND TRUTH (the real event fetched from the
 * homeserver), not a caller's claim: you may file only YOUR own statement (Art. I), only in the
 * halls (Art. II §2).
 */
class TestimonyBridgeService
{
    public function __construct(
        private ConstitutionalEngine $engine,
        private MatrixClientService $client,
        private MatrixPostingGateService $posting,
    ) {}

    public function fileTestimony(User $filer, string $matrixRoomId, string $matrixEventId): array
    {
        // HALLS only — testimony is the Art. II §2 deliberation record, not the open square.
        $room = MatrixRoom::query()->where('matrix_room_id', $matrixRoomId)->whereNull('tombstoned_at')->first();
        if ($room === null || $room->space_type !== MatrixRoom::SPACE_HALLS) {
            throw new ConstitutionalViolation(
                'Testimony is filed in the halls of governance (the Art. II §2 deliberation record), not the open square.',
                'Art. II §2'
            );
        }

        // Ground truth: the real event from the homeserver (its sender, body, timestamp).
        $event = $this->client->getEvent($matrixRoomId, $matrixEventId);
        $sender = (string) ($event['sender'] ?? '');
        $body = (string) ($event['content']['body'] ?? '');

        // OWN-POST only — you may file only YOUR own statement as testimony (Art. I).
        if ($sender === '' || $sender !== $this->posting->matrixUserId($filer)) {
            throw new ConstitutionalViolation(
                "Testimony enters YOUR own statement into the record — a resident cannot file another resident's message as testimony.",
                'Art. I'
            );
        }

        $result = $this->engine->file('F-SOC-002', $filer, [
            'matrix_event_id'  => $matrixEventId,
            'matrix_room_id'   => $matrixRoomId,
            'body_snapshot'    => $body,
            'actor_display'    => $this->displayFor($filer),
            'jurisdiction_id'  => (string) $room->entity_id,
            'origin_server_ts' => $event['origin_server_ts'] ?? null,
        ]);

        // Best-effort on-Matrix back-pointer: a cga.testimony state event keyed on the sealed event
        // (the authoritative link lives in matrix_event_snapshots; a down homeserver never fails the seal).
        try {
            $this->client->sendStateEvent($matrixRoomId, 'cga.testimony', $matrixEventId, [
                'published_record_id' => $result->recorded['published_record_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // ignore — the seal already landed in Plane A; the on-Matrix annotation is advisory.
        }

        return $result->recorded;
    }

    /** The pseudonym frozen into the immutable record — NEVER name/email (mirrors K-1 displayFor). */
    private function displayFor(User $filer): string
    {
        $profile = SocialProfile::query()->where('user_id', (string) $filer->getKey())->first();

        if (! empty($profile?->display_name)) {
            return (string) $profile->display_name;
        }
        if (! empty($profile?->handle)) {
            return '@'.$profile->handle;
        }

        return 'Resident-'.substr(hash('sha256', (string) $filer->getKey()), 0, 8);
    }
}
