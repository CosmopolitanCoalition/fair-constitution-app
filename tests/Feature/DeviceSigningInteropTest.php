<?php

namespace Tests\Feature;

use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\ActorIdentityService;
use Tests\TestCase;

/**
 * INTEROP PIN — the browser device-signer (resources/js/lib/deviceIdentity.js) must produce signatures the
 * PHP backend verifies BYTE-FOR-BYTE; a one-byte drift on either side means a device's voice-token / traveling-
 * write signature silently stops verifying. This pins STABLE vectors produced by the JS module itself
 * (resources/js/lib/deviceIdentity.interop.mjs — fixed seed + timestamp), on BOTH action shapes: the voice
 * body {room, subject_user_id, pseudonym} (all strings) AND a write body {form_id, payload, subject_user_id}
 * where the payload exercises recursive key-sorting + safe ints + booleans + nesting. For each: the canonical
 * body and signing string the JS builds must equal the PHP ones, and the JS Ed25519 signature must verify via
 * the real InstanceIdentityService::verify (the same path the voice + write gates use).
 *
 * Regenerate after any change to the JS canonicalJson / actionSigningString:
 *   docker exec fcw_vite node resources/js/lib/deviceIdentity.interop.mjs
 *
 * If an edit breaks these, the edit is the violation — fix the edit (PHP or JS), not the test.
 */
class DeviceSigningInteropTest extends TestCase
{
    private const SUBJECT = '019f0000-0000-7000-8000-000000000001';

    private const TIMESTAMP = 1700000000;

    private const DEVICE_PUBLIC_KEY = '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=';

    // ── voice vector (all strings) ───────────────────────────────────────────
    private const VOICE_BODY = ['room' => 'call-square-J', 'subject_user_id' => self::SUBJECT, 'pseudonym' => '@u-tester:home.example'];

    private const VOICE_CANONICAL = '{"pseudonym":"@u-tester:home.example","room":"call-square-J","subject_user_id":"019f0000-0000-7000-8000-000000000001"}';

    private const VOICE_SIGNING = "POST\n/actor/voice-token\n1700000000\n3d29fa08d35bf2811bfab842ba0249588a347b2e191e54839c2a483b304b1801";

    private const VOICE_SIGNATURE = 'B6+Xsn3D0gLIVJVsPEhnGIiiDSP00lXnSNHPum5xas6f9qPTBl+wS2Vzx7aesAHeGOF2WN2IqQG+ecqQ4dFEAA==';

    // ── write vector (recursive key-sort + safe ints + bool + nesting) ───────
    private const WRITE_CANONICAL = '{"form_id":"F-LEG-003","payload":{"count":3,"flag":true,"nested":{"label":"plaza"},"note":"forwarded by a citizen"},"subject_user_id":"019f0000-0000-7000-8000-000000000001"}';

    private const WRITE_SIGNING = "POST\n/actor/write\n1700000000\n300fd85882f912a17e179e81a51b7ca723a95ea4a349b6e4b9dc7c756a325586";

    private const WRITE_SIGNATURE = 'LFtu9jT7nV5QcngVTwurNSugtHA6ems8MGqoPTvy6jTSZJyBfuSAsjCks0TXvGC0Hhy1VzlA0jvpBE61dJkFDQ==';

    public function test_voice_canonical_and_signing_string_byte_match_the_browser(): void
    {
        $this->assertSame(self::VOICE_CANONICAL, AuditService::canonicalJson(self::VOICE_BODY),
            'voice canonicalJson must byte-match the JS canonicalize');
        $this->assertSame(self::VOICE_SIGNING,
            ActorIdentityService::actionSigningString('POST', '/actor/voice-token', self::TIMESTAMP, self::VOICE_CANONICAL),
            'the voice signing string must byte-match the JS one');
    }

    public function test_write_canonical_and_signing_string_byte_match_the_browser(): void
    {
        // Keys deliberately out of order — canonicalJson must recursively key-sort to the JS bytes.
        $body = [
            'subject_user_id' => self::SUBJECT,
            'form_id' => 'F-LEG-003',
            'payload' => ['note' => 'forwarded by a citizen', 'flag' => true, 'count' => 3, 'nested' => ['label' => 'plaza']],
        ];
        $this->assertSame(self::WRITE_CANONICAL, AuditService::canonicalJson($body),
            'write canonicalJson (nested + ints + bool) must byte-match the JS canonicalize');
        $this->assertSame(self::WRITE_SIGNING,
            ActorIdentityService::actionSigningString('POST', '/actor/write', self::TIMESTAMP, self::WRITE_CANONICAL),
            'the write signing string must byte-match the JS one');
    }

    public function test_browser_produced_signatures_verify_on_the_backend(): void
    {
        // THE end-to-end proof for BOTH paths: the JS Ed25519 signature verifies against the device public
        // key via the SAME path the voice/write gates use (sodium detached verify, base64 ORIGINAL).
        $this->assertTrue(
            InstanceIdentityService::verify(self::DEVICE_PUBLIC_KEY, self::VOICE_SIGNING, self::VOICE_SIGNATURE),
            'the browser voice signature must verify on the backend');
        $this->assertTrue(
            InstanceIdentityService::verify(self::DEVICE_PUBLIC_KEY, self::WRITE_SIGNING, self::WRITE_SIGNATURE),
            'the browser write signature must verify on the backend');

        // Fails closed: any mutation of the signed message breaks verification.
        $this->assertFalse(
            InstanceIdentityService::verify(self::DEVICE_PUBLIC_KEY, str_replace('1700000000', '1700000001', self::VOICE_SIGNING), self::VOICE_SIGNATURE),
            'a tampered signing string must not verify');
    }
}
