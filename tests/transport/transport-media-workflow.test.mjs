// R2 · rooms over real transport — LiveKit SFU transport-lifetime harness (Node, DB-free).
//
// This exercises the REAL fc_livekit SFU over its real HTTP token-validation endpoint (/rtc/validate),
// using the exact HS256 grant recipe LiveKitTokenService mints. It establishes the transport AUTH
// lifetime the register's remaining scope asks for: the SFU accepts an app-minted grant, refuses an
// expired grant (the TTL characteristic), and refuses a forged grant.
//
// It does NOT establish media-FRAME publish/subscribe: that path is WebRTC in a browser. The
// livekit-client package resolves in fc_vite (it is the frontend dependency), but node has no WebRTC
// media runtime (no RTCPeerConnection / MediaStream / getUserMedia) and browser automation is not
// authorized here, so real media both-directions is reported as NOT ESTABLISHED — never asserted as
// passing. That scope moves to the browser lane.
//
// Run inside fc_vite (Node 22, global fetch):
//   docker exec fc_vite sh -c 'cd /var/www/html/.wt/edu && node --test tests/transport/transport-media-workflow.test.mjs'

import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';

const BASE = (process.env.LIVEKIT_VALIDATE_URL || 'http://livekit:7880').replace(/\/$/, '');
const API_KEY = process.env.LIVEKIT_API_KEY || 'cga_dev_livekit_key';
const API_SECRET = process.env.LIVEKIT_API_SECRET || 'cga_dev_livekit_secret_5c1d8e3a9f47026b';
const MAX_TTL_SECONDS = 21600; // LiveKitTokenService::MAX_TTL_SECONDS

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

// The LiveKitTokenService HS256 join-grant recipe (room-scoped VideoGrant, bounded exp).
function mint({ key = API_KEY, secret = API_SECRET, identity, room, exp }) {
  const now = Math.floor(Date.now() / 1000);
  const claims = {
    iss: key, sub: identity, name: identity, nbf: now,
    exp: exp ?? now + 3600,
    video: { room, roomJoin: true, canPublish: true, canSubscribe: true },
  };
  const si = `${b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))}.${b64url(JSON.stringify(claims))}`;
  const sig = b64url(createHmac('sha256', secret).update(si).digest());
  return `${si}.${sig}`;
}

async function validate(token) {
  const res = await fetch(`${BASE}/rtc/validate?access_token=${encodeURIComponent(token)}`, {
    signal: AbortSignal.timeout(8000),
  });
  return { status: res.status, body: (await res.text()).toLowerCase() };
}

let reachable = true;
test.before(async () => {
  try {
    const res = await fetch(`${BASE}/`, { signal: AbortSignal.timeout(8000) });
    reachable = res.status === 200;
  } catch {
    reachable = false;
  }
  if (!reachable) {
    console.error(`R2 BLOCKED: fc_livekit not reachable at ${BASE}. Bring up the voice profile:`);
    console.error('  docker compose -p wos -f docker-compose.yml -f docker-compose.voice-local.yml --profile voice up -d --no-deps livekit');
  }
});

test('SFU accepts a real app-minted join grant', { skip: !reachable ? 'fc_livekit unreachable' : false }, async () => {
  const room = `r2-board-${Math.random().toString(36).slice(2, 10)}`;
  const { status, body } = await validate(mint({ identity: '@u-r2-chair:localhost', room }));
  assert.equal(status, 200, `expected 200, got ${status}: ${body}`);
  assert.match(body, /success/);
});

test('SFU refuses an expired grant (the TTL characteristic)', { skip: !reachable ? 'fc_livekit unreachable' : false }, async () => {
  const room = `r2-board-${Math.random().toString(36).slice(2, 10)}`;
  const exp = Math.floor(Date.now() / 1000) - 3600;
  const { status, body } = await validate(mint({ identity: '@u-r2-chair:localhost', room, exp }));
  assert.equal(status, 401, `expected 401, got ${status}: ${body}`);
  assert.match(body, /expired/);
});

test('SFU refuses a forged grant (any other secret)', { skip: !reachable ? 'fc_livekit unreachable' : false }, async () => {
  const room = `r2-board-${Math.random().toString(36).slice(2, 10)}`;
  const { status, body } = await validate(mint({ secret: 'not-the-real-secret', identity: '@u-outsider:localhost', room }));
  assert.equal(status, 401, `expected 401, got ${status}: ${body}`);
  assert.match(body, /signature/);
});

// The removed-member already-issued-grant characteristic: a grant minted BEFORE removal carries no app
// seat state — the SFU validates only the token. So it stays valid until its own exp (bounded by
// MAX_TTL_SECONDS). This documents the gap; it does not claim the SFU disconnects a removed member.
test('a still-unexpired grant remains SFU-valid regardless of app seat state (documented TTL gap)', { skip: !reachable ? 'fc_livekit unreachable' : false }, async () => {
  const room = `r2-board-${Math.random().toString(36).slice(2, 10)}`;
  const nearMax = Math.floor(Date.now() / 1000) + MAX_TTL_SECONDS - 60;
  const { status } = await validate(mint({ identity: '@u-r2-removed:localhost', room, exp: nearMax }));
  assert.equal(status, 200, 'an unexpired grant validates at the SFU (no revocation list exists)');
});

// Media FRAME publish/subscribe over WebRTC is NOT established here. livekit-client resolves in fc_vite
// (the frontend dependency), but a resolvable package is not an exchanged media frame: originating and
// receiving media both directions needs a browser WebRTC runtime (RTCPeerConnection plus
// navigator.mediaDevices.getUserMedia) driving an ICE/DTLS/SRTP session against the SFU. The node
// runtime in fc_vite has none of that, and browser automation is not authorized in this lane. This test
// records that boundary honestly rather than pretending a media exchange occurred; the media scope moves
// to the browser lane.
test('media-frame publish/subscribe is NOT established here (no browser WebRTC media runtime)', () => {
  const hasPeerConnection = typeof RTCPeerConnection !== 'undefined';
  const hasMediaStream = typeof MediaStream !== 'undefined';
  const hasGetUserMedia = typeof navigator !== 'undefined'
    && !!navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
  const canOriginateMedia = hasPeerConnection && hasMediaStream && hasGetUserMedia;
  assert.equal(canOriginateMedia, false,
    'node has no WebRTC media runtime (RTCPeerConnection / MediaStream / getUserMedia); real media '
    + 'both-directions requires a browser and is reported as NOT ESTABLISHED — it moves to the browser lane');
});
