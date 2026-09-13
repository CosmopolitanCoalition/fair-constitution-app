<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\ShareTrade;
use App\Models\User;
use App\Services\Economy\ShareTradeService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** No DB or action calls: pin the exact input boundary into the real form handler. */
final class ShareTradeQuantityTest extends TestCase
{
    public static function exactQuantities(): array
    {
        return [['99999999999999.123456', '99999999999999.123456'], ['0.000001', '0.000001'], ['1', '1.000000'], [0.1, '0.100000']];
    }

    #[DataProvider('exactQuantities')]
    public function test_handler_preserves_decimal_quantities_and_prices(mixed $input, string $expected): void
    {
        $actor = (new User)->forceFill(['id' => '60000000-0000-4000-8000-000000000001']);
        $service = $this->createMock(ShareTradeService::class);
        $service->expects($this->once())->method('offer')->with($actor, 'fixture-org', $expected, '999999999999999999.123456')
            ->willReturn(['offer_id' => 'fixture-offer', 'units' => $expected]);
        $result = (new ShareTrade($service))->handle($actor, ['action' => 'offer_shares', 'organization_id' => 'fixture-org',
            'units' => $input, 'price_per_unit' => '999999999999999999.123456']);
        self::assertSame($expected, $result['units']);
    }

    public static function invalidQuantities(): array
    {
        return [[null], [[]], [''], ['-1'], ['0'], ['0.0000001'], ['100000000000000'], ['1e5'], ['abc'], [INF], [NAN]];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_are_civic_refusals_before_service_work(mixed $input): void
    {
        $service = $this->createMock(ShareTradeService::class); $service->expects($this->never())->method('offer');
        $this->expectException(ConstitutionalViolation::class);
        (new ShareTrade($service))->handle((new User)->forceFill(['id' => 'fixture']), ['action' => 'offer_shares', 'units' => $input]);
    }
}
