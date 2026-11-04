<?php
/*
 * Tribune Breizh · pages par club et par sport (SEO) et sitemap automatique.
 *
 * Les données du site (clubs, sports, classements…) vivent dans le JavaScript de src/index.html, réécrit chaque nuit.
 * Ce fichier les lit côté serveur (sans exécuter de JavaScript), les garde en cache, puis fabrique :
 *   - /club/NOM-DU-CLUB   : une page par club (résultats, prochains matchs, classement, fiche)
 *   - /sport/NOM-DU-SPORT : une page par sport (récit du jour, résultats, agenda, classements, clubs)
 *   - /sitemap.xml        : la liste de toutes ces pages, toujours à jour
 *   - un bloc de liens en bas de la page d'accueil, pour que Google découvre les pages (voir tb_seo_footer)
 *
 * Utilisé par pages.php (routeur) et bot/build.php (liens de la page d'accueil).
 * À la main : php bot/seo.php          (liste les pages qui seraient publiées)
 *             php bot/seo.php club rennes | sport football | sitemap   (affiche le HTML / le XML)
 */

const TB_SEO_BASE = 'https://breizh.tribuneouest.fr';

/* ───────────── Lecture d'un littéral JavaScript (objets, tableaux, chaînes, nombres) ───────────── */

final class TbJs {
  private string $s;
  private int $i = 0;
  private int $n;

  private function __construct(string $s, int $at) { $this->s = $s; $this->n = strlen($s); $this->i = $at; }

  /** Valeur de « const NOM=… » dans $src, ou null si absente ou si ce n'est pas un simple littéral. */
  public static function constant(string $src, string $name): mixed {
    $at = strpos($src, "const $name=");
    if ($at === false) return null;
    try {
      $p = new self($src, $at + strlen("const $name="));
      return $p->value();
    } catch (RuntimeException $e) {
      return null;
    }
  }

  private function skip(): void {
    while ($this->i < $this->n) {
      $c = $this->s[$this->i];
      if ($c === ' ' || $c === "\n" || $c === "\t" || $c === "\r") { $this->i++; continue; }
      if ($c === '/' && ($this->s[$this->i + 1] ?? '') === '/') {
        $e = strpos($this->s, "\n", $this->i);
        $this->i = $e === false ? $this->n : $e + 1;
        continue;
      }
      if ($c === '/' && ($this->s[$this->i + 1] ?? '') === '*') {
        $e = strpos($this->s, '*/', $this->i + 2);
        if ($e === false) throw new RuntimeException('commentaire non fermé');
        $this->i = $e + 2;
        continue;
      }
      break;
    }
  }

  private function value(): mixed {
    $this->skip();
    if ($this->i >= $this->n) throw new RuntimeException('fin inattendue');
    $c = $this->s[$this->i];
    if ($c === '{') return $this->object();
    if ($c === '[') return $this->arr();
    if ($c === '"' || $c === "'" || $c === '`') return $this->string();
    if ($c === '!' && preg_match('/\G!([01])/', $this->s, $m, 0, $this->i)) { $this->i += 2; return $m[1] === '0'; }
    if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $this->s, $m, 0, $this->i)) {
      $this->i += strlen($m[0]);
      return preg_match('/^[+-]?\d+$/', $m[0]) ? (int)$m[0] : (float)$m[0];
    }
    if (preg_match('/\G[A-Za-z_$][\w$]*/', $this->s, $m, 0, $this->i)) {
      $this->i += strlen($m[0]);
      return match ($m[0]) { 'true' => true, 'false' => false, 'null', 'undefined' => null,
        default => throw new RuntimeException('identifiant « ' . $m[0] . ' » : pas un littéral') };
    }
    throw new RuntimeException('caractère inattendu');
  }

  private function object(): array {
    $out = [];
    $this->i++;
    while (true) {
      $this->skip();
      if (($this->s[$this->i] ?? '') === '}') { $this->i++; return $out; }
      $c = $this->s[$this->i] ?? '';
      if ($c === '"' || $c === "'") $key = $this->string();
      elseif (preg_match('/\G[A-Za-z_$0-9\x80-\xff][\w$\x80-\xff]*/', $this->s, $m, 0, $this->i)) { $key = $m[0]; $this->i += strlen($key); }
      else throw new RuntimeException('clé d\'objet inattendue');
      $this->skip();
      if (($this->s[$this->i] ?? '') !== ':') throw new RuntimeException('« : » attendu');
      $this->i++;
      $out[$key] = $this->value();
      $this->skip();
      $c = $this->s[$this->i] ?? '';
      if ($c === ',') { $this->i++; continue; }
      if ($c === '}') { $this->i++; return $out; }
      throw new RuntimeException('« , » ou « } » attendu');
    }
  }

  private function arr(): array {
    $out = [];
    $this->i++;
    while (true) {
      $this->skip();
      if (($this->s[$this->i] ?? '') === ']') { $this->i++; return $out; }
      $out[] = $this->value();
      $this->skip();
      $c = $this->s[$this->i] ?? '';
      if ($c === ',') { $this->i++; continue; }
      if ($c === ']') { $this->i++; return $out; }
      throw new RuntimeException('« , » ou « ] » attendu');
    }
  }

  private function string(): string {
    $q = $this->s[$this->i++];
    $out = '';
    while ($this->i < $this->n) {
      $c = $this->s[$this->i];
      if ($c === $q) { $this->i++; return $out; }
      if ($q === '`' && $c === '$' && ($this->s[$this->i + 1] ?? '') === '{') throw new RuntimeException('gabarit avec expression');
      if ($c !== '\\') { $out .= $c; $this->i++; continue; }
      $e = $this->s[$this->i + 1] ?? '';
      $this->i += 2;
      switch ($e) {
        case 'n': $out .= "\n"; break;
        case 't': $out .= "\t"; break;
        case 'r': $out .= "\r"; break;
        case 'b': $out .= "\x08"; break;
        case 'f': $out .= "\x0c"; break;
        case 'v': $out .= "\x0b"; break;
        case '0': $out .= "\0"; break;
        case "\n": break;
        case 'x':
          $out .= mb_chr((int)hexdec(substr($this->s, $this->i, 2)), 'UTF-8');
          $this->i += 2;
          break;
        case 'u':
          $u = substr($this->s, $this->i, 4);
          $this->i += 4;
          $cp = (int)hexdec($u);
          if ($cp >= 0xD800 && $cp <= 0xDBFF && substr($this->s, $this->i, 2) === '\\u') {
            $lo = (int)hexdec(substr($this->s, $this->i + 2, 4));
            if ($lo >= 0xDC00 && $lo <= 0xDFFF) { $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00); $this->i += 6; }
          }
          $out .= mb_chr($cp, 'UTF-8');
          break;
        default: $out .= $e;
      }
    }
    throw new RuntimeException('chaîne non fermée');
  }
}

/* ───────────── Données : lecture de src/index.html, avec cache ───────────── */

/**
 * Renvoie ['clubs'=>…, 'data'=>…, 'names'=>…, 'sha'=>…, 'updated'=>…] ou null si la page source est absente ou inattendue.
 * Le résultat est gardé dans $cacheFile tant que la source et ce fichier ne changent pas.
 */
function tb_seo_data(string $srcFile, string $cacheFile): ?array {
  if (!is_file($srcFile)) return null;
  $raw = (string)file_get_contents($srcFile);
  $sha = md5($raw . md5_file(__FILE__));
  if (is_file($cacheFile)) {
    $c = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($c) && ($c['sha'] ?? '') === $sha) return $c;
  }
  $clubs = TbJs::constant($raw, 'ALLCLUBS');
  $data  = TbJs::constant($raw, 'DATA');
  $names = TbJs::constant($raw, 'NAMES');
  if (!is_array($clubs) || !is_array($data) || !is_array($names) || !$clubs || !$data) {
    // Page reçue anormale : on garde l'ancien cache s'il existe (même logique que bot/build.php)
    return is_file($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: null) : null;
  }
  $root = dirname(__DIR__);
  $ver = is_file("$root/version.txt") ? trim((string)file_get_contents("$root/version.txt")) : '';
  $ts = $ver !== '' ? strtotime($ver) : false;
  $out = ['sha' => $sha, 'updated' => gmdate('c', $ts ?: (int)filemtime($srcFile)), 'clubs' => $clubs, 'data' => $data, 'names' => $names];
  @mkdir(dirname($cacheFile), 0755, true);
  $tmp = $cacheFile . '.' . getmypid() . '.tmp';
  if (file_put_contents($tmp, json_encode($out, JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $cacheFile)) @unlink($tmp);
  return $out;
}

/* ───────────── Petits outils ───────────── */

function tb_h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function tb_slug(string $s): string {
  $s = strtr($s, ['À'=>'a','Â'=>'a','Ä'=>'a','Á'=>'a','Ã'=>'a','à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a',
    'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','Î'=>'i','Ï'=>'i','î'=>'i','ï'=>'i','í'=>'i',
    'Ô'=>'o','Ö'=>'o','ô'=>'o','ö'=>'o','ó'=>'o','Œ'=>'oe','œ'=>'oe','Ù'=>'u','Û'=>'u','Ü'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
    'Ç'=>'c','ç'=>'c','Ñ'=>'n','ñ'=>'n','Æ'=>'ae','æ'=>'ae','ÿ'=>'y']);
  $s = strtolower($s);
  return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

function tb_lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }

/** Coupe $s à $max caractères au plus, sur une fin de mot, avec « … » si coupé. */
function tb_cut(string $s, int $max): string {
  if (preg_match('/^.{0,' . $max . '}$/su', $s)) return $s;
  preg_match('/^.{0,' . ($max - 1) . '}/su', $s, $m);
  $t = preg_replace('/\s+\S*$/u', '', $m[0]) ?: $m[0];
  return rtrim($t, " ,;:.-–") . '…';
}

/** Sports ayant une page : clé de DATA => ['key','slug','name','title','round','url']. */
function tb_seo_sports(array $d): array {
  $out = [];
  foreach ($d['data'] as $k => $v) {
    if (!is_array($v) || !isset($v['title'])) continue;
    $name = (string)($d['names'][$k] ?? $v['title']);
    $out[$k] = ['key' => $k, 'slug' => tb_slug($name), 'name' => $name, 'title' => (string)$v['title'], 'round' => (string)($v['round'] ?? ''), 'url' => '/sport/' . tb_slug($name)];
  }
  return $out;
}

/** Clubs ayant une page : liste de clubs enrichis de 'slug' et 'url' (slug tiré du nom, l'identifiant en secours si doublon). */
function tb_seo_clubs(array $d): array {
  $out = [];
  $seen = [];
  foreach ($d['clubs'] as $c) {
    if (!is_array($c) || empty($c['id']) || empty($c['n'])) continue;
    $slug = tb_slug((string)$c['n']);
    if ($slug === '' || isset($seen[$slug])) $slug = tb_slug((string)$c['id']);
    $seen[$slug] = true;
    $c['slug'] = $slug;
    $c['url'] = '/club/' . $slug;
    $out[] = $c;
  }
  return $out;
}

/* ───────────── Mise en page commune ───────────── */

const TB_SEO_CSS = <<<'CSS'
@font-face{font-family:"Big Shoulders Display";src:url("/fonts/big-shoulders-display.woff") format("woff");font-weight:600 900;font-display:swap}
@font-face{font-family:"Archivo";src:url("/fonts/archivo.woff") format("woff");font-weight:400 700;font-display:swap}
:root{--bg:#f5f6f4;--surface:#fff;--ink:#121417;--muted:#5d636b;--line:#dfe2e0;--accent:#c8102e;--chip:#eef0ee;--hl:#fff4cc;
--display:"Big Shoulders Display","Arial Narrow",Impact,sans-serif;--body:"Archivo",system-ui,-apple-system,"Segoe UI",sans-serif;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#0e1013;--surface:#171a1f;--ink:#eceef1;--muted:#9aa1ab;--line:#2a2f37;--accent:#ff5a6e;--chip:#222730;--hl:#3a3418;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 var(--body)}
a{color:var(--ink);text-decoration-color:var(--accent);text-underline-offset:3px}a:hover{color:var(--accent)}
.wrap{max-width:880px;margin:0 auto;padding:0 max(16px,env(safe-area-inset-left,0px))}
header.mast{background:var(--surface);border-bottom:1px solid var(--line);position:relative}
header.mast::after{content:"";position:absolute;inset:auto 0 0 0;height:10px;background:repeating-linear-gradient(180deg,var(--ink) 0 2px,transparent 2px 4px);opacity:.85}
header.mast .wrap{padding-block:18px 24px;display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:10px 18px}
.brand{font:900 clamp(1.9rem,6vw,2.6rem)/.9 var(--display);text-transform:uppercase;text-decoration:none}.brand em{font-style:normal;color:var(--accent)}
nav.sports{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.85rem}nav.sports a{text-decoration:none;color:var(--muted)}nav.sports a:hover,nav.sports a[aria-current]{color:var(--ink)}
main{padding-block:20px 40px}
.crumbs{font-size:.8rem;color:var(--muted);margin:0 0 10px}.crumbs a{color:var(--muted)}
h1{font:900 clamp(2.1rem,7vw,3.2rem)/1 var(--display);text-transform:uppercase;margin:0 0 6px}
h2{font:800 1.5rem/1.15 var(--display);text-transform:uppercase;margin:0 0 10px}
h3{font:700 1.05rem/1.3 var(--body);margin:18px 0 6px}
.sub{color:var(--muted);margin:0 0 18px}
section{background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:16px 18px;margin:0 0 16px}
section p{margin:0 0 10px}section p:last-child{margin-bottom:0}
dl.facts{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}dl.facts dt{color:var(--muted)}dl.facts dd{margin:0;font-weight:500}
ul.games{list-style:none;margin:0;padding:0}ul.games li{padding:7px 0;border-top:1px solid var(--line)}ul.games li:first-child{border-top:0}
.det{display:block;color:var(--muted);font-size:.85rem}
table{width:100%;border-collapse:collapse;font-size:.9rem;font-variant-numeric:tabular-nums}
th,td{padding:5px 8px;border-top:1px solid var(--line);text-align:right}th{color:var(--muted);font-weight:600;font-size:.8rem;border-top:0}
th:nth-child(2),td:nth-child(2){text-align:left}tr.me{background:var(--hl);font-weight:700}
.tn{color:var(--muted);font-size:.85rem;margin:8px 0 0}
ul.cl{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px}
ul.cl li{background:var(--chip);border-radius:6px;padding:8px 10px}ul.cl a{font-weight:700}
footer.foot{border-top:1px solid var(--line);padding:18px 0 32px;color:var(--muted);font-size:.85rem}footer.foot a{color:var(--muted)}
@media (max-width:520px){dl.facts{grid-template-columns:1fr;gap:0}dl.facts dd{margin-bottom:8px}}
.tb-h{display:none}.tb-hist .tb-h{display:revert}
CSS;

function tb_seo_layout(array $d, array $sports, array $o): string {
  require_once __DIR__ . '/build.php';   // pour TB_STATS (mesure d'audience, la même que sur la page d'accueil)
  $url = TB_SEO_BASE . $o['path'];
  $title = tb_h($o['title']);
  $desc = tb_h($o['description']);
  $nav = '';
  foreach ($sports as $s) {
    $cur = ($o['sport'] ?? '') === $s['key'] ? ' aria-current="page"' : '';
    $nav .= '<a href="' . tb_h($s['url']) . '"' . $cur . '>' . tb_h($s['name']) . '</a>';
  }
  $ld = '';
  foreach ($o['jsonld'] as $j) $ld .= '<script type="application/ld+json">' . str_replace('</', '<\/', json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</script>\n";
  $icon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 90 60'%3E%3Crect width='90' height='60' fill='%23121417'/%3E%3Crect y='6.67' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='20' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='33.33' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='46.67' width='90' height='6.67' fill='%23fff'/%3E%3Crect width='40' height='33.33' fill='%23fff'/%3E%3C/svg%3E";
  return '<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>' . $title . '</title>
<meta name="description" content="' . $desc . '">
<link rel="canonical" href="' . tb_h($url) . '">
<meta property="og:title" content="' . $title . '">
<meta property="og:description" content="' . $desc . '">
<meta property="og:type" content="website">
<meta property="og:url" content="' . tb_h($url) . '">
<meta property="og:locale" content="fr_FR">
<meta property="og:site_name" content="Tribune Breizh">
<meta property="og:image" content="' . TB_SEO_BASE . '/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#121417">
<link rel="preload" href="/fonts/big-shoulders-display.woff" as="font" type="font/woff" crossorigin>
<link rel="preload" href="/fonts/archivo.woff" as="font" type="font/woff" crossorigin>
<link rel="icon" href="' . $icon . '">
<style>' . TB_SEO_CSS . '</style>
<script>try{if(localStorage.getItem("tb-hist")==="1")document.documentElement.classList.add("tb-hist")}catch(e){}</script>
' . $ld . trim(TB_STATS) . '
</head>
<body>
<header class="mast"><div class="wrap"><a class="brand" href="/">Tribune <em>Breizh</em></a><nav class="sports" aria-label="Sports">' . $nav . '</nav></div></header>
<main class="wrap">
' . $o['body'] . '
</main>
<footer class="foot"><div class="wrap"><a href="/">Tribune Breizh · le sport breton, club par club</a> · Mis à jour le ' . tb_h(date('d/m/Y', strtotime($d['updated']))) . ' · <a href="/mentions-legales.html">Mentions légales</a></div></footer>
</body>
</html>
';
}

function tb_crumbs(array $items): string {
  $h = '<p class="crumbs">';
  $last = count($items) - 1;
  foreach ($items as $i => [$label, $href]) $h .= ($i ? ' › ' : '') . ($i === $last || !$href ? tb_h($label) : '<a href="' . tb_h($href) . '">' . tb_h($label) . '</a>');
  return $h . '</p>';
}

function tb_ld_crumbs(array $items): array {
  $list = [];
  foreach ($items as $i => [$label, $href]) $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $label, 'item' => TB_SEO_BASE . $href];
  return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Club de Loire-Atlantique (« Bretagne historique ») : masqué tant que le mode du même nom n'est pas activé (voir tb-hist). */
function tb_is_hist(array $c): bool { return !empty($c['hist']) || ($c['dept'] ?? '') === 'Loire-Atlantique'; }

/* Noms sous lesquels un club apparaît dans les résultats et les classements */
function tb_club_names(array $c): array {
  $n = [];
  foreach (['short', 'nm', 'n'] as $k) if (!empty($c[$k])) $n[tb_slug((string)$c[$k])] = true;
  return $n;
}

function tb_games_list(array $games, array $me = []): string {
  $side = fn($name) => isset($me[tb_slug((string)$name)]) ? '<strong>' . tb_h($name) . '</strong>' : tb_h($name);
  $h = '<ul class="games">';
  foreach ($games as $g) {
    if (count($g) >= 4 && is_numeric($g[1]) && is_numeric($g[2])) {
      $h .= '<li>' . $side($g[0]) . ' ' . (int)$g[1] . '-' . (int)$g[2] . ' ' . $side($g[3]) . (!empty($g[4]) ? '<span class="det">' . tb_h($g[4]) . '</span>' : '') . '</li>';
    }
  }
  return $h . '</ul>';
}

function tb_next_list(array $games, array $me = []): string {
  $side = fn($name) => isset($me[tb_slug((string)$name)]) ? '<strong>' . tb_h($name) . '</strong>' : tb_h($name);
  $h = '<ul class="games">';
  foreach ($games as $g) {
    if (count($g) < 2) continue;
    $h .= '<li>' . $side($g[0]) . ' – ' . $side($g[1]) . (!empty($g[2]) ? '<span class="det">' . tb_h($g[2]) . '</span>' : '') . '</li>';
  }
  return $h . '</ul>';
}

function tb_table(array $t, array $me = []): string {
  $head = is_array($t['head'] ?? null) ? $t['head'] : [];
  $h = '<table><thead><tr><th>#</th><th>Club</th>';
  foreach ($head as $c) $h .= '<th>' . tb_h($c) . '</th>';
  $h .= '</tr></thead><tbody>';
  foreach (($t['rows'] ?? []) as $i => $r) {
    if (!is_array($r) || !$r) continue;
    $is = isset($me[tb_slug((string)$r[0])]);
    $h .= '<tr' . ($is ? ' class="me"' : '') . '><td>' . ($i + 1) . '</td><td>' . tb_h($r[0]) . '</td>';
    for ($k = 1; $k <= count($head); $k++) $h .= '<td>' . tb_h($r[$k] ?? '') . '</td>';
    $h .= '</tr>';
  }
  $h .= '</tbody></table>';
  if (!empty($t['note'])) $h .= '<p class="tn">' . tb_h($t['note']) . '</p>';
  return $h;
}

/* ───────────── Page d'un club ───────────── */

function tb_seo_club_page(array $d, string $slug): ?string {
  $sports = tb_seo_sports($d);
  $clubs = tb_seo_clubs($d);
  $c = null;
  foreach ($clubs as $x) if ($x['slug'] === $slug) { $c = $x; break; }
  if (!$c) return null;
  $sk = (string)($c['sport'] ?? '');
  $sp = $sports[$sk] ?? null;
  $sportName = $sp['name'] ?? ($d['names'][$sk] ?? 'Sport');
  $me = tb_club_names($c);
  $n = (string)$c['n'];

  // Titre et description
  $title = tb_cut("$n : résultats, classement, prochains matchs", 58);
  if (preg_match_all('/./su', $title) + 17 <= 74) $title .= ' | Tribune Breizh';   // marque ajoutée si le titre reste raisonnable
  $place = trim(($c['r'] ?? '') . (!empty($c['pts']) ? ', ' . $c['pts'] : ''), ', ');
  $desc = $n . (!empty($c['lg']) ? ' (' . $c['lg'] . ')' : '') . ($place !== '' ? ' : ' . $place . '.' : '.')
        . ' Derniers résultats, prochains matchs, classement, stade et palmarès du club, mis à jour chaque jour.';
  $desc = tb_cut($desc, 158);

  // Texte d'introduction (uniquement à partir des données du club)
  $lede = $n . ' est un club de ' . tb_lower($sportName) . (!empty($c['fem']) ? ' féminin' : '') . ' basé à ' . ($c['city'] ?? '') . (!empty($c['dept']) ? ' (' . $c['dept'] . ')' : '') . '.';
  if (!empty($c['lg'])) $lede .= ' Il évolue en ' . $c['lg'] . (!empty($c['r']) ? ' et est actuellement ' . $c['r'] . ' du classement' . (!empty($c['pts']) ? ' (' . $c['pts'] . ')' : '') : '') . '.';
  if (!empty($c['venue'])) $lede .= ' Il joue à ' . $c['venue'] . (!empty($c['cap']) ? ' (' . $c['cap'] . ' places)' : '') . '.';

  $facts = [];
  if (!empty($c['lg'])) $facts['Championnat'] = $c['lg'];
  if (!empty($c['r'])) $facts['Classement'] = $c['r'] . (!empty($c['pts']) ? ' · ' . $c['pts'] : '');
  if (!empty($c['city'])) $facts['Ville'] = $c['city'] . (!empty($c['dept']) ? ' (' . $c['dept'] . ')' : '');
  if (!empty($c['venue'])) $facts['Stade ou salle'] = $c['venue'] . (!empty($c['cap']) ? ' · ' . $c['cap'] . ' places' : '');
  if (!empty($c['coach'])) $facts['Entraîneur'] = $c['coach'];
  if (!empty($c['founded'])) $facts['Fondation'] = $c['founded'];
  if (!empty($c['palm'])) $facts['Palmarès'] = $c['palm'];

  $crumbs = [['Accueil', '/'], [$sportName, $sp['url'] ?? null], [$n, $c['url']]];
  $b = tb_crumbs($crumbs) . '<h1>' . tb_h($n) . '</h1><p class="sub">' . tb_h(trim(($c['lg'] ?? '') . ' · ' . ($c['city'] ?? ''), ' ·')) . '</p>';
  $b .= '<section><p>' . tb_h($lede) . '</p>';
  if (!empty($c['note'])) $b .= '<p>' . tb_h($c['note']) . '</p>';
  $b .= '</section>';
  if (!empty($c['res'])) $b .= '<section><h2>Derniers résultats</h2>' . tb_games_list($c['res'], $me) . '</section>';
  if (!empty($c['nxt'])) $b .= '<section><h2>Prochains matchs</h2>' . tb_next_list($c['nxt'], $me) . '</section>';
  $tbl = (isset($c['tbl'][0], $c['tbl'][1])) ? ($d['data'][$c['tbl'][0]]['tables'][$c['tbl'][1]] ?? null) : null;
  if (is_array($tbl)) $b .= '<section><h2>Classement · ' . tb_h($tbl['name'] ?? '') . '</h2>' . tb_table($tbl, $me) . '</section>';
  if ($facts) {
    $b .= '<section><h2>Fiche du club</h2><dl class="facts">';
    foreach ($facts as $k => $v) $b .= '<dt>' . tb_h($k) . '</dt><dd>' . tb_h($v) . '</dd>';
    $b .= '</dl>';
    if (!empty($c['wiki'])) $b .= '<p class="tn"><a href="' . tb_h($c['wiki']) . '" rel="noopener">En savoir plus sur ' . tb_h($n) . ' (Wikipédia)</a></p>';
    $b .= '</section>';
  }
  $others = array_filter($clubs, fn($x) => ($x['sport'] ?? '') === $sk && $x['slug'] !== $c['slug']);
  if ($others) {
    $b .= '<section><h2>Autres clubs de ' . tb_h(tb_lower($sportName)) . ' en Bretagne</h2><ul class="cl">';
    // Clubs de Loire-Atlantique masqués hors mode Bretagne historique, sauf sur la page d'un club de Loire-Atlantique (on y est déjà)
    $hideHist = !tb_is_hist($c);
    foreach ($others as $x) $b .= '<li' . ($hideHist && tb_is_hist($x) ? ' class="tb-h"' : '') . '><a href="' . tb_h($x['url']) . '">' . tb_h($x['n']) . '</a>' . (!empty($x['lg']) ? '<span class="det">' . tb_h($x['lg']) . '</span>' : '') . '</li>';
    $b .= '</ul>' . ($sp ? '<p class="tn"><a href="' . tb_h($sp['url']) . '">Tout le ' . tb_h(tb_lower($sportName)) . ' breton : résultats, classements et agenda</a></p>' : '') . '</section>';
  }

  $team = ['@context' => 'https://schema.org', '@type' => 'SportsTeam', 'name' => $n, 'sport' => $sportName, 'url' => TB_SEO_BASE . $c['url'], 'description' => $desc];
  if (!empty($c['founded']) && preg_match('/^\d{4}$/', (string)$c['founded'])) $team['foundingDate'] = (string)$c['founded'];
  if (!empty($c['coach'])) $team['coach'] = ['@type' => 'Person', 'name' => $c['coach']];
  if (!empty($c['lg'])) $team['memberOf'] = ['@type' => 'SportsOrganization', 'name' => $c['lg']];
  if (!empty($c['wiki'])) $team['sameAs'] = [$c['wiki']];
  $loc = ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $c['city'] ?? '', 'addressCountry' => 'FR']];
  if (!empty($c['venue'])) $loc['name'] = $c['venue'];
  if (isset($c['lat'], $c['lon'])) $loc['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $c['lat'], 'longitude' => $c['lon']];
  if (!empty($c['city'])) $team['location'] = $loc;

  return tb_seo_layout($d, $sports, ['path' => $c['url'], 'title' => $title, 'description' => $desc, 'sport' => $sk, 'body' => $b,
    'jsonld' => [$team, tb_ld_crumbs($crumbs)]]);
}

/* ───────────── Page d'un sport ───────────── */

function tb_seo_sport_page(array $d, string $slug): ?string {
  $sports = tb_seo_sports($d);
  $s = null;
  foreach ($sports as $x) if ($x['slug'] === $slug) { $s = $x; break; }
  if (!$s) return null;
  $v = $d['data'][$s['key']];
  $name = $s['name'];
  $lower = tb_lower($name);
  $clubs = array_values(array_filter(tb_seo_clubs($d), fn($x) => ($x['sport'] ?? '') === $s['key']));

  $title = tb_cut("$name breton : résultats, classements et agenda", 56) . ' | Tribune Breizh';
  $head = trim((string)($v['recit']['t'] ?? ''));
  $desc = tb_cut(($head !== '' ? rtrim($head, '.') . '. ' : '') . "Résultats, classements, agenda et fiches des clubs de $lower en Bretagne, mis à jour chaque jour.", 158);

  $crumbs = [['Accueil', '/'], [$name, $s['url']]];
  $b = tb_crumbs($crumbs) . '<h1>' . tb_h($name) . ' breton</h1><p class="sub">' . tb_h($s['round'] !== '' ? $s['round'] : "Résultats, classements et agenda") . '</p>';
  if (!empty($v['recit']['p'])) {
    $b .= '<section><h2>' . tb_h($head !== '' ? $head : "L'actualité du $lower breton") . '</h2>';
    foreach ($v['recit']['p'] as $p) $b .= '<p>' . tb_h($p) . '</p>';
    $b .= '</section>';
  }
  if ($clubs) {
    $b .= '<section><h2>Les clubs de ' . tb_h($lower) . ' bretons</h2><ul class="cl">';
    foreach ($clubs as $x) $b .= '<li' . (tb_is_hist($x) ? ' class="tb-h"' : '') . '><a href="' . tb_h($x['url']) . '">' . tb_h($x['n']) . '</a><span class="det">' . tb_h(trim(($x['lg'] ?? '') . (!empty($x['r']) ? ' · ' . $x['r'] : ''), ' ·')) . '</span></li>';
    $b .= '</ul></section>';
  }
  if (!empty($v['results'])) {
    $b .= '<section><h2>Derniers résultats</h2>';
    foreach ($v['results'] as $day) if (!empty($day['games'])) $b .= '<h3>' . tb_h($day['day'] ?? '') . '</h3>' . tb_games_list($day['games']);
    $b .= '</section>';
  }
  if (!empty($v['next'])) {
    $b .= '<section><h2>Prochains matchs</h2>';
    foreach ($v['next'] as $day) if (!empty($day['games'])) $b .= '<h3>' . tb_h($day['day'] ?? '') . '</h3>' . tb_next_list($day['games']);
    $b .= '</section>';
  }
  foreach (($v['tables'] ?? []) as $t) if (is_array($t) && !empty($t['rows'])) $b .= '<section' . (!empty($t['hist']) ? ' class="tb-h"' : '') . '><h2>Classement · ' . tb_h($t['name'] ?? '') . '</h2>' . tb_table($t) . '</section>';

  $items = [];
  foreach ($clubs as $i => $x) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => TB_SEO_BASE . $x['url'], 'name' => $x['n']];
  $page = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => "$name breton", 'url' => TB_SEO_BASE . $s['url'], 'description' => $desc, 'inLanguage' => 'fr',
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Tribune Breizh', 'url' => TB_SEO_BASE . '/']];
  if ($items) $page['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $items];

  return tb_seo_layout($d, $sports, ['path' => $s['url'], 'title' => $title, 'description' => $desc, 'sport' => $s['key'], 'body' => $b,
    'jsonld' => [$page, tb_ld_crumbs($crumbs)]]);
}

/* ───────────── Sitemap et liens de la page d'accueil ───────────── */

function tb_seo_urls(array $d): array {
  $urls = [['/', 'daily'], ['/mentions-legales.html', 'yearly']];
  foreach (tb_seo_sports($d) as $s) $urls[] = [$s['url'], 'daily'];
  foreach (tb_seo_clubs($d) as $c) $urls[] = [$c['url'], 'daily'];
  return $urls;
}

function tb_seo_sitemap(array $d): string {
  $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach (tb_seo_urls($d) as [$path, $freq]) {
    // Page fixe (mentions légales) : pas de date de mise à jour, car celle du fichier change à chaque « git pull » sans que la page change
    $last = $path === '/mentions-legales.html' ? '' : '<lastmod>' . tb_h($d['updated']) . '</lastmod>';
    $x .= '  <url><loc>' . tb_h(TB_SEO_BASE . $path) . '</loc>' . $last . '<changefreq>' . $freq . '</changefreq></url>' . "\n";
  }
  return $x . '</urlset>' . "\n";
}

/**
 * Bloc de liens ajouté en bas de la page d'accueil par bot/build.php : des liens ordinaires vers chaque page sport et club,
 * pour que Google les découvre depuis la page principale. Renvoie '' si les données sont illisibles (la page ne change pas).
 */
function tb_seo_footer(): string {
  try {
    $root = dirname(__DIR__);
    $d = tb_seo_data("$root/src/index.html", "$root/cache/seo-data.json");
    if (!$d) return '';
    $a = fn(array $x, string $label) => '<a href="' . tb_h($x['url']) . '">' . tb_h($label) . '</a>';
    $sports = [];
    foreach (tb_seo_sports($d) as $s) $sports[] = $a($s, $s['name']);
    // Clubs de Loire-Atlantique (« Bretagne historique » : Nantes, Saint-Nazaire…) : masqués tant que le mode n'est pas activé sur la page.
    // Ils sont placés en fin de liste, avec leur séparateur « · » à l'intérieur de la partie masquée, pour qu'aucun « · » ne reste seul.
    $clubs = '';
    $hidden = '';
    foreach (tb_seo_clubs($d) as $c) {
      $link = $a($c, $c['n']);
      if (tb_is_hist($c)) $hidden .= '<span class="tb-h"> · ' . $link . '</span>';
      else $clubs .= ($clubs !== '' ? ' · ' : '') . $link;
    }
    $clubs .= $hidden;
    if (!$sports && $clubs === '') return '';
    $sep = fn(array $items) => implode(' · ', $items);
    $css = '.tb-seo{max-width:1080px;margin:8px auto 0;padding:24px max(16px,env(safe-area-inset-left,0px)) 48px;font:14px/1.9 var(--body,system-ui,sans-serif);color:var(--muted,#5d636b)}.tb-seo h2{font:800 1.1rem/1.2 var(--display,Impact,sans-serif);text-transform:uppercase;margin:0 0 6px;color:var(--ink,#121417)}.tb-seo p{margin:0 0 6px}.tb-seo a{color:var(--ink,#121417)}.tb-seo .tb-h{display:none}.tb-seo.tb-hist .tb-h{display:inline}';
    // Même réglage que la page : « tb-hist » = « 1 » dans le stockage local ; mis à jour quand on touche le bouton du mode
    $js = "(function(){var n=document.querySelector('.tb-seo');if(!n)return;function s(){var h=false;try{h=localStorage.getItem('tb-hist')==='1'}catch(e){}n.classList.toggle('tb-hist',h)}s();var b=document.getElementById('histbtn');if(b)b.addEventListener('click',function(){setTimeout(s,0)})})();";
    return '<nav class="tb-seo" aria-label="Pages par sport et par club"><style>' . $css . '</style>'
      . '<h2>Le sport breton, club par club</h2>'
      . '<p><strong>Par sport</strong> : ' . $sep($sports) . '</p>'
      . '<p><strong>Par club</strong> : ' . $clubs . '</p><script>' . $js . '</script></nav>';
  } catch (Throwable $e) {
    return '';
  }
}

/* ───────────── À la main (terminal) ───────────── */

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
  $root = dirname(__DIR__);
  $d = tb_seo_data("$root/src/index.html", "$root/cache/seo-data.json");
  if (!$d) { fwrite(STDERR, "Données illisibles dans src/index.html\n"); exit(1); }
  $cmd = $argv[1] ?? '';
  if ($cmd === 'club') echo tb_seo_club_page($d, $argv[2] ?? '') ?? "club inconnu\n";
  elseif ($cmd === 'sport') echo tb_seo_sport_page($d, $argv[2] ?? '') ?? "sport inconnu\n";
  elseif ($cmd === 'sitemap') echo tb_seo_sitemap($d);
  else {
    $u = tb_seo_urls($d);
    echo count($u) . " pages (mise à jour : {$d['updated']}) :\n";
    foreach ($u as [$p]) echo TB_SEO_BASE . $p . "\n";
  }
}
