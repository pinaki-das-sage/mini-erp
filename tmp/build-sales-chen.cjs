const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require('../node_modules/playwright');
const md = fs.readFileSync('docs/sales-and-order-entry-db-design.md','utf8');
const tables = new Map();
for (const m of md.matchAll(/CREATE TABLE (\w+) \(\n([\s\S]*?)\n\);/g)) {
  const cols = [...m[2].matchAll(/^    ([\w#]+)\s+(?:NUMBER|VARCHAR2|CHAR|TIMESTAMP|DATE)\b/gm)].map(x => x[1]);
  const pk = /PRIMARY KEY \(([^)]+)\)/.exec(m[2])[1].split(',').map(x => x.trim());
  tables.set(m[1], {cols,pk});
}
assert.equal(tables.size,5);
tables.set('ME_COMPANY',{cols:['ID'],pk:['ID'],shared:true});
tables.set('ME_ACTIONS',{cols:['COMPANY_ID','ACTION_REFERENCE','ACTOR_ID','CREATED_AT'],pk:['COMPANY_ID','ACTION_REFERENCE'],shared:true});
const W=6600,H=5750;
const entities = {
  ME_COMPANY:{x:2500,y:230,w:420,h:86},
  ME_OE_CUSTOMERS:{x:1250,y:1400,w:560,h:90},
  ME_OE_DOCUMENTS:{x:4300,y:1400,w:560,h:90},
  ME_OE_DOCUMENT_LINES:{x:5450,y:3550,w:650,h:90},
  ME_OE_SOURCE_LINKS:{x:2900,y:3100,w:610,h:90},
  ME_OE_PAYMENTS:{x:3050,y:4750,w:540,h:90},
  ME_ACTIONS:{x:1000,y:4750,w:430,h:86},
};
const rels = {
  ownsCustomer:{x:2300,y:650,w:310,h:170,label:['OWNS']},
  ownsAction:{x:160,y:3450,w:260,h:150,label:['OWNS']},
  customerFor:{x:2750,y:1400,w:430,h:220,label:['CUSTOMER','FOR']},
  contains:{x:4800,y:2800,w:380,h:200,label:['CONTAINS']},
  source:{x:2750,y:2150,w:430,h:220,label:['SOURCE','DOCUMENT']},
  target:{x:3350,y:2650,w:430,h:220,label:['TARGET','DOCUMENT']},
  copy:{x:5670,y:2720,w:380,h:210,label:['COPIED','FROM']},
  receives:{x:3800,y:4100,w:430,h:230,label:['RECEIVES','PAYMENT']},
  recording:{x:2000,y:4750,w:380,h:200,label:['RECORDED','BY']},
};
const attrs=[];
function wrap(text) {
  const words=text.split('_'),lines=[];let current='';
  for(const word of words){if(current && current.length+word.length+1>17){lines.push(current);current=word;}else current+=(current?'_':'')+word;}
  if(current)lines.push(current);return lines;
}
function attribute(table,col,x,y) {
  const lines=wrap(col),w=Math.max(140,...lines.map(t=>t.length*17+70)),h=lines.length*34+36;
  attrs.push({table,col,lines,x,y,ox:x,oy:y,w,h,key:tables.get(table).pk.includes(col)});
}
function fan(table,rx,ry,start,end) {
  const {x,y}=entities[table],cols=tables.get(table).cols;
  cols.forEach((col,i)=>{const a=(start+(end-start)*i/Math.max(1,cols.length-1))*Math.PI/180;attribute(table,col,x+rx*Math.cos(a),y+ry*Math.sin(a));});
}
fan('ME_OE_CUSTOMERS',830,1050,55,305);
fan('ME_OE_DOCUMENTS',1100,1100,-130,65);
fan('ME_OE_DOCUMENT_LINES',760,700,-20,180);
fan('ME_OE_PAYMENTS',900,550,-20,155);
fan('ME_ACTIONS',790,540,45,255);
attribute('ME_COMPANY','ID',3000,230);
attribute('ME_OE_SOURCE_LINKS','RECORD#',2480,3400);
attribute('ME_OE_SOURCE_LINKS','CNY#',3280,3400);
attribute('ME_OE_SOURCE_LINKS','SOURCE_DOCUMENT_RECORD#',2520,3700);
attribute('ME_OE_SOURCE_LINKS','TARGET_DOCUMENT_RECORD#',3280,3700);
attribute('ME_OE_SOURCE_LINKS','WHENCREATED',2170,3410);
attribute('ME_OE_SOURCE_LINKS','WHENMODIFIED',3620,3410);
// Separate labels without changing the entity/relationship layout.
const fixed=[...Object.values(entities),...Object.values(rels)];
for(let round=0;round<400;round++){
 let changes=0;
 for(let i=0;i<attrs.length;i++)for(const b of [...attrs.slice(i+1),...fixed]){
  const a=attrs[i],dx=a.x-b.x,dy=a.y-b.y,px=(a.w+b.w)/2+20-Math.abs(dx),py=(a.h+b.h)/2+20-Math.abs(dy);
  if(px<=0||py<=0)continue;
  const mobile=attrs.includes(b),f=mobile ? 0.51 : 1.02;
  if(px<py){const v=(dx>=0?1:-1)*px*f;a.x+=v;if(mobile)b.x-=v;}
  else{const v=(dy>=0?1:-1)*py*f;a.y+=v;if(mobile)b.y-=v;}
  changes++;
 }
 if(!changes)break;
}
for(const a of attrs){assert(a.x-a.w/2>0&&a.x+a.w/2<W&&a.y-a.h/2>170&&a.y+a.h/2<5480,a.table+'.'+a.col+' outside canvas');}
const esc=s=>String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
const edges=[],shapes=[];
const label=(x,y,lines,size=30,extra='')=>`<text x="${x}" y="${y-(lines.length-1)*size*.6}" font-size="${size}" ${extra}>${lines.map((s,i)=>`<tspan x="${x}" dy="${i?size*1.2:0}">${esc(s)}</tspan>`).join('')}</text>`;
function wire(points,dashed=false){edges.push(`<polyline points="${points.map(p=>p.join(',')).join(' ')}" fill="none" stroke="#303030" stroke-width="3" ${dashed?'stroke-dasharray="13 10"':''}/>`);}
function cardinal(x,y,t){shapes.push(label(x,y,[t],35,'class="card"'));}
function boundary(e,other,ellipse=false){const dx=other.x-e.x,dy=other.y-e.y;const k=ellipse?1/Math.sqrt((dx/(e.w/2))**2+(dy/(e.h/2))**2):Math.min(e.w/2/Math.abs(dx||.0001),e.h/2/Math.abs(dy||.0001));return[e.x+dx*k,e.y+dy*k];}
for(const a of attrs){const e=entities[a.table];wire([boundary(e,a),boundary(a,e,true)]);shapes.push(`<ellipse cx="${a.x}" cy="${a.y}" rx="${a.w/2}" ry="${a.h/2}" fill="white" stroke="#333" stroke-width="3"/>`+label(a.x,a.y,a.lines,28,a.key?'text-decoration="underline"':''));}
// Business relationship paths; numbers are placed at the corresponding entity ends.
wire([[2500,273],[2500,490],[2300,565]]);wire([[2180,680],[1530,1380]]);cardinal(2550,365,'1');cardinal(1650,1230,'N');
wire([[2290,230],[80,230],[80,3375]]);wire([[80,3525],[80,4750],[785,4750]]);cardinal(2150,190,'1');cardinal(670,4700,'N');
wire([[1530,1400],[2535,1400]]);wire([[2965,1400],[4020,1400]]);cardinal(1650,1350,'1');cardinal(3900,1350,'N');
wire([[4300,1445],[4300,2650],[4610,2800]]);wire([[4990,2800],[5250,3505]]);cardinal(4350,1600,'1');cardinal(5180,3390,'N');
wire([[4020,1420],[3500,1900],[2965,2150]]);wire([[2750,2260],[2750,2920],[2800,3055]]);cardinal(3890,1530,'1');cardinal(2680,2950,'0..1');
wire([[4100,1445],[3850,2200],[3565,2650]]);wire([[3350,2760],[3205,3070]]);cardinal(4020,1680,'1');cardinal(3350,2960,'0..1');
wire([[5280,3505],[5200,3000],[5480,2720]]);wire([[5860,2720],[6060,2900],[5630,3505]]);cardinal(5250,3370,'0..1');cardinal(5750,3370,'0..1');
wire([[4460,1445],[4460,3880],[4015,4100]]);wire([[3800,4215],[3290,4705]]);cardinal(4520,1620,'1');cardinal(3440,4590,'N');
wire([[1215,4750],[1810,4750]]);wire([[2190,4750],[2780,4750]]);cardinal(1370,4700,'1');cardinal(2590,4700,'0..1');
for(const [name,e]of Object.entries(entities)){const shared=tables.get(name).shared;shapes.push(`<rect x="${e.x-e.w/2}" y="${e.y-e.h/2}" width="${e.w}" height="${e.h}" fill="${shared?'#f4f4f4':'#e7e7e7'}" stroke="#222" stroke-width="4"/>`+label(e.x,e.y,[name],31,'font-weight="600"'));if(shared)shapes.push(label(e.x,e.y+e.h/2+32,['existing shared table'],23));}
for(const r of Object.values(rels)){shapes.push(`<polygon points="${r.x},${r.y-r.h/2} ${r.x+r.w/2},${r.y} ${r.x},${r.y+r.h/2} ${r.x-r.w/2},${r.y}" fill="#eee" stroke="#333" stroke-width="3"/>`+label(r.x,r.y,r.label,29));}
const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-labelledby="title desc"><title id="title">Sales and Order Entry Chen ER diagram</title><desc id="desc">Five Sales tables and two existing shared tables. Rectangles are entities, ellipses are attributes, diamonds are relationships. Underlined attributes are primary keys. Customer Code is the business identifier. All Sales primary keys are CNY# plus RECORD#. Customer balance is stored and server maintained.</desc><style>text{font-family:Arial,Helvetica,sans-serif;text-anchor:middle;dominant-baseline:middle;fill:#222}.card{paint-order:stroke;stroke:white;stroke-width:13;stroke-linejoin:round}</style><rect width="100%" height="100%" fill="white"/>${label(80,65,['Sales and Order Entry'],48,'text-anchor="start" style="text-anchor:start" font-weight="600"')}${label(80,125,['Chen ER diagram | Database design proposal'],29,'style="text-anchor:start"')}${edges.join('')}${shapes.join('')}<g>${label(100,5530,['Rectangle = entity     Oval = attribute     Diamond = relationship     Underline = primary key'],30,'style="text-anchor:start"')}${label(100,5590,['1 = one     N = many     0..1 = optional, at most one     Sales references include CNY#'],30,'style="text-anchor:start"')}${label(100,5650,['All Sales CNY# columns reference ME_COMPANY.ID; repeated Company edges are omitted. Payments reference Invoices only.'],28,'style="text-anchor:start"')}</g></svg>`;
fs.mkdirSync('output/diagrams',{recursive:true});
fs.writeFileSync('output/diagrams/sales-and-order-entry-chen.svg',svg);
fs.writeFileSync('output/diagrams/sales-and-order-entry-chen.html',`<!doctype html><meta charset="utf-8"><title>Sales and Order Entry ER Diagram</title><style>body{margin:0;background:#fff}svg{display:block}</style>${svg}`);
(async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:W,height:H},deviceScaleFactor:1});
  await page.goto('file:///'+path.resolve('output/diagrams/sales-and-order-entry-chen.html').replaceAll('\\','/'));
  await page.screenshot({path:'output/diagrams/sales-and-order-entry-chen.png',fullPage:true});
  await page.setViewportSize({width:1650,height:1438});
  await page.addStyleTag({content:'svg{width:1650px;height:auto}'});
  await page.screenshot({path:'tmp/sales-chen-preview.png',fullPage:true});
  console.log(JSON.stringify({entities:tables.size,attributes:attrs.length,relationships:Object.keys(rels).length,svg:'output/diagrams/sales-and-order-entry-chen.svg',png:'output/diagrams/sales-and-order-entry-chen.png'}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
