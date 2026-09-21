// Export the original Coalition artwork for browsers and OS launchers. No redraw.
// Source: operator's Image Kit/Gold-Purple-Logo.svg, with its original embedded
// branches retained. The public SVG adds only a square purple canvas and padding.
import { chromium } from '@playwright/test';
import fs from 'node:fs';

const browser = await chromium.launch({ headless: true, ...(process.platform === 'win32' ? { channel: 'msedge' } : {}) });
try {
    const svg = fs.readFileSync('public/app-icons/icon.svg', 'utf8');
    for (const size of [32, 180, 192, 512]) {
        const page = await browser.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 });
        await page.setContent(`<style>body{margin:0;background:#643264}svg{width:${size}px;height:${size}px}</style>${svg}`);
        await page.evaluate(() => Promise.all([...document.images].map(image => image.decode().catch(() => {}))));
        await page.screenshot({ path: `public/app-icons/icon-${size}.png` });
        await page.close();
    }
    // ICO directory pointing to the exported 32px PNG (supported by modern browsers/Windows).
    const png = fs.readFileSync('public/app-icons/icon-32.png');
    const header = Buffer.alloc(22);
    header.writeUInt16LE(1, 2); header.writeUInt16LE(1, 4);
    header[6] = 32; header[7] = 32;
    header.writeUInt16LE(1, 10); header.writeUInt16LE(32, 12);
    header.writeUInt32LE(png.length, 14); header.writeUInt32LE(22, 18);
    fs.writeFileSync('public/favicon.ico', Buffer.concat([header, png]));
} finally { await browser.close(); }
