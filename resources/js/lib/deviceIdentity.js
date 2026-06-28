/* ============================================================================
   CGA — lib/deviceIdentity.js
   The browser half of G-ID device signing (Phase G / Phase 5 "Deferred 1").

   A player's DEVICE holds an Ed25519 keypair. The SECRET never leaves the
   browser (no escrow); only the PUBLIC key is enrolled. The device then SIGNS
   actions — a cross-node voice-token request, and (shared) a traveling write —
   exactly the way the backend verifies them:

     signing string = METHOD "\n" TARGET "\n" TIMESTAMP "\n" sha256_hex(body)
                       (App\Services\Identity\ActorIdentityService::actionSigningString)
     body           = canonical JSON: recursive key-sort, compact, slashes +
                       unicode UNESCAPED
                       (App\Services\AuditService::canonicalJson)
     signature      = detached Ed25519 over the signing string, base64
                       (SODIUM_BASE64_VARIANT_ORIGINAL = standard, padded)

   Byte-for-byte parity with the PHP verifier is REQUIRED — a one-byte drift
   means the signature does not verify. tests/Feature/DeviceSigningInteropTest
   pins it by verifying a signature produced HERE against the real PHP path.

   Crypto: @noble/ed25519 (audited, zero-dep) + @noble/hashes. The async signer
   uses the platform WebCrypto for SHA-512, so no global setup is needed and it
   works across browsers + Node ≥ 20.
   ============================================================================ */

import * as ed from '@noble/ed25519';
import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';

const STORAGE_KEY = 'cga.device.secretKey'; // base64 of the 32-byte Ed25519 secret seed

const ENROLL_URL = '/civic/actor/devices';
const VOICE_REACH_URL = '/civic/matrix/voice-reach';

/* ── encoding helpers (standard base64 WITH padding = sodium ORIGINAL) ──────── */

function bytesToBase64(bytes) {
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
}

function base64ToBytes(b64) {
    const binary = atob(b64);
    const out = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
    return out;
}

/* ── canonical form — MUST byte-match AuditService::canonicalJson ───────────── */

function canonicalize(value) {
    if (Array.isArray(value)) return value.map(canonicalize); // lists keep order
    if (value !== null && typeof value === 'object') {
        const sorted = {};
        for (const key of Object.keys(value).sort()) sorted[key] = canonicalize(value[key]);
        return sorted;
    }
    return value;
}

/**
 * Recursive key-sorted, compact JSON with slashes + unicode unescaped — the same
 * bytes AuditService::canonicalJson produces (JSON.stringify already leaves '/'
 * and non-ASCII unescaped and emits no whitespace).
 */
export function canonicalJson(payload) {
    return JSON.stringify(canonicalize(payload));
}

/**
 * Reject any value whose canonical JSON could DIVERGE between JS and PHP, so a
 * device signs nothing the leader will silently reject. PHP canonicalJson round-
 * trips through json_decode(json_encode()) + ksort(SORT_STRING); JS uses
 * JSON.stringify + numeric key reindexing. They agree on strings, booleans, null,
 * SAFE integers, arrays, and non-empty objects with non-numeric keys — and diverge
 * on floats (1.0 / 1e20 / 1e-7), bignums, NaN/Infinity (→ JS null), empty objects
 * (PHP → []), and numeric-string keys (different ordering). Decimals/bignums must
 * be encoded as STRINGS by the caller. Fails LOUD (throws) rather than producing a
 * signature that won't verify.
 */
function assertSignable(value, path = 'body') {
    if (value === null) return;
    const type = typeof value;

    if (type === 'string' || type === 'boolean') return;

    if (type === 'number') {
        if (!Number.isInteger(value) || !Number.isSafeInteger(value)) {
            throw new Error(
                `deviceIdentity: ${path} is a non-safe-integer number (${value}); encode decimals/bignums as `
                + 'strings so the device signature is byte-stable across JS and PHP.',
            );
        }
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item, i) => assertSignable(item, `${path}[${i}]`));
        return;
    }

    if (type === 'object') {
        const keys = Object.keys(value);
        if (keys.length === 0) {
            throw new Error(`deviceIdentity: ${path} is an empty object (PHP canonicalizes {} to []); omit it or use an array.`);
        }
        for (const key of keys) {
            if (/^\d+$/.test(key)) {
                throw new Error(`deviceIdentity: ${path} has the numeric-string key "${key}"; JS and PHP order/reindex these differently — use a non-numeric key.`);
            }
            assertSignable(value[key], `${path}.${key}`);
        }
        return;
    }

    throw new Error(`deviceIdentity: ${path} has an unsupported value type (${type}).`);
}

/** METHOD\nTARGET\nTIMESTAMP\nsha256_hex(body) — ActorIdentityService::actionSigningString. */
export function actionSigningString(method, target, timestamp, body) {
    return `${method.toUpperCase()}\n${target}\n${timestamp}\n${bytesToHex(sha256(utf8ToBytes(body)))}`;
}

/* ── the device key (un-escrowed; the secret never leaves the browser) ───────────
 *
 * STORAGE / THREAT MODEL: the secret seed is held in localStorage. It never leaves
 * the browser to the server (no escrow), but it IS readable by any script on this
 * origin — so an XSS can exfiltrate it and sign as the user until the device is
 * revoked. The blast radius is bounded by the ATTESTATION layer (short TTL +
 * revocable CRL that now propagates cross-node), and today this key only authorizes
 * VOICE join tokens. ⚑ Before signTravelingWrite is wired to a real form, harden to
 * a NON-EXTRACTABLE WebCrypto Ed25519 CryptoKey in IndexedDB (XSS can use but not
 * exfiltrate it). Ed25519 is deterministic, so WebCrypto signatures verify
 * identically on the backend.
 */

let deviceKeyPromise = null;

/**
 * Load the device secret, generating + persisting one on first use. Serialized via
 * a cached promise so concurrent first-use calls (e.g. enroll + a voice request)
 * can't each mint a different key and orphan one.
 * @returns {Promise<{secretKey: Uint8Array, publicKeyB64: string}>}
 */
export function ensureDeviceKey() {
    if (deviceKeyPromise) return deviceKeyPromise;

    deviceKeyPromise = (async () => {
        let b64 = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;

        let secretKey;
        if (b64) {
            secretKey = base64ToBytes(b64);
        } else {
            secretKey = ed.utils.randomSecretKey();
            b64 = bytesToBase64(secretKey);
            if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, b64);
        }

        const publicKey = await ed.getPublicKeyAsync(secretKey);

        return { secretKey, publicKeyB64: bytesToBase64(publicKey) };
    })().catch((error) => {
        deviceKeyPromise = null; // don't cache a failure
        throw error;
    });

    return deviceKeyPromise;
}

/** The enrolled device's public key (base64), creating the key on first use. */
export async function devicePublicKey() {
    return (await ensureDeviceKey()).publicKeyB64;
}

/**
 * Enrol this device's PUBLIC key with the server (idempotent). The secret stays
 * local. Returns the server's { device_id, enrolled_at }.
 */
export async function enrollDevice(label = null) {
    const { publicKeyB64 } = await ensureDeviceKey();
    const { data } = await window.axios.post(ENROLL_URL, { device_public_key: publicKeyB64, label });
    return data;
}

/**
 * Sign one action with the device key.
 * @returns {Promise<{device_public_key: string, action_signature: string, timestamp: number}>}
 */
export async function signAction(method, target, bodyObject) {
    assertSignable(bodyObject); // fail loud on anything whose canonical JSON would diverge from PHP
    const { secretKey, publicKeyB64 } = await ensureDeviceKey();
    const timestamp = Math.floor(Date.now() / 1000);
    const message = actionSigningString(method, target, timestamp, canonicalJson(bodyObject));
    const signature = await ed.signAsync(utf8ToBytes(message), secretKey);

    return {
        device_public_key: publicKeyB64,
        action_signature: bytesToBase64(signature),
        timestamp,
    };
}

/* ── the two action shapes the backend expects ─────────────────────────────── */

/** The voice body the device signs (matches TravelingVoiceTokenService::actionBody). */
function voiceBody(room, subjectUserId, pseudonym) {
    return { room, subject_user_id: subjectUserId, pseudonym };
}

/**
 * Request a cross-node-capable LiveKit voice token for a commons call room. The
 * device signs the /actor/voice-token action; the home node issues a short-TTL
 * attestation and forwards. On `503 { degrade: true }` the caller should fall
 * back to text-only (no SFU reachable).
 *
 * `subjectUserId` and `pseudonym` MUST be the authenticated player's OWN id and
 * @u-<handle> (from the page's Inertia props) — the home node rebuilds the signed
 * body from the player's identity + attestation, so a mismatch simply fails closed
 * (403 action_signature_invalid), never impersonates anyone.
 *
 * @returns {Promise<{token, sfu_url, room, identity, via}>}
 */
export async function requestVoiceToken({ jurisdictionId, room, pseudonym, subjectUserId }) {
    const signed = await signAction('POST', '/actor/voice-token', voiceBody(room, subjectUserId, pseudonym));

    const { data } = await window.axios.post(VOICE_REACH_URL, {
        jurisdiction_id: jurisdictionId,
        room,
        device_public_key: signed.device_public_key,
        action_signature: signed.action_signature,
        timestamp: signed.timestamp,
    });

    return data;
}

/**
 * Sign a traveling WRITE (shared with the voice path). The home node attaches a
 * fresh attestation and forwards to the jurisdiction's authoritative leader,
 * which verifies this device signature (AttestedForwardedActor). Returns the
 * actor block the forwarder embeds in the write envelope.
 */
export async function signTravelingWrite(formId, payload, subjectUserId) {
    return signAction('POST', '/actor/write', {
        form_id: formId,
        payload,
        subject_user_id: subjectUserId,
    });
}
