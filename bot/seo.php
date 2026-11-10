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

  /** Littéral JavaScript commençant à $offset dans $src (objet, tableau, chaîne…), ou null s'il n'est pas lisible. */
  public static function at(string $src, int $offset = 0): mixed {
    try {
      $p = new self($src, $offset);
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

/* ───────────── Foot amateur : données dispersées entre constantes et gabarit de la page ───────────── */

/**
 * Le foot amateur n'a pas de bloc de données complet comme les autres sports : les classements et les matchs sont dans
 * la constante AMATEUR (et POUCETS), mais le récit, les notes et le calendrier de la Coupe sont écrits dans le gabarit
 * renderAmateur(). Cette fonction rassemble le tout ; chaque morceau est facultatif (absent = simplement omis).
 * Renvoie null si AMATEUR est illisible (la page foot amateur n'est alors pas publiée).
 */
function tb_seo_amateur(string $raw): ?array {
  $am = TbJs::constant($raw, 'AMATEUR');
  if (!is_array($am) || !$am) return null;
  $clean = fn($s) => trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  $ok = fn($s) => is_string($s) && $s !== '' && !str_contains($s, '${');

  $f = strpos($raw, 'function renderAmateur()');
  $region = '';
  if ($f !== false) {
    $end = strpos($raw, 'const R1=', $f);
    $region = substr($raw, $f, ($end !== false && $end > $f) ? $end - $f : 9000);
  }
  $out = ['title' => 'Foot amateur', 'round' => '', 'recit' => null, 'cdf' => null, 'tables' => [], 'suite' => null, 'poucets' => null];

  if (preg_match('/k==="amateur"\)\{head=`<h2>[^<]*<\/h2><span class="round">([^<$]*)<\/span>/', $raw, $m)) $out['round'] = $clean($m[1]);

  // Récit du jour : un titre (h4) et des paragraphes
  if (preg_match('/<section class="block recit[^>]*>(.*?)<\/section>/s', $region, $m)) {
    preg_match('/<h4>(.*?)<\/h4>/s', $m[1], $h);
    preg_match_all('/<p>(.*?)<\/p>/s', $m[1], $ps);
    $paras = array_values(array_filter(array_map($clean, $ps[1] ?? []), $ok));
    if ($paras) $out['recit'] = ['t' => isset($h[1]) && $ok($clean($h[1])) ? $clean($h[1]) : '', 'p' => $paras];
  }

  // Coupe de France : titre, libellé du tour, matchs (AMATEUR.cdf)
  if (is_array($am['cdf'] ?? null) && $am['cdf']) {
    $cdf = ['title' => 'Coupe de France', 'label' => '', 'games' => $am['cdf']];
    if (preg_match('/<h3>([^<$]+)<\/h3>\s*<div class="daylbl">([^<$]*)<\/div>\s*\$\{cdf\}/', $region, $m)) { $cdf['title'] = $clean($m[1]); $cdf['label'] = $clean($m[2]); }
    $out['cdf'] = $cdf;
  }

  // Classements : nom, colonnes et note écrits dans le gabarit ; lignes dans AMATEUR.n1 / n2
  $re = '/tableHTML\(\{name:("(?:[^"\\\\]|\\\\.)*"),\s*head:(\[[^\]]*\]),\s*rows:AMATEUR\.(\w+),\s*note:("(?:[^"\\\\]|\\\\.)*")/s';
  if (preg_match_all($re, $region, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
      $rows = $am[$m[3]] ?? null;
      $name = TbJs::at($m[1]); $head = TbJs::at($m[2]); $note = TbJs::at($m[4]);
      if (is_array($rows) && $rows && is_string($name) && is_array($head)) $out['tables'][] = ['name' => $name, 'head' => $head, 'rows' => $rows, 'note' => $ok($note) ? $note : ''];
    }
  }
  if (!$out['tables']) {
    foreach (['n1' => 'National 1', 'n2' => 'National 2'] as $k => $label) {
      if (is_array($am[$k] ?? null) && $am[$k]) $out['tables'][] = ['name' => $label, 'head' => ['J', 'Diff', 'Pts'], 'rows' => $am[$k], 'note' => ''];
    }
  }

  // Calendrier de la Coupe (« La suite de la Coupe ») : tableau d'objets {d, t, s}
  if (preg_match('/<h3>([^<$]+)<\/h3>\s*\$\{factsHTML\(/', $region, $m, PREG_OFFSET_CAPTURE)) {
    $items = TbJs::at($region, $m[0][1] + strlen($m[0][0]));
    if (is_array($items) && $items) $out['suite'] = ['title' => $clean($m[1][0]), 'items' => $items];
  }

  // Petits poucets (POUCETS) : club, niveau, match du tour suivant
  $pou = TbJs::constant($raw, 'POUCETS');
  if (is_array($pou) && $pou) {
    $p = ['title' => 'Le suivi des petits poucets', 'intro' => '', 'note' => '', 'rows' => $pou];
    $pf = strpos($raw, 'function renderPoucets()');
    if ($pf !== false) {
      $fn = substr($raw, $pf, 2600);
      if (preg_match('/<h3>([^<$]+)<\/h3><p class="note"[^>]*>(.*?)<\/p>/s', $fn, $m)) { $p['title'] = $clean($m[1]); $p['intro'] = $ok($clean($m[2])) ? $clean($m[2]) : ''; }
      if (preg_match_all('/<p class="note">(.*?)<\/p>/s', $fn, $nn) && $ok($clean(end($nn[1])))) $p['note'] = $clean(end($nn[1]));
    }
    $out['poucets'] = $p;
  }

  // Foot régional (R1 et R2) : lignes dans R1, R2A à R2D ; titres, libellé de journée et notes écrits dans le gabarit renderRegional()
  $out['regional'] = null;
  $rf = strpos($raw, 'function renderRegional()');
  $r1 = TbJs::constant($raw, 'R1');
  if ($rf !== false && is_array($r1) && $r1) {
    $rr = substr($raw, $rf, 7000);
    $reg = ['title' => 'Le foot régional', 'r1label' => '', 'r1' => [], 'r2' => []];
    if (preg_match('/<h3 class="sec-title"[^>]*>([^<$]+)<\/h3>/', $rr, $m)) $reg['title'] = $clean($m[1]);
    if (preg_match('/\$\{esc\(r\.zone\)\} · ([^<$`]+)<\/div>/', $rr, $m) && $ok($clean($m[1]))) $reg['r1label'] = $clean($m[1]);
    foreach ($r1 as $g) {
      if (is_array($g) && !empty($g['g']) && !empty($g['top']) && is_array($g['top'])) $reg['r1'][] = $g;
    }
    $re2 = '/tableHTML\(\{name:("(?:[^"\\\\]|\\\\.)*"),\s*head:(\[[^\]]*\]),\s*rows:(R2[A-Z]),\s*note:("(?:[^"\\\\]|\\\\.)*")/s';
    if (preg_match_all($re2, $rr, $mm, PREG_SET_ORDER)) {
      foreach ($mm as $m) {
        $rows = TbJs::constant($raw, $m[3]);
        $name = TbJs::at($m[1]); $head = TbJs::at($m[2]); $note = TbJs::at($m[4]);
        if (is_array($rows) && $rows && is_string($name) && is_array($head)) $reg['r2'][] = ['name' => $name, 'head' => $head, 'rows' => $rows, 'note' => $ok($note) ? $note : ''];
      }
    }
    if ($reg['r1'] || $reg['r2']) $out['regional'] = $reg;
  }
  return ($out['recit'] || $out['cdf'] || $out['tables'] || $out['poucets'] || $out['regional']) ? $out : null;
}

/* ───────────── Route du Rhum : page d'événement, avec archive ───────────── */

/**
 * Données de l'événement « Route du Rhum » : constante RHUM (dates, skippers, classement, sources) + textes du gabarit
 * renderVoile() (récit, départ, flottes). Chaque morceau du gabarit est facultatif. Renvoie null si RHUM est absente ou illisible.
 * L'adresse porte l'année du départ (/evenement/route-du-rhum-2026) : une prochaine édition aura sa propre page.
 */
function tb_seo_rhum(string $raw): ?array {
  $r = TbJs::constant($raw, 'RHUM');
  if (!is_array($r) || empty($r['skippers']) || !is_array($r['skippers']) || !preg_match('/^(\d{4})-\d{2}-\d{2}/', (string)($r['start'] ?? ''), $y) || !strtotime((string)$r['start'])) return null;
  $clean = fn($s) => trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  $ok = fn($s) => is_string($s) && $s !== '' && !str_contains($s, '${');
  $slug = 'route-du-rhum-' . $y[1];
  $out = ['slug' => $slug, 'name' => 'Route du Rhum ' . $y[1], 'url' => '/evenement/' . $slug, 'start' => (string)$r['start'], 'fin' => (string)($r['fin'] ?? ''),
    'maj' => $ok($clean($r['maj'] ?? '')) ? $clean($r['maj']) : '', 'skippers' => $r['skippers'], 'classement' => is_array($r['classement'] ?? null) ? $r['classement'] : [],
    'src' => is_array($r['src'] ?? null) ? $r['src'] : [], 'recit' => null, 'depart' => null, 'flottes' => null];

  $f = strpos($raw, 'function renderVoile()');
  $region = '';
  if ($f !== false) {
    $end = strpos($raw, 'function renderAgenda()', $f);
    $region = substr($raw, $f, ($end !== false && $end > $f) ? $end - $f : 7000);
  }
  if (preg_match('/<section class="block recit[^>]*>(.*?)<\/section>/s', $region, $m)) {
    preg_match('/<h4>(.*?)<\/h4>/s', $m[1], $h);
    preg_match_all('/<p>(.*?)<\/p>/s', $m[1], $ps);
    $paras = array_values(array_filter(array_map($clean, $ps[1] ?? []), $ok));
    if ($paras) $out['recit'] = ['t' => isset($h[1]) && $ok($clean($h[1])) ? $clean($h[1]) : '', 'p' => $paras];
  }
  if (preg_match('/<section class="block"><h3>Le départ<\/h3>(.*?)<\/section>/s', $region, $m)) {
    preg_match_all('/<p class="note"[^>]*>(.*?)<\/p>/s', $m[1], $nn);
    preg_match_all('/<b>([^<]+)<\/b><small>([^<]+)<\/small>/', $m[1], $ss, PREG_SET_ORDER);
    $notes = array_values(array_filter(array_map($clean, $nn[1] ?? []), $ok));
    $stats = array_map(fn($x) => [$clean($x[1]), $clean($x[2])], $ss);
    if ($notes || $stats) $out['depart'] = ['notes' => $notes, 'stats' => $stats];
  }
  if (preg_match('/<h3>Les flottes<\/h3>\$\{factsHTML\(/', $region, $m, PREG_OFFSET_CAPTURE)) {
    $items = TbJs::at($region, $m[0][1] + strlen($m[0][0]));
    if (is_array($items) && $items) {
      $note = preg_match('/<h3>Les flottes<\/h3>.*?<p class="note">(.*?)<\/p>\s*<\/section>/s', $region, $n) && $ok($clean($n[1])) ? $clean($n[1]) : '';
      $out['flottes'] = ['items' => $items, 'note' => $note];
    }
  }
  return $out;
}

/** Date en français : « dimanche 1er novembre 2026 » (heure de Paris). */
function tb_fr_date(int $ts, bool $weekday = true): string {
  $J = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  $M = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  $dt = new DateTime('@' . $ts);
  $dt->setTimezone(new DateTimeZone('Europe/Paris'));
  $d = (int)$dt->format('j');
  return ($weekday ? $J[(int)$dt->format('w')] . ' ' : '') . ($d === 1 ? '1er' : $d) . ' ' . $M[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
}

/**
 * Événements ayant une page : celui des données du jour, plus ceux gardés dans archive/ (copie faite à chaque mise à jour du site).
 * Si la mise à jour de nuit retire l'événement de la page, sa page reste en ligne avec le dernier pointage enregistré.
 */
function tb_seo_events(array $d): array {
  $evs = [];
  if (!empty($d['rhum']['slug'])) $evs[$d['rhum']['slug']] = $d['rhum'] + ['archived' => false];
  foreach (glob(dirname(__DIR__) . '/archive/*.json') ?: [] as $file) {
    $e = json_decode((string)@file_get_contents($file), true);
    if (is_array($e) && !empty($e['slug']) && !empty($e['skippers']) && !isset($evs[$e['slug']])) $evs[$e['slug']] = $e + ['archived' => true];
  }
  uasort($evs, fn($a, $b) => strcmp((string)$b['start'], (string)$a['start']));
  return $evs;
}

/* ───────────── Données : lecture de src/index.html, avec cache ───────────── */

/**
 * Foot amateur féminin (Coupe de France féminine, Régional 1 et Régional 2 féminins) : lignes dans CDFF, R1F, R2FA, R2FB ;
 * récit, titres et notes écrits dans le gabarit renderAmateurF(). Renvoie null si la rubrique est absente ou illisible.
 */
function tb_seo_amateur_f(string $raw): ?array {
  $cd = TbJs::constant($raw, 'CDFF');
  $f = strpos($raw, 'function renderAmateurF()');
  if ($f === false) return null;
  $clean = fn($s) => trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  $ok = fn($s) => is_string($s) && $s !== '' && !str_contains($s, '${');
  $end = strpos($raw, '/* ---------- ', $f);
  $region = substr($raw, $f, ($end !== false && $end > $f) ? $end - $f : 9000);
  $out = ['title' => 'Foot amateur féminin', 'recit' => null, 'cdf' => null, 'suite' => null, 'tables' => []];

  if (preg_match('/<h3 class="sec-title"[^>]*>([^<$]+)<\/h3>/', $region, $m) && $ok($clean($m[1]))) $out['title'] = $clean($m[1]);

  if (preg_match('/<section class="block recit[^>]*>(.*?)<\/section>/s', $region, $m)) {
    preg_match('/<h4>(.*?)<\/h4>/s', $m[1], $h);
    preg_match_all('/<p>(.*?)<\/p>/s', preg_replace('/\$\{srcHTML.*?\}/s', '', $m[1]), $ps);
    $paras = array_values(array_filter(array_map($clean, $ps[1] ?? []), $ok));
    if ($paras) $out['recit'] = ['t' => isset($h[1]) && $ok($clean($h[1])) ? $clean($h[1]) : '', 'p' => $paras];
  }

  if (is_array($cd) && is_array($cd['games'] ?? null) && $cd['games']) {
    $out['cdf'] = ['title' => $ok($cd['title'] ?? null) ? $cd['title'] : 'Coupe de France féminine', 'label' => $ok($cd['label'] ?? null) ? $cd['label'] : '', 'games' => $cd['games'], 'note' => ''];
    if (preg_match('/\$\{cdf\}<p class="note">(.*?)<\/p>/s', $region, $m) && $ok($clean($m[1]))) $out['cdf']['note'] = $clean($m[1]);
  }

  if (preg_match('/<h3>([^<$]+)<\/h3>\s*\$\{factsHTML\(/', $region, $m, PREG_OFFSET_CAPTURE)) {
    $items = TbJs::at($region, $m[0][1] + strlen($m[0][0]));
    if (is_array($items) && $items) $out['suite'] = ['title' => $clean($m[1][0]), 'items' => $items];
  }

  $re = '/tF\(\{name:("(?:[^"\\\\]|\\\\.)*"),\s*head:(\[[^\]]*\]),\s*rows:(\w+),\s*note:("(?:[^"\\\\]|\\\\.)*")/s';
  if (preg_match_all($re, $region, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) {
      $rows = TbJs::constant($raw, $m[3]);
      $name = TbJs::at($m[1]); $head = TbJs::at($m[2]); $note = TbJs::at($m[4]);
      if (is_array($rows) && $rows && is_string($name) && is_array($head)) $out['tables'][] = ['name' => $name, 'head' => $head, 'rows' => $rows, 'note' => $ok($note) ? $note : ''];
    }
  }
  return ($out['recit'] || $out['cdf'] || $out['tables']) ? $out : null;
}

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
    // Le cache n'est valable que s'il a été fabriqué par une version du code qui connaît le foot régional (clé « regional ») :
    // évite de garder un cache périmé si l'ancien code, encore en mémoire du serveur, l'a écrit juste après un changement de ce fichier.
    $fresh = is_array($c) && (!isset($c['amateur']) || !is_array($c['amateur']) || array_key_exists('regional', $c['amateur'])) && array_key_exists('amateurf', $c);
    if (is_array($c) && ($c['sha'] ?? '') === $sha && $fresh) return $c;
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
  $updated = gmdate('c', $ts ?: (int)filemtime($srcFile));
  $rhum = tb_seo_rhum($raw);
  // Noms des clubs bretons (BZH) et des villes de la « Bretagne historique » (HISTNAMES) : même règle que la page d'accueil pour masquer les matchs de Nantes ou Saint-Nazaire contre un club hors Bretagne
  $setOf = function (string $name) use ($raw): array {
    $at = strpos($raw, "const $name=new Set(");
    $v = $at === false ? null : TbJs::at($raw, $at + strlen("const $name=new Set("));
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
  };
  $out = ['sha' => $sha, 'updated' => $updated, 'clubs' => $clubs, 'data' => $data, 'names' => $names, 'amateur' => tb_seo_amateur($raw), 'amateurf' => tb_seo_amateur_f($raw), 'rhum' => $rhum,
    'bzh' => $setOf('BZH'), 'histnames' => $setOf('HISTNAMES') ?: ['Nantes', 'Saint-Nazaire']];
  if ($rhum) {   // archive : copie de l'événement, hors Git (archive/ est ignoré), relue si l'événement quitte la page
    $dir = $root . '/archive';
    @mkdir($dir, 0755, true);
    $tmp = $dir . '/.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($rhum + ['snapshot_at' => $updated], JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $dir . '/' . $rhum['slug'] . '.json')) @unlink($tmp);
  }
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
  // Le foot amateur n'est pas dans DATA : sa page vient de tb_seo_amateur() ; il se place juste après le football, comme sur le site
  $amateur = function () use ($d): ?array {
    if (empty($d['amateur'])) return null;
    $name = (string)($d['names']['amateur'] ?? 'Foot amateur');
    return ['key' => 'amateur', 'slug' => tb_slug($name), 'name' => $name, 'title' => $name, 'round' => (string)($d['amateur']['round'] ?? ''), 'url' => '/sport/' . tb_slug($name)];
  };
  // Le foot amateur féminin suit le foot amateur ; sa page vient de tb_seo_amateur_f()
  $amateurF = function () use ($d): ?array {
    if (empty($d['amateurf'])) return null;
    $name = 'Foot amateur féminin';
    return ['key' => 'amateurf', 'slug' => tb_slug($name), 'name' => $name, 'title' => $name, 'round' => '', 'url' => '/sport/' . tb_slug($name)];
  };
  foreach ($d['data'] as $k => $v) {
    if (!is_array($v) || !isset($v['title'])) continue;
    $name = (string)($d['names'][$k] ?? $v['title']);
    $out[$k] = ['key' => $k, 'slug' => tb_slug($name), 'name' => $name, 'title' => (string)$v['title'], 'round' => (string)($v['round'] ?? ''), 'url' => '/sport/' . tb_slug($name)];
    if ($k === 'foot' && ($a = $amateur())) { $out['amateur'] = $a; if ($f = $amateurF()) $out['amateurf'] = $f; }
  }
  if (!isset($out['amateur']) && ($a = $amateur())) { $out['amateur'] = $a; if ($f = $amateurF()) $out['amateurf'] = $f; }
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
table.tl th,table.tl td{text-align:left}
.ai{display:inline-flex;align-items:center;gap:6px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--accent);margin-bottom:8px}
.ai::before{content:"";width:7px;height:7px;border-radius:2px;background:var(--accent)}
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
  foreach (tb_seo_events($d) as $e) {
    $cur = ($o['event'] ?? '') === $e['slug'] ? ' aria-current="page"' : '';
    $nav .= '<a href="' . tb_h($e['url']) . '"' . $cur . '>' . tb_h($e['name']) . '</a>';
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

/** Vrai si le match met en jeu une ville de la « Bretagne historique » sans qu'un club breton y joue (même règle que renderSport de la page : hv). */
function tb_game_is_hist(string $a, string $b, ?array $hist): bool {
  if (!$hist) return false;
  $hn = $hist['histnames']; $bz = $hist['bzh'];
  return (in_array($a, $hn, true) || in_array($b, $hn, true)) && !in_array($a, $bz, true) && !in_array($b, $bz, true);
}

/** Une journée de matchs (titre + liste) ; masquée entièrement hors mode « Bretagne historique » si tous ses matchs sont historiques. */
function tb_day_block(array $day, bool $next, ?array $hist): string {
  $games = $day['games'];
  $all = $games && count(array_filter($games, fn($g) => !tb_game_is_hist((string)($g[0] ?? ''), (string)($g[$next ? 1 : 3] ?? ''), $hist))) === 0;
  $html = '<h3>' . tb_h($day['day'] ?? '') . '</h3>' . ($next ? tb_next_list($games, [], $hist) : tb_games_list($games, [], $hist));
  return $all ? '<div class="tb-h">' . $html . '</div>' : $html;
}

function tb_games_list(array $games, array $me = [], ?array $hist = null): string {
  $side = fn($name) => isset($me[tb_slug((string)$name)]) ? '<strong>' . tb_h($name) . '</strong>' : tb_h($name);
  $h = '<ul class="games">';
  foreach ($games as $g) {
    if (count($g) >= 4 && is_numeric($g[1]) && is_numeric($g[2])) {
      $h .= '<li' . (tb_game_is_hist((string)$g[0], (string)$g[3], $hist) ? ' class="tb-h"' : '') . '>' . $side($g[0]) . ' ' . (int)$g[1] . '-' . (int)$g[2] . ' ' . $side($g[3]) . (!empty($g[4]) ? '<span class="det">' . tb_h($g[4]) . '</span>' : '') . '</li>';
    }
  }
  return $h . '</ul>';
}

function tb_next_list(array $games, array $me = [], ?array $hist = null): string {
  $side = fn($name) => isset($me[tb_slug((string)$name)]) ? '<strong>' . tb_h($name) . '</strong>' : tb_h($name);
  $h = '<ul class="games">';
  foreach ($games as $g) {
    if (count($g) < 2) continue;
    $h .= '<li' . (tb_game_is_hist((string)$g[0], (string)$g[1], $hist) ? ' class="tb-h"' : '') . '>' . $side($g[0]) . ' – ' . $side($g[1]) . (!empty($g[2]) ? '<span class="det">' . tb_h($g[2]) . '</span>' : '') . '</li>';
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
  if ($s['key'] === 'amateur') return tb_seo_amateur_page($d, $s, $sports);
  if ($s['key'] === 'amateurf') return tb_seo_amateur_f_page($d, $s, $sports);
  $v = $d['data'][$s['key']];
  $histRule = !empty($d['histnames']) ? ['histnames' => $d['histnames'], 'bzh' => $d['bzh'] ?? []] : null;   // règle « Bretagne historique » pour les listes de matchs
  $name = $s['name'];
  $lower = tb_lower($name);
  $clubs = array_values(array_filter(tb_seo_clubs($d), fn($x) => ($x['sport'] ?? '') === $s['key']));

  $title = tb_cut("$name breton : résultats, classements et agenda", 56) . ' | Tribune Breizh';
  $head = trim((string)($v['recit']['t'] ?? ''));
  $desc = tb_cut(($head !== '' ? rtrim($head, '.') . '. ' : '') . "Résultats, classements, agenda et fiches des clubs de $lower en Bretagne, mis à jour chaque jour.", 158);

  $crumbs = [['Accueil', '/'], [$name, $s['url']]];
  $b = tb_crumbs($crumbs) . '<h1>' . tb_h($name) . ' breton</h1><p class="sub">' . tb_h($s['round'] !== '' ? $s['round'] : "Résultats, classements et agenda") . '</p>';
  if (!empty($v['recit']['p'])) {
    $b .= '<section><div class="ai">Résumé IA</div><h2>' . tb_h($head !== '' ? $head : "L'actualité du $lower breton") . '</h2>';
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
    foreach ($v['results'] as $day) if (!empty($day['games'])) $b .= tb_day_block($day, false, $histRule);
    $b .= '</section>';
  }
  if (!empty($v['next'])) {
    $b .= '<section><h2>Prochains matchs</h2>';
    foreach ($v['next'] as $day) if (!empty($day['games'])) $b .= tb_day_block($day, true, $histRule);
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

/* ───────────── Page du foot amateur ───────────── */

/** Vainqueur d'un match de Coupe de France : 1 = premier nommé, 2 = second, 0 = inconnu. Tirs au but lus dans le 5e champ (« tab 4-3 »). */
function tb_amateur_winner(array $g): int {
  $sh = $g[1] ?? null; $sa = $g[2] ?? null;
  if (is_numeric($sh) && is_numeric($sa) && $sh != $sa) return $sh > $sa ? 1 : 2;
  if (preg_match('/(\d+)\s*-\s*(\d+)/', (string)($g[4] ?? ''), $m) && $m[1] != $m[2]) return (int)$m[1] > (int)$m[2] ? 1 : 2;
  return 0;
}

function tb_seo_amateur_page(array $d, array $s, array $sports): string {
  $a = $d['amateur'];
  $name = $s['name'];
  $title = tb_cut("$name breton : résultats, classements et agenda", 56) . ' | Tribune Breizh';
  $head = trim((string)($a['recit']['t'] ?? ''));
  $lead = $head !== '' ? rtrim($head, '.') . '. ' : '';
  $desc = $lead . 'Résultats, classements et agenda du foot amateur en Bretagne' . ($s['round'] !== '' ? ' (' . $s['round'] . ')' : '') . ', mis à jour chaque jour.';
  if (!preg_match('/^.{0,158}$/su', $desc)) $desc = tb_cut($lead . 'Résultats, classements et agenda du foot amateur en Bretagne, mis à jour chaque jour.', 158);   // sans la parenthèse si c'est trop long

  $crumbs = [['Accueil', '/'], [$name, $s['url']]];
  $b = tb_crumbs($crumbs) . '<h1>' . tb_h($name) . ' breton</h1><p class="sub">' . tb_h($s['round'] !== '' ? $s['round'] : 'Résultats, classements et agenda') . '</p>';

  if (!empty($a['recit'])) {
    $b .= '<section><div class="ai">Résumé IA</div><h2>' . tb_h($head !== '' ? $head : "L'actualité du foot amateur breton") . '</h2>';
    foreach ($a['recit']['p'] as $p) $b .= '<p>' . tb_h($p) . '</p>';
    $b .= '</section>';
  }
  if (!empty($a['cdf'])) {
    $c = $a['cdf'];
    $b .= '<section><h2>' . tb_h($c['title']) . '</h2>' . ($c['label'] !== '' ? '<p class="sub">' . tb_h($c['label']) . '</p>' : '') . '<ul class="games">';
    foreach ($c['games'] as $g) {
      if (!is_array($g) || count($g) < 4) continue;
      $w = tb_amateur_winner($g);
      $home = $w === 1 ? '<strong>' . tb_h($g[0]) . '</strong>' : tb_h($g[0]);
      $away = $w === 2 ? '<strong>' . tb_h($g[3]) . '</strong>' : tb_h($g[3]);
      $b .= '<li>' . $home . ' ' . (int)$g[1] . '-' . (int)$g[2] . ' ' . $away . (!empty($g[4]) ? '<span class="det">' . tb_h($g[4]) . '</span>' : '') . '</li>';
    }
    $b .= '</ul><p class="tn">Le vainqueur est en gras. tab = tirs au but.</p></section>';
  }
  if (!empty($a['suite'])) {
    $b .= '<section><h2>' . tb_h($a['suite']['title']) . '</h2><ul class="games">';
    foreach ($a['suite']['items'] as $it) {
      if (!is_array($it) || empty($it['t'])) continue;
      $b .= '<li>' . (!empty($it['d']) ? tb_h($it['d']) . ' · ' : '') . '<strong>' . tb_h($it['t']) . '</strong>' . (!empty($it['s']) ? '<span class="det">' . tb_h($it['s']) . '</span>' : '') . '</li>';
    }
    $b .= '</ul></section>';
  }
  foreach ($a['tables'] as $t) $b .= '<section><h2>Classement · ' . tb_h($t['name']) . '</h2>' . tb_table($t) . '</section>';
  if (!empty($a['poucets'])) {
    $p = $a['poucets'];
    $b .= '<section><h2>' . tb_h($p['title']) . '</h2>' . ($p['intro'] !== '' ? '<p>' . tb_h($p['intro']) . '</p>' : '')
        . '<table class="tl"><thead><tr><th>Club</th><th>Niveau</th><th>Prochain tour</th></tr></thead><tbody>';
    foreach ($p['rows'] as $r) if (is_array($r) && count($r) >= 3) $b .= '<tr><td><strong>' . tb_h($r[0]) . '</strong></td><td>' . tb_h($r[1]) . '</td><td>' . tb_h($r[2]) . '</td></tr>';
    $b .= '</tbody></table>' . ($p['note'] !== '' ? '<p class="tn">' . tb_h($p['note']) . '</p>' : '') . '</section>';
  }
  if (!empty($a['regional'])) {
    $g = $a['regional'];
    $b .= '<h2>' . tb_h($g['title']) . '</h2>';
    foreach ($g['r1'] as $r) {
      $nb = count($r['low'] ?? []);
      $b .= '<section><h3>' . tb_h($r['g']) . '</h3><p class="sub">' . tb_h(trim(($r['zone'] ?? '') . ($g['r1label'] !== '' ? ' · ' . $g['r1label'] : ''), ' ·')) . '</p>'
          . '<table><thead><tr><th>#</th><th>Club</th><th>J</th><th>G-N-P</th><th>Pts</th></tr></thead><tbody>';
      foreach ($r['top'] as $i => $x) if (is_array($x) && count($x) >= 4) $b .= '<tr><td>' . ($i + 1) . '</td><td>' . tb_h($x[0]) . '</td><td>' . tb_h($x[1]) . '</td><td>' . tb_h($x[2]) . '</td><td>' . tb_h($x[3]) . '</td></tr>';
      if (!empty($r['low'])) {
        $b .= '<tr><td colspan="5">…</td></tr>';
        foreach ($r['low'] as $i => $x) if (is_array($x) && count($x) >= 2) $b .= '<tr><td>' . (14 - $nb + 1 + $i) . '</td><td>' . tb_h($x[0]) . '</td><td></td><td></td><td>' . tb_h($x[1]) . '</td></tr>';
      }
      $b .= '</tbody></table>';
      if (!empty($r['scorers'])) $b .= '<p class="tn">Buteurs : ' . tb_h($r['scorers']) . '</p>';
      if (!empty($r['last'])) $b .= '<p class="tn">Derniers résultats : ' . tb_h($r['last']) . '</p>';
      $b .= '</section>';
    }
    foreach ($g['r2'] as $t) $b .= '<section><h3>' . tb_h($t['name']) . '</h3>' . tb_table($t) . '</section>';
  }
  if (isset($sports['foot'])) $b .= '<section><p><a href="' . tb_h($sports['foot']['url']) . '">Tout le football breton professionnel : résultats, classements et agenda</a></p></section>';

  $page = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => "$name breton", 'url' => TB_SEO_BASE . $s['url'], 'description' => $desc, 'inLanguage' => 'fr',
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Tribune Breizh', 'url' => TB_SEO_BASE . '/']];
  return tb_seo_layout($d, $sports, ['path' => $s['url'], 'title' => $title, 'description' => $desc, 'sport' => 'amateur', 'body' => $b,
    'jsonld' => [$page, tb_ld_crumbs($crumbs)]]);
}

/* ───────────── Page du foot amateur féminin ───────────── */

function tb_seo_amateur_f_page(array $d, array $s, array $sports): string {
  $a = $d['amateurf'];
  $name = $s['name'];
  $title = tb_cut("$name breton : résultats et classements", 56) . ' | Tribune Breizh';
  $head = trim((string)($a['recit']['t'] ?? ''));
  $lead = $head !== '' ? rtrim($head, '.') . '. ' : '';
  $desc = tb_cut($lead . 'Résultats, classements et Coupe de France du foot amateur féminin en Bretagne (Régional 1 et Régional 2), mis à jour chaque jour.', 158);

  $crumbs = [['Accueil', '/'], [$name, $s['url']]];
  $b = tb_crumbs($crumbs) . '<h1>' . tb_h($name) . ' breton</h1><p class="sub">Coupe de France féminine, Régional 1 et Régional 2</p>';

  if (!empty($a['recit'])) {
    $b .= '<section><div class="ai">Résumé IA</div><h2>' . tb_h($head !== '' ? $head : "L'actualité du foot amateur féminin breton") . '</h2>';
    foreach ($a['recit']['p'] as $p) $b .= '<p>' . tb_h($p) . '</p>';
    $b .= '</section>';
  }
  if (!empty($a['cdf'])) {
    $c = $a['cdf'];
    $b .= '<section><h2>' . tb_h($c['title']) . '</h2>' . ($c['label'] !== '' ? '<p class="sub">' . tb_h($c['label']) . '</p>' : '') . '<ul class="games">';
    foreach ($c['games'] as $g) {
      if (!is_array($g) || count($g) < 4) continue;
      $w = tb_amateur_winner($g);
      $home = $w === 1 ? '<strong>' . tb_h($g[0]) . '</strong>' : tb_h($g[0]);
      $away = $w === 2 ? '<strong>' . tb_h($g[3]) . '</strong>' : tb_h($g[3]);
      $b .= '<li>' . $home . ' ' . (int)$g[1] . '-' . (int)$g[2] . ' ' . $away . (!empty($g[4]) ? '<span class="det">' . tb_h($g[4]) . '</span>' : '') . '</li>';
    }
    $b .= '</ul><p class="tn">' . tb_h($c['note'] !== '' ? $c['note'] : 'Le vainqueur est en gras. tab = tirs au but.') . '</p></section>';
  }
  if (!empty($a['suite'])) {
    $b .= '<section><h2>' . tb_h($a['suite']['title']) . '</h2><ul class="games">';
    foreach ($a['suite']['items'] as $it) {
      if (!is_array($it) || empty($it['t'])) continue;
      $b .= '<li>' . (!empty($it['d']) ? tb_h($it['d']) . ' · ' : '') . '<strong>' . tb_h($it['t']) . '</strong>' . (!empty($it['s']) ? '<span class="det">' . tb_h($it['s']) . '</span>' : '') . '</li>';
    }
    $b .= '</ul></section>';
  }
  foreach ($a['tables'] as $t) $b .= '<section><h2>Classement · ' . tb_h($t['name']) . '</h2>' . tb_table($t) . ($t['note'] !== '' ? '<p class="tn">' . tb_h($t['note']) . '</p>' : '') . '</section>';
  if (isset($sports['amateur'])) $b .= '<section><p><a href="' . tb_h($sports['amateur']['url']) . '">Le foot amateur masculin : Coupe de France, National 1 et National 2</a></p></section>';

  $page = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => "$name breton", 'url' => TB_SEO_BASE . $s['url'], 'description' => $desc, 'inLanguage' => 'fr',
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Tribune Breizh', 'url' => TB_SEO_BASE . '/']];
  return tb_seo_layout($d, $sports, ['path' => $s['url'], 'title' => $title, 'description' => $desc, 'sport' => 'amateurf', 'body' => $b,
    'jsonld' => [$page, tb_ld_crumbs($crumbs)]]);
}

/* ───────────── Page d'un événement (Route du Rhum) ───────────── */

function tb_seo_event_page(array $d, string $slug, ?int $now = null): ?string {
  $e = tb_seo_events($d)[$slug] ?? null;
  if (!$e) return null;
  $now = $now ?? time();
  $sports = tb_seo_sports($d);
  $name = $e['name'];
  $start = (int)strtotime($e['start']);
  $fin = $e['fin'] !== '' ? strtotime($e['fin'] . ' 23:59:59 Europe/Paris') : false;
  $state = $now < $start ? 'avant' : (($fin && $now > $fin) ? 'terminée' : 'en cours');
  $n = count($e['skippers']);
  $paris = new DateTimeZone('Europe/Paris');
  $dstart = new DateTime('@' . $start); $dstart->setTimezone($paris);
  $dnow = new DateTime('@' . $now); $dnow->setTimezone($paris);
  $jour = (int)(new DateTime($dstart->format('Y-m-d'), $paris))->diff(new DateTime($dnow->format('Y-m-d'), $paris))->days + 1;
  $sub = match ($state) {
    'avant' => 'Départ le ' . tb_fr_date($start) . ' à ' . $dstart->format('G\hi') . " · $n skippers bretons",
    'en cours' => "Course en cours · jour $jour · $n skippers bretons",
    default => "Course terminée · $n skippers bretons suivis",
  };

  $head = trim((string)($e['recit']['t'] ?? ''));
  $title = tb_cut("$name : classement des Bretons et skippers", 56) . ' | Tribune Breizh';
  $desc = tb_cut(($head !== '' ? rtrim($head, '.') . '. ' : '') . "Classement des Bretons et liste des $n skippers bretons, mis à jour chaque jour.", 158);
  $crumbs = [['Accueil', '/'], [$name, $e['url']]];

  $b = tb_crumbs($crumbs) . '<h1>' . tb_h($name) . '</h1><p class="sub">' . tb_h($sub) . '</p>';
  if (!empty($e['archived'])) {
    $b .= '<section><p><strong>Archive.</strong> Cette page garde le dernier pointage publié' . (!empty($e['snapshot_at']) ? ' (' . tb_h(tb_fr_date((int)strtotime($e['snapshot_at']), false)) . ')' : '') . '.</p></section>';
  }
  if (!empty($e['recit'])) {
    $b .= '<section><div class="ai">Résumé IA</div><h2>' . tb_h($head !== '' ? $head : "L'actualité de la $name") . '</h2>';
    foreach ($e['recit']['p'] as $p) $b .= '<p>' . tb_h($p) . '</p>';
    $b .= '</section>';
  }

  // Classement des Bretons
  $rank = fn($x) => (int)$x === 1 ? '1er' : (int)$x . 'e';
  $b .= '<section><h2>Le classement des Bretons</h2>';
  if ($e['classement']) {
    $b .= '<table class="tl"><thead><tr><th>Classe</th><th>Rang</th><th>Skipper</th><th>Écart</th></tr></thead><tbody>';
    foreach ($e['classement'] as $x) if (is_array($x) && count($x) >= 3) $b .= '<tr><td>' . tb_h($x[0]) . '</td><td>' . tb_h($rank($x[1])) . '</td><td><strong>' . tb_h($x[2]) . '</strong></td><td>' . tb_h($x[3] ?? '') . '</td></tr>';
    $b .= '</tbody></table>' . ($e['maj'] !== '' ? '<p class="tn">' . tb_h($e['maj']) . '</p>' : '');
  } else {
    $b .= '<p>' . ($state === 'avant' ? 'Le classement apparaîtra après le départ, avec le rang de chaque Breton dans sa classe.' : 'Premier classement attendu au prochain pointage.') . '</p>';
  }
  $b .= '</section>';

  // Départ et flottes
  if (!empty($e['depart'])) {
    $b .= '<section><h2>Le départ</h2>';
    if ($e['depart']['stats']) {
      $b .= '<dl class="facts">';
      foreach ($e['depart']['stats'] as [$v, $l]) $b .= '<dt>' . tb_h($l) . '</dt><dd>' . tb_h($v) . '</dd>';
      $b .= '</dl>';
    }
    foreach ($e['depart']['notes'] as $t) $b .= '<p class="tn">' . tb_h($t) . '</p>';
    $b .= '</section>';
  }
  if (!empty($e['flottes'])) {
    $b .= '<section><h2>Les flottes</h2><ul class="games">';
    foreach ($e['flottes']['items'] as $it) {
      if (!is_array($it) || empty($it['t'])) continue;
      $b .= '<li>' . (!empty($it['d']) ? '<strong>' . tb_h($it['d']) . '</strong> · ' : '') . tb_h($it['t']) . (!empty($it['s']) ? '<span class="det">' . tb_h($it['s']) . '</span>' : '') . '</li>';
    }
    $b .= '</ul>' . ($e['flottes']['note'] !== '' ? '<p class="tn">' . tb_h($e['flottes']['note']) . '</p>' : '') . '</section>';
  }

  // Skippers bretons, par classe
  $byClass = [];
  foreach ($e['skippers'] as $s) if (is_array($s) && count($s) >= 4) $byClass[(string)$s[1]][] = $s;
  $b .= '<section><h2>Les ' . $n . ' skippers bretons</h2>';
  foreach ($byClass as $class => $list) {
    $b .= '<h3>' . tb_h($class) . ' (' . count($list) . ')</h3><ul class="games">';
    foreach ($list as $s) $b .= '<li><strong>' . tb_h($s[0]) . '</strong><span class="det">' . tb_h($s[2]) . ' · ' . tb_h($s[3]) . '</span></li>';
    $b .= '</ul>';
  }
  $b .= '</section>';

  if ($e['src']) {
    $b .= '<section><h2>Sources</h2><ul class="games">';
    foreach ($e['src'] as $s) if (is_array($s) && count($s) >= 2 && preg_match('#^https?://#', (string)$s[1])) $b .= '<li><a href="' . tb_h($s[1]) . '" rel="noopener">' . tb_h($s[0]) . '</a></li>';
    $b .= '</ul></section>';
  }

  $ev = ['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => $name, 'sport' => 'Voile', 'url' => TB_SEO_BASE . $e['url'], 'description' => $desc,
    'startDate' => $e['start'], 'eventStatus' => 'https://schema.org/EventScheduled', 'image' => TB_SEO_BASE . '/og.png',
    'location' => ['@type' => 'Place', 'name' => 'Saint-Malo', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Saint-Malo', 'addressCountry' => 'FR']]];
  if ($e['fin'] !== '') $ev['endDate'] = $e['fin'];

  return tb_seo_layout($d, $sports, ['path' => $e['url'], 'title' => $title, 'description' => $desc, 'sport' => '', 'event' => $slug, 'body' => $b,
    'jsonld' => [$ev, tb_ld_crumbs($crumbs)]]);
}

/* ───────────── Sitemap et liens de la page d'accueil ───────────── */

/** Pages publiées : [adresse, fréquence, date de mise à jour ou null (= celle du site)]. */
function tb_seo_urls(array $d): array {
  // Les mentions légales portent un « noindex » : volontairement absentes du sitemap (une page noindex dans un sitemap est signalée comme erreur par la Search Console)
  $urls = [['/', 'daily', null]];
  foreach (tb_seo_sports($d) as $s) $urls[] = [$s['url'], 'daily', null];
  foreach (tb_seo_events($d) as $e) $urls[] = [$e['url'], !empty($e['archived']) ? 'yearly' : 'daily', !empty($e['archived']) ? ($e['snapshot_at'] ?? null) : null];
  foreach (tb_seo_clubs($d) as $c) $urls[] = [$c['url'], 'daily', null];
  return $urls;
}

function tb_seo_sitemap(array $d): string {
  $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach (tb_seo_urls($d) as [$path, $freq, $lm]) {
    $last = '<lastmod>' . tb_h($lm ?: $d['updated']) . '</lastmod>';
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
    $events = [];
    foreach (tb_seo_events($d) as $e) $events[] = $a($e, $e['name']);
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
      . ($events ? '<p><strong>Événements</strong> : ' . $sep($events) . '</p>' : '')
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
  elseif ($cmd === 'evenement') echo tb_seo_event_page($d, $argv[2] ?? '') ?? "événement inconnu\n";
  elseif ($cmd === 'sitemap') echo tb_seo_sitemap($d);
  else {
    $u = tb_seo_urls($d);
    echo count($u) . " pages (mise à jour : {$d['updated']}) :\n";
    foreach ($u as [$p]) echo TB_SEO_BASE . $p . "\n";
  }
}
