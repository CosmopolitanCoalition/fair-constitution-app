<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /** Browser push endpoints only: never turn a subscription into an SSRF proxy. */
    public static function validEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass'])
            || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');

        return $host === 'fcm.googleapis.com'
            || $host === 'updates.push.services.mozilla.com'
            || $host === 'updates-autopush.push.services.mozilla.com'
            || $host === 'web.push.apple.com'
            || str_ends_with($host, '.notify.windows.com');
    }

    /** One durable key pair per installation. Never regenerate on deploy or restart. */
    public function keys(): array
    {
        $row = DB::table('web_push_keys')->find(1);
        if (! $row) {
            $keys = VAPID::createVapidKeys();
            DB::table('web_push_keys')->insertOrIgnore(['id' => 1, 'public_key' => $keys['publicKey'], 'private_key' => Crypt::encryptString($keys['privateKey'])]);
            $row = DB::table('web_push_keys')->find(1);
        }

        return ['publicKey' => $row->public_key, 'privateKey' => Crypt::decryptString($row->private_key), 'subject' => config('app.url')];
    }

    /** Injectable transport for tests; only vendor-acknowledged sends count as accepted. */
    public function send(array $subscription, array $payload): string
    {
        if (! self::validEndpoint($subscription['endpoint'] ?? '')) {
            return 'invalid';
        }
        $push = new WebPush(['VAPID' => $this->keys()], ['TTL' => 300, 'urgency' => 'normal'], 5,
            ['allow_redirects' => false, 'connect_timeout' => 3] + $this->httpOptions());
        // Safari requires the current RFC 8291 encoding; the library's compatibility default is aesgcm.
        $report = $push->sendOneNotification(Subscription::create(array_replace($subscription, ['contentEncoding' => 'aes128gcm'])), json_encode($payload, JSON_THROW_ON_ERROR));
        if ($report->isSubscriptionExpired()) {
            return 'expired';
        }
        $status = $report->getResponse()?->getStatusCode();

        return $report->isSuccess() && $status >= 200 && $status < 300 ? 'accepted' : 'retry';
    }

    /** Test transports may supply an HTTP handler without bypassing encryption or redirect policy. */
    protected function httpOptions(): array
    {
        return [];
    }
}
