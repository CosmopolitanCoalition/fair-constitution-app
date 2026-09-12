import fs from 'node:fs';
import path from 'node:path';
import { PLAYER_NAV, SITEMAP, TOUR, FIRST_VISIT } from '../../../resources/js/registry/surfaces.js';

const root = process.cwd();
const out = path.join(root, 'docs/audits/2026-09-12');
const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(e => e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]);
const rel = file => path.relative(root, file).replaceAll('\\', '/');
const routes = JSON.parse(fs.readFileSync(path.join(out, 'routes.json'), 'utf8').replace(/^\uFEFF/, ''));
const files = walk(path.join(root, 'app/Http/Controllers')).filter(f => f.endsWith('.php'));
const renderRefs = [];
for (const file of [...files, ...walk(path.join(root, 'routes')).filter(f => f.endsWith('.php'))]) {
  const src = fs.readFileSync(file, 'utf8');
  const methods = [...src.matchAll(/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/g)];
  const namespace = src.match(/namespace\s+([^;]+);/)?.[1];
  const className = src.match(/class\s+(\w+)/)?.[1];
  for (const match of src.matchAll(/Inertia::render\(\s*(['"])([A-Za-z0-9_/-]+)\1/g)) {
    const method = methods.filter(m => m.index < match.index).at(-1)?.[1];
    const action = namespace && className && method ? `${namespace}\\${className}@${method}` : null;
    let reads = action ? routes.filter(r => r.action === action && r.method.includes('GET')).map(r => '/' + r.uri.replace(/^\//, '')) : [];
    if (!action) {
      const declaration = [...src.slice(0, match.index).matchAll(/Route::get\(\s*['"]([^'"]+)['"]/g)].at(-1);
      if (declaration) reads = [declaration[1]];
    }
    renderRefs.push({ page: match[2], file: rel(file), line: src.slice(0, match.index).split('\n').length, action, routes: reads });
  }
}
const pages = walk(path.join(root, 'resources/js/Pages')).filter(f => f.endsWith('.vue')).map(file => {
  const name = rel(file).replace('resources/js/Pages/', '').replace(/\.vue$/, '');
  const refs = renderRefs.filter(r => r.page === name);
  return { page: name, file: rel(file), group: name.includes('/') ? name.split('/')[0] : 'Arrival', references: refs, routes: [...new Set(refs.flatMap(r => r.routes))] };
});
const nav = [...PLAYER_NAV.map(n => ({ ...n, section: 'Go' })), ...SITEMAP.flatMap(s => s.items.map(n => ({ ...n, section: s.title })))];
const byHref = Object.groupBy(nav.filter(n => n.href && n.href !== 'tour:start'), n => n.href);
const repeatedDestinations = Object.entries(byHref).filter(([,rows]) => rows.length > 1).map(([href, rows]) => ({ href, entries: rows.map(r => ({ section: r.section, label: r.label })) }));
const data = { generatedAt: new Date().toISOString(), evidenceLevel: 'Source inventory. Static render mapping is best effort; dynamic renderers and redirects need review.', counts: { pages: pages.length, routes: routes.length, playerNavEntries: PLAYER_NAV.length, sitemapEntries: SITEMAP.reduce((n,s)=>n+s.items.length,0), sitemapSections: SITEMAP.length, tourStops: TOUR.length }, groups: Object.entries(Object.groupBy(pages, p => p.group)).map(([name, rows])=>({name,count:rows.length})), nav, tour: TOUR, firstVisit: FIRST_VISIT, repeatedDestinations, pages };
fs.writeFileSync(path.join(out, 'source-inventory.json'), JSON.stringify(data,null,2)+'\n');
console.log(JSON.stringify({counts:data.counts, groups:data.groups, repeatedDestinations, staticRouteGaps:pages.filter(p=>!p.routes.length).map(p=>p.page)},null,2));
