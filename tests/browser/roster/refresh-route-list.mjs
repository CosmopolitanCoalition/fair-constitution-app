// @ts-check
// Refresh the authoritative route snapshot the guest-page roster derives from.
// Reads `php artisan route:list --json` on stdin, keeps GET routes, normalizes
// each to {uri,name,middleware}, and writes route-list.json beside this file.
//
//   docker exec fc_app php artisan route:list --json \
//     | docker exec -i fc_vite node /var/www/html/tests/browser/roster/refresh-route-list.mjs
//
// After a refresh, run the sweep once: if PIN_PAGES / PIN_NONPAGE in roster.mjs
// no longer match the derivation the pin test fails, and the arrays are updated
// deliberately.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));

let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (c) => (raw += c));
process.stdin.on('end', () => {
    const table = JSON.parse(raw);
    const rows = [];
    for (const r of table) {
        if (!String(r.method || '').includes('GET')) continue;
        rows.push({
            uri: '/' + String(r.uri || '').replace(/^\/+/, ''),
            name: r.name ?? null,
            middleware: [...(r.middleware || [])].sort(),
        });
    }
    rows.sort((a, b) => (a.uri < b.uri ? -1 : a.uri > b.uri ? 1 : (a.name || '') < (b.name || '') ? -1 : 1));
    const out = {
        _provenance:
            'php artisan route:list --json (fc_app), GET routes only, normalized to {uri,name,middleware}. Refresh: tests/browser/roster/refresh-route-list.mjs',
        _capturedAt: new Date().toISOString().slice(0, 10),
        routes: rows,
    };
    fs.writeFileSync(path.join(HERE, 'route-list.json'), JSON.stringify(out, null, 1));
    process.stderr.write(`route-list.json written: ${rows.length} GET routes\n`);
});
