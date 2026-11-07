<?php
/*
 * Tribune Breizh · liens vers les pages de club (/club/NOM-DU-CLUB) dans la page d'accueil.
 *
 * La page d'accueil est réécrite chaque nuit à partir de la page publiée sur Claude (src/index.html) : on n'y modifie rien à la main.
 * Ces liens sont donc ajoutés ici, au moment de fabriquer la page (bot/build.php, appelé par gate.php), comme le bloc de liens SEO et la mesure d'audience.
 *
 * Ce qui est ajouté :
 *   - un lien « Page du club → » au bas de chaque carte club (la carte garde son clic vers la fiche de la page) ;
 *   - le nom du club en lien dans les classements, les résultats, les prochains matchs, les fiches et l'agenda
 *     (seulement pour un club suivi ; un nom ambigu, comme « Nantes » en foot, n'est pas lié) ;
 *   - les adresses sont calculées comme dans bot/seo.php (nom du club, identifiant en secours si doublon).
 *
 * Tout ou rien : chaque remplacement doit trouver son motif une seule fois dans la page. Si la page change de forme au point
 * qu'un seul motif manque, aucun lien n'est ajouté et la page reste telle que publiée (jamais de page à moitié modifiée).
 * Vérification à la main : php bot/clublinks.php [page.html]   (dit si les liens s'appliquent)
 */

function tb_clublinks_edits(): array {
  $E = [];
  $E[] = [<<<'OLD'
.club:hover{border-color:var(--c)}
OLD,
<<<'NEW'
.club:hover{border-color:var(--c)}
.clublink{margin-top:2px;font-size:.78rem;font-weight:700;color:var(--c);text-decoration:none}
.clublink:hover{text-decoration:underline}
a.tl{color:inherit;text-decoration:none;border-bottom:1px dotted currentColor}
a.tl:hover{color:var(--c,var(--accent));border-bottom-style:solid}
NEW];
  $E[] = [<<<'OLD'
const disp=n=>n==="Rennes (basket)"?"Rennes":n;
function matchRow(g){
  const [h,sh,sa,a,x]=g;
OLD,
<<<'NEW'
const disp=n=>n==="Rennes (basket)"?"Rennes":n;
/* Liens vers les pages de club du site (/club/NOM-DU-CLUB) : même calcul d'adresse que bot/seo.php (nom du club, identifiant en secours si doublon) */
const SITE="https://breizh.tribuneouest.fr";
const tbSlug=s=>String(s).normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/[æÆ]/g,"ae").replace(/[œŒ]/g,"oe").toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");
const CLUBURL={}; (()=>{const seen={}; ALLCLUBS.forEach(c=>{let s=tbSlug(c.n); if(!s||seen[s]) s=tbSlug(c.id); seen[s]=1; CLUBURL[c.id]=SITE+"/club/"+s;});})();
/* Club de ALLCLUBS pour un nom d'équipe dans un sport donné ; rien si le nom est ambigu (ex. deux « Nantes » en foot) */
function clubFor(n,sp){const m=ALLCLUBS.filter(c=>(HIST||!c.hist)&&(c.nm||c.short)===n&&(!sp||c.sport===sp)); return m.length===1?m[0]:null;}
/* Nom d'équipe, cliquable vers la page du club quand c'est un club suivi */
function tl(n,sp,raw){const t=esc(raw?n:disp(n)); const c=isB(n)&&clubFor(n,sp); return c?`<a class="tl" href="${CLUBURL[c.id]}">${t}</a>`:t;}
function matchRow(g,sp){
  const [h,sh,sa,a,x]=g;
NEW];
  $E[] = [<<<'OLD'
<div class="t h ${isB(h)?"bzh":""}">${esc(disp(h))}</div><div class="score next">${esc(x||"")}</div><div class="t ${isB(a)?"bzh":""}">${esc(disp(a))}</div></div>`;
OLD,
<<<'NEW'
<div class="t h ${isB(h)?"bzh":""}">${tl(h,sp)}</div><div class="score next">${esc(x||"")}</div><div class="t ${isB(a)?"bzh":""}">${tl(a,sp)}</div></div>`;
NEW];
  $E[] = [<<<'OLD'
<div class="t h ${sh>sa?"w":""} ${isB(h)?"bzh":""}">${esc(disp(h))}</div>
OLD,
<<<'NEW'
<div class="t h ${sh>sa?"w":""} ${isB(h)?"bzh":""}">${tl(h,sp)}</div>
NEW];
  $E[] = [<<<'OLD'
<div class="t ${sa>sh?"w":""} ${isB(a)?"bzh":""}">${esc(disp(a))}</div></div>`;
OLD,
<<<'NEW'
<div class="t ${sa>sh?"w":""} ${isB(a)?"bzh":""}">${tl(a,sp)}</div></div>`;
NEW];
  $E[] = [<<<'OLD'
const nextRow=g=>matchRow([g[0],null,null,g[1],g[2]]);
OLD,
<<<'NEW'
const nextRow=(g,sp)=>matchRow([g[0],null,null,g[1],g[2]],sp);
NEW];
  $E[] = [<<<'OLD'
<td>${esc(r[0])}${(()=>{const c=isB(r[0])&&clubForRow(t,r[0]);return c&&c.res.length>1?formHTML(c):"";})()}</td>
OLD,
<<<'NEW'
<td>${(()=>{const c=isB(r[0])&&clubForRow(t,r[0]);return (c?`<a class="tl" href="${CLUBURL[c.id]}">${esc(r[0])}</a>`:esc(r[0]))+(c&&c.res.length>1?formHTML(c):"");})()}</td>
NEW];
  $E[] = [<<<'OLD'
${x.games.map(matchRow).join("")}
OLD,
<<<'NEW'
${x.games.map(g=>matchRow(g,k)).join("")}
NEW];
  $E[] = [<<<'OLD'
${x.games.map(nextRow).join("")}
OLD,
<<<'NEW'
${x.games.map(g=>nextRow(g,k)).join("")}
NEW];
  $E[] = [<<<'OLD'
${esc(g[4]||"")}</div>${matchRow(g.slice(0,4))}`).join("");
  const nx=c.nxt.length?c.nxt.map(g=>nextRow(g)).join("")
OLD,
<<<'NEW'
${esc(g[4]||"")}</div>${matchRow(g.slice(0,4),c.sport)}`).join("");
  const nx=c.nxt.length?c.nxt.map(g=>nextRow(g,c.sport)).join("")
NEW];
  $E[] = [<<<'OLD'
return `<button class="club" style="--c:${SCOL[c.sport]}" data-club="${c.id}">
OLD,
<<<'NEW'
return `<div class="club" role="button" tabindex="0" style="--c:${SCOL[c.sport]}" data-club="${c.id}">
NEW];
  $E[] = [<<<'OLD'
<span>${nx?esc((nx[0]===(c.nm||c.short)?"contre "+nx[1]:"à "+nx[0]))+" · "+esc(nx[2]):"À venir"}</span></div></button>`;
OLD,
<<<'NEW'
<span>${nx?esc((nx[0]===(c.nm||c.short)?"contre "+nx[1]:"à "+nx[0]))+" · "+esc(nx[2]):"À venir"}</span></div><a class="clublink" href="${CLUBURL[c.id]}">Page du club →</a></div>`;
NEW];
  $E[] = [<<<'OLD'
view.addEventListener("click",e=>{
  const dp=
OLD,
<<<'NEW'
view.addEventListener("click",e=>{
  if(e.target.closest("a.tl,a.clublink")) return; /* vrais liens vers les pages de club : on laisse le navigateur faire */
  const dp=
NEW];
  $E[] = [<<<'OLD'
view.addEventListener("keydown",e=>{if((e.key==="Enter"||e.key===" ")&&e.target.closest(".pin")){e.preventDefault();go("club-"+e.target.closest(".pin").dataset.club);}});
OLD,
<<<'NEW'
view.addEventListener("keydown",e=>{if((e.key==="Enter"||e.key===" ")&&e.target.closest(".pin")){e.preventDefault();go("club-"+e.target.closest(".pin").dataset.club);return;}
  if((e.key==="Enter"||e.key===" ")&&e.target.classList&&e.target.classList.contains("club")&&e.target.dataset.club){e.preventDefault();go("club-"+e.target.dataset.club);window.scrollTo({top:0,behavior:"smooth"});}});
NEW];
  $E[] = [<<<'OLD'
<div class="what"><b>${esc(e[2])}</b><small>${esc(e[3])}</small>
OLD,
<<<'NEW'
<div class="what"><b>${(()=>{const p=String(e[2]).split(" – "); return p.length===2?p.map(n=>tl(n,e[1],true)).join(" – "):esc(e[2]);})()}</b><small>${esc(e[3])}</small>
NEW];
  return $E;
}

/** Ajoute les liens de club à la page ; renvoie la page telle quelle si un seul motif manque ou est ambigu. */
function tb_clublinks(string $html): string {
  $out = $html;
  foreach (tb_clublinks_edits() as [$old, $new]) {
    if (substr_count($out, $old) !== 1) return $html;
    $out = str_replace($old, $new, $out);
  }
  return $out;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
  $file = $argv[1] ?? dirname(__DIR__) . '/src/index.html';
  $raw = (string)@file_get_contents($file);
  echo tb_clublinks($raw) !== $raw ? "Liens de club : appliqués\n" : "Liens de club : NON appliqués (page de forme inattendue ou déjà modifiée)\n";
}
