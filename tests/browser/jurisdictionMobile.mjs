// Real Inertia page + Leaflet + shared shell, with bounded read-only geography
// fixtures served through Vite. No installed database or remote service is used.
import { chromium, firefox, expect as baseExpect } from '@playwright/test';
import fs from 'node:fs';
import assert from 'node:assert/strict';

const earth={id:'00000000-0000-4000-8000-000000000001',slug:'test-earth',name:'Earth',adm_label:'World',adm_level:0,population:8000000000};
const country={id:'00000000-0000-4000-8000-000000000002',slug:'test-country',name:'Test Country',adm_label:'Country',adm_level:1,population:100000};
const collection=features=>({type:'FeatureCollection',features});
const polygon=(place,extent)=>({type:'Feature',properties:{...place,centroid_lat:0,centroid_lng:0,child_count:place===earth?1:0},
 geometry:{type:'Polygon',coordinates:[[[-extent,-Math.min(75,extent)],[extent,-Math.min(75,extent)],[extent,Math.min(75,extent)],[-extent,Math.min(75,extent)],[-extent,-Math.min(75,extent)]]]}});
function payload(path){
 const place=path.includes(country.slug)?country:earth;
 return {component:'Jurisdictions/Show',url:path,version:'map-layout-test',clearHistory:false,encryptHistory:false,
 props:{locale:'en',auth:{user:null,roles:['R-00']},flash:{},errors:{},
  shellInstance:{name:'United Earth – Demo',host:'fixture.test',setupComplete:true},
  surface:{id:'jurisdictions/map',title:'Jurisdiction map',citation:'Every place governs itself.'},
  jurisdictionContext:{current:place,chain:place===earth?[earth]:[earth,country]},
  jurisdiction:place,ancestors:place===earth?[]:[earth],hasChildren:place===earth,childCount:place===earth?1:0,
  map_acceptance:{is_planet_scope:place===earth,setup_completed_at:'2026-09-20'},
  legislature_id:'test-legislature',has_district_map:true,chamber_seated:true,executive_id:'test-executive',judiciary_id:'test-court'}};
}
const origin='http://localhost:5173';
const expect=baseExpect.configure({timeout:15000});
for(const engine of ['chromium','firefox']){
 const browser=await(engine==='chromium'?chromium.launch({channel:'msedge',headless:true}):firefox.launch({headless:true}));
 try{
  const context=await browser.newContext({viewport:{width:390,height:844},...(engine==='chromium'?{hasTouch:true,isMobile:true}:{})});
  // Fixture documents need no live-reload socket. Keep Vite's client connected
  // without relying on the browser's local-network permission for WebSockets.
  await context.routeWebSocket('ws://localhost:5173/**',socket=>socket.send(JSON.stringify({type:'connected'})));
  await context.route('**/i18n/en.json',route=>route.fulfill({status:200,contentType:'application/json',body:fs.readFileSync('public/i18n/en.json','utf8')}));
  await context.route('**/api/**',async route=>{
   const path=new URL(route.request().url()).pathname;
   let response={};
   if(path.includes('/jurisdictions/')){
    const place=path.includes(country.id)?country:earth;
    response=collection(path.endsWith('/self.geojson')?[polygon(place,place===earth?170:25)]:path.endsWith('/children.geojson')?[polygon(country,25)]:[]);
   }
   await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(response)});
  });
  await context.route('**/*.pmtiles',route=>route.fulfill({status:404,body:''}));
  await context.route('**/sw.js',route=>route.abort());
  await context.route('**/jurisdictions/test-*/map',async route=>{
   const path=new URL(route.request().url()).pathname, data=payload(path);
   if(route.request().headers()['x-inertia']){
    await route.fulfill({status:200,contentType:'application/json',headers:{'X-Inertia':'true'},body:JSON.stringify(data)});
   }else{
    await route.fulfill({status:200,contentType:'text/html',body:`<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/resources/css/app.css"></head><body><div id="app"></div><script>document.getElementById('app').dataset.page=${JSON.stringify(JSON.stringify(data))};</script><script type="module" src="/resources/js/app.js"></script></body></html>`});
   }
  });
  const page=await context.newPage();page.setDefaultTimeout(60000);page.setDefaultNavigationTimeout(90000);
  const errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error(engine,e.message)});
  page.on('console',msg=>{if(msg.type()==='error')console.error(engine,msg.text())});
  await page.goto(origin+'/jurisdictions/test-earth/map');
  const map=page.locator('#jurisdiction-map');
  await expect(map.locator('.leaflet-overlay-pane path').first()).toBeVisible({timeout:90000});
  await expect(page.getByText('Loading map…',{exact:true})).toHaveCount(0);
  async function assertMobile(width,height){
   await page.setViewportSize({width,height});
   await expect(page.getByRole('button',{name:'Details',exact:true})).toBeVisible();
   await expect(page.locator('#jurisdiction-details')).toBeHidden();
   await expect.poll(async()=>Math.round((await map.boundingBox()).width)).toBeGreaterThanOrEqual(width-2);
   const geometry=await page.evaluate(()=>({width:document.documentElement.scrollWidth,viewport:innerWidth,
    map:document.getElementById('jurisdiction-map').getBoundingClientRect().toJSON(),
    footer:document.querySelector('.app-footer').getBoundingClientRect().toJSON(),cmdbar:document.querySelector('.cmdbar').getBoundingClientRect().toJSON()}));
   assert.ok(geometry.width<=width+1,JSON.stringify(geometry));
   assert.ok(geometry.map.height>=(height<450?120:180),JSON.stringify(geometry));
   assert.ok(geometry.map.bottom<=geometry.footer.top+1);
   assert.ok(geometry.footer.bottom<=geometry.cmdbar.top+1);
  }
  for(const dimensions of [[390,844],[320,568],[768,1024],[844,390],[390,844]])await assertMobile(...dimensions);
  for(const button of await page.getByRole('group',{name:'Map layers'}).getByRole('button').all()){
   const box=await button.boundingBox();assert.ok(box.width>=44&&box.height>=44);
  }
  const names=page.getByRole('button',{name:'Names',exact:true});await names.click();await expect(names).toHaveAttribute('aria-pressed','true');
  await page.getByRole('button',{name:'Details',exact:true}).click();
  await expect(map).toBeHidden();await expect(page.locator('#jurisdiction-details')).toBeVisible();
  await expect(page.getByRole('link',{name:'Jurisdiction overview →',exact:true})).toHaveAttribute('href','/jurisdictions/test-earth');
  await expect(page.getByRole('link',{name:'Legislative maps →',exact:true})).toHaveAttribute('href','/legislatures/test-earth/districts');
  await page.getByRole('button',{name:'Back to map',exact:true}).press('Escape');
  await expect(page.getByRole('button',{name:'Details',exact:true})).toBeFocused();await expect(map).toBeVisible();
  await expect(map.locator('.leaflet-overlay-pane path').first()).toBeVisible();
  const pathBeforeZoom=await map.locator('.leaflet-overlay-pane path').first().getAttribute('d');
  const zoom=map.locator('.leaflet-control-zoom-in');await zoom.click();
  await expect.poll(async()=>map.locator('.leaflet-overlay-pane path').first().getAttribute('d')).not.toBe(pathBeforeZoom);
  await page.locator('.footer-mobile-help summary').click();
  await expect(page.locator('.footer-mobile-help').getByRole('link',{name:'Report an issue',exact:true})).toBeVisible();
  await page.locator('.footer-mobile-help summary').click();
  await page.screenshot({path:`storage/framework/testing/jurisdiction-mobile-${engine}.png`});
  // The child covers the centre of our bounded geometry; a tap stays in the map route.
  const box=await map.boundingBox();
  if(engine==='chromium')await page.touchscreen.tap(box.x+box.width/2,box.y+box.height/2);
  else await page.mouse.click(box.x+box.width/2,box.y+box.height/2);
  await page.waitForURL('**/jurisdictions/test-country/map');
  await expect(page.getByText('Loading map…',{exact:true})).toHaveCount(0);
  await page.getByRole('button',{name:'Details',exact:true}).click();
  await page.locator('#jurisdiction-details').getByRole('link',{name:'Earth',exact:true}).click();
  await page.waitForURL('**/jurisdictions/test-earth/map');
  await expect(page.getByRole('button',{name:'Details',exact:true})).toBeVisible();
  await page.setViewportSize({width:1440,height:900});
  await expect(page.locator('#jurisdiction-details')).toBeVisible();await expect(page.locator('.jurisdiction-mobile-toolbar')).toBeHidden();
  await expect(page.getByText('Loading map…',{exact:true})).toHaveCount(0,{timeout:60000});
  await expect(map.locator('.leaflet-overlay-pane path').first()).toBeVisible();
  assert.ok((await map.boundingBox()).width>=1100);
  await expect(page.locator('.footer-standard').getByRole('link',{name:'Report an issue',exact:true})).toBeVisible();
  await page.screenshot({path:`storage/framework/testing/jurisdiction-desktop-${engine}.png`});
  await page.setViewportSize({width:390,height:844});await page.evaluate(()=>document.documentElement.dir='rtl');await assertMobile(390,844);
  assert.deepEqual(errors,[]);
  console.log(`PASS ${engine}: 320–1440px, portrait/landscape, touch controls, details/resize, child/parent map navigation, footer links and RTL`);
 }finally{await browser.close();}
}
