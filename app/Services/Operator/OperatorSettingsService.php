<?php

namespace App\Services\Operator;

use App\Models\InstanceSettings;
use InvalidArgumentException;
use Throwable;

/**
 * Operator Operations console (Phase 2) — the INSTANT-tier override store. Holds the
 * operator's overrides for the no-trust-impact federation tuning knobs and overlays
 * them onto config() at boot (InfraOverridesServiceProvider), so an edit applies on
 * the NEXT request with no container restart and no change to any read site. Every
 * knob is whitelisted with a type + bounds — nothing outside the whitelist can be set,
 * and a value is validated before it is stored. An absent override = the env/config
 * default, byte-for-byte unchanged.
 */
class OperatorSettingsService
{
    /**
     * The editable instant-tier knobs: console key => { the config path it overlays,
     * its type + bounds }. ONLY these may be set.
     */
    public const KNOBS = [
        'heartbeat_minutes' => ['config' => 'cga.federation_heartbeat_minutes', 'type' => 'int', 'min' => 1, 'max' => 1440],
        'http_timeout' => ['config' => 'cga.federation_http_timeout_seconds', 'type' => 'int', 'min' => 5, 'max' => 600],
        'sync_page_size' => ['config' => 'cga.federation_sync_page_size', 'type' => 'int', 'min' => 10, 'max' => 5000],
        'geodata_origin' => ['config' => 'cga.geodata_origin', 'type' => 'string', 'max' => 255, 'nullable' => true],
        // The foundation seed transport — the no-code fallback lever: flip to 'tarball' here if the
        // paginated drain ever misbehaves, with no restart and no command. Read at seed time.
        'seed_transport' => ['config' => 'cga.federation_seed_transport', 'type' => 'string', 'enum' => ['paginated', 'tarball']],
    ];

    public function isEditable(string $key): bool
    {
        return array_key_exists($key, self::KNOBS);
    }

    /** @return array<string,mixed> the raw stored overrides (console key => value) */
    public function all(): array
    {
        $o = InstanceSettings::current()->infra_overrides;

        return is_array($o) ? $o : [];
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** Validate + store an override. Throws on an unknown key or an out-of-bounds value. */
    public function set(string $key, mixed $raw): void
    {
        if (! $this->isEditable($key)) {
            throw new InvalidArgumentException("'{$key}' is not an editable instant-tier knob.");
        }
        $value = $this->coerce($key, $raw);

        $settings = InstanceSettings::current();
        $overrides = is_array($settings->infra_overrides) ? $settings->infra_overrides : [];
        $overrides[$key] = $value;
        $settings->infra_overrides = $overrides;
        $settings->save();
    }

    /** Remove an override — the knob reverts to its env/config default on the next request. */
    public function clear(string $key): void
    {
        $settings = InstanceSettings::current();
        $overrides = is_array($settings->infra_overrides) ? $settings->infra_overrides : [];
        unset($overrides[$key]);
        $settings->infra_overrides = $overrides === [] ? null : $overrides;
        $settings->save();
    }

    /**
     * Overlay the stored overrides onto the live config repository — so every existing
     * config('cga.…') read transparently picks them up. Defensive: a missing table /
     * unreachable DB (pre-migration boot) must never crash boot.
     */
    public function overlay(): void
    {
        try {
            foreach ($this->all() as $key => $value) {
                $spec = self::KNOBS[$key] ?? null;
                if ($spec === null) {
                    continue; // a stale key from an older build — ignore
                }
                config([$spec['config'] => $value]);
            }
        } catch (Throwable) {
            // boot must survive a missing instance_settings table / unreachable DB
        }
    }

    private function coerce(string $key, mixed $raw): mixed
    {
        $spec = self::KNOBS[$key];

        if (($spec['type'] ?? null) === 'int') {
            if (! is_numeric($raw)) {
                throw new InvalidArgumentException("'{$key}' must be a number.");
            }
            $v = (int) $raw;
            $min = (int) ($spec['min'] ?? PHP_INT_MIN);
            $max = (int) ($spec['max'] ?? PHP_INT_MAX);
            if ($v < $min || $v > $max) {
                throw new InvalidArgumentException("'{$key}' must be between {$min} and {$max}.");
            }

            return $v;
        }

        // string
        $v = trim((string) $raw);
        if ($v === '') {
            if (! ($spec['nullable'] ?? false)) {
                throw new InvalidArgumentException("'{$key}' may not be blank.");
            }

            return null;
        }
        if (isset($spec['enum']) && ! in_array($v, $spec['enum'], true)) {
            throw new InvalidArgumentException("'{$key}' must be one of: ".implode(', ', $spec['enum']).'.');
        }
        $max = (int) ($spec['max'] ?? 255);
        if (mb_strlen($v) > $max) {
            throw new InvalidArgumentException("'{$key}' must be at most {$max} characters.");
        }

        return $v;
    }
}
