<?php

namespace App\Services\Messenger;

use App\Models\MatrixRoom;
use App\Models\SocialMembership;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Social\PrivateRoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The floating messenger's read and write path over the two kinds of conversation a person is in:
 * their private rooms (membership-gated) and the public squares they follow (open, pseudonymous).
 * A conversation key is `private:<space id>` or `commons:<jurisdiction id>`. It reuses the same
 * gates as the room pages, so the dock can never reach a room the page would refuse.
 */
class MessengerService
{
    public const MAX_ROOMS = 30;

    public function __construct(
        private readonly PrivateRoomService $rooms,
        private readonly MatrixClientService $client,
        private readonly MatrixPostingGateService $posting,
    ) {}

    /** @return array{kind:string,matrix_room_id:string,title:string,href:string,jurisdiction_id:?string}|null */
    public function resolve(User $user, string $key): ?array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return null;
        }
        if ($kind === 'private') {
            $space = SocialSpace::query()->find($id);
            if ($space === null || ! $space->is_private || ! $this->rooms->isMember($space, $user)) {
                return null;
            }
            $room = $this->rooms->ensureRoom($space);

            return $room === null ? null : [
                'kind' => 'private', 'matrix_room_id' => $room->matrix_room_id, 'title' => (string) $space->title,
                'href' => '/civic/rooms/'.$space->id, 'jurisdiction_id' => null,
            ];
        }
        if ($kind === 'commons') {
            $room = MatrixRoom::query()->where('entity_type', MatrixRoom::ENTITY_JURISDICTION)->where('entity_id', $id)
                ->where('space_type', MatrixRoom::SPACE_PUBLIC_SQUARE)->where('room_type', MatrixRoom::ROOM_COMMONS)
                ->where('is_public', true)->where('is_encrypted', false)->whereNull('tombstoned_at')->whereNotNull('matrix_room_id')
                ->first(['id', 'matrix_room_id']);
            $place = DB::table('jurisdictions')->where('id', $id)->whereNull('deleted_at')->first(['id', 'name']);

            return ($room === null || $place === null) ? null : [
                'kind' => 'commons', 'matrix_room_id' => $room->matrix_room_id, 'title' => (string) $place->name,
                'href' => '/civic/commons/square?jurisdiction='.$id, 'jurisdiction_id' => $id,
            ];
        }

        return null;
    }

    /** Conversations to list: every private room the user is in, then the public squares they follow. */
    public function keysFor(User $user, array $following): array
    {
        $ids = SocialMembership::query()->where('user_id', (string) $user->getKey())->whereNull('deleted_at')->pluck('space_id');
        $private = SocialSpace::query()->where('is_private', true)->where('space_type', SocialSpace::TYPE_GROUP)
            ->whereKey($ids)->orderByDesc('created_at')->limit(self::MAX_ROOMS)->pluck('id')->map(fn ($i) => 'private:'.$i)->all();
        $public = array_values(array_filter($following, fn ($k) => is_string($k) && str_starts_with($k, 'commons:')));

        return array_slice(array_merge($private, array_slice($public, 0, 10)), 0, self::MAX_ROOMS);
    }

    /**
     * The list rows, with a last-message preview and an unread count against the user's read marks.
     * One Matrix read per room, cut short after the first failure so a down homeserver costs one call.
     * Cached for a few seconds per user so several tabs do not each pay for it.
     */
    public function list(User $user, array $following, array $reads, int $baselineMs): array
    {
        $uid = (string) $user->getKey();

        return Cache::remember("messenger.list.$uid", 8, function () use ($user, $following, $reads, $baselineMs) {
            $me = $this->posting->matrixUserId($user);
            $down = false;
            $rows = [];
            foreach ($this->keysFor($user, $following) as $key) {
                $c = $this->resolve($user, $key);
                if ($c === null) {
                    continue;
                }
                $messages = [];
                if (! $down) {
                    try {
                        $messages = $this->map((array) ($this->client->getMessages($c['matrix_room_id'], 'b', null, 10)['chunk'] ?? []));
                    } catch (Throwable $e) {
                        $down = true;
                    }
                }
                $last = end($messages) ?: null;
                $mark = (int) ($reads[$key] ?? $baselineMs);
                $unread = count(array_filter($messages, fn ($m) => ($m['sender'] ?? '') !== $me && (int) ($m['at'] ?? 0) > $mark));
                $rows[] = [
                    'key' => $key, 'kind' => $c['kind'], 'title' => $c['title'], 'href' => $c['href'],
                    'preview' => $last ? mb_strimwidth(($last['sender'] === $me ? 'You: ' : '').$last['body'], 0, 90, '…') : null,
                    'last_at' => $last['at'] ?? null, 'unread' => $unread,
                ];
            }
            usort($rows, fn ($a, $b) => ((int) $b['last_at']) <=> ((int) $a['last_at']));

            return ['rows' => $rows, 'reachable' => ! $down];
        });
    }

    /** @return array{messages:list<array>,my_mxid:string,reachable:bool} */
    public function thread(User $user, array $conversation, int $limit = 40): array
    {
        try {
            $messages = $this->map((array) ($this->client->getMessages($conversation['matrix_room_id'], 'b', null, $limit)['chunk'] ?? []));
            $reachable = true;
        } catch (Throwable $e) {
            $messages = [];
            $reachable = false;
        }

        return ['messages' => $messages, 'my_mxid' => $this->posting->matrixUserId($user), 'reachable' => $reachable];
    }

    public function send(User $user, array $conversation, string $body): void
    {
        if ($conversation['kind'] === 'private') {
            $this->posting->postToPrivateRoom($user, $conversation['matrix_room_id'], $body);
        } else {
            $this->posting->post($user, (string) $conversation['jurisdiction_id'], $conversation['matrix_room_id'], $body);
        }
        Cache::forget('messenger.list.'.$user->getKey());
    }

    /** @return list<array{event_id:string,sender:string,body:string,at:mixed}> oldest first */
    private function map(array $chunk): array
    {
        $out = [];
        foreach ($chunk as $event) {
            if (($event['type'] ?? '') !== 'm.room.message') {
                continue;
            }
            $out[] = [
                'event_id' => (string) ($event['event_id'] ?? ''), 'sender' => (string) ($event['sender'] ?? ''),
                'body' => (string) (($event['content'] ?? [])['body'] ?? ''), 'at' => $event['origin_server_ts'] ?? null,
            ];
        }

        return array_reverse($out);
    }

    public static function nowMs(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }
}
