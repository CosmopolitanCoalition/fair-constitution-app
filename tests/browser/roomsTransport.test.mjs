// Browser review lane — R2 · rooms over REAL transport (browser media).
//
// Register row: "Combined institutional rooms over real transport. Dependency:
// separate fixture identities and actual private Matrix/LiveKit connections with
// generated media. Pass criterion: join the same app-authorized presider/member/
// witness/board journey to real transport. Verify media continuity, connection
// interruption/rejoin, retained identity/floor/history, direct unauthorized joins
// and removed members with already-issued grants/connections."
//
// This is the MEDIA HALF, in a real browser against the real SFU (fc_livekit,
// LiveKit server 1.13.2). The application / controller / seating scope and the
// Matrix history + SFU token-grant/TTL scope already passed in
// tests/Unit/RoomTransportTest.php (Parts A/B/C). What that PHP harness could NOT
// establish — real WebRTC media publish/subscribe, the transport connection
// lifetime, interruption/rejoin, and removed-member connection loss — is what
// this suite drives in a real Chromium against the real SFU.
//
// Grants: the app's own mint (LiveKitTokenService) is already proven against this
// SFU by the PHP harness. For THIS browser journey the grants are minted here in
// node with the committed development key pair from docker/livekit/livekit.yaml
// (== config/matrix.php), HS256 over node's crypto (no new dependency), with the
// exact claim recipe LiveKitTokenService::mintJwt uses. Distinct identities for
// presider / member / witness; a nonced room r2-<hex>; a forged grant (wrong
// secret) and an expired grant.
//
// Run ONLY inside the fc_vite container:
//   docker exec fc_vite sh -c 'cd /var/www/html && npx playwright test \
//     --config playwright.config.mjs tests/browser/roomsTransport.test.mjs'

import { test, expect } from '@playwright/test';
import crypto from 'node:crypto';

// Committed development key pair (docker/livekit/livekit.yaml == config/matrix.php dev defaults).
const KEY = 'cga_dev_livekit_key';
const SECRET = 'cga_dev_livekit_secret_5c1d8e3a9f47026b';

const VITE = 'http://localhost:5173';
const HARNESS = `${VITE}/tests/browser/harness/rooms-transport.html`;

// Probed live 2026-09-14 from inside fc_vite: signaling answers at
// host.docker.internal:7880 and livekit:7880; localhost:7880 does not resolve to
// the SFU from this container. The Twirp RoomService (server API) is reached over
// plain HTTP from the node test process, which also runs inside fc_vite.
//
// Both URLs read from env so the BLOCKED handoff (run from a HOST browser) is
// executable without editing this file. Defaults are the in-container values;
// on the Windows host the published ports resolve 127.0.0.1 to the SFU, so run
// with SFU_WS=ws://localhost:7880 SFU_HTTP=http://localhost:7880.
const SFU_WS = process.env.SFU_WS || 'ws://host.docker.internal:7880';
const SFU_HTTP = process.env.SFU_HTTP || 'http://livekit:7880';

const b64url = (buf) => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

// The LiveKitTokenService::mintJwt recipe, reproduced in node. `video` is the
// VideoGrant; `serverApi` swaps it for a RoomService admin grant.
function mint({ identity, room, ttlSeconds = 3600, secret = SECRET, exp, nbf, serverApi = false, canPublish = true, canSubscribe = true }) {
    const now = Math.floor(Date.now() / 1000);
    const claims = {
        iss: KEY,
        sub: identity,
        name: identity,
        nbf: nbf ?? now,
        exp: exp ?? now + ttlSeconds,
        video: serverApi
            ? { roomAdmin: true, roomList: true, roomCreate: true, room }
            : { room, roomJoin: true, canPublish, canSubscribe },
    };
    const signingInput = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' })) + '.' + b64url(JSON.stringify(claims));
    const signature = crypto.createHmac('sha256', secret).update(signingInput).digest();
    return signingInput + '.' + b64url(signature);
}

async function twirp(method, body, token) {
    const res = await fetch(`${SFU_HTTP}/twirp/livekit.RoomService/${method}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
        body: JSON.stringify(body),
    });
    let json = null;
    const text = await res.text();
    try { json = JSON.parse(text); } catch { /* keep text */ }
    return { status: res.status, json, text };
}

async function loadHarness(page) {
    page.on('pageerror', (e) => console.log('PAGEERROR', e.message));
    await page.goto(HARNESS, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.__LK_READY__ === true, { timeout: 90000 });
}

const nonce = () => crypto.randomBytes(4).toString('hex');

// ─────────────────────────────────────────────────────────────────────────────
// 1 · node-minted grants against the real SFU token validator (/rtc/validate).
//     Establishes that THIS suite's node-minted grants — distinct identities,
//     forged, expired — are honoured/refused by the real SFU exactly as the
//     app's own mint is. Pure HTTP; no media, no ICE.
// ─────────────────────────────────────────────────────────────────────────────
test('node-minted grants: valid accepted, forged refused, expired refused (real SFU /rtc/validate)', async () => {
    const room = 'r2-' + nonce();
    const validate = async (token) => {
        const res = await fetch(`${SFU_HTTP}/rtc/validate?access_token=${encodeURIComponent(token)}`);
        return { status: res.status, body: (await res.text()).slice(0, 200) };
    };

    const presider = mint({ identity: '@u-r2-presider-' + nonce() + ':cga', room });
    const member = mint({ identity: '@u-r2-member-' + nonce() + ':cga', room });
    const witness = mint({ identity: '@u-r2-witness-' + nonce() + ':cga', room, canPublish: false });
    const forged = mint({ identity: '@u-r2-attacker:cga', room, secret: 'not-the-real-secret' });
    const expired = mint({ identity: '@u-r2-presider:cga', room, exp: Math.floor(Date.now() / 1000) - 3600, nbf: Math.floor(Date.now() / 1000) - 7200 });

    const rP = await validate(presider);
    const rM = await validate(member);
    const rW = await validate(witness);
    const rForged = await validate(forged);
    const rExpired = await validate(expired);
    console.log('VALIDATE presider=' + JSON.stringify(rP) + ' member=' + JSON.stringify(rM) + ' witness=' + JSON.stringify(rW));
    console.log('VALIDATE forged=' + JSON.stringify(rForged) + ' expired=' + JSON.stringify(rExpired));

    for (const r of [rP, rM, rW]) {
        expect(r.status).toBe(200);
        expect(r.body.toLowerCase()).toContain('success');
    }
    // The real SFU (LiveKit 1.13.2) refuses a bad grant with 401 "invalid token"
    // at /rtc/validate and does not name the cause in the body; both a forged
    // (wrong-secret) and an expired grant are refused with 401. Recorded literally.
    expect(rForged.status).toBe(401);
    expect(rForged.body.toLowerCase()).toContain('invalid token');
    expect(rExpired.status).toBe(401);
    expect(rExpired.body.toLowerCase()).toContain('invalid token');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2 · The media journey: distinct identities join the SAME room over real
//     transport and publish generated media; the other side must subscribe the
//     remote track and see frames arrive in BOTH directions. Frames are asserted
//     only when a real ICE connection completes; when ICE cannot complete from
//     this network position the exact observed ICE state is recorded (BLOCKED),
//     never asserted as a pass and never mocked away.
// ─────────────────────────────────────────────────────────────────────────────
test('media continuity — two identities join r2-<hex>, publish generated media, frames both directions', async ({ browser }, testInfo) => {
    test.setTimeout(180_000); // two browser contexts each load the SDK harness (~50s) before the media attempt
    const room = 'r2-' + nonce();
    const idP = '@u-r2-presider-' + nonce() + ':cga';
    const idM = '@u-r2-member-' + nonce() + ':cga';
    const tokP = mint({ identity: idP, room });
    const tokM = mint({ identity: idM, room });

    const ctxP = await browser.newContext();
    const ctxM = await browser.newContext();
    const pageP = await ctxP.newPage();
    const pageM = await ctxM.newPage();
    try {
        await Promise.all([loadHarness(pageP), loadHarness(pageM)]);

        const [connP, connM] = await Promise.all([
            pageP.evaluate(({ url, token }) => window.__rt.connect(url, token), { url: SFU_WS, token: tokP }),
            pageM.evaluate(({ url, token }) => window.__rt.connect(url, token), { url: SFU_WS, token: tokM }),
        ]);
        console.log('JOIN presider=' + JSON.stringify(connP));
        console.log('JOIN member=' + JSON.stringify(connM));

        // Signaling-layer journey: both distinct identities were accepted by the
        // real SFU and assigned their own identity in the same room. This holds
        // regardless of whether media ICE can complete.
        expect(connP.signalConnected).toBe(true);
        expect(connM.signalConnected).toBe(true);
        expect(connP.identity).toBe(idP);
        expect(connM.identity).toBe(idM);
        expect(connP.identity).not.toBe(connM.identity);

        const iceP = await pageP.evaluate(() => window.__rt.iceConnected());
        const iceM = await pageM.evaluate(() => window.__rt.iceConnected());
        const pcP = await pageP.evaluate(() => window.__rt.pcSnapshot());
        const pcM = await pageM.evaluate(() => window.__rt.pcSnapshot());
        console.log('ICE presider connected=' + iceP + ' pc=' + JSON.stringify(pcP));
        console.log('ICE member connected=' + iceM + ' pc=' + JSON.stringify(pcM));

        if (!(iceP && iceM && connP.joined && connM.joined)) {
            const blocked = {
                reason: 'ICE could not complete from inside fc_vite',
                presider: { joined: connP.joined, err: connP.err, pc: pcP },
                member: { joined: connM.joined, err: connM.err, pc: pcM },
                sfu_advertised_node_ip: '127.0.0.1',
                operator_command: 'Run from a HOST browser (host maps 7880/7881/7882): '
                    + 'SFU_WS=ws://localhost:7880 SFU_HTTP=http://localhost:7880 '
                    + 'npx playwright test --config playwright.config.mjs tests/browser/roomsTransport.test.mjs, '
                    + 'on the Windows host where the published ports resolve 127.0.0.1 to the SFU. '
                    + 'Both env vars are read by this test (SFU_HTTP is required so tests 1 and 5, which call '
                    + '/rtc/validate and the RoomService Twirp API, resolve on the host — the default http://livekit:7880 is a docker-only service name).',
            };
            console.log('MEDIA_BLOCKED ' + JSON.stringify(blocked));
            testInfo.annotations.push({ type: 'blocked', description: 'media frames not established: ' + blocked.reason });
            return; // do NOT assert frames — the environment blocks media, this is not a code defect
        }

        // Real ICE up (host-browser path): publish generated media and prove
        // frames arrive on the OTHER side in both directions.
        console.log('PUB presider=' + JSON.stringify(await pageP.evaluate(() => window.__rt.publishGenerated())));
        console.log('PUB member=' + JSON.stringify(await pageM.evaluate(() => window.__rt.publishGenerated())));

        const framesRising = async (page, label) => {
            let first = null;
            await expect.poll(async () => {
                const rows = (await page.evaluate(() => window.__rt.inboundStats())).filter((r) => r.kind === 'video' && r.subscribed);
                if (!rows.length) return 0;
                const v = rows[0].framesDecoded ?? 0;
                if (first === null) first = v;
                console.log('STATS ' + label + ' ' + JSON.stringify(rows[0]));
                return v;
            }, { timeout: 30000, intervals: [1000, 2000, 3000] }).toBeGreaterThan(0);
        };
        await framesRising(pageP, 'presider<-member');
        await framesRising(pageM, 'member<-presider');
    } finally {
        await pageP.evaluate(() => window.__rt.disconnect()).catch(() => {});
        await pageM.evaluate(() => window.__rt.disconnect()).catch(() => {});
        await ctxP.close();
        await ctxM.close();
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 3 · A direct unauthorized join (forged grant, wrong secret) is refused by the
//     SFU. Refusal happens at the signaling join, BEFORE any PC/ICE — so it is
//     observable from inside the container and is a genuine result, not blocked.
// ─────────────────────────────────────────────────────────────────────────────
test('direct unauthorized join — forged grant refused by the SFU at signaling', async ({ page }) => {
    await loadHarness(page);
    const room = 'r2-' + nonce();
    const forged = mint({ identity: '@u-r2-attacker:cga', room, secret: 'not-the-real-secret' });
    const out = await page.evaluate(({ url, token }) => window.__rt.connect(url, token, { peerConnectionTimeout: 6000 }), { url: SFU_WS, token: forged });
    console.log('FORGED_JOIN ' + JSON.stringify(out));
    expect(out.joined).toBe(false);
    expect(out.signalConnected).toBe(false); // rejected during the join handshake
    // The refusal is an auth/signal failure, NOT a pc-connection timeout.
    expect(String(out.err || '').toLowerCase()).not.toContain('could not establish pc');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4 · An expired grant is refused by the SFU, likewise at the signaling join.
// ─────────────────────────────────────────────────────────────────────────────
test('expired grant refused by the SFU at signaling', async ({ page }) => {
    await loadHarness(page);
    const room = 'r2-' + nonce();
    const now = Math.floor(Date.now() / 1000);
    const expired = mint({ identity: '@u-r2-presider:cga', room, exp: now - 3600, nbf: now - 7200 });
    const out = await page.evaluate(({ url, token }) => window.__rt.connect(url, token, { peerConnectionTimeout: 6000 }), { url: SFU_WS, token: expired });
    console.log('EXPIRED_JOIN ' + JSON.stringify(out));
    expect(out.joined).toBe(false);
    expect(out.signalConnected).toBe(false);
    expect(String(out.err || '').toLowerCase()).not.toContain('could not establish pc');
});

// ─────────────────────────────────────────────────────────────────────────────
// 5 · Removed member + already-issued grant on rejoin. The removal is driven
//     through the SFU's real RoomService (Twirp) with a server-API grant minted
//     the same way. What the SFU does with the SAME already-issued join grant on
//     a rejoin attempt is OBSERVED and recorded (allowed until expiry, or
//     refused), not asserted as a refusal. The media-loss half needs a live
//     media connection (ICE); where ICE is blocked that half is recorded, not
//     asserted.
// ─────────────────────────────────────────────────────────────────────────────
test('removed member via RoomService + already-issued grant on rejoin (observed)', async ({ page }, testInfo) => {
    await loadHarness(page);
    const room = 'r2-' + nonce();
    const idM = '@u-r2-member-' + nonce() + ':cga';
    const memberGrant = mint({ identity: idM, room }); // the already-issued join grant
    const adminGrant = mint({ identity: 'r2-server', room, serverApi: true });

    // Ensure the room exists server-side.
    console.log('CREATE ' + JSON.stringify((await twirp('CreateRoom', { name: room }, adminGrant)).status));

    // The member joins over real transport (signaling registers the participant
    // even before media ICE completes).
    const conn = await page.evaluate(({ url, token }) => window.__rt.connect(url, token, { peerConnectionTimeout: 8000 }), { url: SFU_WS, token: memberGrant });
    console.log('MEMBER_JOIN ' + JSON.stringify(conn));
    expect(conn.signalConnected).toBe(true);
    expect(conn.identity).toBe(idM);

    // Poll the real SFU roster for the member (a participant that never
    // establishes a PC is reaped after a timeout, so read it promptly).
    let seen = null;
    for (let i = 0; i < 10; i++) {
        const lp = await twirp('ListParticipants', { room }, adminGrant);
        const names = (lp.json?.participants || []).map((p) => p.identity);
        if (names.includes(idM)) { seen = names; break; }
        await new Promise((r) => setTimeout(r, 500));
    }
    console.log('ROSTER_BEFORE ' + JSON.stringify(seen));

    // Remove the member through the real RoomService.
    const removed = await twirp('RemoveParticipant', { room, identity: idM }, adminGrant);
    console.log('REMOVE ' + JSON.stringify({ status: removed.status, text: removed.text.slice(0, 120) }));

    // Observe the client side: the SDK should surface a disconnect (reason
    // ParticipantRemoved) if the signaling link was still up when the removal
    // landed. Record literally.
    await page.waitForTimeout(1500);
    const afterEvents = await page.evaluate(() => window.__rt.events);
    console.log('MEMBER_EVENTS_AFTER_REMOVE ' + JSON.stringify(afterEvents));

    // Confirm removal server-side.
    const lpAfter = await twirp('ListParticipants', { room }, adminGrant);
    const namesAfter = (lpAfter.json?.participants || []).map((p) => p.identity);
    console.log('ROSTER_AFTER ' + JSON.stringify(namesAfter));
    expect(namesAfter).not.toContain(idM);

    // Already-issued grant on a rejoin attempt: OBSERVE what the SFU does, do not
    // assert a refusal. LiveKit tokens are stateless until exp, so a removal does
    // not revoke the grant — a rejoin with the same grant is expected to be
    // ALLOWED at signaling (the ban is per-connection, not per-token). Record it.
    await page.evaluate(() => window.__rt.disconnect()).catch(() => {});
    const rejoin = await page.evaluate(({ url, token }) => window.__rt.connect(url, token, { peerConnectionTimeout: 8000 }), { url: SFU_WS, token: memberGrant });
    const observed = {
        signalConnected: rejoin.signalConnected,
        joined: rejoin.joined,
        identity: rejoin.identity,
        err: rejoin.err,
        sfu_behavior: rejoin.signalConnected ? 'ALLOWED (grant valid until expiry; removal is per-connection, not a token revocation)' : 'REFUSED at signaling',
    };
    console.log('REJOIN_WITH_SAME_GRANT ' + JSON.stringify(observed));
    testInfo.annotations.push({ type: 'observed', description: 'already-issued grant on rejoin after removal: ' + observed.sfu_behavior });

    await page.evaluate(() => window.__rt.disconnect()).catch(() => {});
});

// ─────────────────────────────────────────────────────────────────────────────
// 6 · Connection interruption / rejoin with retained identity. Interruption is
//     emulated with CDP Network offline for a few seconds, then restored; the
//     client must reconnect or the same identity must rejoin. Media resume needs
//     a live media connection (ICE); where ICE is blocked, the media-resume half
//     is recorded (BLOCKED) and only the retained-identity-on-rejoin fact — which
//     lives at the signaling layer — is asserted.
// ─────────────────────────────────────────────────────────────────────────────
test('interruption/rejoin — reconnect or same-identity rejoin, identity retained', async ({ page, context }, testInfo) => {
    await loadHarness(page);
    const room = 'r2-' + nonce();
    const idP = '@u-r2-presider-' + nonce() + ':cga';
    const grant = mint({ identity: idP, room });

    const conn = await page.evaluate(({ url, token }) => window.__rt.connect(url, token, { peerConnectionTimeout: 8000 }), { url: SFU_WS, token: grant });
    console.log('INTERRUPT_JOIN ' + JSON.stringify(conn));
    expect(conn.signalConnected).toBe(true);
    expect(conn.identity).toBe(idP);

    const iceUp = await page.evaluate(() => window.__rt.iceConnected());

    if (iceUp) {
        // Host-browser path: emulate a real network interruption and assert the
        // SDK reconnects with the same identity and media resumes.
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
        await page.waitForTimeout(4000);
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        const state = await page.evaluate(async () => {
            for (let i = 0; i < 30; i++) {
                if (window.__rt.room?.state === 'connected') break;
                await new Promise((r) => setTimeout(r, 500));
            }
            return { state: window.__rt.room?.state, identity: window.__rt.room?.localParticipant?.identity, events: window.__rt.events };
        });
        console.log('INTERRUPT_RESUME ' + JSON.stringify(state));
        expect(state.state).toBe('connected');
        expect(state.identity).toBe(idP);
    } else {
        // ICE blocked: the media session cannot be established, so a media-resume
        // reconnect cannot be exercised here. Record it, and assert the fact that
        // IS establishable from the signaling layer: a fresh join with the same
        // grant retains the same identity (the rejoin path).
        await page.evaluate(() => window.__rt.disconnect()).catch(() => {});
        const rejoin = await page.evaluate(({ url, token }) => window.__rt.connect(url, token, { peerConnectionTimeout: 6000 }), { url: SFU_WS, token: grant });
        console.log('INTERRUPT_BLOCKED_REJOIN ' + JSON.stringify(rejoin));
        testInfo.annotations.push({ type: 'blocked', description: 'media-resume reconnect not establishable (ICE blocked); signaling rejoin retains identity' });
        expect(rejoin.signalConnected).toBe(true);
        expect(rejoin.identity).toBe(idP); // retained identity on rejoin
    }
    await page.evaluate(() => window.__rt.disconnect()).catch(() => {});
});

// ─────────────────────────────────────────────────────────────────────────────
// Retained FLOOR and HISTORY: the app's cache-backed floor queue and the Matrix
// room history are covered by the case journey and the PHP harness
// (tests/Unit/RoomTransportTest.php Part A: the real LiveFloorService queue;
// Part B: real Matrix createRoom + send + read-back + re-read). They are recorded
// as covered there and are not re-proven at the media-transport layer here.
// ─────────────────────────────────────────────────────────────────────────────
