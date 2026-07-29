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
 * The key registry is closed: an unknown key refuses. Keys:
 *
 *   board_nomination_window_days — how many days the nomination (approval)
 *   phase of a BOARD election runs before ranking opens. Bounded 1..90;
 *   absent means the jurisdiction's default schedule applies unchanged.
 *
 *   dues_amount / dues_period_days — the org's membership-dues POLICY
 *   (Design Round 2, ②; operator ruling 2026-07-29). Dues are a membership
 *   subscription obligation, NOT a tax and NOT a share system: a member's
 *   obligation derives from active membership + this policy, and a payment
 *   is an ordinary F-IND-023 transfer with kind='dues'. ABSENCE IS HONEST —
 *   an org with no dues_amount charges no dues, and the page says so. Dues
 *   can never gate a civic right (Art. I / Art. II §8): they are voluntary,
 *   and a lapse ends membership without withholding any right. There is no
 *   dues engine and no scheduler — a member pays each period themselves.
 *   dues_amount is a money string (numeric(24,6)); dues_period_days is the
 *   cadence in days (30 ≈ monthly).
 */
class OrgSettingsService
{
    /** @var array<string, array{type: string, min: float|int, max: float|int}> */
    public const KEYS = [
        'board_nomination_window_days' => ['type' => 'int',     'min' => 1, 'max' => 90],
        'dues_amount'                  => ['type' => 'decimal', 'min' => 0, 'max' => 1_000_000],
        'dues_period_days'             => ['type' => 'int',     'min' => 1, 'max' => 3650],
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
        } elseif ($rule['type'] === 'decimal') {
            if (! is_numeric($value)) {
                throw new InvalidArgumentException("[{$key}] takes a number.");
            }

            if ((float) $value < $rule['min'] || (float) $value > $rule['max']) {
                throw new InvalidArgumentException(
                    "[{$key}] must be between {$rule['min']} and {$rule['max']}."
                );
            }

            // Store as a canonical money string (numeric(24,6)) so a formatter
            // never has to arithmetic it — the prop contract's money rule.
            $value = sprintf('%.6f', (float) $value);
        }

        // Merge onto the PERSISTED settings, not the in-memory model: a stale
        // Organization instance (two sets in one request, or a model loaded
        // before an earlier change) would otherwise clobber a sibling key.
        $current  = DB::table('organizations')->where('id', $org->id)->value('settings');
        $settings = is_string($current) ? (json_decode($current, true) ?? []) : (is_array($current) ? $current : []);
        $previous = $settings[$key] ?? null;
        $settings[$key] = $value;

        DB::table('organizations')->where('id', $org->id)->update([
            'settings'   => json_encode($settings),
            'updated_at' => now(),
        ]);

        // Keep the passed model coherent so a get() on it after set() is true.
        $org->settings = $settings;

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
