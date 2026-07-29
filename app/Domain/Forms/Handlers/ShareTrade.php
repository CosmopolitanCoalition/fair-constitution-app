<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Economy\ShareTradeService;
use InvalidArgumentException;
use RuntimeException;

/**
 * F-IND-021 — Share Trade (R-01; Wave 4 ②).
 *
 * Holder-to-holder equity RESALE on the exchange. A person trading their OWN
 * shares is an INDIVIDUAL act (R-01), not an org-agent act — which is exactly
 * why this is F-IND, not an F-ORG-008 action: the seller is a shareholder, not
 * the organization's agent (operator ruled the mint 2026-07-29).
 *
 * Action-dispatched: offer_shares / buy_shares / cancel_offer. Every write goes
 * through ShareTradeService (the one write core) and files through the engine
 * like every other form — there is no second economy write API. A REFUSAL IS AN
 * ANSWER: selling more than you hold, or buying without funds, returns a
 * ConstitutionalViolation with its citation, and both legs of a buy roll back
 * together.
 *
 * TWO PLANES: the units move on the NAMED ownership plane (Ruling B); the money
 * moves on the pseudonymous account plane. This handler never joins them.
 */
class ShareTrade implements FormHandler
{
    public function __construct(private ShareTradeService $trades) {}

    public function module(): string
    {
        return 'economy';
    }

    public function event(): string
    {
        return 'economy.share_trade';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'A share trade is an act of a person — the system trades none.',
                'CGA Forms Catalog (F-IND-021)'
            );
        }

        $action = (string) ($payload['action'] ?? '');

        $result = $this->wrap(fn () => match ($action) {
            'offer_shares' => $this->trades->offer(
                $actor,
                (string) ($payload['organization_id'] ?? ''),
                (float) ($payload['units'] ?? 0),
                (string) ($payload['price_per_unit'] ?? '0'),
            ),
            'buy_shares'   => $this->trades->buy($actor, (string) ($payload['offer_id'] ?? '')),
            'cancel_offer' => $this->trades->cancel($actor, (string) ($payload['offer_id'] ?? '')),
            default        => throw new ConstitutionalViolation(
                "Unknown F-IND-021 action [{$action}].",
                'CGA Forms Catalog (F-IND-021)'
            ),
        });

        return ['action' => $action] + $result;
    }

    /**
     * Turn a service RuntimeException/InvalidArgumentException into a
     * constitutional refusal; let a ConstitutionalViolation carry its own
     * citation through untouched.
     *
     * @param  callable():array<string, mixed>  $fn
     * @return array<string, mixed>
     */
    private function wrap(callable $fn): array
    {
        try {
            return $fn();
        } catch (ConstitutionalViolation $e) {
            throw $e;
        } catch (RuntimeException | InvalidArgumentException $e) {
            throw new ConstitutionalViolation($e->getMessage(), 'CGA Forms Catalog (F-IND-021)');
        }
    }
}
