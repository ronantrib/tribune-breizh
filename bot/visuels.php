<?php
/*
 * Tribune Breizh · les visuels d'origine du site (onglet Visuels), redessinés à l'identique sur le serveur avec GD.
 * Reprend fidèlement drawVisuels() de la page : mêmes positions, tailles, couleurs et polices.
 * Les données sont lues dans src/index.html (la page déposée par les tâches), comme le fait la page elle-même :
 * AFFICHE, ALLCLUBS, BUTEURS, FACE, RHUM, et le tableau des résultats écrit dans drawVisuels.
 *
 * Visuels : derby, classement, resultats, buteurs, feminin, face, rhum (1080×1080) et story (1080×1920).
 * À la main : php bot/visuels.php derby sortie.jpg
 */
require_once __DIR__ . '/card.php';

/* ---------- lecture des données de la page (littéraux JavaScript → PHP) ---------- */

/** Convertit un littéral JavaScript simple (objets, tableaux, chaînes, nombres) en valeur PHP. */
function tb_js_value(string $js) {
  $out = ''; $n = strlen($js); $i = 0;
  while ($i < $n) {
    $ch = $js[$i];
    if ($ch === '"' || $ch === "'" || $ch === '`') {
      $q = $ch; $s = ''; $i++;
      while ($i < $n && $js[$i] !== $q) {
        if ($js[$i] === '\\' && $i + 1 < $n) { $nx = $js[$i + 1]; $s .= ($nx === 'n' ? "\n" : ($nx === 't' ? "\t" : $nx)); $i += 2; continue; }
        $s .= $js[$i++];
      }
      $i++; $out .= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); continue;
    }
    if ($ch === '/' && ($js[$i + 1] ?? '') === '*') { $e = strpos($js, '*/', $i + 2); $i = $e === false ? $n : $e + 2; continue; }   // commentaires
    if ($ch === '/' && ($js[$i + 1] ?? '') === '/') { $e = strpos($js, "\n", $i); $i = $e === false ? $n : $e; continue; }
    if (ctype_alpha($ch) || $ch === '_' || $ch === '$') {
      $j = $i; while ($j < $n && (ctype_alnum($js[$j]) || $js[$j] === '_' || $js[$j] === '$')) $j++;
      $w = substr($js, $i, $j - $i); $k = $j; while ($k < $n && ctype_space($js[$k])) $k++;
      if ($k < $n && $js[$k] === ':') $out .= json_encode($w);
      elseif (in_array($w, ['true', 'false', 'null'], true)) $out .= $w;
      else $out .= 'null';
      $i = $j; continue;
    }
    $out .= $ch; $i++;
  }
  $out = preg_replace('/,\s*([}\]])/', '$1', $out);
  $v = json_decode($out, true);
  if ($v === null && trim($out) !== 'null') throw new Exception('données illisibles dans la page : ' . json_last_error_msg());
  return $v;
}
/** Trouve le littéral qui suit $marker (ex. « const RHUM= ») dans la page, en suivant crochets et chaînes. */
function tb_js_literal(string $src, string $marker) {
  $at = strpos($src, $marker);
  if ($at === false) throw new Exception("donnée $marker absente de la page");
  $i = $at + strlen($marker); $start = $i; $depth = 0; $n = strlen($src);
  for (; $i < $n; $i++) {
    $ch = $src[$i];
    if ($ch === '"' || $ch === "'" || $ch === '`') { $q = $ch; for ($i++; $i < $n && $src[$i] !== $q; $i++) if ($src[$i] === '\\') $i++; continue; }
    if ($ch === '{' || $ch === '[' || $ch === '(') $depth++;
    elseif ($ch === '}' || $ch === ']' || $ch === ')') { $depth--; if ($depth === 0) { $i++; break; } }
    elseif (($ch === ';' || $ch === "\n") && $depth === 0) break;
  }
  return tb_js_value(substr($src, $start, $i - $start));
}

/* ---------- outils de dessin, comme le canvas de la page ---------- */
const TB_D = 'bsd-900', TB_B7 = 'archivo-700', TB_B4 = 'archivo-400';
function vf($name) { return tb_font($name); }
/** fillText avec textAlign left/center/right ; $y = ligne de base. */
function vt($im, $txt, $x, $y, $px, $font, $hex, $align = 'left') {
  $w = tb_w($px, vf($font), $txt);
  if ($align === 'center') $x -= $w / 2; elseif ($align === 'right') $x -= $w;
  tb_text($im, $px, vf($font), tb_rgb($im, $hex), $x, $y, $txt);
}
/** fit() de la page : réduit de 4 px en 4 px jusqu'à tenir dans $max (20 px au plus petit). */
function vfit($txt, $max, $px, $font) { while (tb_w($px, vf($font), $txt) > $max && $px > 20) $px -= 4; return $px; }
/** ctr() de la page : texte centré sur x = 540, réduit pour tenir dans $max (960 par défaut). */
function vctr($im, $txt, $y, $px, $font, $hex, $max = 960) { vt($im, $txt, 540, $y, vfit($txt, $max, $px, $font), $font, $hex, 'center'); }
function vrect($im, $x, $y, $w, $h, $hex) { imagefilledrectangle($im, (int)$x, (int)$y, (int)($x + $w - 1), (int)($y + $h - 1), tb_rgb($im, $hex)); }
function vup($s) { return mb_strtoupper((string)$s, 'UTF-8'); }
function vhead($im, $kicker) {                    // base() de la page, sans le fond
  for ($i = 0; $i < 9; $i++) vrect($im, 0, 40 + $i * 14, 1080, 6, 'f5f6f4');
  vrect($im, 60, 20, 560, 170, '121417');
  vt($im, 'TRIBUNE BREIZH', 80, 110, 64, TB_D, 'f5f6f4');
  vt($im, vup($kicker), 80, 160, 26, TB_B7, 'ff5a6e');
}
function vbase($kicker) {
  $im = imagecreatetruecolor(1080, 1080); imagealphablending($im, true);
  vrect($im, 0, 0, 1080, 1080, '121417');
  vhead($im, $kicker);
  vt($im, 'Le sport breton, club par club · Kenavo !', 60, 1040, 24, TB_B4, '9aa1ab');
  return $im;
}
function vrow($im, $i, $y, $h, $up) { vrect($im, 60, $y - $up, 960, $h, $i % 2 ? '1b1f25' : '171a1f'); }

/* ---------- les sept visuels ---------- */
function tb_visuel(string $id, string $page) {
  $src = (string)@file_get_contents($page);
  if ($src === '') throw new Exception('page du site introuvable');
  switch ($id) {
    case 'derby': {
      $A = tb_js_literal($src, 'const AFFICHE=');
      $im = vbase($A['kicker'] ?? '');
      vctr($im, vup($A['a']), 470, 170, TB_D, 'f5f6f4');
      if (!empty($A['score'])) vctr($im, $A['score'][0] . ' – ' . $A['score'][1], 590, 110, TB_D, 'ff5a6e');
      else vctr($im, 'CONTRE', 570, 70, 'bsd-800', 'ff5a6e');
      vctr($im, vup($A['b']), 740, 170, TB_D, 'f5f6f4');
      vctr($im, !empty($A['score']) ? 'Score final' : ($A['l1'] ?? ''), 840, 40, TB_B7, 'f5f6f4');
      vctr($im, $A['l2'] ?? '', 895, 32, TB_B4, '9aa1ab');
      return $im;
    }
    case 'classement': {
      $im = vbase('Les Bretons au classement');
      $rows = array_values(array_filter(tb_js_literal($src, 'const ALLCLUBS='), fn($c) => empty($c['hist']) && ($c['r'] ?? '—') !== '—'));
      foreach (array_slice($rows, 0, 9) as $i => $c) {
        $y = 300 + $i * 76; vrow($im, $i, $y, 68, 50);
        vt($im, (string)$c['r'], 84, $y, 52, TB_D, 'f5f6f4');
        vt($im, (string)$c['short'], 230, $y - 6, 34, TB_B7, 'f5f6f4');
        vt($im, (string)$c['lg'], 230, $y + 18, 24, TB_B4, '9aa1ab');
        vt($im, (string)$c['pts'], 996, $y - 2, 30, TB_B7, 'f5f6f4', 'right');
      }
      return $im;
    }
    case 'resultats': {
      $im = vbase('Résultats du week-end');
      $rs = tb_js_literal(substr($src, (int)strpos($src, 'cv-resultats')), 'const rs=');
      foreach ($rs as $i => $r) {
        $y = 300 + $i * 84; vrow($im, $i, $y, 72, 54);
        vt($im, (string)$r[0], 440, $y, 36, TB_B7, 'f5f6f4', 'right');
        vt($im, $r[1] . '-' . $r[2], 540, $y + 4, 54, TB_D, 'ff5a6e', 'center');
        vt($im, (string)$r[3], 640, $y, 36, TB_B7, 'f5f6f4');
      }
      return $im;
    }
    case 'feminin': {
      // Les Bretonnes : dernier résultat de chaque club féminin suivi (fem:true, hors Bretagne historique), lu dans ALLCLUBS.
      $im = vbase('Les Bretonnes');
      $rows = array_values(array_filter(tb_js_literal($src, 'const ALLCLUBS='), fn($c) => !empty($c['fem']) && empty($c['hist']) && !empty($c['res'])));
      foreach (array_slice($rows, 0, 8) as $i => $c) {
        $y = 300 + $i * 84; vrow($im, $i, $y, 72, 54);
        $nm = (string)($c['nm'] ?? $c['short']); $r = $c['res'][0];
        $dom = $r[0] === $nm; $mine = $dom ? $r[1] : $r[2]; $their = $dom ? $r[2] : $r[1]; $opp = $dom ? $r[3] : $r[0];
        $out = $mine > $their ? 'V' : ($mine < $their ? 'D' : 'N');
        vt($im, (string)$c['short'], 84, $y - 10, vfit((string)$c['short'], 380, 34, TB_B7), TB_B7, 'f5f6f4');
        vt($im, (string)$c['lg'], 84, $y + 12, vfit((string)$c['lg'], 380, 22, TB_B4), TB_B4, '9aa1ab');
        vt($im, $out, 560, $y + 6, 46, TB_D, $out === 'V' ? '3ecf6e' : ($out === 'D' ? 'ff5a6e' : 'f5f6f4'), 'center');
        vt($im, $mine . '-' . $their, 650, $y + 6, 46, TB_D, 'f5f6f4', 'center');
        vt($im, (string)$opp, 1004, $y + 4, vfit((string)$opp, 300, 28, TB_B7), TB_B7, 'f5f6f4', 'right');
      }
      vt($im, 'Dernier match de chaque club féminin suivi', 60, 1000, 22, TB_B4, '9aa1ab');
      return $im;
    }
    case 'resfoot': case 'reshand': case 'resbasket': case 'resrugby': case 'resvolley': {
      // Résultats du week-end d'un sport : groupes de DATA.<sport>.results datés (« 7 nov. », « 7 novembre ») des 4 derniers jours, hors Bretagne historique.
      $sp = ['resfoot' => 'foot', 'reshand' => 'hand', 'resbasket' => 'basket', 'resrugby' => 'rugby', 'resvolley' => 'volley'][$id];
      $nomSp = ['foot' => 'Football', 'hand' => 'Handball', 'basket' => 'Basket', 'rugby' => 'Rugby', 'volley' => 'Volley'][$sp];
      $mois = ['janv' => 1, 'févr' => 2, 'mars' => 3, 'avr' => 4, 'mai' => 5, 'juin' => 6, 'juil' => 7, 'août' => 8, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'déc' => 12];
      $setOf = function ($name) use ($src) { return preg_match('/const ' . $name . '=new Set\((\[.*?\])\)/su', $src, $mm) ? (json_decode($mm[1], true) ?: []) : []; };
      $hist = $setOf('HISTNAMES'); $bzh = $setOf('BZH');   // comme la page : un match de Bretagne historique n'est montré que si un club breton y joue
      $D = tb_js_literal(substr($src, (int)strpos($src, "\n  $sp:{title")), 'results:');
      $now = (int)(getenv('TB_NOW') ?: time()); $games = [];  // TB_NOW : date de test (timestamp)
      foreach ($D as $grp) {
        if (!preg_match('/\b(\d{1,2})(?:er)? (janv|févr|mars|avr|mai|juin|juil|août|sept|oct|nov|déc)/u', (string)$grp['day'], $m)) continue;
        $mo = $mois[$m[2]]; $yr = (int)date('Y', $now); if ($mo > (int)date('n', $now) + 6) $yr--;
        $ts = mktime(12, 0, 0, $mo, (int)$m[1], $yr);
        if ($ts > $now + 86400 || $now - $ts > 4 * 86400) continue;
        $comp = trim(explode(' · ', preg_replace('/\s*\(.*$/u', '', (string)$grp['day']))[0]);
        foreach ($grp['games'] as $g) {
          if ((in_array($g[0], $hist, true) || in_array($g[3], $hist, true)) && !in_array($g[0], $bzh, true) && !in_array($g[3], $bzh, true)) continue;
          $c = $comp; $x = (string)($g[4] ?? '');
          if (preg_match('/^(Lundi|Mardi|Mercredi|Jeudi|Vendredi|Samedi|Dimanche)/u', $c)) $c = ['L1' => 'Ligue 1', 'L2' => 'Ligue 2', 'L3' => 'Ligue 3', 'Arkema' => 'Arkema'][$x] ?? $x;
          $games[] = [$g[0], $g[1], $g[2], $g[3], $c, $x];
        }
      }
      if (!$games) throw new Exception("aucun résultat récent pour $sp");
      $im = vbase('Résultats du week-end · ' . $nomSp);
      foreach (array_slice($games, 0, 8) as $i => $g) {
        $y = 300 + $i * 88; vrow($im, $i, $y, 76, 54);
        vt($im, (string)$g[0], 440, $y - 10, vfit((string)$g[0], 340, 34, TB_B7), TB_B7, 'f5f6f4', 'right');
        vt($im, $g[1] . '-' . $g[2], 540, $y - 6, 50, TB_D, 'ff5a6e', 'center');
        vt($im, (string)$g[3], 640, $y - 10, vfit((string)$g[3], 340, 34, TB_B7), TB_B7, 'f5f6f4');
        $lab = $g[4] . (preg_match('/^(J\d|a\.p\.)/u', (string)$g[5]) ? ' · ' . $g[5] : '');
        vt($im, $lab, 540, $y + 14, 20, TB_B4, '9aa1ab', 'center');
      }
      if (count($games) > 8) vt($im, '+ ' . (count($games) - 8) . ' autres résultats sur breizh.tribuneouest.fr', 60, 1000, 22, TB_B4, '9aa1ab');
      return $im;
    }
    case 'buteurs': {
      $im = vbase('Les buteurs bretons');
      $l = tb_js_literal($src, 'const BUTEURS=');
      usort($l, fn($a, $b) => $b[3] <=> $a[3]);
      foreach (array_slice($l, 0, 8) as $i => $b) {
        $y = 300 + $i * 84; vrow($im, $i, $y, 72, 54);
        vt($im, (string)$b[3], 130, $y + 6, 58, TB_D, 'ff5a6e', 'center');
        vt($im, (string)$b[0], 210, $y - 4, 36, TB_B7, 'f5f6f4');
        vt($im, $b[1] . ' · ' . $b[2], 210, $y + 24, 24, TB_B4, '9aa1ab');
      }
      // Date du classement : lue dans la note des buteurs (« Buts en championnat au 3 novembre (…) »), elle change à chaque mise à jour.
      $date = '';
      try { if (preg_match('/\bau (\d{1,2}(?:er)?) (\p{L}+)/u', (string)tb_js_literal($src, 'const BUT_NOTE='), $m)) $date = " au $m[1] $m[2]"; } catch (Exception $e) {}
      vt($im, 'Buts en championnat' . $date, 60, 990, 22, TB_B4, '9aa1ab');
      return $im;
    }
    case 'face': {
      $F = tb_js_literal($src, 'const FACE=');
      if (empty($F['m'])) throw new Exception("pas de face-à-face pour l'affiche en cours");
      $im = vbase('Le face-à-face');
      $va = $vb = $nul = 0;
      foreach ($F['m'] as $g) { [$h, $sh, $sa, $a] = $g; $w = $sh > $sa ? $h : ($sa > $sh ? $a : null); if (!$w) $nul++; elseif ($w === $F['a']) $va++; elseif ($w === $F['b']) $vb++; }
      vctr($im, vup($F['a']) . ' – ' . vup($F['b']), 300, 96, TB_D, 'f5f6f4');
      foreach ([[$va, 'victoire' . ($va > 1 ? 's' : '') . ' ' . $F['a'], 250], [$nul, 'nul' . ($nul > 1 ? 's' : ''), 540], [$vb, 'victoire' . ($vb > 1 ? 's' : '') . ' ' . $F['b'], 830]] as [$v, $lab, $cx]) {
        vt($im, (string)$v, $cx, 450, 120, TB_D, 'ff5a6e', 'center');
        vt($im, $lab, $cx, 492, vfit($lab, 260, 26, TB_B4), TB_B4, '9aa1ab', 'center');
      }
      foreach (array_slice($F['m'], 0, 5) as $i => $g) {
        $y = 600 + $i * 80; vrow($im, $i, $y, 68, 50);
        vt($im, (string)$g[0], 420, $y, 32, TB_B7, 'f5f6f4', 'right');
        vt($im, $g[1] . '-' . $g[2], 500, $y + 2, 50, TB_D, 'ff5a6e', 'center');
        vt($im, (string)$g[3], 580, $y, 32, TB_B7, 'f5f6f4');
        vt($im, explode(' · ', (string)($g[4] ?? ''))[0], 1004, $y, 22, TB_B4, '9aa1ab', 'right');
      }
      return $im;
    }
    case 'rhum': {
      $R = tb_js_literal($src, 'const RHUM=');
      $im = vbase('Route du Rhum 2026');
      $st = strtotime($R['start']); $now = time(); $cl = $R['classement'] ?? [];
      if ($now >= $st && $cl) {
        vctr($im, 'LES BRETONS EN COURSE', 290, 90, TB_D, 'f5f6f4');
        foreach (array_slice($cl, 0, 8) as $i => $r) {
          $y = 400 + $i * 76; vrow($im, $i, $y, 68, 50);
          vt($im, $r[1] . 'e', 130, $y + 2, 50, TB_D, 'ff5a6e', 'center');
          vt($im, (string)$r[2], 210, $y - 6, 32, TB_B7, 'f5f6f4');
          vt($im, $r[0] . (!empty($r[3]) ? ' · ' . $r[3] : ''), 210, $y + 20, 22, TB_B4, '9aa1ab');
        }
        if (!empty($R['maj'])) vt($im, (string)$R['maj'], 60, 1000, 22, TB_B4, '9aa1ab');
      } else {
        $d = max(0, (int)ceil(($st - $now) / 86400));
        vctr($im, $now >= $st ? 'LES BRETONS SONT EN MER' : count($R['skippers']) . ' BRETONS AU DÉPART', 290, 90, TB_D, 'f5f6f4');
        foreach (array_slice($R['skippers'], 0, 12) as $i => $b) {
          $col = $i < 6 ? 0 : 1; $row = $i < 6 ? $i : $i - 6; $X = $col ? 560 : 80; $y = 390 + $row * 80;
          vrect($im, $X - 16, $y - 46, 456, 66, $i % 2 ? '1b1f25' : '171a1f');
          vt($im, (string)$b[0], $X, $y - 4, vfit((string)$b[0], 300, 30, TB_B7), TB_B7, 'f5f6f4');
          vt($im, vup($b[1]), $X, $y + 18, 20, TB_B7, 'ff5a6e');
        }
        vctr($im, $now >= $st ? 'Premier classement au prochain pointage' : ($d ? "J−$d · " : '') . 'Dimanche 1er novembre · 13h02 · Saint-Malo', 920, 34, TB_B7, 'f5f6f4');
      }
      return $im;
    }
    case 'story': {
      $A = tb_js_literal($src, 'const AFFICHE=');
      $im = imagecreatetruecolor(1080, 1920); imagealphablending($im, true);
      vrect($im, 0, 0, 1080, 1920, '121417');
      vhead($im, $A['kicker'] ?? '');
      $sc = !empty($A['score']);
      vctr($im, $sc ? 'SCORE FINAL' : vup($A['l1'] ?? ''), 640, $sc ? 100 : 64, TB_D, 'ff5a6e');
      vctr($im, vup($A['a']), 900, 240, TB_D, 'f5f6f4');
      if ($sc) vctr($im, $A['score'][0] . ' – ' . $A['score'][1], 1080, 170, TB_D, 'ff5a6e');
      else vctr($im, 'CONTRE', 1030, 80, 'bsd-800', 'ff5a6e');
      vctr($im, vup($A['b']), $sc ? 1300 : 1250, 240, TB_D, 'f5f6f4');
      vctr($im, $A['l2'] ?? '', 1420, 40, TB_B4, '9aa1ab');
      vt($im, 'Tout le sport breton sur Tribune Breizh · lien en bio', 60, 1880, 24, TB_B4, '9aa1ab');
      return $im;
    }
  }
  throw new Exception("visuel inconnu : $id");
}

/** Dessine le visuel $id dans $out (JPEG). */
function tb_render_visuel(string $id, string $page, string $out) {
  if (!function_exists('imagettftext')) throw new Exception('GD avec FreeType manquant sur le serveur');
  $im = tb_visuel($id, $page);
  @mkdir(dirname($out), 0755, true);
  if (!imagejpeg($im, $out, 92)) throw new Exception("écriture impossible : $out");
  imagedestroy($im);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
  if (count($argv) < 3) { fwrite(STDERR, "Usage : php bot/visuels.php derby|classement|resultats|buteurs|feminin|face|rhum|story sortie.jpg\n"); exit(2); }
  try { tb_render_visuel($argv[1], dirname(__DIR__) . '/src/index.html', $argv[2]); echo "ok {$argv[2]}\n"; }
  catch (Exception $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
