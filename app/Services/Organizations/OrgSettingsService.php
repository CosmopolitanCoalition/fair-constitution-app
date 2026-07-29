<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Organizational self-governance dials (operator v3.2 item 0d) — stored on
 * organizations.settings (jsonb), NEVER in constitutional_settings: these
 * are an organization's own rules about itself, and the operator's ruling
 * is explicit that they are not constitutional values.
 *
 * ROLE-GATED: only the organization's agent or a seated member of its board
 * may change one. Every change is appended to the audit chain — a dial an
 * org turns is still a public act of self-governance.
 *
 * The key registry is closed: an unknown key refuses. First key:
 *
 *   board_nomination_window_days — how many days the nomination (approval)
 *   phase of a BOARD election runs before ranking opens. Bounded 1..90;
 *   absent means the jurisdiction's default schedule applies unchanged.
 */
class OrgSettingsService
{
    /** @var array<string, array{type: string, min: int, max: int}> */
    public const KEYS = [
        'board_nomination_window_days' => ['type' => 'int', 'min' => 1, 'max' => 90],
    ];

    public function __construct(private AuditService $audit) {}

    public function get(Organization $org, string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new InvalidArgumentException("Unknown organization setting [{$key}].");
        }

        $settings = $org->settings;

        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?? [];
        }

        return $settings[$key] ?? $default;
    }

    public function set(Organization $org, string $key, mixed $value, User $actor): void
    {
        if (! array_key_exists($key, self::KEYS)) {
            throw new InvalidArgumentException("Unknown organization setting [{$key}].");
        }

        $this->assertMaySteer($org, $actor);

        $rule = self::KEYS[$key];

        if ($rule['type'] === 'int') {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("[{$key}] takes a whole number.");
            }

            $value = (int) $value;

            if ($value < $rule['min'] || $value > $rule['max']) {
                throw new InvalidArgumentException(
                    "[{$key}] must be between {$rule['min']} and {$rule['max']}."
                );
            }
        }

        $settings = $org->settings;
        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?? [];
        }
        $settings = ($settings ?? []);
        $previous = $settings[$key] ?? null;
        $settings[$key] = $value;

        DB::table('organizations')->where('id', $org->id)->update([
            'settings'   => json_encode($settings),
            'updated_at' => now(),
        ]);

        $this->audit->append(
            module: 'organizations',
            event: 'org.setting_changed',
            payload: [
                'organization_id' => (string) $org->id,
                'key'             => $key,
                'from'            => $previous,
                'to'              => $value,
            ],
            ref: 'v3.2-0d',
            actorId: (string) $actor->id,
            jurisdictionId: $org->jurisdiction_id === null ? null : (string) $org->jurisdiction_id,
        );
    }

    /**
     * The gate: the org's agent, or a seated member of its board. An
     * organization's dials are turned by the people it answers to — never
     * by an outsider, and never silently.
     */
    private function assertMaySteer(Organization $org, User $actor): void
    {
        if ((string) $org->agent_user_id === (string) $actor->id) {
            return;
        }

        $seated = $org->board_id !== null && DB::table('board_seats')
            ->where('board_id', $org->board_id)
            ->where('holder_user_id', (string) $actor->id)
            ->where('status', 'seated')
            ->whereNull('deleted_at')
            ->exists();

        if (! $seated) {
            throw new RuntimeException(
                'Only the organization\'s agent or a seated board member changes its settings.'
            );
        }
    }
}
