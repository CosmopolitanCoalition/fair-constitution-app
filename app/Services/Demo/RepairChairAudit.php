<?php

namespace App\Services\Demo;

use App\Models\AuditEntry;
use App\Services\AuditService;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Repair-action collector. PostgreSQL savepoints govern staged evidence too. */
final class RepairChairAudit
{
    private static bool $active = false;

    public static function active(): bool { return self::$active; }

    public static function begin(): void
    {
        if (self::$active || DB::transactionLevel() < 1 || app(AuditService::class)->isBatching()) {
            throw new \LogicException('Repair evidence requires its own active action transaction.');
        }
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS sim_repair_chair_audit
            (ordinal bigint GENERATED ALWAYS AS IDENTITY, act jsonb NOT NULL, record jsonb, attachment jsonb, canonical text)
            ON COMMIT DELETE ROWS');
        if (DB::table('pg_temp.sim_repair_chair_audit')->exists()) { throw new \LogicException('Unflushed repair evidence'); }
        self::$active = true;
    }

    public static function append(array $act): AuditEntry
    {
        $act['id'] = (string) Str::uuid7();
        $act['occurred_at'] = now()->toDateTimeString();
        $row = DB::selectOne('INSERT INTO pg_temp.sim_repair_chair_audit (act) VALUES (?::jsonb) RETURNING ordinal', [json_encode($act, JSON_THROW_ON_ERROR)]);
        return (new AuditEntry)->forceFill(['id' => $act['id'], 'module' => $act['module'], 'event' => $act['event'], 'seq' => null,
            'hash' => '', 'rejected' => $act['rejected'], '_chair_ordinal' => $row->ordinal]);
    }

    /** Stage complete publication pairs in bounded SQL writes, before taking the chain lock. */
    public static function publications(array $pairs): void
    {
        if (! self::$active || DB::transactionLevel() < 1) { throw new \LogicException('No atomic repair collection'); }
        $rows = [];
        foreach ($pairs as [$act, $attributes]) {
            $act['id'] = (string) Str::uuid7();
            $act['occurred_at'] = now()->toDateTimeString();
            $rows[] = ['act' => json_encode($act, JSON_THROW_ON_ERROR),
                'record' => json_encode($attributes, JSON_THROW_ON_ERROR)];
        }
        foreach (array_chunk($rows, 500) as $chunk) { DB::table('pg_temp.sim_repair_chair_audit')->insert($chunk); }
    }

    /** Only these two immutable, sequence-bearing repair outputs may be deferred. */
    public static function attach(AuditEntry $entry, string $table, array $attributes): void
    {
        if (! in_array($table, ['achievements', 'cgc_ip_register'], true)) { throw new \LogicException('Unsupported repair seal'); }
        $attributes['id'] ??= (string) Str::uuid7();
        $written = DB::table('pg_temp.sim_repair_chair_audit')->where('ordinal', $entry->getAttribute('_chair_ordinal'))
            ->update(['attachment' => json_encode(['table' => $table, 'attributes' => $attributes], JSON_THROW_ON_ERROR)]);
        if ($written !== 1) { throw new \LogicException('Immutable repair output lost its audit entry'); }
    }

    public static function hasAchievement(string $user, string $key): bool
    {
        return self::$active && DB::table('pg_temp.sim_repair_chair_audit')
            ->where('attachment->table', 'achievements')->where('attachment->attributes->user_id', $user)
            ->where('attachment->attributes->award_key', $key)->exists();
    }

    public static function hasTraining(string $user, string $track): bool
    {
        return self::$active && DB::table('pg_temp.sim_repair_chair_audit')
            ->where('act->ref', 'F-EDU-001')->where('act->event', 'education.training_completed')
            ->where('act->rejected', false)->where('act->actorId', $user)->where('act->payload->track_key', $track)->exists();
    }

    /**
     * The minted-training tail needs both chains until its outer commit. Join
     * the audit queue before owning money, but never wait for money while
     * owning audit: an ordinary writer may already hold money and need audit.
     * Rolling back ONLY the reservation savepoint releases a failed attempt's
     * locks, keeping the completed training work and its staged evidence intact.
     */
    public static function reserveTrainingPaymentLocks(): void
    {
        if (! self::$active || DB::transactionLevel() < 1) { throw new \LogicException('No atomic repair collection'); }
        $level = DB::transactionLevel();
        $money = \App\Services\Economy\LedgerService::APPEND_LOCK_KEY;
        SimTimer::open('repair.training_append_wait');
        try {
            while (true) {
                DB::beginTransaction();
                DB::statement('SELECT pg_advisory_xact_lock(?)', [AuditService::APPEND_LOCK_KEY]);
                $acquired = DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$money])->acquired;
                if ($acquired) {
                    DB::commit(); // savepoint only; both locks survive to the action commit
                    return;
                }
                DB::rollBack(); // release audit so the existing money owner can finish

                // Wait without holding audit, then release this temporary
                // reservation and retry the pair. No spin loop or session locks.
                DB::beginTransaction();
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$money]);
                DB::rollBack();
            }
        } finally {
            if (DB::transactionLevel() > $level) { DB::rollBack($level); }
            SimTimer::close('repair.training_append_wait');
        }
    }

    public static function flush(): void
    {
        if (! self::$active || DB::transactionLevel() < 1) { throw new \LogicException('No atomic repair collection'); }
        $query = DB::table('pg_temp.sim_repair_chair_audit')->orderBy('ordinal');
        $acts = (clone $query)->limit(501)->get();
        if ($acts->isEmpty()) { self::$active = false; return; }
        if ($acts->count() <= 500) {
            $pages = [$acts]; // Common chair path avoids another preparation write.
        } else {
            // Large election recoveries must not load the entire evidence set.
            // Canonicalize bounded pages BEFORE acquiring the global chain lock.
            (clone $query)->chunkById(500, function ($page): void {
                $canonical = [];
                foreach ($page as $row) {
                    $act = json_decode($row->act, true, flags: JSON_THROW_ON_ERROR);
                    $canonical[] = ['ordinal' => $row->ordinal, 'canonical' => AuditService::canonicalJson($act['payload'])];
                }
                DB::statement('UPDATE pg_temp.sim_repair_chair_audit AS target SET canonical=x.canonical
                    FROM jsonb_to_recordset(?::jsonb) AS x(ordinal bigint, canonical text) WHERE target.ordinal=x.ordinal',
                    [json_encode($canonical, JSON_THROW_ON_ERROR)]);
            }, 'ordinal');
            $pages = (function () use ($query) {
                $last = 0;
                while (($page = (clone $query)->where('ordinal', '>', $last)->limit(500)->get())->isNotEmpty()) {
                    $last = $page->last()->ordinal;
                    yield $page;
                }
            })();
        }
        // Small collections prepare payloads before locking too.
        if (is_array($pages)) {
            foreach ($acts as $row) { $row->canonical = AuditService::canonicalJson(json_decode($row->act, true, flags: JSON_THROW_ON_ERROR)['payload']); }
        }
        SimTimer::open('repair.audit_commit'); // closed AFTER the owned outer commit
        SimTimer::open('repair.audit_flush');
        try {
            SimTimer::open('repair.audit_lock_wait');
            try { DB::statement('SELECT pg_advisory_xact_lock(?)', [AuditService::APPEND_LOCK_KEY]); }
            finally { SimTimer::close('repair.audit_lock_wait'); }
            $head = DB::table('audit_log')->orderByDesc('seq')->value('hash');
            if (! $head) { throw new \RuntimeException('Audit genesis missing'); }
            foreach ($pages as $page) {
                $rows = []; $records = []; $attachments = [];
                foreach ($page as $ordinal => $item) {
                    $act = json_decode($item->act, true, flags: JSON_THROW_ON_ERROR);
                    $act['canonical'] = $item->canonical;
                    if ($item->record !== null) { $records[$act['id']] = json_decode($item->record, true, flags: JSON_THROW_ON_ERROR); }
                    if ($item->attachment !== null) { $attachments[$act['id']] = json_decode($item->attachment, true, flags: JSON_THROW_ON_ERROR); }
                    $hash = AuditService::chainHash($head, $act['canonical']);
                    $rows[] = ['id' => $act['id'], 'ordinal' => $ordinal, 'occurred_at' => $act['occurred_at'], 'created_at' => $act['occurred_at'],
                        'actor_user_id' => $act['actorId'], 'module' => $act['module'], 'event' => $act['event'], 'ref' => $act['ref'],
                        'jurisdiction_id' => $act['jurisdictionId'], 'payload' => $act['payload'], 'prev_hash' => $head, 'hash' => $hash,
                        'rejected' => $act['rejected'], 'blocked_reason' => $act['blockedReason']];
                    $head = $hash;
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    $inserted = DB::select('INSERT INTO audit_log
                        (id,occurred_at,created_at,actor_user_id,module,event,ref,jurisdiction_id,payload,prev_hash,hash,rejected,blocked_reason)
                        SELECT id,occurred_at,created_at,actor_user_id,module,event,ref,jurisdiction_id,payload,prev_hash,hash,rejected,blocked_reason
                        FROM jsonb_to_recordset(?::jsonb) AS x(id uuid, ordinal int, occurred_at timestamp,created_at timestamp,actor_user_id uuid,
                            module text,event text,ref text,jurisdiction_id uuid,payload jsonb,prev_hash text,hash text,rejected boolean,blocked_reason text)
                        ORDER BY ordinal RETURNING id,seq', [json_encode($chunk, JSON_THROW_ON_ERROR)]);
                    foreach ($inserted as $entry) {
                        if (isset($records[$entry->id])) { $records[$entry->id]['audit_seq'] = (int) $entry->seq; }
                        if (isset($attachments[$entry->id])) { $attachments[$entry->id]['attributes']['audit_seq'] = (int) $entry->seq; }
                    }
                }
                foreach ($records as $record) { if (empty($record['audit_seq'])) { throw new \LogicException('Unsealed chair public record'); } }
                foreach (array_chunk(array_values($records), 500) as $chunk) { DB::table('public_records')->insert($chunk); }
                $sealed = [];
                foreach ($attachments as $attachment) {
                    if (empty($attachment['attributes']['audit_seq'])) { throw new \LogicException('Unsealed repair output'); }
                    $sealed[$attachment['table']][] = $attachment['attributes'];
                }
                foreach ($sealed as $table => $rows) {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        if ($table === 'achievements') { DB::table($table)->insertOrIgnore($chunk); }
                        else { DB::table($table)->insert($chunk); }
                    }
                }
            }
            DB::table('pg_temp.sim_repair_chair_audit')->delete();
            self::$active = false;
        } finally { SimTimer::close('repair.audit_flush'); }
    }

    public static function end(): void { self::$active = false; }
}
