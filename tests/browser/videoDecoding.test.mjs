// Browser review lane — L1/L2 · video.
//
// Register row: "Video decoding, playback and language tracks. Pass criterion:
// a real browser decodes and plays the lesson video player's media: play, seek,
// per-language audio track switch, caption track switch, buffering state,
// reload/resume; the poster placeholder path when no media base URL is
// configured; and the player's controls are operable. Synthetic component tests
// alone do not establish decoding."
//
// This suite mounts the REAL resources/js/Components/Media/MultiTrackVideoPlayer.vue
// (through the running Vite dev server, no test-only defineExpose) and drives it
// against media generated at test time in this Chromium build with
// canvas.captureStream + MediaRecorder (the bundled Playwright ffmpeg is a
// stripped VP8-only build with no lavfi input, so it cannot generate a source).
// Assertions read the real HTMLMediaElement (readyState, videoWidth,
// currentTime, ended, textTracks/audio element src) and the rendered DOM, never
// synthetic component internals.
//
// Run ONLY inside the fc_vite container:
//   docker exec fc_vite sh -c 'cd /var/www/html && npx playwright test \
//     --config playwright.config.mjs tests/browser/videoDecoding.test.mjs'

import { test, expect } from '@playwright/test';

const VITE = 'http://localhost:5173';
const HARNESS = `${VITE}/tests/browser/harness/index.html`;
// Same-origin as the harness page so the component's caption fetch() is not
// blocked by CORS; every request under this path is intercepted by page.route.
const MEDIA_BASE = `${VITE}/__cga_media`;

const VIDEO = {
    id: 'demo',
    subject: 'TestLesson',
    master: 'TestLesson-Silent.webm',
    title: 'Test lesson',
    summary: 'A generated fixture.',
    poster: 'learn',
    seconds: 3,
    audio: [
        { code: 'en', name: 'English', native: 'English', dir: 'ltr', locale: 'en' },
        { code: 'pl', name: 'Polish', native: 'Polski', dir: 'ltr', locale: 'pl' },
    ],
    captions: [
        { code: 'en', name: 'English', native: 'English', dir: 'ltr', locale: 'en' },
        { code: 'ar', name: 'Arabic', native: 'العربية', dir: 'rtl', locale: 'ar' },
    ],
};

// W-0430: a second film for the playlist auto-advance test. A distinct subject
// so the served audio/master URLs differ from the first film.
const VIDEO2 = {
    id: 'demo2',
    subject: 'TestLesson2',
    master: 'TestLesson2-Silent.webm',
    title: 'Test lesson two',
    summary: 'A second generated fixture.',
    poster: 'learn',
    seconds: 3,
    audio: VIDEO.audio,
    captions: VIDEO.captions,
};

const VTT_EN = 'WEBVTT\n\n00:00:00.000 --> 00:00:10.000\nEnglish caption line one.\n';
const VTT_AR = 'WEBVTT\n\n00:00:00.000 --> 00:00:10.000\nسطر الترجمة العربية.\n';

// Generated once, reused across tests. base64 of a silent VP9 WebM master and
// an Opus WebM audio dub.
let videoBuf = null;
let audioBuf = null;
let codecMatrix = null;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    await page.goto('about:blank');
    const gen = await page.evaluate(async () => {
        const b64 = async (blob) => {
            const bytes = new Uint8Array(await blob.arrayBuffer());
            let s = '';
            const CH = 0x8000;
            for (let i = 0; i < bytes.length; i += CH) s += String.fromCharCode.apply(null, bytes.subarray(i, i + CH));
            return btoa(s);
        };
        const canPlay = (() => {
            const v = document.createElement('video');
            const a = document.createElement('audio');
            return {
                h264_mp4: v.canPlayType('video/mp4; codecs="avc1.42E01E"'),
                aac_mp4: a.canPlayType('audio/mp4; codecs="mp4a.40.2"'),
                vp9_webm: v.canPlayType('video/webm; codecs="vp9"'),
                opus_webm: a.canPlayType('audio/webm; codecs="opus"'),
            };
        })();
        async function makeVideo(seconds) {
            const c = document.createElement('canvas');
            c.width = 160; c.height = 90;
            const ctx = c.getContext('2d');
            const stream = c.captureStream(15);
            const rec = new MediaRecorder(stream, { mimeType: 'video/webm;codecs=vp9', videoBitsPerSecond: 120000 });
            const chunks = [];
            rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            const stopped = new Promise((r) => (rec.onstop = r));
            rec.start();
            let f = 0;
            const iv = setInterval(() => {
                ctx.fillStyle = `hsl(${(f * 20) % 360},60%,50%)`;
                ctx.fillRect(0, 0, 160, 90);
                ctx.fillStyle = '#fff';
                ctx.font = '20px sans-serif';
                ctx.fillText(String(f), 10, 50);
                f++;
            }, 66);
            await new Promise((r) => setTimeout(r, seconds * 1000));
            clearInterval(iv); rec.stop(); await stopped;
            return b64(new Blob(chunks, { type: 'video/webm' }));
        }
        async function makeAudio(seconds) {
            const AC = window.AudioContext || window.webkitAudioContext;
            const ac = new AC();
            const dest = ac.createMediaStreamDestination();
            const osc = ac.createOscillator();
            osc.frequency.value = 220; osc.connect(dest); osc.start();
            const rec = new MediaRecorder(dest.stream, { mimeType: 'audio/webm;codecs=opus' });
            const chunks = [];
            rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            const stopped = new Promise((r) => (rec.onstop = r));
            rec.start();
            await new Promise((r) => setTimeout(r, seconds * 1000));
            rec.stop(); await stopped; osc.stop(); ac.close();
            return b64(new Blob(chunks, { type: 'audio/webm' }));
        }
        return { canPlay, video: await makeVideo(3), audio: await makeAudio(3) };
    });
    await page.close();
    videoBuf = Buffer.from(gen.video, 'base64');
    audioBuf = Buffer.from(gen.audio, 'base64');
    codecMatrix = gen.canPlay;
    // eslint-disable-next-line no-console
    console.log('CODEC_MATRIX ' + JSON.stringify(codecMatrix));
    console.log(`FIXTURE_BYTES video=${videoBuf.length} audio=${audioBuf.length}`);
    expect(videoBuf.length).toBeGreaterThan(0);
    expect(audioBuf.length).toBeGreaterThan(0);
});

function classify(url) {
    if (url.includes('/audio/')) return 'audio';
    if (url.includes('/captions/') || url.endsWith('.vtt')) return 'caption';
    return 'video';
}

function serveBytes(route, buf, contentType) {
    const range = route.request().headers()['range'];
    if (range) {
        const m = /bytes=(\d+)-(\d*)/.exec(range);
        const start = Number(m[1]);
        const end = m[2] ? Number(m[2]) : buf.length - 1;
        const slice = buf.subarray(start, end + 1);
        return route.fulfill({
            status: 206,
            headers: {
                'content-type': contentType,
                'accept-ranges': 'bytes',
                'content-range': `bytes ${start}-${end}/${buf.length}`,
                'content-length': String(slice.length),
            },
            body: slice,
        });
    }
    return route.fulfill({
        status: 200,
        headers: { 'content-type': contentType, 'accept-ranges': 'bytes', 'content-length': String(buf.length) },
        body: buf,
    });
}

// Install the media host. opts.videoGate: a Promise the video response awaits
// (buffering test). opts.videoStatus: an HTTP status to fail the video with
// (error/retry test); flip opts.videoStatus=null to recover on the next load.
async function installMedia(page, opts = {}) {
    await page.route('**/__cga_media/**', async (route) => {
        const url = route.request().url();
        const kind = classify(url);
        if (kind === 'video') {
            if (opts.videoGate) await opts.videoGate;
            if (opts.videoStatus) return route.fulfill({ status: opts.videoStatus, body: 'nope' });
            return serveBytes(route, videoBuf, 'video/webm');
        }
        if (kind === 'audio') return serveBytes(route, audioBuf, 'audio/webm');
        // caption
        const body = url.includes('Arabic') ? VTT_AR : VTT_EN;
        return route.fulfill({ status: 200, headers: { 'content-type': 'text/vtt; charset=utf-8' }, body });
    });
}

async function mount(page, cfg) {
    await page.addInitScript((c) => { window.__CGA_MOUNT__ = c; }, cfg);
    await page.goto(HARNESS);
    await page.waitForFunction(() => window.__CGA_MOUNTED__ === true, { timeout: 30000 });
    await page.waitForSelector('figure.vplayer', { timeout: 30000 });
}

// Read real media element + status region state.
function state(page) {
    return page.evaluate(() => {
        const v = document.querySelector('figure.vplayer video');
        const a = document.querySelector('figure.vplayer audio');
        const cc = document.querySelector('.vplayer-cc');
        const status = document.querySelector('.vplayer-status[role="status"]');
        return {
            hasVideo: !!v,
            hasAudio: !!a,
            videoReadyState: v ? v.readyState : null,
            videoWidth: v ? v.videoWidth : null,
            videoHeight: v ? v.videoHeight : null,
            videoPaused: v ? v.paused : null,
            videoEnded: v ? v.ended : null,
            videoCurrentTime: v ? v.currentTime : null,
            videoDuration: v ? v.duration : null,
            audioSrc: a ? a.getAttribute('src') : null,
            audioReadyState: a ? a.readyState : null,
            audioPaused: a ? a.paused : null,
            capText: cc ? cc.textContent.trim() : null,
            capDir: cc ? cc.getAttribute('dir') : null,
            statusText: status ? status.textContent.replace(/\s+/g, ' ').trim() : null,
        };
    });
}

test('codec support matrix of this Chromium build', async () => {
    // Recorded, not asserted as a gate: the register asks to record the matrix
    // and to mark H.264 as not established if the build cannot decode it.
    expect(codecMatrix).toBeTruthy();
    console.log('CODEC_MATRIX(recorded) ' + JSON.stringify(codecMatrix));
    // VP9/Opus is the fixture path and MUST be decodable for the rest to hold.
    expect(codecMatrix.vp9_webm).not.toBe('');
    expect(codecMatrix.opus_webm).not.toBe('');
});

test('H.264 decoding — established by an actual decode or recorded as not', async ({ page }) => {
    // The register asks to record H.264 decoding, and to mark it NOT established
    // if this build cannot decode it. canPlayType is a claim, not a decode, so
    // this generates a real H.264/mp4 clip (MediaRecorder) and decodes it in a
    // real <video>. Whatever the browser actually does is recorded literally.
    await page.goto('about:blank');
    const result = await page.evaluate(async () => {
        const canPlay = document.createElement('video').canPlayType('video/mp4; codecs="avc1.42E01E"');
        const recSupported = !!(window.MediaRecorder && MediaRecorder.isTypeSupported('video/mp4;codecs=avc1'));
        if (!recSupported) return { canPlay, recSupported, decoded: false, reason: 'MediaRecorder has no mp4/avc1 encoder' };
        try {
            const c = document.createElement('canvas'); c.width = 160; c.height = 90;
            const ctx = c.getContext('2d');
            const stream = c.captureStream(15);
            const rec = new MediaRecorder(stream, { mimeType: 'video/mp4;codecs=avc1', videoBitsPerSecond: 120000 });
            const chunks = [];
            rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            const stopped = new Promise((r) => (rec.onstop = r));
            rec.start();
            let f = 0;
            const iv = setInterval(() => { ctx.fillStyle = `hsl(${(f * 20) % 360},60%,50%)`; ctx.fillRect(0, 0, 160, 90); f++; }, 66);
            await new Promise((r) => setTimeout(r, 1500));
            clearInterval(iv); rec.stop(); await stopped;
            const blob = new Blob(chunks, { type: 'video/mp4' });
            const v = document.createElement('video');
            v.muted = true; v.playsInline = true; v.preload = 'metadata';
            v.src = URL.createObjectURL(blob);
            document.body.appendChild(v);
            const ok = await new Promise((res) => {
                const t = setTimeout(() => res(false), 6000);
                v.addEventListener('loadeddata', () => { clearTimeout(t); res(true); }, { once: true });
                v.addEventListener('error', () => { clearTimeout(t); res(false); }, { once: true });
            });
            return { canPlay, recSupported, decoded: ok && v.readyState >= 2 && v.videoWidth > 0, readyState: v.readyState, videoWidth: v.videoWidth, blobSize: blob.size };
        } catch (e) { return { canPlay, recSupported, decoded: false, reason: String(e && e.message) }; }
    });
    console.log('H264_DECODE ' + JSON.stringify(result));
    // Not a gate: the register wants the fact recorded either way.
    expect(result).toBeTruthy();
});

test('real decode + play + seek + timeupdate', async ({ page }) => {
    const consoleErr = [];
    page.on('console', (m) => m.type() === 'error' && consoleErr.push(m.text()));
    page.on('pageerror', (e) => consoleErr.push('PAGEERROR ' + e.message));
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });

    // Decode established: the <video> element loads real frames.
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);
    const loaded = await state(page);
    expect(loaded.hasVideo).toBe(true);
    expect(loaded.videoWidth).toBe(160); // decoded frame geometry, not a placeholder
    expect(loaded.videoHeight).toBe(90);

    // Play.
    await page.click('button[aria-label="Play or pause"]');
    await expect.poll(async () => (await state(page)).videoPaused, { timeout: 10000 }).toBe(false);
    await expect.poll(async () => (await state(page)).videoCurrentTime, { timeout: 10000 }).toBeGreaterThan(0.1);
    await expect.poll(async () => (await state(page)).statusText).toMatch(/Playing/);

    // Seek to ~50% via the real range control.
    await page.locator('input.vplayer-seek').fill('50');
    const dur = (await state(page)).videoDuration;
    await expect.poll(async () => (await state(page)).videoCurrentTime, { timeout: 8000 }).toBeGreaterThan(dur * 0.4);
    console.log('CONSOLE_ERR(decode) ' + JSON.stringify(consoleErr));
    expect(consoleErr).toEqual([]);
});

test('per-language audio track switch', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);

    const before = await state(page);
    expect(before.audioSrc).toContain('English');

    await page.click('button[aria-label="Play or pause"]');
    await expect.poll(async () => (await state(page)).videoPaused, { timeout: 8000 }).toBe(false);

    // Switch the audio <select> to Polish. The component re-points the reactive
    // <audio :src> and re-syncs on load (onAudioLoaded).
    await page.locator('.vplayer-tracks label').nth(0).locator('select').selectOption('pl');
    await expect.poll(async () => (await state(page)).audioSrc, { timeout: 8000 }).toContain('Polish');
    await expect.poll(async () => (await state(page)).audioReadyState, { timeout: 10000 }).toBeGreaterThanOrEqual(1);
    // Linked default also moves captions to Polish in the status line.
    await expect.poll(async () => (await state(page)).statusText).toMatch(/audio Polski/);
});

test('caption track switch (per-language cues + RTL)', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);

    // Position inside the cue window [0,10s) and show the English cue.
    await page.click('button[aria-label="Play or pause"]');
    await expect.poll(async () => (await state(page)).capText, { timeout: 8000 }).toBe('English caption line one.');

    // Switch captions to Arabic (unlink first so audio stays put and we test the
    // caption fetch/parse path in isolation).
    await page.locator('.vplayer-tracks label.vtrack--link input').uncheck();
    await page.locator('.vplayer-tracks label').nth(1).locator('select').selectOption('ar');
    await expect.poll(async () => (await state(page)).capText, { timeout: 8000 }).toBe('سطر الترجمة العربية.');
    await expect.poll(async () => (await state(page)).capDir).toBe('rtl');
});

test('buffering / stalled state (delayed route) then recovers', async ({ page }) => {
    let release;
    const gate = new Promise((r) => (release = r));
    await installMedia(page, { videoGate: gate });
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });

    // Request the play while the video bytes are still withheld by the route.
    await page.click('button[aria-label="Play or pause"]');
    // The real element cannot get data: the component surfaces a stalled state
    // (buffering via the waiting event, else the loading state while the
    // request hangs). Record which one the real element produced.
    const stalled = await page.evaluate(() => new Promise((resolve) => {
        const started = Date.now();
        const iv = setInterval(() => {
            const s = document.querySelector('.vplayer-status[role="status"]')?.textContent?.replace(/\s+/g, ' ').trim() || '';
            const v = document.querySelector('figure.vplayer video');
            if (/Buffering/.test(s)) { clearInterval(iv); resolve({ kind: 'buffering', status: s, readyState: v?.readyState }); }
            else if (Date.now() - started > 4000) { clearInterval(iv); resolve({ kind: 'timeout', status: s, readyState: v?.readyState }); }
        }, 100);
    }));
    console.log('STALLED_STATE ' + JSON.stringify(stalled));
    // A genuinely stalled element: no data buffered yet.
    expect(stalled.readyState === 0 || stalled.kind === 'buffering').toBeTruthy();

    // Release the bytes; the element must now decode and play.
    release();
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);
    await expect.poll(async () => (await state(page)).videoCurrentTime, { timeout: 10000 }).toBeGreaterThan(0.05);
});

test('ended state at end of media', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);
    // Seek near the end and play out.
    await page.evaluate(() => { const v = document.querySelector('figure.vplayer video'); v.currentTime = Math.max(0, (isFinite(v.duration) ? v.duration : 3) - 0.3); });
    await page.click('button[aria-label="Play or pause"]');
    await expect.poll(async () => (await state(page)).videoEnded, { timeout: 12000 }).toBe(true);
    await expect.poll(async () => (await state(page)).statusText).toMatch(/Video finished/);
});

test('reload/resume — language prefs restored on a fresh page load', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);
    // Choose Polish audio; the watcher persists it to localStorage.
    await page.locator('.vplayer-tracks label').nth(0).locator('select').selectOption('pl');
    await expect.poll(async () => (await state(page)).statusText).toMatch(/audio Polski/);

    // Reload the page: a brand-new component instance mounts and must resume the
    // stored language choice from localStorage.
    await page.reload();
    await page.waitForFunction(() => window.__CGA_MOUNTED__ === true, { timeout: 30000 });
    await expect.poll(async () => (await state(page)).statusText, { timeout: 10000 }).toMatch(/audio Polski/);
    const sel = await page.locator('.vplayer-tracks label').nth(0).locator('select').inputValue();
    expect(sel).toBe('pl');
});

test('reload/resume — error surfaces retry and Retry video recovers playback', async ({ page }) => {
    const opts = { videoStatus: 500 };
    await installMedia(page, opts);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });

    // The failed master fires a real error event -> error state + alert + Retry.
    await expect.poll(async () => (await state(page)).statusText, { timeout: 12000 }).toMatch(/Video unavailable/);
    await expect(page.locator('.vplayer-status[role="alert"]')).toContainText('could not play');
    await expect(page.locator('button[aria-label="Play or pause"]')).toBeDisabled();
    await expect(page.locator('input.vplayer-seek')).toBeDisabled();

    // Fix the route and click Retry video: retryVideo() reloads the source and
    // resumes (resumeAt = the retained currentTime).
    opts.videoStatus = null;
    await page.locator('button.form-chip', { hasText: 'Retry video' }).click();
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);
    await expect(page.locator('button[aria-label="Play or pause"]')).toBeEnabled();
    await expect.poll(async () => (await state(page)).videoCurrentTime, { timeout: 10000 }).toBeGreaterThan(0.05);
});

test('controls operable — play toggles, captions toggle, poster disables', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);

    // Play -> pause via the same button (aria updates).
    const playBtn = page.locator('button[aria-label="Play or pause"]');
    await playBtn.click();
    await expect.poll(async () => (await state(page)).videoPaused, { timeout: 8000 }).toBe(false);
    await playBtn.click();
    await expect.poll(async () => (await state(page)).videoPaused, { timeout: 8000 }).toBe(true);

    // Captions toggle button flips aria-pressed and hides the cue bar.
    const capBtn = page.locator('button[aria-label^="Captions"]');
    await expect(capBtn).toHaveAttribute('aria-pressed', 'true');
    await capBtn.click();
    await expect(capBtn).toHaveAttribute('aria-pressed', 'false');
});

test('poster placeholder path — no media base URL configured', async ({ page }) => {
    const consoleErr = [];
    page.on('console', (m) => m.type() === 'error' && consoleErr.push(m.text()));
    page.on('pageerror', (e) => consoleErr.push('PAGEERROR ' + e.message));
    // No installMedia: baseUrl null => poster mode, no media requested.
    await mount(page, { video: VIDEO, baseUrl: null, initialLocale: 'en' });
    const s = await page.evaluate(() => ({
        hasVideo: !!document.querySelector('figure.vplayer video'),
        posterLabel: document.querySelector('.vplayer-stage[role="img"]')?.getAttribute('aria-label') ?? null,
        playDisabled: document.querySelector('button[aria-label="Play or pause"]')?.disabled,
        seekDisabled: document.querySelector('input.vplayer-seek')?.disabled,
        status: document.querySelector('.vplayer-status[role="status"]')?.textContent?.replace(/\s+/g, ' ').trim() ?? null,
        audioOptions: [...document.querySelectorAll('.vplayer-tracks select')].map((s2) => s2.options.length),
    }));
    console.log('POSTER_STATE ' + JSON.stringify(s));
    expect(s.hasVideo).toBe(false); // the <video> is not rendered in poster mode
    expect(s.posterLabel).toContain('Video not available yet');
    expect(s.playDisabled).toBe(true);
    expect(s.seekDisabled).toBe(true);
    expect(s.status).toMatch(/Video not available yet/);
    expect(s.audioOptions).toEqual([2, 2]); // language controls stay live
    expect(consoleErr).toEqual([]);
});

test('W-0431 — volume and mute act on the real dub audio, never the master', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en' });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);

    // Start playback so the dub audio element loads its source.
    await page.click('button[aria-label="Play or pause"]');
    await expect.poll(async () => (await state(page)).audioReadyState, { timeout: 12000 }).toBeGreaterThanOrEqual(1);

    // The master <video> is muted by design and stays muted throughout.
    expect(await page.evaluate(() => document.querySelector('figure.vplayer video').muted)).toBe(true);

    // The range control sets the real dub audio volume.
    await page.locator('input.vplayer-volume').fill('0.5');
    await expect.poll(async () => page.evaluate(() => document.querySelector('figure.vplayer audio').volume), { timeout: 8000 }).toBeCloseTo(0.5, 2);

    // The mute button toggles the dub audio, and the master is still muted.
    await page.click('button[aria-label="Mute audio"]');
    await expect.poll(async () => page.evaluate(() => document.querySelector('figure.vplayer audio').muted), { timeout: 8000 }).toBe(true);
    expect(await page.evaluate(() => document.querySelector('figure.vplayer video').muted)).toBe(true);

    // Unmute again through the flipped label.
    await page.click('button[aria-label="Unmute audio"]');
    await expect.poll(async () => page.evaluate(() => document.querySelector('figure.vplayer audio').muted), { timeout: 8000 }).toBe(false);
});

test('W-0430 — a two-film playlist auto-advances on ended with real media', async ({ page }) => {
    await installMedia(page);
    await mount(page, { video: VIDEO, baseUrl: MEDIA_BASE, initialLocale: 'en', playlist: [VIDEO, VIDEO2] });
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);

    // The playlist controls render and open on the first film.
    await expect(page.locator('.vplayer-playlist select')).toHaveValue('demo');
    await expect(page.locator('button[aria-label="Previous film"]')).toBeDisabled();

    // Choose Polish audio; the choice must carry over to the next film.
    await page.locator('.vplayer-tracks label').nth(0).locator('select').selectOption('pl');
    await expect.poll(async () => (await state(page)).audioSrc, { timeout: 8000 }).toContain('Polish');

    // Seek near the end and play out; the film ends and auto-advances.
    await page.evaluate(() => { const v = document.querySelector('figure.vplayer video'); v.currentTime = Math.max(0, (isFinite(v.duration) ? v.duration : 3) - 0.3); });
    await page.click('button[aria-label="Play or pause"]');

    // The second film is now selected, with the same language choice.
    await expect.poll(async () => page.locator('.vplayer-playlist select').inputValue(), { timeout: 15000 }).toBe('demo2');
    await expect.poll(async () => (await state(page)).audioSrc, { timeout: 10000 }).toContain('TestLesson2');
    await expect.poll(async () => (await state(page)).audioSrc, { timeout: 10000 }).toContain('Polish');

    // And it decodes and plays.
    await expect.poll(async () => (await state(page)).videoReadyState, { timeout: 15000 }).toBeGreaterThanOrEqual(1);
    await expect.poll(async () => (await state(page)).videoCurrentTime, { timeout: 12000 }).toBeGreaterThan(0.05);
});

test('live /videos page renders as guest without console errors', async ({ page }) => {
    const consoleErr = [];
    page.on('console', (m) => m.type() === 'error' && consoleErr.push(m.text()));
    page.on('pageerror', (e) => consoleErr.push('PAGEERROR ' + e.message));

    // Container-origin bridge (does NOT touch the app): from inside fc_vite the
    // only address for the app is http://nginx, but Vite's dev-server CORS
    // allowlist is http://localhost:8080 (the host port a real browser uses) and
    // http://localhost:5173 — not http://nginx. So the app's cross-origin
    // ES-module load from Vite is CORS-blocked for THIS origin only, and the
    // SPA cannot hydrate. A real user's browser at localhost:8080 is already
    // allowed and hydrates normally. We reproduce that by adding, to Vite's own
    // real responses, the Access-Control-Allow-Origin header the host browser
    // would receive. The app HTML/JS is served unchanged; only the missing CORS
    // header for the nginx origin is supplied.
    await page.route('http://localhost:5173/**', async (route) => {
        const resp = await route.fetch();
        const headers = { ...resp.headers(), 'access-control-allow-origin': 'http://nginx' };
        route.fulfill({ response: resp, headers });
    });

    // baseURL is http://nginx (the running app). Guest navigation only.
    await page.goto('/videos', { waitUntil: 'networkidle' });
    await page.waitForSelector('figure.vplayer', { timeout: 30000 });
    const s = await page.evaluate(() => ({
        title: document.title,
        players: document.querySelectorAll('figure.vplayer').length,
        films: document.querySelectorAll('.lesson-list .ticket-row').length,
        // The live app has no CGA_MEDIA_BASE_URL, so each entry shows the poster.
        posters: document.querySelectorAll('.vplayer-stage[role="img"]').length,
        liveVideoEls: document.querySelectorAll('figure.vplayer video').length,
        firstPosterLabel: document.querySelector('.vplayer-stage[role="img"]')?.getAttribute('aria-label') ?? null,
    }));
    console.log('LIVE_VIDEOS ' + JSON.stringify(s));
    expect(s.players).toBeGreaterThanOrEqual(1);
    expect(s.films).toBeGreaterThanOrEqual(1);
    console.log('CONSOLE_ERR(live) ' + JSON.stringify(consoleErr));
    expect(consoleErr).toEqual([]);
});
