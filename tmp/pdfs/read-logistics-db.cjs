const fs = require('fs');
const zlib = require('zlib');
const input = 'C:/Users/sakhadka/Downloads/_7120209443620f3dfb41ff9936763b66e6417e-DB Design_ Logistics-181126-112140.pdf';
const raw = fs.readFileSync(input).toString('latin1');
const objects = new Map([...raw.matchAll(/(\d+) 0 obj\s*([\s\S]*?)endobj/g)].map(m => [+m[1], m[2]]));
function stream(body) {
  const m = /stream\r?\n/.exec(body || '');
  if (!m) return '';
  const bytes = Buffer.from(body.slice(m.index + m[0].length, body.lastIndexOf('endstream')), 'latin1');
  try { return zlib.inflateSync(bytes).toString('latin1'); } catch { return ''; }
}
const unicode = hex => (hex.match(/.{4}/g) || []).map(x => String.fromCharCode(parseInt(x, 16))).join('');
const cmaps = new Map();
for (const [id, body] of objects) {
  const mapRef = /\/ToUnicode (\d+) 0 R/.exec(body);
  if (!mapRef) continue;
  const source = stream(objects.get(+mapRef[1]));
  const map = new Map();
  for (const block of source.matchAll(/beginbfchar([\s\S]*?)endbfchar/g)) for (const m of block[1].matchAll(/<([\da-f]+)>\s*<([\da-f]+)>/gi)) map.set(parseInt(m[1],16), unicode(m[2]));
  for (const block of source.matchAll(/beginbfrange([\s\S]*?)endbfrange/g)) for (const m of block[1].matchAll(/<([\da-f]+)>\s*<([\da-f]+)>\s*<([\da-f]+)>/gi)) {
    const first = parseInt(m[1],16), last = parseInt(m[2],16), start = parseInt(m[3],16);
    for (let n=first;n<=last;n++) map.set(n,String.fromCodePoint(start+n-first));
  }
  cmaps.set(id, { map, width: /<0000>/.test(source) ? 4 : 2 });
}
const pages = [...objects].filter(([,body]) => /\/Type \/Page\b/.test(body));
const output = [];
for (const [id, page] of pages) {
  const fonts = new Map([...page.matchAll(/\/(F\d+) (\d+) 0 R/g)].map(m => [m[1],cmaps.get(+m[2])]));
  const contentRef = /\/Contents (\d+) 0 R/.exec(page);
  if (!contentRef) throw new Error('Unsupported page content ' + id);
  const content = stream(objects.get(+contentRef[1]));
  const lines = [];
  let font;
  for (const text of content.matchAll(/BT([\s\S]*?)ET/g)) {
    let decoded='';
    for (const t of text[1].matchAll(/\/(F\d+)\s+[\d.]+\s+Tf|<([\da-f]+)>\s*Tj/gim)) {
      if (t[1]) { font = fonts.get(t[1]); continue; }
      if (!font) throw new Error('Unknown font on page '+id);
      for(let i=0;i<t[2].length;i+=font.width) decoded += font.map.get(parseInt(t[2].slice(i,i+font.width),16)) || '�';
    }
    if(decoded.trim()) lines.push(decoded);
  }
  output.push('PAGE '+(output.length+1)+'\n'+lines.join('\n'));
}
fs.writeFileSync('tmp/pdfs/logistics-db-reference.txt',output.join('\n\n'));
console.log(JSON.stringify({pages:pages.length, fonts:cmaps.size, output:'tmp/pdfs/logistics-db-reference.txt', unknownGlyphs: output.join('').split('�').length-1}));
