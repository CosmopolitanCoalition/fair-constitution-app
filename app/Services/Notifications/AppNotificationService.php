<?php

namespace App\Services\Notifications;

use App\Models\ClockTimer;
use App\Models\MatrixIdentity;
use App\Models\MatrixRoom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Opt-in notification outbox. Constitutional actions never depend on push delivery. */
class AppNotificationService
{
    // Only public, scheduled civic clocks. No case details, residency traces or infrastructure ticks.
    public const PUBLIC_CLOCKS = ['CLK-01', 'CLK-02', 'CLK-03', 'CLK-04', 'CLK-18', 'CLK-21', 'CLK-22'];

    public function enqueue(object $device, string $category, string $event, array $context, ?Carbon $expires = null): void
    {
        if ($category !== 'test' && ! ($device->{$category} ?? false)) {
            return;
        }
        DB::table('web_push_deliveries')->insertOrIgnore([
            'subscription_id' => $device->id, 'category' => $category, 'event_key' => hash('sha256', $event),
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'available_at' => now(),
            'expires_at' => $expires ?? now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Called only by the authenticated Matrix appservice transaction receiver. */
    public function matrixEvent(array $event): void
    {
        $type = $event['type'] ?? '';
        if (! in_array($type, ['m.room.message', 'm.room.encrypted', 'm.room.member'], true) || empty($event['event_id'])) {
            return;
        }
        $at = (int) ($event['origin_server_ts'] ?? 0);
        if ($at < now()->subDay()->getTimestampMs() || $at > now()->addMinutes(5)->getTimestampMs()) {
            return;
        }
        if (($event['content']['m.relates_to']['rel_type'] ?? '') === 'm.replace') {
            return;
        }
        $room = MatrixRoom::query()->where('matrix_room_id', $event['room_id'] ?? '')->whereNull('tombstoned_at')->first();
        if (! $room || $room->room_type !== MatrixRoom::ROOM_USER_PRIVATE || $room->entity_type !== MatrixRoom::ENTITY_SOCIAL_SPACE) {
            return;
        }
        $sender = $this->identityUser($event['sender'] ?? '');
        $query = DB::table('web_push_subscriptions');
        if ($type === 'm.room.member') {
            if (($event['content']['membership'] ?? '') !== 'invite') {
                return;
            }
            $recipient = $this->identityUser($event['state_key'] ?? '');
            if (! $recipient || $recipient === $sender) {
                return;
            }
            // A Matrix invitation alone grants no game-room membership. The landing remains gated.
            $query->where('user_id', $recipient)->where('invitations', true);
            $category = 'invitations';
        } else {
            $category = 'messages';
            $query->where('messages', true)->whereIn('user_id', DB::table('social_memberships')->select('user_id')
                ->where('space_id', $room->entity_id)->whereNull('deleted_at')->whereNull('block_user_id'));
        }
        if ($sender) {
            $query->where('user_id', '<>', $sender);
        }
        $query->where('created_at', '<=', Carbon::createFromTimestampMs($at))->orderBy('id')->chunkById(100,
            function ($devices) use ($category, $event, $room) {
                foreach ($devices as $device) {
                    $this->enqueue($device, $category, 'matrix:'.$event['event_id'], [
                        'space_id' => $room->entity_id, 'matrix_room_id' => $room->matrix_room_id,
                    ]);
                }
            });
    }

    public function invitationAccepted(string $inviterId, string $inviteId, string $userId): void
    {
        if ($inviterId === $userId) {
            return;
        }
        foreach (DB::table('web_push_subscriptions')->where('user_id', $inviterId)->where('invitations', true)->get() as $device) {
            $this->enqueue($device, 'invitations', 'accepted:'.$inviteId.':'.$userId, ['accepted' => true]);
        }
    }

    private function identityUser(string $mxid): ?string
    {
        if (! preg_match('/^@([^:]+):.+$/D', $mxid, $parts)) {
            return null;
        }

        // Probe the existing partial unique localpart index, then verify the full MXID/domain.
        return MatrixIdentity::query()->whereRaw('lower(matrix_localpart) = ?', [strtolower($parts[1])])
            ->where('matrix_user_id', $mxid)->value('user_id');
    }

    /** Fair, bounded round-robin over opted-in devices, never the simulated population. */
    public function prepareClockReminders(): void
    {
        $devices = DB::table('web_push_subscriptions')->where('clocks', true)
            ->orderByRaw('clocks_checked_at ASC NULLS FIRST')->orderBy('id')->limit(50)->get();
        foreach ($devices as $device) {
            if ($this->isResident($device)) {
                foreach (self::PUBLIC_CLOCKS as $clock) {
                    // The existing (clock_id, jurisdiction_id) index bounds each lookup to this scope.
                    $timer = ClockTimer::query()->where('clock_id', $clock)->where('jurisdiction_id', $device->clock_jurisdiction_id)
                        ->armed()->where('fires_at', '>', now())->where('fires_at', '<=', now()->addMinutes($device->remind_minutes))
                        ->orderBy('fires_at')->orderBy('id')->first();
                    if ($timer) {
                        $this->enqueue($device, 'clocks', 'clock:'.$timer->id.':'.$timer->fires_at->toIso8601String(), [
                            'timer_id' => $timer->id, 'fires_at' => $timer->fires_at->toIso8601String(),
                        ], $timer->fires_at);
                    }
                }
            }
            DB::table('web_push_subscriptions')->where('id', $device->id)->update(['clocks_checked_at' => now()]);
        }
    }

    private function isResident(object $device): bool
    {
        return $device->clock_jurisdiction_id && DB::table('residency_confirmations')->where('user_id', $device->user_id)
            ->where('jurisdiction_id', $device->clock_jurisdiction_id)->where('is_active', true)->exists();
    }

    /** Recheck membership, preferences and deadlines at delivery, including queued retries. */
    public function payload(object $delivery, object $device): ?array
    {
        if (! DB::table('users')->where('id', $device->user_id)->whereNull('deleted_at')->exists()) {
            return null;
        }
        if ($delivery->category !== 'test' && ! ($device->{$delivery->category} ?? false)) {
            return null;
        }
        $context = json_decode($delivery->context, true, flags: JSON_THROW_ON_ERROR);
        $body = __('Your test notification arrived.');
        $url = '/system/app';
        if ($delivery->category === 'messages' || isset($context['space_id'])) {
            $room = MatrixRoom::query()->where('matrix_room_id', $context['matrix_room_id'])->whereNull('tombstoned_at')->first();
            if (! $room || $room->entity_id !== $context['space_id']) {
                return null;
            }
            if ($delivery->category === 'messages' && ! DB::table('social_memberships')->where('space_id', $context['space_id'])
                ->where('user_id', $device->user_id)->whereNull('deleted_at')->whereNull('block_user_id')->exists()) {
                return null;
            }
            $body = $delivery->category === 'messages' ? __('You have a new private message.') : __('You have a room invitation.');
            $url = '/civic/rooms/'.$context['space_id'];
        } elseif ($delivery->category === 'invitations') {
            $body = __('Someone accepted your invitation.');
            $url = '/civic/rooms';
        } elseif ($delivery->category === 'clocks') {
            if (! $this->isResident($device)) {
                return null;
            }
            $timer = ClockTimer::query()->armed()->find($context['timer_id']);
            if (! $timer || ! $timer->fires_at || $timer->fires_at->isPast() || $timer->jurisdiction_id !== $device->clock_jurisdiction_id
                || $timer->fires_at->toIso8601String() !== $context['fires_at'] || ! in_array($timer->clock_id, self::PUBLIC_CLOCKS, true)) {
                return null;
            }
            $name = DB::table('clocks')->where('id', $timer->clock_id)->value('name');
            $body = ($name ?: __('Civic deadline')).' · '.$timer->fires_at->utc()->format('Y-m-d H:i').' UTC';
            $url = '/system/clocks';
        }

        return ['title' => 'World of Statecraft', 'body' => $body, 'url' => $url, 'tag' => 'wos-'.$delivery->event_key];
    }

    /** A single scheduler owner processes a bounded batch; retries retain the same notification tag. */
    public function deliverBatch(WebPushService $transport, int $seconds = 45): int
    {
        $started = microtime(true);
        $accepted = 0;
        foreach (DB::table('web_push_deliveries')->whereNull('finished_at')->where('available_at', '<=', now())->orderBy('id')->limit(100)->get() as $delivery) {
            if (microtime(true) - $started >= $seconds) {
                break;
            }
            $device = DB::table('web_push_subscriptions')->find($delivery->subscription_id);
            if (! $device) {
                continue;
            }
            $outcome = 'skipped';
            try {
                $payload = Carbon::parse($delivery->expires_at)->isFuture() ? $this->payload($delivery, $device) : null;
                if ($payload) {
                    $outcome = $transport->send(json_decode(Crypt::decryptString($device->subscription), true, flags: JSON_THROW_ON_ERROR), $payload);
                }
            } catch (\Throwable $error) {
                // Never log push endpoints, browser keys, message content or transport request URLs.
                Log::warning('Web push delivery will retry', ['delivery_id' => $delivery->id, 'error_type' => $error::class]);
                $outcome = 'retry';
            }
            if ($outcome === 'expired' || $outcome === 'invalid') {
                DB::table('web_push_subscriptions')->where('id', $device->id)->delete();

                continue;
            }
            if ($outcome === 'accepted') {
                $accepted++;
            }
            $attempts = $delivery->attempts + 1;
            DB::table('web_push_deliveries')->where('id', $delivery->id)->update([
                'outcome' => $outcome, 'attempts' => $attempts, 'updated_at' => now(),
                'finished_at' => $outcome !== 'retry' || $attempts >= 5 ? now() : null,
                'available_at' => now()->addSeconds(min(900, 30 * (2 ** $attempts))),
            ]);
        }
        // Indexed, bounded retention; old replayed Matrix events are rejected by their timestamp.
        $ids = DB::table('web_push_deliveries')->where('finished_at', '<', now()->subDays(7))->orderBy('finished_at')->limit(500)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('web_push_deliveries')->whereIn('id', $ids)->delete();
        }

        return $accepted;
    }
}
