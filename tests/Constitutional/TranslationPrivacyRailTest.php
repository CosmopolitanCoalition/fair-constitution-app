<?php

namespace Tests\Constitutional;

use App\Models\MatrixRoom;
use App\Services\Matrix\Translation\LocalStubTranslationProvider;
use App\Services\Matrix\Translation\TranslationGate;
use App\Services\Matrix\Translation\TranslationProvider;
use Mockery;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-K), the TRANSLATION PRIVACY RAIL. Public-room messages are
 * server-translatable; a PRIVATE room's content may NEVER be sent to a CLOUD translator. The rail is
 * enforced BEFORE the provider is touched — a refusal leaks no source text, not even an attempt. It is
 * content-neutral + structural (privacy derived from the room row) and FAILS CLOSED (unknown / tombstoned
 * room → treated private). A local / on-device provider is admissible everywhere.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class TranslationPrivacyRailTest extends TestCase
{
    private const SECRET = 'A private confession that must never leave the box.';

    public function test_a_cloud_provider_is_refused_on_a_private_room_without_seeing_the_text(): void
    {
        // The cloud provider must NEVER be asked to translate a private room's content.
        $cloud = Mockery::mock(TranslationProvider::class);
        $cloud->shouldReceive('isCloud')->andReturnTrue();
        $cloud->shouldReceive('translate')->never();

        $gate = new TranslationGate($cloud);
        $result = $gate->translate($this->privateRoom(), self::SECRET, 'es');

        $this->assertFalse($result->admitted, 'a private room is never cloud-translated');
        $this->assertNull($result->translated);
        $this->assertNotNull($result->reason);
        $this->assertStringNotContainsString(self::SECRET, (string) $result->reason, 'the source text never appears in the refusal');
    }

    public function test_a_cloud_provider_is_allowed_on_a_public_room(): void
    {
        $cloud = Mockery::mock(TranslationProvider::class);
        $cloud->shouldReceive('isCloud')->andReturnTrue();
        $cloud->shouldReceive('name')->andReturn('fake-cloud');
        $cloud->shouldReceive('translate')->once()->andReturn('una confesión');

        $gate = new TranslationGate($cloud);
        $result = $gate->translate($this->publicRoom(), self::SECRET, 'es');

        $this->assertTrue($result->admitted, 'a public room is server-translatable');
        $this->assertSame('una confesión', $result->translated);
        $this->assertSame('fake-cloud', $result->provider);
    }

    public function test_a_local_provider_is_admissible_on_both_private_and_public_rooms(): void
    {
        $gate = new TranslationGate(new LocalStubTranslationProvider());

        $this->assertTrue($gate->translate($this->privateRoom(), 'hola', 'en')->admitted, 'on-box translation is fine for a private room');
        $this->assertTrue($gate->translate($this->publicRoom(), 'hola', 'en')->admitted);
    }

    public function test_the_rail_fails_closed_for_unknown_and_tombstoned_rooms(): void
    {
        $cloud = Mockery::mock(TranslationProvider::class);
        $cloud->shouldReceive('isCloud')->andReturnTrue();
        $cloud->shouldReceive('translate')->never();
        $gate = new TranslationGate($cloud);

        // Unknown room (null — e.g. not in matrix_rooms) → treated private.
        $this->assertFalse($gate->translate(null, self::SECRET, 'es')->admitted, 'an unknown room fails closed');

        // A tombstoned room → treated private.
        $tombstoned = $this->publicRoom();
        $tombstoned->tombstoned_at = now();
        $this->assertFalse($gate->translate($tombstoned, self::SECRET, 'es')->admitted, 'a tombstoned room fails closed');

        // The structural privacy predicate is correct for an org-private room (public flag alone isn't enough).
        $orgPrivate = new MatrixRoom(['is_public' => true, 'room_type' => MatrixRoom::ROOM_ORG_PRIVATE]);
        $this->assertTrue($gate->isPrivate($orgPrivate), 'an org-private room is private even if flagged public');
    }

    private function privateRoom(): MatrixRoom
    {
        return new MatrixRoom(['is_public' => false, 'room_type' => MatrixRoom::ROOM_ORG_PRIVATE]);
    }

    private function publicRoom(): MatrixRoom
    {
        return new MatrixRoom(['is_public' => true, 'room_type' => MatrixRoom::ROOM_COMMONS]);
    }
}
