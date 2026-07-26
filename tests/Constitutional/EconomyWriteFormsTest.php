<?php

namespace Tests\Constitutional;

use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\Handlers\AssetRegistration;
use App\Domain\Forms\Handlers\FundsTransfer;
use App\Domain\Forms\Handlers\MarketplaceListingOrder;
use ReflectionClass;
use Tests\TestCase;

/**
 * F-IND-022/023/024 — the economy's write path.
 *
 * These forms exist because the economy was fully built and entirely
 * unusable: every service written, tested, and driven end to end by
 * `institutions:demo-treasury`, with no constitutional door. A playtester
 * could read the economy and could not act in it. `mutual-aid` sat at 0 of 4
 * walkable steps for exactly this reason.
 *
 * THE PROPERTY THESE PINS PROTECT: the forms are DOORS, never SHORTCUTS.
 * Every action routes through the real service, so the rails the services
 * already enforce cannot be bypassed by filing a form instead — no
 * overdraft, no unbalanced posting, no writing a balance or a ledger row
 * directly, no inventing an owner for an account.
 */
class EconomyWriteFormsTest extends TestCase
{
    private const HANDLERS = [
        'F-IND-022' => MarketplaceListingOrder::class,
        'F-IND-023' => FundsTransfer::class,
        'F-IND-024' => AssetRegistration::class,
    ];

    private function sourceOf(string $class): string
    {
        return file_get_contents((new ReflectionClass($class))->getFileName());
    }

    public function test_all_three_forms_are_registered_and_dispatchable(): void
    {
        foreach (self::HANDLERS as $formId => $expected) {
            $this->assertSame(
                $formId,
                FormRegistry::canonical($formId),
                "{$formId} must be a canonical form id."
            );

            $this->assertTrue(
                FormRegistry::exists($formId),
                "{$formId} must exist in the catalog — an unregistered form has no door."
            );
        }
    }

    /** Every one is a resident's act. None may be filed by the system. */
    public function test_they_are_resident_acts_and_never_system_filed(): void
    {
        foreach (self::HANDLERS as $formId => $class) {
            $handler = app($class);

            $this->assertSame(['R-01'], $handler->requiredRoles(), "{$formId} is an R-01 act.");
            $this->assertFalse(
                $handler->systemOnly(),
                "{$formId} must not be system-only — buying, paying and making things are things PEOPLE do."
            );
        }
    }

    /**
     * THE PIN. A handler must not write the economy's planes directly — that
     * is how a rail gets bypassed. Money moves through AccountService,
     * things move through AssetService, sales through MarketplaceService,
     * and all three post to the ledger through LedgerService.
     */
    public function test_no_handler_writes_the_economy_planes_directly(): void
    {
        $forbidden = [
            "table('ledger_entries')"     => 'the ledger has exactly one writer, LedgerService',
            "table('economic_accounts')"  => 'balances move only through AccountService',
            "table('market_transactions')" => 'transactions are written by the service that posts them',
            "table('ubi_receipts')"       => 'receipts belong to the stipend run',
            "table('issuance_events')"    => 'money comes into existence only through IssuanceService',
            'DB::update'                  => 'a form must not hand-write state',
        ];

        foreach (self::HANDLERS as $formId => $class) {
            $source = $this->sourceOf($class);

            foreach ($forbidden as $needle => $why) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$formId} must not contain [{$needle}] — {$why}."
                );
            }
        }
    }

    /** Each handler must actually call its service — a door with nothing behind it is worse than none. */
    public function test_each_handler_routes_through_its_real_service(): void
    {
        $expected = [
            MarketplaceListingOrder::class => ['$this->market->list', '$this->market->order', '$this->market->settle'],
            FundsTransfer::class           => ['$this->accounts->transfer'],
            AssetRegistration::class       => ['$this->assets->register', '$this->assets->transfer'],
        ];

        foreach ($expected as $class => $calls) {
            $source = $this->sourceOf($class);

            foreach ($calls as $call) {
                $this->assertStringContainsString(
                    $call,
                    $source,
                    basename(str_replace('\\', '/', $class)) . " must route through {$call}."
                );
            }
        }
    }

    /**
     * A service refusal is the CONSTITUTIONAL answer, not a crash. An empty
     * wallet, a closed listing, buying your own goods — each must reach the
     * filer as a ConstitutionalViolation with a citation, so the engine
     * records a reasoned rejection rather than a 500.
     */
    public function test_service_refusals_surface_as_constitutional_rejections(): void
    {
        foreach (self::HANDLERS as $formId => $class) {
            $source = $this->sourceOf($class);

            $this->assertStringContainsString(
                'InvalidArgumentException',
                $source,
                "{$formId} must catch the service's refusals rather than let them 500."
            );

            $this->assertStringContainsString(
                'ConstitutionalViolation',
                $source,
                "{$formId} must re-raise a refusal WITH a citation — a rejection is an answer."
            );
        }
    }

    /**
     * PRIVACY (operator ruling: reader privacy, like a ballot). A filer names
     * an ACCOUNT, never a person. The only lawful identity lookup is the
     * filer resolving their OWN account, so a write form can never be used
     * to discover who owns one.
     */
    public function test_no_form_takes_a_person_as_a_counterparty(): void
    {
        foreach (self::HANDLERS as $formId => $class) {
            $source = $this->sourceOf($class);

            foreach (["payload['to_user_id']", "payload['user_id']", "payload['recipient_email']"] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$formId} must not accept [{$needle}] — counterparties are accounts, never people."
                );
            }

            $this->assertStringContainsString(
                "accountIdFor('users'",
                $source,
                "{$formId} must resolve the FILER's own account — the one lawful identity lookup."
            );
        }
    }

    /** Only the seller settles. A buyer settling their own order takes the goods. */
    public function test_a_buyer_cannot_settle_their_own_purchase(): void
    {
        $source = $this->sourceOf(MarketplaceListingOrder::class);

        $this->assertStringContainsString(
            'Only the seller accepts an order',
            $source,
            'Settlement must be the seller\'s act.'
        );
    }
}
