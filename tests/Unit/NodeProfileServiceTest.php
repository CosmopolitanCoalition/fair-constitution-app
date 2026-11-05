<?php

namespace Tests\Unit;

use App\Services\Node\NodeProfileService;
use PHPUnit\Framework\TestCase;

/** W-0490 - the node profile rules, without a database. */
class NodeProfileServiceTest extends TestCase
{
    public function test_individual_is_always_on_and_first(): void
    {
        $r = (new NodeProfileService())->normalize(['community', 'household'], []);

        $this->assertSame(['individual', 'household', 'community'], $r['hosted']);
    }

    public function test_unknown_kits_are_dropped_and_duplicates_collapse(): void
    {
        $r = (new NodeProfileService())->normalize(['household', 'household', 'empire'], null);

        $this->assertSame(['individual', 'household'], $r['hosted']);
    }

    public function test_community_gets_a_mode_and_defaults_to_simulation(): void
    {
        $svc = new NodeProfileService();

        $this->assertSame('simulation', $svc->normalize(['community'], [])['scopes']['community']['mode']);
        $this->assertSame('real', $svc->normalize(['community'], ['community' => ['mode' => 'real']])['scopes']['community']['mode']);
        $this->assertSame('simulation', $svc->normalize(['community'], ['community' => ['mode' => 'bogus']])['scopes']['community']['mode']);
    }

    public function test_scope_is_kept_only_for_hosted_kits_and_trimmed(): void
    {
        $r = (new NodeProfileService())->normalize(['organization'], [
            'organization' => ['scope' => '  Clubs in town  '],
            'household' => ['scope' => 'ignored'],
        ]);

        $this->assertSame('Clubs in town', $r['scopes']['organization']['scope']);
        $this->assertArrayNotHasKey('household', $r['scopes']);
    }

    public function test_save_rejects_unknown_kit_and_long_scope(): void
    {
        $svc = new NodeProfileService();

        $this->expectException(\InvalidArgumentException::class);
        $svc->save(['empire'], []);
    }
}
