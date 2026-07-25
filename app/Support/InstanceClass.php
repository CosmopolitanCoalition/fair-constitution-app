<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The instance's CLASS (production | scale_demo), chosen at founding and stored
 * on instance_settings.instance_class.
 *
 * PRODUCTION — a real instance. It bears consent, it may federate, and it must
 * NEVER carry synthetic data. This is the default for every existing world.
 *
 * SCALE_DEMO — "the Attained": the same constitutional standard broadly
 * materialized into a full-scale illustration. Federation is off (CI-2: a demo
 * has received no consent, so it has nothing to federate). It is the ONLY class
 * on which the sim-world generators may write.
 *
 * ⚠ THIS IS NOT game_mode. `GameMode` (production|sandbox) asks whether the dev
 * toolbox is legitimate in this world. `InstanceClass` asks whether this world
 * is a government or an illustration. They are ORTHOGONAL — a sandbox world
 * still bears real consent semantics, and a scale_demo world may run with dev
 * tooling off. Never collapse them.
 *
 * Reads are defensive and FAIL CLOSED: a missing table / column / row, or a DB
 * that is down mid-migration, resolves to `production` — i.e. "no synthetic
 * data may be written here". The dangerous direction is a generator believing
 * it is on a demo instance when it is not, so uncertainty must never resolve to
 * scale_demo.
 */
class InstanceClass
{
    public const PRODUCTION = 'production';
    public const SCALE_DEMO = 'scale_demo';

    /** Cached per request — the gate is consulted by guards on several paths. */
    private static ?string $cached = null;

    private static bool $resolved = false;

    public static function current(): string
    {
        if (self::$resolved) {
            return self::$cached ?? self::PRODUCTION;
        }

        self::$resolved = true;
        self::$cached = self::PRODUCTION;

        try {
            if (! Schema::hasTable('instance_settings')
                || ! Schema::hasColumn('instance_settings', 'instance_class')) {
                return self::PRODUCTION;
            }

            $value = DB::table('instance_settings')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->value('instance_class');

            self::$cached = $value === self::SCALE_DEMO ? self::SCALE_DEMO : self::PRODUCTION;
        } catch (\Throwable) {
            // DB down / mid-migration — fail closed (no synthetic writes).
            self::$cached = self::PRODUCTION;
        }

        return self::$cached;
    }

    /**
     * Coerce an EXTERNAL, untrusted class value (a peer's advertised handshake
     * field) to one of the two known classes.
     *
     * Anything unrecognised — null, empty, a typo, a value from a future
     * release — reads as `production`. That is the fail-closed direction for
     * BOTH sides of the rule: a demo refuses to peer with an instance it cannot
     * positively identify as a demo, and a real instance's behaviour is
     * unchanged by a peer that says nothing (which is what every pre-Phase-O
     * instance says).
     */
    public static function normalize(mixed $value): string
    {
        return $value === self::SCALE_DEMO ? self::SCALE_DEMO : self::PRODUCTION;
    }

    /**
     * The class-scoped federation rule (operator ruling 2026-07-25): a demo
     * federates only with demos, a real instance only with real instances.
     * Symmetric by construction — this is plain equality, deliberately, so
     * neither side can be the lenient one.
     */
    public static function federatesWith(mixed $peerClass): bool
    {
        return self::normalize($peerClass) === self::current();
    }

    public static function isScaleDemo(): bool
    {
        return self::current() === self::SCALE_DEMO;
    }

    public static function isProduction(): bool
    {
        return self::current() === self::PRODUCTION;
    }

    /** Forget the per-request cache (tests, and right after founding). */
    public static function flush(): void
    {
        self::$resolved = false;
        self::$cached = null;
    }

    /**
     * TEST SEAM: force the resolved class without a DB read. Tests legitimately
     * construct the world state they exercise; production code never calls this.
     * Pair with flush() so an override cannot leak into a later test.
     */
    public static function override(?string $class): void
    {
        self::$resolved = true;
        self::$cached = $class;
    }
}
