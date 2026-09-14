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
        baseURL: 'http://nginx',
        headless: true,
        // The video review generates media with canvas.captureStream +
        // MediaRecorder; a fake device is not required, but the flags keep
        // autoplay/codecs unrestricted in the headless shell.
        launchOptions: {
            args: ['--autoplay-policy=no-user-gesture-required'],
        },
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
