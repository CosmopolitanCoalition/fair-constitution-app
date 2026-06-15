<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\ActorIdentityService;
use App\Services\Identity\AttestationRefused;
use App\Services\Identity\AttestationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * G-ID actor self-service (Phase G). The authenticated person manages their own
 * device signing keys and mints the short-lived attestation a client (browser or
 * mobile) attaches to a forwarded write:
 *
 *   POST /civic/actor/devices       — enrol a device's PUBLIC key (the secret never
 *                                     leaves the device; no escrow);
 *   POST /civic/actor/attestations  — mint a signed standing attestation for a
 *                                     device, returned in the WIRE form the client
 *                                     carries (AttestedForwardedActor verifies it
 *                                     on the leader).
 *
 * Both act on `$request->user()` only — a person can attest only their OWN
 * standing, and only their home authority issues it (AttestationService enforces).
 */
class ActorIdentityController extends Controller
{
    public function __construct(
        private readonly ActorIdentityService $devices,
        private readonly AttestationService $attestations,
    ) {}

    public function enrollDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_public_key' => ['required', 'string', 'min:32', 'max:128'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $device = $this->devices->enrollDevice(
            $request->user(),
            $validated['device_public_key'],
            $validated['label'] ?? null,
        );

        return response()->json([
            'device_id' => (string) $device->id,
            'enrolled_at' => $device->enrolled_at?->toIso8601String(),
        ]);
    }

    public function issueAttestation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_public_key' => ['required', 'string', 'min:32', 'max:128'],
            'ttl_seconds' => ['nullable', 'integer', 'min:60', 'max:'.AttestationService::MAX_TTL_SECONDS],
        ]);

        try {
            $attestation = $this->attestations->issue(
                $request->user(),
                $validated['device_public_key'],
                (int) ($validated['ttl_seconds'] ?? AttestationService::DEFAULT_TTL_SECONDS),
            );
        } catch (AttestationRefused $e) {
            return response()->json(['error' => $e->reason], 403);
        }

        // The WIRE form the client attaches to a forwarded write (epoch ints — the
        // exact bytes AttestedForwardedActor reconstructs and verifies).
        return response()->json([
            'attestation' => [
                'id' => (string) $attestation->id,
                'subject_user_id' => (string) $attestation->subject_user_id,
                'device_public_key' => (string) $attestation->device_public_key,
                'issuer_server_id' => (string) $attestation->issuer_server_id,
                'roles' => array_values((array) $attestation->roles),
                'issued_at' => $attestation->issued_at->getTimestamp(),
                'expires_at' => $attestation->expires_at->getTimestamp(),
                'signature' => (string) $attestation->signature,
            ],
        ]);
    }
}
