// Tribune Breizh · fabrique les visuels Instagram (JPEG) à partir du site.
// Usage : node bot/render.js <dossier_du_site> <dossier_de_sortie> [id ...]
//   ids possibles : derby classement resultats buteurs face rhum story (tous par défaut)
// Le dossier du site doit contenir index.html (généré par build.py) et fonts/.
// Les visuels sont dessinés par la page elle-même (onglet Visuels), exactement comme sur le site.
const { chromium } = require("playwright");
const http = require("http"), fs = require("fs"), path = require("path");

const [root, out, ...want] = process.argv.slice(2);
if (!root || !out) { console.error("Usage : node bot/render.js <site> <sortie> [id ...]"); process.exit(2); }
fs.mkdirSync(out, { recursive: true });
const TYPES = { ".html": "text/html; charset=utf-8", ".woff": "font/woff", ".png": "image/png", ".svg": "image/svg+xml" };

const srv = http.createServer((q, r) => {
  let p = path.join(root, decodeURIComponent(q.url.split("?")[0]));
  if (!p.startsWith(path.resolve(root))) { r.writeHead(403); return r.end(); }
  if (p.endsWith("/")) p += "index.html";
  fs.readFile(p, (e, d) => {
    if (e) { r.writeHead(404); return r.end(); }
    r.writeHead(200, { "content-type": TYPES[path.extname(p)] || "application/octet-stream" }); r.end(d);
  });
}).listen(0, "127.0.0.1", async () => {
  let code = 0;
  const b = await chromium.launch();
  try {
    const p = await b.newPage({ viewport: { width: 1200, height: 900 } });
    // L'onglet Visuels n'apparaît que pour le propriétaire : on simule ce rôle, sans rien d'autre.
    await p.addInitScript(() => { window.claude = { use: async n => (n === "user" ? { isOwner: async () => true } : null) }; });
    const errs = []; p.on("pageerror", e => errs.push(e.message));
    await p.goto(`http://127.0.0.1:${srv.address().port}/#visuels`, { waitUntil: "load" });
    await p.waitForFunction(() => document.querySelectorAll("canvas.vis").length > 0, null, { timeout: 15000 });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(2000);
    const ids = (await p.$$eval("canvas.vis", cs => cs.map(c => c.id.replace(/^cv-/, "")))).filter(id => !want.length || want.includes(id));
    for (const id of ids) {
      const data = await p.$eval("#cv-" + id, c => c.toDataURL("image/jpeg", 0.92));
      const f = path.join(out, id + ".jpg");
      fs.writeFileSync(f, Buffer.from(data.split(",")[1], "base64"));
      console.log("ok " + f);
    }
    const missing = want.filter(w => !ids.includes(w));
    if (missing.length) { console.error("Visuels introuvables : " + missing.join(", ")); code = 1; }
    if (errs.length) { console.error("Erreurs dans la page : " + errs.join(" | ")); code = 1; }
  } catch (e) { console.error(e.message); code = 1; }
  await b.close(); srv.close(); process.exit(code);
});
