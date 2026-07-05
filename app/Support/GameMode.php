<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * The world's game mode (production | sandbox), chosen once at founding and
 * stored on instance_settings.game_mode.
 *
 * SANDBOX/DEV — operates WITHOUT constitutional hardening: the dev toolbox may
 * manufacture qualifications, assume any role, and take any user's perspective.
 * This is a WORLD property, not a code flag — a sandbox world is the ONLY place
 * the dev tooling is legitimate ([[feedback_no_dev_exceptions_and_test_discipline]]).
 *
 * PRODUCTION — operates within the constitutional constraints set at founding;
 * after setup the operator plane hands off to the constitutional plane.
 *
 * Reads are defensive: the gate is consulted on early requests (including
 * before the schema exists), so a missing table / column / row resolves to
 * "no mode chosen" → not sandbox → dev tooling stays 404.
 */
class GameMode
{
    public const PRODUCTION = 'production';
    public const SANDBOX    = 'sandbox';

    /** Cached per request — the gate can be consulted on several routes. */
    private static ?string $cached = null;
    private static bool $resolved = false;

    public static function current(): ?string
    {
        if (self::$resolved) {
            return self::$cached;
        }
        self::$resolved = true;
        self::$cached   = null;

        try {
            if (! Schema::hasTable('instance_settings')
                || ! Schema::hasColumn('instance_settings', 'game_mode')) {
                return null;
            }
            $value = DB::table('instance_settings')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->value('game_mode');
            self::$cached = $value ?: null;
        } catch (\Throwable $e) {
            // DB down / mid-migration — fail closed (no sandbox powers).
            self::$cached = null;
        }

        return self::$cached;
    }

    public static function isSandbox(): bool
    {
        return self::current() === self::SANDBOX;
    }

    public static function isProduction(): bool
    {
        return self::current() === self::PRODUCTION;
    }

    /** Forget the per-request cache (used by tests and right after the founder picks a mode). */
    public static function flush(): void
    {
        self::$resolved = false;
        self::$cached   = null;
    }

    /**
     * TEST SEAM: force the resolved mode without a DB read. Tests legitimately
     * construct the world state they exercise (a sandbox vs production world);
     * production code never calls this. Pair with flush() to return to
     * DB-resolved behavior so the override never leaks into a later test.
     */
    public static function override(?string $mode): void
    {
        self::$resolved = true;
        self::$cached   = $mode;
    }
}
