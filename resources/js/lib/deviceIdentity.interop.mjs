/* Interop vector generator for the browser device-signer (Phase 5 "Deferred 1").
 *
 * Produces a STABLE vector (fixed seed + timestamp) for a voice-token action,
 * using the module's real canonicalJson + actionSigningString (the byte-critical
 * functions) and the same @noble Ed25519 the browser uses. The vector is pinned
 * by tests/Feature/DeviceSigningInteropTest, which reconstructs the signing
 * string via the REAL PHP path and asserts the signature verifies.
 *
 * Run (in the vite container, from the project root):
 *   docker exec fcw_vite node resources/js/lib/deviceIdentity.interop.mjs
 */
import * as ed from '@noble/ed25519';
import { utf8ToBytes } from '@noble/hashes/utils.js';
import { actionSigningString, canonicalJson } from './deviceIdentity.js';

function b64(bytes) {
    let s = '';
    for (const byte of bytes) s += String.fromCharCode(byte);
    return btoa(s);
}

const room = 'call-square-J';
const subjectUserId = '019f0000-0000-7000-8000-000000000001';
const pseudonym = '@u-tester:home.example';
const timestamp = 1700000000;

const seed = new Uint8Array(32).fill(7); // fixed seed → stable vector
const publicKey = await ed.getPublicKeyAsync(seed);

async function sign(signingString) {
    return b64(await ed.signAsync(utf8ToBytes(signingString), seed));
}

// ── voice vector (all strings) ───────────────────────────────────────────────
const voiceBody = { room, subject_user_id: subjectUserId, pseudonym };
const voiceCanonical = canonicalJson(voiceBody);
const voiceSigning = actionSigningString('POST', '/actor/voice-token', timestamp, voiceCanonical);

// ── write vector (a SAFE payload: strings / safe-ints / bool / nested) ───────
const writeBody = {
    form_id: 'F-LEG-003',
    payload: { note: 'forwarded by a citizen', count: 3, flag: true, nested: { label: 'plaza' } },
    subject_user_id: subjectUserId,
};
const writeCanonical = canonicalJson(writeBody);
const writeSigning = actionSigningString('POST', '/actor/write', timestamp, writeCanonical);

console.log(JSON.stringify({
    device_public_key: b64(publicKey),
    timestamp,
    voice: {
        canonical_body: voiceCanonical,
        signing_string: voiceSigning,
        action_signature: await sign(voiceSigning),
    },
    write: {
        canonical_body: writeCanonical,
        signing_string: writeSigning,
        action_signature: await sign(writeSigning),
    },
}, null, 2));
