// Playwright config for the browser review lane (L1/L2 · video).
// Runs ONLY inside the fc_vite container:
//   docker exec fc_vite sh -c 'cd /var/www/html && npx playwright test --config playwright.config.mjs tests/browser/<file>'
// The app answers at http://nginx and Vite serves at http://localhost:5173 from
// inside that container. The Chromium headless shell (v1208) ships in the image
// cache at /root/.cache/ms-playwright and launches headless.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/browser',
    timeout: 120_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        // Host run (operator order 2026-09-14): CGA_BROWSER_BASE_URL=http://localhost:8080
        // and CGA_BROWSER_CHANNEL=msedge drive the installed Edge on Windows; no browser in Docker.
        baseURL: process.env.CGA_BROWSER_BASE_URL || 'http://nginx',
        channel: process.env.CGA_BROWSER_CHANNEL || undefined,
        headless: true,
        // The video review generates media with canvas.captureStream +
        // MediaRecorder; a fake device is not required, but the flags keep
        // autoplay/codecs unrestricted in the headless shell.
        // --disable-dev-shm-usage: the container's /dev/shm is the Docker
        // default 64 MB; heavier guest pages (e.g. /tour, /legislatures,
        // /coverage-ops, /system/public-records) exhaust it and the Chromium
        // renderer crashes ("Page crashed") before the app can establish. The
        // flag routes shared memory to /tmp so those pages load and can be
        // scanned. Harness-only; changes nothing on the live app.
        launchOptions: {
            args: ['--autoplay-policy=no-user-gesture-required', '--disable-dev-shm-usage'],
        },
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
