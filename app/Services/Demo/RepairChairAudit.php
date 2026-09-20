<?php

namespace App\Services\Demo;

use App\Models\{AuditEntry, PublicRecord};
use App\Services\AuditService;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;

/** Chair-action collector. PostgreSQL savepoints govern staged evidence too. */
final class RepairChairAudit
{
    private static bool $active = false;

    public static function active(): bool { return self::$active; }

    public static function begin(): void
    {
        if (self::$active || DB::transactionLevel() < 1 || app(AuditService::class)->isBatching()) {
            throw new \LogicException('Chair evidence requires its own active action transaction.');
        }
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS sim_repair_chair_audit
            (ordinal bigint GENERATED ALWAYS AS IDENTITY, act jsonb NOT NULL, record jsonb)
            ON COMMIT DELETE ROWS');
        if (DB::table('pg_temp.sim_repair_chair_audit')->exists()) { throw new \LogicException('Unflushed chair evidence'); }
        self::$active = true;
    }

    public static function append(array $act): AuditEntry
    {
        $act['occurred_at'] = now()->toDateTimeString();
        $row = DB::selectOne('INSERT INTO pg_temp.sim_repair_chair_audit (act) VALUES (?::jsonb) RETURNING ordinal', [json_encode($act, JSON_THROW_ON_ERROR)]);
        return (new AuditEntry)->forceFill(['module' => $act['module'], 'event' => $act['event'], 'seq' => null,
            'hash' => '', 'rejected' => $act['rejected'], '_chair_ordinal' => $row->ordinal]);
    }

    public static function record(AuditEntry $entry, array $attributes): PublicRecord
    {
        $record = (new PublicRecord)->forceFill($attributes + ['created_at' => now()]);
        $written = DB::table('pg_temp.sim_repair_chair_audit')->where('ordinal', $entry->getAttribute('_chair_ordinal'))
            ->update(['record' => json_encode($record->getAttributes(), JSON_THROW_ON_ERROR)]);
        if ($written !== 1) { throw new \LogicException('Public record lost its staged audit entry.'); }
        // Only the UUID is consumed by chair ballots. No row is made public
        // until flush supplies its true sequence, before the SAME commit.
        return $record;
    }

    public static function flush(): void
    {
        if (! self::$active || DB::transactionLevel() < 1) { throw new \LogicException('No atomic chair collection'); }
        $acts = DB::table('pg_temp.sim_repair_chair_audit')->orderBy('ordinal')->get();
        $prepared = []; $records = [];
        foreach ($acts as $item) {
            $act = json_decode($item->act, true, flags: JSON_THROW_ON_ERROR);
            $act['canonical'] = AuditService::canonicalJson($act['payload']);
            $prepared[] = $act;
            if ($item->record !== null) {
                $record = json_decode($item->record, true, flags: JSON_THROW_ON_ERROR);
                $records[$record['id']] = $record;
            }
        }
        SimTimer::open('repair.audit_commit'); // closed AFTER the owned outer commit
        SimTimer::open('repair.audit_flush');
        try {
            SimTimer::open('repair.audit_lock_wait');
            try { DB::statement('SELECT pg_advisory_xact_lock(?)', [AuditService::APPEND_LOCK_KEY]); }
            finally { SimTimer::close('repair.audit_lock_wait'); }
            $head = DB::table('audit_log')->orderByDesc('seq')->value('hash');
            if (! $head) { throw new \RuntimeException('Audit genesis missing'); }
            $rows = [];
            foreach ($prepared as $ordinal => $act) {
                $hash = AuditService::chainHash($head, $act['canonical']);
                $rows[] = ['ordinal' => $ordinal, 'occurred_at' => $act['occurred_at'], 'created_at' => $act['occurred_at'],
                    'actor_user_id' => $act['actorId'], 'module' => $act['module'], 'event' => $act['event'], 'ref' => $act['ref'],
                    'jurisdiction_id' => $act['jurisdictionId'], 'payload' => $act['payload'], 'prev_hash' => $head, 'hash' => $hash,
                    'rejected' => $act['rejected'], 'blocked_reason' => $act['blockedReason']];
                $head = $hash;
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $inserted = DB::select('INSERT INTO audit_log
                    (occurred_at,created_at,actor_user_id,module,event,ref,jurisdiction_id,payload,prev_hash,hash,rejected,blocked_reason)
                    SELECT occurred_at,created_at,actor_user_id,module,event,ref,jurisdiction_id,payload,prev_hash,hash,rejected,blocked_reason
                    FROM jsonb_to_recordset(?::jsonb) AS x(ordinal int, occurred_at timestamp,created_at timestamp,actor_user_id uuid,
                        module text,event text,ref text,jurisdiction_id uuid,payload jsonb,prev_hash text,hash text,rejected boolean,blocked_reason text)
                    ORDER BY ordinal RETURNING seq,payload', [json_encode($chunk, JSON_THROW_ON_ERROR)]);
                foreach ($inserted as $entry) {
                    $id = json_decode($entry->payload, true)['record_id'] ?? null;
                    if ($id && isset($records[$id])) { $records[$id]['audit_seq'] = (int) $entry->seq; }
                }
            }
            foreach ($records as $record) { if (empty($record['audit_seq'])) { throw new \LogicException('Unsealed chair public record'); } }
            foreach (array_chunk(array_values($records), 500) as $chunk) { DB::table('public_records')->insert($chunk); }
            DB::table('pg_temp.sim_repair_chair_audit')->delete();
            self::$active = false;
        } finally { SimTimer::close('repair.audit_flush'); }
    }

    public static function end(): void { self::$active = false; }
}
