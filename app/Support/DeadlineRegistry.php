<?php

namespace App\Support;

/**
 * THE DEADLINE REGISTRY — every column that "advance the world" is allowed to move.
 *
 * The playtest controls advance time by pulling DEADLINES BACKWARD, not by
 * offsetting the clock (DEV_TIME_AND_ROLE_CONTROLS.md §2). Nothing in this app
 * centralises `now()`, so a global offset would mean auditing every call site
 * including inside PROTECTED files — and one missed call site gives a world
 * that silently disagrees with itself about what time it is. Moving a bounded,
 * enumerable set of columns fails LOUDLY instead: an unregistered deadline
 * simply does not move and the journey visibly stalls.
 *
 * That guarantee only holds while this list is complete, and a hand-maintained
 * list rots. `DeadlineRegistryTest` therefore fails when a deadline-shaped
 * column exists on a deadline-bearing table and is neither registered here nor
 * exempted with a reason. **If that test fails, add the column — do not
 * silence the test.**
 *
 * ── What may move, and what may not ──────────────────────────────────────
 * A deadline is something SCHEDULED that has not happened yet. Those may move.
 * A record of something that DID happen may never move: audit entries keep
 * real timestamps, and a journey walked on 26 July must still read as 26 July
 * forever after. Every entry below therefore carries a state guard so only
 * pending rows shift — fired, cancelled, expired and certified rows are
 * history and are left exactly where they are.
 */
final class DeadlineRegistry
{
    /**
     * Columns "advance the world" moves.
     *
     * `guard` is appended to the UPDATE's WHERE clause so only rows that are
     * still WAITING are touched. Without it, advancing time would rewrite the
     * past.
     *
     * @return list<array{table:string, column:string, guard:string, why:string}>
     */
    public static function movable(): array
    {
        return [
            [
                'table'  => 'clock_timers',
                'column' => 'fires_at',
                'guard'  => "state = 'armed' AND deleted_at IS NULL",
                'why'    => 'The spine. Every constitutional clock that is waiting to fire.',
            ],

            // The election phase ladder. All four move together or an election
            // ends up with its ranked window opening before its approval one.
            [
                'table'  => 'elections',
                'column' => 'approval_opens_at',
                'guard'  => "deleted_at IS NULL AND status NOT IN ('certified','final','cancelled')",
                'why'    => 'When candidates may start being approved.',
            ],
            [
                'table'  => 'elections',
                'column' => 'finalist_cutoff_at',
                'guard'  => "deleted_at IS NULL AND status NOT IN ('certified','final','cancelled')",
                'why'    => 'When the finalist field freezes (CLK-18).',
            ],
            [
                'table'  => 'elections',
                'column' => 'ranked_opens_at',
                'guard'  => "deleted_at IS NULL AND status NOT IN ('certified','final','cancelled')",
                'why'    => 'When ranked voting opens (CLK-01).',
            ],
            [
                'table'  => 'elections',
                'column' => 'ranked_closes_at',
                'guard'  => "deleted_at IS NULL AND status NOT IN ('certified','final','cancelled')",
                'why'    => 'When ranked voting closes (CLK-01).',
            ],

            [
                'table'  => 'emergency_powers',
                'column' => 'expires_at',
                'guard'  => "deleted_at IS NULL AND status = 'active'",
                'why'    => 'Art. II §7 caps emergency powers at 90 days; reaching that expiry is a journey.',
            ],
            [
                'table'  => 'emergency_power_renewals',
                'column' => 'new_expires_at',
                // No deleted_at on this table, and no status — it is an
                // append-only record of renewals. The guard is the deadline
                // itself: only a window still in the future can be brought
                // forward. (CLAUDE.md's "all tables use deleted_at" is doc
                // drift; this is one of several that do not.)
                'guard'  => 'new_expires_at > now()',
                'why'    => 'The renewed window, so a renewal can also be walked to its end.',
            ],

            [
                'table'  => 'remedy_recommendations',
                'column' => 'veto_closes_at',
                // No status column either — the veto window is open precisely
                // while it has not closed, so the deadline is its own guard.
                'guard'  => 'deleted_at IS NULL AND veto_closes_at > now()',
                'why'    => 'The legislature\'s window to veto a judicial remedy (Art. IV §5).',
            ],

            [
                'table'  => 'legislatures',
                'column' => 'next_meeting_due_by',
                'guard'  => "deleted_at IS NULL AND status = 'active'",
                'why'    => 'Art. II §2 caps the gap between meetings at 90 days.',
            ],
        ];
    }

    /**
     * Deadline-shaped columns that must NOT move, each with the reason.
     *
     * Being on this list is a decision, not an oversight — which is the point
     * of requiring a reason.
     *
     * @return array<string, string> "table.column" => why it is exempt
     */
    public static function exempt(): array
    {
        return [
            // ── History. Moving these would forge the record. ──────────────
            'emergency_power_renewals.previous_expires_at' =>
                'Records the window that was ALREADY running when the renewal was filed. History, not a deadline.',

            // ── Federation. Dev time is refused outright on any federated or
            //    peered node, so these can never legitimately be in play. ────
            'instance_capabilities.grant_expires_at' =>
                'A capability another instance granted us. Only that instance may move it, and dev time is refused on federated nodes.',
            'directory_entries.expires_at' =>
                'Federation directory advertisement. Peer-facing, not a constitutional deadline of this world.',
            'cluster_join_keys.expires_at' =>
                'An operator credential with a deliberate short life. Extending it by time-travel would be a security change, not a playtest.',

            // ── Not constitutional deadlines. ─────────────────────────────
            'invites.expires_at' =>
                'A share link\'s convenience lifetime. Nothing in the constitution turns on it.',
        ];
    }

    /**
     * Tables the registry test scans. A deadline-shaped column on any of these
     * must be registered or exempted.
     *
     * @return list<string>
     */
    public static function scannedTables(): array
    {
        return [
            'clock_timers',
            'elections',
            'emergency_powers',
            'emergency_power_renewals',
            'remedy_recommendations',
            'legislatures',
            'instance_capabilities',
            'directory_entries',
            'cluster_join_keys',
            'invites',
        ];
    }

    /** Column-name suffixes that mark something as a deadline. */
    public static function deadlineSuffixes(): array
    {
        return ['_opens_at', '_closes_at', '_expires_at', '_ends_at', '_cutoff_at', '_fires_at', '_due_by'];
    }

    /** "table.column" keys for everything registered as movable. */
    public static function movableKeys(): array
    {
        return array_map(
            static fn (array $e): string => $e['table'].'.'.$e['column'],
            self::movable(),
        );
    }
}
