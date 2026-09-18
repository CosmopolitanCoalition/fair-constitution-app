// node --experimental-vm-modules --test tests/js/composeRestartPolicy.test.mjs
//
// Every long-running service returns after a host stop/start (WoS demo box
// 2026-09-19: the etl service had no restart policy, so after a VM stop and
// start the ETL supervisor stayed "Exited (0)" while the geodata pumps kept
// ticking). DB-free and Docker-free: reads docker-compose.yml as text.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const lines = readFileSync(path.join(root, 'docker-compose.yml'), 'utf8').split(/\r?\n/);

// The service blocks: two-space keys between the top-level `services:` key and
// the next top-level key.
function serviceBlocks() {
    const blocks = new Map();
    let inServices = false;
    let current = null;
    for (const line of lines) {
        if (/^[a-z_]+:\s*$/.test(line)) {
            inServices = line.startsWith('services:');
            current = null;
            continue;
        }
        if (!inServices) continue;
        const m = line.match(/^ {2}([a-z0-9_-]+):\s*$/);
        if (m) {
            current = m[1];
            blocks.set(current, []);
            continue;
        }
        if (current) blocks.get(current).push(line);
    }
    return blocks;
}

test('the parser finds the services, the etl among them', () => {
    const names = [...serviceBlocks().keys()];
    for (const s of ['app', 'postgres', 'horizon', 'scheduler', 'etl']) {
        assert.ok(names.includes(s), `service ${s} is parsed`);
    }
});

test('every service carries restart: unless-stopped', () => {
    const missing = [];
    for (const [name, body] of serviceBlocks()) {
        if (!body.some((l) => /^ {4}restart:\s*unless-stopped\s*$/.test(l))) missing.push(name);
    }
    assert.deepEqual(missing, [], `services with no restart policy: ${missing.join(', ')}`);
});
