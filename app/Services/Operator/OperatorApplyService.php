<?php

namespace App\Services\Operator;

use App\Services\AuditService;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Operator Operations console (Phase 3) — the RESTART-tier host-apply protocol. Some
 * knobs (LiveKit ICE networking) are env/yaml-baked and need a `.env` rewrite + a
 * container recreate, which the app CANNOT do from inside its own container. So the
 * console writes a desired-state CONTROL FILE; a host-side supervisor
 * (scripts/ops/infra_supervisor.py, run by the operator — same pattern as the ETL
 * supervisor) rewrites `.env` and recreates the named service, writing status back.
 *
 * Hard guards:
 *   • a STRICT whitelist — only the LiveKit ICE networking knobs may be applied;
 *   • per-key VALUE validation (IP / ws-URL) before anything is written;
 *   • NO SECRETS through this path — api keys/secrets/tokens are deliberately excluded
 *     (rotating them safely is gated on the credential-security pass).
 */
class OperatorApplyService
{
    /**
     * The applyable restart-tier knobs: env var => { validator, the compose service to
     * recreate, a label }. ONLY these may be written. No secrets, ever.
     */
    public const APPLYABLE = [
        'LIVEKIT_NODE_IP' => ['validate' => 'ip', 'recreate' => 'livekit', 'label' => 'LiveKit ICE node IP'],
        'LIVEKIT_PUBLIC_URL' => ['validate' => 'wsurl', 'recreate' => 'livekit', 'label' => 'LiveKit public (browser) URL'],
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function isApplyable(string $key): bool
    {
        return array_key_exists($key, self::APPLYABLE);
    }

    private function controlDir(): string
    {
        // Config-overridable so tests use an isolated dir and never touch the real control files.
        return (string) config('cga.ops_control_path', base_path('scripts/ops/control'));
    }

    /**
     * Validate + stage an apply request as the control file the host supervisor consumes.
     * Throws on an unknown key or an invalid value — nothing is written unless the whole
     * request is clean.
     *
     * @param  array<string,mixed>  $changes  env var => new value
     * @return array<string,mixed> the staged request payload
     */
    public function requestApply(array $changes): array
    {
        $clean = [];
        $recreate = [];
        foreach ($changes as $key => $val) {
            if (! $this->isApplyable($key)) {
                throw new InvalidArgumentException("'{$key}' is not an applyable infra knob.");
            }
            $spec = self::APPLYABLE[$key];
            $clean[$key] = $this->validateValue($key, (string) $val, $spec['validate']);
            $recreate[$spec['recreate']] = true;
        }
        if ($clean === []) {
            throw new InvalidArgumentException('No changes to apply.');
        }

        $dir = $this->controlDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create the ops control directory.');
        }

        $payload = [
            'id' => (string) Str::uuid(),
            'requested_at' => now()->toIso8601String(),
            'changes' => $clean,
            'recreate' => array_keys($recreate),
        ];

        // Fresh slate: clear any prior terminal/running state, then stage the request.
        foreach (['running.json', 'done.json', 'failed.json'] as $f) {
            @unlink($dir.'/'.$f);
        }
        file_put_contents($dir.'/request.json', (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->audit->append('operator', 'infra.apply.requested', [
            'id' => $payload['id'],
            'keys' => array_keys($clean), // the KEYS only — never echo a value that might be sensitive
            'recreate' => $payload['recreate'],
        ], 'OPS');

        return $payload;
    }

    /**
     * The current apply lifecycle, read from the control files the host supervisor writes.
     *   idle → pending (request staged, supervisor hasn't picked it up) → applying →
     *   applied | failed.
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $dir = $this->controlDir();
        $read = function (string $f) use ($dir): ?array {
            $p = $dir.'/'.$f;
            if (! is_file($p)) {
                return null;
            }
            $d = json_decode((string) @file_get_contents($p), true);

            return is_array($d) ? $d : null;
        };

        $request = $read('request.json');
        $running = $read('running.json');
        $done = $read('done.json');
        $failed = $read('failed.json');

        [$lifecycle, $active] = match (true) {
            $failed !== null => ['failed', $failed],
            $done !== null => ['applied', $done],
            $running !== null => ['applying', $running],
            $request !== null => ['pending', $request],
            default => ['idle', null],
        };

        return [
            'lifecycle' => $lifecycle,
            'active' => $active,
            'error' => $failed['error'] ?? null,
            // A request sitting 'pending' with no supervisor file means the host
            // supervisor probably isn't running — the UI surfaces that hint.
            'supervisor_seen' => $running !== null || $done !== null || $failed !== null,
            'applyable' => array_map(
                fn ($k, $s) => ['key' => $k, 'label' => $s['label'], 'recreate' => $s['recreate']],
                array_keys(self::APPLYABLE),
                array_values(self::APPLYABLE),
            ),
        ];
    }

    private function validateValue(string $key, string $value, string $kind): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("'{$key}' may not be blank.");
        }

        switch ($kind) {
            case 'ip':
                if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                    throw new InvalidArgumentException("'{$key}' must be a valid IP address.");
                }

                return $value;

            case 'wsurl':
                $scheme = parse_url($value, PHP_URL_SCHEME);
                $host = parse_url($value, PHP_URL_HOST);
                if (! in_array($scheme, ['ws', 'wss'], true) || ! $host) {
                    throw new InvalidArgumentException("'{$key}' must be a ws:// or wss:// URL.");
                }

                return $value;

            default:
                throw new InvalidArgumentException("Unknown validator for '{$key}'.");
        }
    }
}
