<?php

namespace Tests\Unit;

use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Rooms\PublicRoomNames;
use Tests\TestCase;

class LiveKitPublicNameTest extends TestCase
{
    public function test_public_label_does_not_change_identity_room_or_permissions(): void
    {
        config(['matrix.livekit.api_key' => 'test-key', 'matrix.livekit.api_secret' => 'test-secret']);
        $identity = '@u-123456abcdef:example.test';
        $names = \Mockery::mock(PublicRoomNames::class);
        $names->shouldReceive('forHandles')->once()->with([$identity])->andReturn([$identity => 'Public pseudonym']);
        $service = new LiveKitTokenService(\Mockery::mock(MatrixPostingGateService::class), $names);
        $token = $service->mintAccessToken($identity, '!hearing:example.test');
        $claims = $service->verify($token['token']);

        $this->assertSame('Public pseudonym', $claims['name']);
        $this->assertSame($identity, $claims['sub']);
        $this->assertSame('!hearing:example.test', $claims['video']['room']);
        $this->assertArrayNotHasKey('roomAdmin', $claims['video']);
        $this->assertArrayNotHasKey('canUpdateOwnMetadata', $claims['video']);
    }

    public function test_missing_profile_keeps_the_pseudonymous_identity(): void
    {
        $identity = '@u-123456abcdef:example.test';
        $names = \Mockery::mock(PublicRoomNames::class);
        $names->shouldReceive('forHandles')->once()->with([$identity])->andReturn([]);
        $service = new LiveKitTokenService(\Mockery::mock(MatrixPostingGateService::class), $names);
        $token = $service->mintAccessToken($identity, '!hearing:example.test');
        $this->assertSame($identity, $service->verify($token['token'])['name']);
    }
}
