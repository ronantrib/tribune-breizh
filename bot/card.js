// Tribune Breizh · fabrique une « carte » Instagram (JPEG 1080×1350, ou 1080×1920 pour une story) aux couleurs du site.
// Usage : node bot/card.js carte.json sortie.jpg
// carte.json : {
//   "kicker": "Le saviez-vous ?",            petit titre en couleur (court)
//   "title":  "Brest, roi du hand",          grand titre (40 caractères au plus conseillé)
//   "lines":  ["…", "…"],                    2 à 6 lignes de texte (phrases courtes)
//   "rows":   [["Sam. 31 · 19h", "Rennes – Nice", "Ligue 1+"], …]   (facultatif) tableau de 2 ou 3 colonnes, 8 lignes au plus
//   "color":  "foot|hand|rugby|basket|voile|velo|amateur|accent",   (facultatif) couleur du thème
//   "foot":   "Source : …"                   (facultatif) petite ligne en bas
//   "format": "story"                        (facultatif) 1080×1920 pour une story, marges adaptées à l'interface d'Instagram
// }
// Le texte vient des données du site : la carte ne fait que les mettre en forme.
const { chromium } = require("playwright");
const fs = require("fs"), path = require("path"), os = require("os");

const [src, out] = process.argv.slice(2);
if (!src || !out) { console.error("Usage : node bot/card.js carte.json sortie.jpg"); process.exit(2); }
const c = JSON.parse(fs.readFileSync(src, "utf8"));
const COL = { accent: "#ff4d63", foot: "#3ecf6e", hand: "#f2a33a", rugby: "#b38cff", basket: "#ff8a3d", voile: "#4fc0dd", velo: "#f2d33a", amateur: "#7fd6a4" };
const col = COL[c.color] || COL.accent;
const STORY = c.format === "story", H = STORY ? 1920 : 1350;
const esc = s => String(s ?? "").replace(/[&<>"]/g, m => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[m]));
const fonts = path.resolve(__dirname, "..", "fonts");
if (!c.title || !Array.isArray(c.lines) || !c.lines.length) { console.error("Il faut au moins title et lines."); process.exit(2); }
if (c.lines.length > 6 || (c.rows && c.rows.length > 8)) { console.error("Trop de lignes (lines : 6 au plus, rows : 8 au plus)."); process.exit(2); }

const FLAG = `<svg viewBox="0 0 90 60" width="84" height="56"><rect width="90" height="60" fill="#fff"/>${[0, 2, 4, 6, 8].map(i => `<rect y="${i * 6.67}" width="90" height="6.67" fill="#121417"/>`).join("")}<rect width="40" height="33.33" fill="#fff"/><g fill="#121417">${[[6.5, 8], [15.5, 8], [24.5, 8], [33.5, 8], [11, 17.5], [20, 17.5], [29, 17.5], [6.5, 27], [15.5, 27], [24.5, 27], [33.5, 27]].map(([x, y]) => `<g transform="translate(${x} ${y})"><circle cx="-1.6" cy="-2.4" r="1.05"/><circle cx="0" cy="-3.3" r="1.05"/><circle cx="1.6" cy="-2.4" r="1.05"/><path d="M0-1.8L2.1 3.6 0 2.6-2.1 3.6Z"/></g>`).join("")}</g></svg>`;

const html = `<!doctype html><html><head><meta charset="utf-8"><style>
@font-face{font-family:"BSD";src:url("file://${fonts}/big-shoulders-display.woff");font-weight:600 900}
@font-face{font-family:"Arc";src:url("file://${fonts}/archivo.woff");font-weight:400 700}
html,body{margin:0;width:1080px;height:${H}px;overflow:hidden}
body{background:#121417;color:#f5f6f4;font-family:"Arc",sans-serif;position:relative}
.str{position:absolute;right:0;top:0;width:300px;height:100%;background:repeating-linear-gradient(180deg,#f5f6f4 0 56px,#121417 56px 112px);opacity:.06}
.bar{position:absolute;left:0;top:0;bottom:0;width:18px;background:${col}}
.w{position:absolute;left:90px;right:90px;top:${STORY ? 240 : 80}px;bottom:${STORY ? 280 : 80}px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:20px;font-family:"BSD";font-weight:900;font-size:48px;text-transform:uppercase}
.brand em{font-style:normal;color:#ff4d63}
.k{margin-top:${STORY ? 150 : 70}px;font-size:30px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:${col}}
h1{margin:14px 0 0;font-family:"BSD";font-weight:900;font-size:${c.title.length > 26 ? 92 : STORY ? 130 : 116}px;line-height:.92;text-transform:uppercase;text-wrap:balance}
.l{margin-top:36px;display:flex;flex-direction:column;gap:18px}
.l p{margin:0;font-size:${c.rows ? 32 : 38}px;line-height:1.35;font-weight:500}
table{margin-top:34px;border-collapse:collapse;width:100%}
td{padding:16px 0;border-top:2px solid rgba(255,255,255,.12);font-size:31px;font-weight:600;vertical-align:top}
td:first-child{color:${col};width:250px;font-size:27px;font-weight:700;padding-top:19px}
td:nth-child(3){text-align:right;color:#aab1bc;font-size:25px;padding-top:20px}
.f{margin-top:auto;display:flex;justify-content:space-between;align-items:flex-end;gap:30px;font-size:24px;color:#8b93a3}
.f b{color:#ff4d63;font-size:28px;white-space:nowrap}
</style></head><body><div class="str"></div><div class="bar"></div><div class="w">
<div class="brand">${FLAG}<span>Tribune <em>Breizh</em></span></div>
<div class="k">${esc(c.kicker || "")}</div><h1>${esc(c.title)}</h1>
<div class="l">${c.lines.map(x => `<p>${esc(x)}</p>`).join("")}</div>
${c.rows ? `<table>${c.rows.map(r => `<tr>${r.map(x => `<td>${esc(x)}</td>`).join("")}</tr>`).join("")}</table>` : ""}
<div class="f"><span>${esc(c.foot || "")}</span><b>breizh.tribuneouest.fr</b></div></div></body></html>`;

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1080, height: H } });
  const tmp = path.join(fs.mkdtempSync(path.join(os.tmpdir(), "tb-card-")), "carte.html");
  fs.writeFileSync(tmp, html);
  await p.goto("file://" + tmp, { waitUntil: "load" });
  await p.evaluate(() => document.fonts.ready);
  const okFonts = await p.evaluate(() => document.fonts.check("900 60px BSD") && [...document.fonts].some(f => f.family.replace(/"/g, "") === "BSD" && f.status === "loaded"));
  if (!okFonts) { console.error("Polices du site introuvables (dossier fonts/)."); await b.close(); process.exit(1); }
  const over = await p.evaluate(() => { const w = document.querySelector(".w"); return w.scrollHeight > w.clientHeight + 2; });
  if (over) { console.error("Le texte dépasse de la carte : raccourcis-le."); await b.close(); process.exit(1); }
  await p.screenshot({ path: out, type: "jpeg", quality: 92 });
  await b.close();
  console.log("ok " + out);
})();
