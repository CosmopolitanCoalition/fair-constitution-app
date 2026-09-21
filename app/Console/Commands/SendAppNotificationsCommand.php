<?php

namespace App\Console\Commands;

use App\Services\Notifications\AppNotificationService;
use App\Services\Notifications\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendAppNotificationsCommand extends Command
{
    protected $signature = 'app:notifications';

    protected $description = 'Prepare opted-in civic reminders and deliver pending browser notifications';

    public function handle(AppNotificationService $notifications, WebPushService $transport): int
    {
        // Session lock also covers manual invocations and survives scheduler-lock expiry.
        $locked = DB::selectOne('SELECT pg_try_advisory_lock(1464816464, 1) AS held')->held;
        if (! $locked) {
            return self::SUCCESS;
        }
        try {
            $notifications->prepareClockReminders();
            $sent = $notifications->deliverBatch($transport);
            $this->info("Accepted by push provider: {$sent}");
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(1464816464, 1)');
        }

        return self::SUCCESS;
    }
}
