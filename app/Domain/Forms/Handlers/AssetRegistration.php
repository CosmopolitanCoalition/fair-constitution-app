<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\AssetService;

/**
 * F-IND-024 — Asset Registration / Transfer (R-01). Art. I — freedom to
 * contract; the inventory half of the market.
 *
 * Operator ruling 2026-07-25, overturning my own recommendation to retire
 * this: it is not a property-rights regime, it is an INVENTORY MODEL. A
 * person registers a specific thing they made — a hand-woven blanket — and
 * that thing has its own identity, attributes and provenance. Required for
 * virtual AND physical goods, and it is the substrate the fantasy-map worlds
 * need.
 *
 * A DOOR ON A BUILT SERVICE. Everything goes through AssetService, which
 * writes the append-only `asset_transfers` provenance record. This handler
 * writes nothing itself.
 *
 * Two design points inherited from the service and worth restating, because
 * a form is where someone would be tempted to erode them:
 *   - PHYSICAL AND VIRTUAL ARE ONE FLAG, not two systems. Same table, same
 *     transfer path, same market.
 *   - `attributes` IS DELIBERATELY OPEN. A blanket carries materials and
 *     dimensions; a game item carries stats. The app does not get to
 *     enumerate what people may make — that is content, and enumerating it
 *     would be the engine deciding what a world can contain.
 *
 * Account-scoped, so it inherits the reader-privacy posture with no special
 * case: provenance shows a thing moved between ACCOUNTS, never between
 * named people.
 */
class AssetRegistration implements FormHandler
{
    public function __construct(
        private readonly AssetService $assets,
        private readonly AccountService $accounts,
    ) {
    }

    public function module(): string
    {
        return 'economy';
    }

    public function event(): string
    {
        return 'asset.registered';
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
                'An asset belongs to somebody — system filing is not defined.',
                'CGA Forms Catalog (F-IND-024)'
            );
        }

        $accountId = $this->accountFor($actor, $payload);

        // TRANSFER branch — hand a registered thing to another account.
        if (isset($payload['asset_id'])) {
            $toAccountId = (string) ($payload['to_account_id'] ?? '');

            if ($toAccountId === '') {
                throw new ConstitutionalViolation('A transfer names the receiving account.', 'CGA Forms Catalog (F-IND-024)');
            }

            $asset = \Illuminate\Support\Facades\DB::table('assets')
                ->where('id', (string) $payload['asset_id'])->first();

            if ($asset === null) {
                throw new ConstitutionalViolation('Unknown asset.', 'CGA Forms Catalog (F-IND-024)');
            }

            if ((string) $asset->owner_account_id !== $accountId) {
                throw new ConstitutionalViolation(
                    'You can only hand on a thing you hold.',
                    'Art. I · as implemented'
                );
            }

            try {
                $movedId = $this->assets->transfer(
                    (string) $payload['asset_id'],
                    $toAccountId,
                    isset($payload['quantity']) ? (string) $payload['quantity'] : null,
                );
            } catch (\InvalidArgumentException $e) {
                throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
            }

            return [
                'action'        => 'transferred',
                'asset_id'      => $movedId,
                'to_account_id' => $toAccountId,
            ];
        }

        // REGISTER branch — bring a new thing into the world.
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new ConstitutionalViolation('A thing needs a name.', 'CGA Forms Catalog (F-IND-024)');
        }

        $kind = (string) ($payload['kind'] ?? AssetService::KIND_PHYSICAL);

        try {
            $assetId = $this->assets->register(
                $accountId,
                $kind,
                $name,
                isset($payload['description']) ? (string) $payload['description'] : null,
                isset($payload['attributes']) && is_array($payload['attributes']) ? $payload['attributes'] : null,
                isset($payload['quantity']) ? (string) $payload['quantity'] : '1',
                isset($payload['origin']) ? (string) $payload['origin'] : 'crafted',
                isset($payload['jurisdiction_id']) ? (string) $payload['jurisdiction_id'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new ConstitutionalViolation($e->getMessage(), 'Art. I · as implemented');
        }

        return [
            'action'     => 'registered',
            'asset_id'   => $assetId,
            'kind'       => $kind,
            'name'       => $name,
            'account_id' => $accountId,
        ];
    }

    private function accountFor(User $actor, array $payload): string
    {
        $currency = $this->currency($payload);

        $accountId = $this->accounts->accountIdFor('users', (string) $actor->id, $currency->id);

        if ($accountId === null) {
            throw new ConstitutionalViolation(
                'You have no account in this world yet — one opens with confirmed residency.',
                'Art. I · as implemented'
            );
        }

        return $accountId;
    }

    private function currency(array $payload): Currency
    {
        if (isset($payload['currency_id'])) {
            $currency = Currency::query()->find((string) $payload['currency_id']);
        } else {
            $rootId = \Illuminate\Support\Facades\DB::table('jurisdictions')
                ->whereNull('parent_id')->whereNull('deleted_at')->value('id');

            $currency = $rootId === null
                ? null
                : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();
        }

        if ($currency === null) {
            throw new ConstitutionalViolation(
                'This world has no currency yet — the root jurisdiction defines one (Art. V §5).',
                'Art. V §5'
            );
        }

        return $currency;
    }
}
