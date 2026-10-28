// Tribune Breizh · résumé compact des données du site, pour les posts Instagram.
// Usage : node bot/data.js [page.html]   (par défaut : src/index.html, sinon index.html à la racine du dépôt)
// Lit seulement les données (const AFFICHE=…, const RHUM=…, etc.) du <script> de la page, sans exécuter la page,
// et affiche l'essentiel en JSON : beaucoup plus court à lire que la page entière.
const fs = require("fs"), path = require("path"), vm = require("vm");
// Par défaut : src/index.html (page déposée par les tâches, la plus récente), sinon l'ancien index.html.
const file = process.argv[2] || [path.join(__dirname, "..", "src", "index.html"), path.join(__dirname, "..", "index.html")].find(f => fs.existsSync(f));
const src = fs.readFileSync(file, "utf8");

// Trouve la valeur littérale de « const NOM= » en suivant les crochets, les chaînes et les gabarits.
function literal(name) {
  const at = src.indexOf("const " + name + "=");
  if (at < 0) return undefined;
  let i = at + name.length + 7, depth = 0, start = i;
  for (; i < src.length; i++) {
    const ch = src[i];
    if (ch === '"' || ch === "'" || ch === "`") {
      const q = ch;
      for (i++; i < src.length && src[i] !== q; i++) if (src[i] === "\\") i++;
      continue;
    }
    if (ch === "{" || ch === "[" || ch === "(") depth++;
    else if (ch === "}" || ch === "]" || ch === ")") depth--;
    else if ((ch === ";" || ch === "\n") && depth === 0) break;
  }
  const text = src.slice(start, i).trim();
  try { return vm.runInNewContext("(" + text + ")", Object.create(null), { timeout: 1000 }); }
  catch (e) { return { erreur: "lecture impossible : " + e.message }; }
}

const today = new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Paris" }).format(new Date());
const plus = n => { const d = new Date(today + "T12:00:00Z"); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };

const DATA = literal("DATA") || {};
const sports = {};
for (const [k, v] of Object.entries(DATA)) {
  if (!v || typeof v !== "object") continue;
  sports[k] = {
    titre: v.title,
    resultats: Array.isArray(v.results) ? v.results.slice(0, 6) : undefined,
    prochains: Array.isArray(v.next) ? v.next.slice(0, 6) : undefined,
  };
}
const RHUM = literal("RHUM") || {};
const AGENDA = literal("AGENDA") || [];
const BUTEURS = literal("BUTEURS") || [];

const out = {
  aujourdhui: today,
  bilan_nuit: literal("BILAN_NUIT"),
  affiche: literal("AFFICHE"),
  face: literal("FACE"),
  rhum: { start: RHUM.start, fin: RHUM.fin, maj: RHUM.maj, classement: RHUM.classement, skippers: (RHUM.skippers || []).map(s => [s[0], s[1]]) },
  agenda_7_jours: Array.isArray(AGENDA) ? AGENDA.filter(r => r[0] >= today && r[0] <= plus(7)).map(r => ({ date: r[0], sport: r[1], affiche: r[2], detail: r[3], heure: r[4], tv: r[6] || "" })) : AGENDA,
  breves: literal("BREVES"),
  saviez: literal("SAVIEZ"),
  buteurs: { note: literal("BUT_NOTE"), liste: Array.isArray(BUTEURS) ? BUTEURS.slice(0, 10) : BUTEURS },
  derbys_joues: literal("DERBYRES"),
  petits_poucets: literal("POUCETS"),
  autres_sports: (literal("AUTRES") || []).map ? (literal("AUTRES") || []).map(a => ({ sport: a.sport, club: a.club, lieu: a.lieu, titre: a.titre, txt: a.txt })) : undefined,
  mercato: (() => { const m = literal("MERCATO"); return m && m.per ? { per: m.per, note: m.note } : m; })(),
  sports,
};
process.stdout.write(JSON.stringify(out, null, 1) + "\n");
