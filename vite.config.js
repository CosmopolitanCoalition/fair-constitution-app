import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

// Host-side ports propagated by docker-compose (.env-driven). Defaults match
// the parent checkout (vite on 5173, app on 8080); worktrees override via
// .env to avoid port collisions when multiple stacks run simultaneously.
// Read from process.env at config-load time so we don't have to edit this
// file per checkout.
const VITE_HOST_PORT  = parseInt(process.env.VITE_HOST_PORT  || '5173', 10);
const NGINX_HOST_PORT = parseInt(process.env.NGINX_HOST_PORT || '8080', 10);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    // Pre-bundle these into single esbuild chunks on first run so the browser
    // doesn't fetch hundreds of individual node_modules files on cold page load.
    // The bundle is cached in node_modules/.vite/deps, which lives inside the
    // named docker volume `node_modules` — persistent across container restarts.
    // First restart after a deps change pays ~5-10s; every subsequent restart
    // reuses the cached pre-bundle in <1s.
    optimizeDeps: {
        include: [
            '@inertiajs/vue3',
            'vue',
            'axios',
            'leaflet',
            'protomaps-leaflet',
            '@vue-leaflet/vue-leaflet',
        ],
    },
    server: {
        host: '0.0.0.0',
        port: 5173,   // internal container port — always 5173 regardless of host mapping
        // strictPort: fail loudly if 5173 is taken inside the container instead
        // of silently jumping and breaking the host port mapping.
        strictPort: true,
        origin: `http://localhost:${VITE_HOST_PORT}`,
        cors: {
            origin: [
                `http://localhost:${NGINX_HOST_PORT}`,
                `http://localhost:${VITE_HOST_PORT}`,
            ],
            credentials: true,
        },
        watch: {
            // WSL2 + Windows-filesystem bind mounts: inotify events from the
            // Windows host don't propagate reliably across the WSL2 boundary.
            // Polling is more reliable but expensive — every interval, every
            // watched file is stat()'d across the Windows boundary. Keep the
            // ignored list aggressive so the poll only walks files where
            // edits actually matter for HMR.
            usePolling: true,
            interval: 1000,
            ignored: [
                '**/node_modules/**',    // chokidar default but be explicit
                '**/.git/**',
                '**/.claude/**',         // worktrees + plans + memory
                '**/vendor/**',          // composer-installed PHP
                '**/storage/**',         // logs, sessions, compiled views, etc.
                '**/public/build/**',    // vite's own output (when build runs)
                '**/data/**',            // ETL archive bind mount
                '**/docs/**',            // reference documents
                '**/tests/**',           // not touched by HMR
            ],
        },
        hmr: {
            // The browser hits us on host port VITE_HOST_PORT (mapped from the
            // container's internal 5173). Without clientPort, Vite's HMR
            // client would try to connect WebSocket to localhost:5173 — which
            // is unreachable from the host browser when the host-side port
            // differs (e.g. 5174 in a worktree) — leaving the HMR socket
            // pending forever and contributing to half-closed-connection
            // pileups on the dev server.
            clientPort: VITE_HOST_PORT,
            // Disable the in-browser error overlay. Referenced as a workaround
            // for the "pending requests / stuck server" class of issues in
            // vitejs/vite#5310 (the WSL2 + Node TCP half-close problem). It
            // removes one extra WebSocket-driven UI surface that can pin a
            // connection open if the page errors mid-load.
            overlay: false,
        },
        // Eagerly transform hot-path source files in parallel as soon as the
        // dev server boots, instead of waiting for the browser to discover and
        // request each one serially. The transform results are kept in Vite's
        // in-memory module graph and the on-disk cache. Net effect: by the
        // time the operator's browser fires the page request, the heavy Vue
        // SFCs and their imports are already compiled and ready to ship —
        // turning the cold-restart waterfall (10-30s per page) into a fast
        // hand-off (~1-2s).
        //
        // Listed: every Pages entry (Inertia code-splits per page), all
        // Components and Layouts (shared by most pages), the app entry, and
        // bootstrap. Add new hot-path files here when they appear.
        warmup: {
            clientFiles: [
                './resources/js/app.js',
                './resources/js/bootstrap.js',
                './resources/js/Pages/**/*.vue',
                './resources/js/Components/**/*.vue',
                './resources/js/Layouts/**/*.vue',
                // Tailwind v4's first scan walks every @source pattern (all
                // *.blade.php + *.js under resources/). On this codebase that's
                // tens of seconds the first time. Warming it here means the
                // compile starts when Vite boots, in parallel with everything
                // else, instead of blocking the browser's first CSS request.
                './resources/css/app.css',
            ],
        },
    },
});
