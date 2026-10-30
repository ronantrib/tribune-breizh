<?php
/*
 * Tribune Breizh · dessine une carte Instagram (JPEG) sur le serveur, avec GD, aux couleurs du site.
 * Remplace bot/card.js pour les posts automatiques : les tâches Claude n'écrivent plus que le texte
 * (dans insta/queue.json) et c'est le serveur qui fabrique l'image au moment de publier.
 *
 * Carte (spec JSON) :
 *   {"kicker": "Le saviez-vous ?", "title": "Brest, roi du hand", "lines": ["…"],
 *    "rows": [["Sam. 31 · 19h", "Rennes – Nice", "Ligue 1+"], …],     (facultatif, 8 lignes au plus)
 *    "color": "foot|hand|rugby|basket|voile|velo|amateur|accent", "foot": "Source : …",
 *    "format": "story"}                                             (facultatif : 1080×1920 au lieu de 1080×1350)
 * Affiche de match :
 *   {"layout": "affiche", "kicker": "Derby breton · Ligue 1", "a": "Lorient", "b": "Brest",
 *    "l1": "Lundi 2 novembre · 20h45", "l2": "Stade du Moustoir · Ligue 1+", "score": [2, 1] ou null, "format": …}
 *
 * À la main : php bot/card.php carte.json sortie.jpg
 */

const TB_COL = ['accent' => 'ff4d63', 'foot' => '3ecf6e', 'hand' => 'f2a33a', 'rugby' => 'b38cff', 'basket' => 'ff8a3d',
                'voile' => '4fc0dd', 'velo' => 'f2d33a', 'amateur' => '7fd6a4'];

function tb_font($n) { return __DIR__ . "/fonts/$n.ttf"; }
function tb_pt($px) { return $px * 0.75; }   // GD compte en points à 96 ppp
function tb_rgb($im, $hex, $alpha = 0) {
  return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
}
function tb_up($s) { return mb_strtoupper((string)$s, 'UTF-8'); }
function tb_w($px, $font, $text, $track = 0) {
  $b = imagettfbbox(tb_pt($px), 0, $font, $text);
  return ($b[2] - $b[0]) + $track * max(0, mb_strlen($text) - 1);
}
/** Écrit un texte, avec un espacement de lettres éventuel ; $y est la ligne de base. */
function tb_text($im, $px, $font, $col, $x, $y, $text, $track = 0) {
  if (!$track) { imagettftext($im, tb_pt($px), 0, (int)round($x), (int)round($y), $col, $font, $text); return; }
  foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
    imagettftext($im, tb_pt($px), 0, (int)round($x), (int)round($y), $col, $font, $ch);
    $x += tb_w($px, $font, $ch) + $track;
  }
}
/** Coupe un texte en lignes de largeur $max. */
function tb_wrap($px, $font, $text, $max) {
  $out = []; $cur = '';
  foreach (preg_split('/\s+/u', trim((string)$text)) as $w) {
    $try = $cur === '' ? $w : "$cur $w";
    if ($cur !== '' && tb_w($px, $font, $try) > $max) { $out[] = $cur; $cur = $w; } else $cur = $try;
  }
  if ($cur !== '') $out[] = $cur;
  return $out;
}
function tb_fit($px, $font, $text, $max) { while ($px > 10 && tb_w($px, $font, $text) > $max) $px -= 1; return $px; }
/** Raccourcit un texte avec « … » pour tenir dans $max. */
function tb_clip($px, $font, $text, $max) {
  if (tb_w($px, $font, $text) <= $max) return $text;
  while (mb_strlen($text) > 1 && tb_w($px, $font, $text . '…') > $max) $text = mb_substr($text, 0, -1);
  return rtrim($text) . '…';
}

function tb_frame($c, $H) {
  $im = imagecreatetruecolor(1080, $H);
  imagealphablending($im, true);
  imagefilledrectangle($im, 0, 0, 1080, $H, tb_rgb($im, '121417'));
  $stripe = tb_rgb($im, 'f5f6f4', 119);                          // bandes de l'hermine en fond, très discrètes
  for ($y = 0; $y < $H; $y += 112) imagefilledrectangle($im, 780, $y, 1079, $y + 55, $stripe);
  imagefilledrectangle($im, 0, 0, 17, $H, tb_rgb($im, TB_COL[$c['color'] ?? 'accent'] ?? TB_COL['accent']));
  return $im;
}
function tb_brand($im, $x, $y) {                                  // drapeau breton + « TRIBUNE BREIZH » ; $y = haut
  $fl = @imagecreatefrompng(__DIR__ . '/flag.png');                // même drapeau que les visuels préparés (hermines nettes)
  if ($fl) { imagecopyresampled($im, $fl, $x, $y, 0, 0, 84, 56, imagesx($fl), imagesy($fl)); imagedestroy($fl); }
  $f = tb_font('bsd-900'); $base = $y + 46;
  tb_text($im, 48, $f, tb_rgb($im, 'f5f6f4'), $x + 104, $base, 'TRIBUNE ');
  tb_text($im, 48, $f, tb_rgb($im, 'ff4d63'), $x + 104 + tb_w(48, $f, 'TRIBUNE '), $base, 'BREIZH');
}
function tb_footer($im, $H, $bottom, $left) {
  $y = $H - $bottom;
  $f = tb_font('archivo-500'); $px = max(18, tb_fit(24, $f, $left, 560));   // mention en bas : réduite si besoin, coupée en dernier recours
  tb_text($im, $px, $f, tb_rgb($im, '8b93a3'), 90, $y, tb_clip($px, $f, $left, 560));
  $u = 'breizh.tribuneouest.fr'; $f = tb_font('archivo-700');
  tb_text($im, 28, $f, tb_rgb($im, 'ff4d63'), 990 - tb_w(28, $f, $u), $y, $u);
}

/** Carte standard. Renvoie l'image GD ou lance une exception si le texte ne tient pas. */
function tb_card(array $c) {
  $story = ($c['format'] ?? '') === 'story'; $H = $story ? 1920 : 1350;
  $top = $story ? 240 : 80; $bottom = $story ? 280 : 80; $X = 90; $W = 900;
  if (trim((string)($c['title'] ?? '')) === '' || empty($c['lines'])) throw new Exception('il faut au moins title et lines');
  $rows = array_slice($c['rows'] ?? [], 0, 8);
  $col = TB_COL[$c['color'] ?? 'accent'] ?? TB_COL['accent'];
  $fT = tb_font('bsd-900'); $fR = tb_font('archivo-500'); $fB = tb_font('archivo-700');
  for ($scale = 1.0; $scale >= 0.6; $scale -= 0.05) {               // on réduit jusqu'à ce que tout tienne
    $tPx = (mb_strlen($c['title']) > 26 ? 92 : ($story ? 130 : 116)) * $scale;
    $tLines = tb_wrap($tPx, $fT, tb_up($c['title']), $W);
    $lPx = ($rows ? 32 : 38) * $scale; $lLines = [];
    foreach ($c['lines'] as $p) $lLines[] = tb_wrap($lPx, $fR, $p, $W);
    $rPx = 31 * $scale;
    $y = $top + 56 + ($story ? 150 : 70);                         // sous la marque
    $y += 30 + 14;                                                // petit titre
    $y += count($tLines) * $tPx * 0.92;
    $y += 36; foreach ($lLines as $ls) $y += count($ls) * $lPx * 1.35 + 18;
    if ($rows) $y += 34 + count($rows) * ($rPx + 34);
    if ($y <= $H - $bottom - 60 && count($tLines) <= 3) break;
  }
  if ($scale < 0.6) throw new Exception('texte trop long pour la carte');
  $im = tb_frame($c, $H);
  tb_brand($im, $X, $top);
  $y = $top + 56 + ($story ? 150 : 70);
  tb_text($im, 30, $fB, tb_rgb($im, $col), $X, $y + 24, tb_up($c['kicker'] ?? ''), 4.8);
  $y += 30 + 14;
  $white = tb_rgb($im, 'f5f6f4');
  foreach ($tLines as $t) { $y += $tPx * 0.92; tb_text($im, $tPx, $fT, $white, $X, $y - $tPx * 0.14, $t); }
  $y += 36;
  foreach ($lLines as $ls) { foreach ($ls as $t) { $y += $lPx * 1.35; tb_text($im, $lPx, $fR, $white, $X, $y - $lPx * 0.38, $t); } $y += 18; }
  if ($rows) {
    $y += 16; $line = tb_rgb($im, 'ffffff', 112); $grey = tb_rgb($im, 'aab1bc');
    foreach ($rows as $r) {
      imagefilledrectangle($im, $X, (int)$y, $X + $W, (int)$y + 1, $line);
      $base = $y + 16 + $rPx * 0.85;
      $three = count($r) >= 3; $c1 = 250;
      tb_text($im, 27 * $scale, $fB, tb_rgb($im, $col), $X, $base, tb_clip(27 * $scale, $fB, (string)$r[0], $c1 - 16));
      $c3w = $three ? min(260, tb_w(25 * $scale, $fR, (string)$r[2])) : 0;
      tb_text($im, $rPx, $fB, $white, $X + $c1, $base, tb_clip($rPx, $fB, (string)($r[1] ?? ''), $W - $c1 - ($three ? $c3w + 20 : 0)));
      if ($three) { $t3 = tb_clip(25 * $scale, $fR, (string)$r[2], 260); tb_text($im, 25 * $scale, $fR, $grey, $X + $W - tb_w(25 * $scale, $fR, $t3), $base, $t3); }
      $y += $rPx + 34;
    }
  }
  tb_footer($im, $H, $bottom, (string)($c['foot'] ?? ''));
  return $im;
}

/** Affiche de match : A contre B (ou le score final), date, lieu. */
function tb_affiche(array $c) {
  $story = ($c['format'] ?? '') === 'story'; $H = $story ? 1920 : 1350;
  $top = $story ? 240 : 80; $bottom = $story ? 280 : 80;
  foreach (['a', 'b', 'l1'] as $k) if (trim((string)($c[$k] ?? '')) === '') throw new Exception("affiche : champ $k manquant");
  $fT = tb_font('bsd-900'); $fR = tb_font('archivo-500'); $fB = tb_font('archivo-700');
  $im = tb_frame($c, $H);
  tb_brand($im, 90, $top);
  tb_text($im, 30, $fB, tb_rgb($im, 'ff4d63'), 90, $top + 56 + 50, tb_up($c['kicker'] ?? ''), 4.8);
  $white = tb_rgb($im, 'f5f6f4'); $acc = tb_rgb($im, 'ff4d63');
  $score = is_array($c['score'] ?? null) && count($c['score']) === 2;
  $A = tb_up($c['a']); $B = tb_up($c['b']);
  $big = min(tb_fit(190, $fT, $A, 880), tb_fit(190, $fT, $B, 880));
  $mid = $score ? ($c['score'][0] . ' – ' . $c['score'][1]) : 'CONTRE'; $midPx = $score ? 120 : 76;
  $block = $big * 0.82 * 2 + $midPx * 0.82 + 80 + 130;
  $y = ($H - $block) / 2 + ($story ? 40 : 30);
  $cx = fn($px, $f, $t) => 540 - tb_w($px, $f, $t) / 2;
  $y += $big * 0.82; tb_text($im, $big, $fT, $white, $cx($big, $fT, $A), $y, $A);
  $y += 40 + $midPx * 0.82; tb_text($im, $midPx, $fT, $acc, $cx($midPx, $fT, $mid), $y, $mid);
  $y += 40 + $big * 0.82; tb_text($im, $big, $fT, $white, $cx($big, $fT, $B), $y, $B);
  if ($score) { $sf = 'SCORE FINAL'; $y += 90; tb_text($im, 30, $fB, $acc, $cx(30, $fB, $sf) - 2.4 * mb_strlen($sf), $y, $sf, 4.8); }
  $l1 = tb_clip(40, $fB, (string)$c['l1'], 900); $y += 100; tb_text($im, 40, $fB, $white, $cx(40, $fB, $l1), $y, $l1);
  if (!empty($c['l2'])) { $l2 = tb_clip(32, $fR, (string)$c['l2'], 900); $y += 56; tb_text($im, 32, $fR, tb_rgb($im, 'aab1bc'), $cx(32, $fR, $l2), $y, $l2); }
  tb_footer($im, $H, $bottom, (string)($c['foot'] ?? 'Le sport breton, club par club'));
  return $im;
}

/** Fabrique le JPEG d'une spec. Lance une exception en cas de problème. */
function tb_render(array $spec, string $out) {
  if (!function_exists('imagettftext')) throw new Exception('GD avec FreeType manquant sur le serveur');
  $im = (($spec['layout'] ?? '') === 'affiche') ? tb_affiche($spec) : tb_card($spec);
  @mkdir(dirname($out), 0755, true);
  if (!imagejpeg($im, $out, 90)) throw new Exception("écriture impossible : $out");
  imagedestroy($im);
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
  if (count($argv) < 3) { fwrite(STDERR, "Usage : php bot/card.php carte.json sortie.jpg\n"); exit(2); }
  try { tb_render(json_decode(file_get_contents($argv[1]), true) ?: [], $argv[2]); echo "ok {$argv[2]}\n"; }
  catch (Exception $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
