<?php
/*
 * Tribune Breizh · fabrique la page du site public à partir de la page publiée sur Claude.
 * Même travail que build.py, mais fait sur le serveur : les tâches automatiques n'ont plus qu'à déposer
 * la page telle quelle dans src/index.html sur GitHub, sans exécuter aucun script.
 *
 * Utilisé par gate.php (reconstruit cache/index.html dès que src/index.html change).
 * À la main : php bot/build.php   (écrit cache/index.html et affiche sa taille)
 */

const TB_HEAD = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="google-site-verification" content="zlcjk-kSuIzvyjDgvaxEaBcJ_vQKCQsjVimZx1mhN3E">
<meta name="description" content="Tribune Breizh : tout le sport breton, club par club. Résultats, classements, agenda TV, foot amateur, Route du Rhum et fiches de tous les clubs de Bretagne, mis à jour chaque jour.">
<meta property="og:title" content="Tribune Breizh · le sport breton, club par club">
<meta property="og:description" content="Résultats, classements, agenda TV et fiches clubs : tout le sport breton, mis à jour chaque jour.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://breizh.tribuneouest.fr/">
<meta property="og:locale" content="fr_FR">
<meta property="og:site_name" content="Tribune Breizh">
<meta property="og:image" content="https://breizh.tribuneouest.fr/og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Tribune Breizh, le sport breton club par club">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#121417">
<link rel="canonical" href="https://breizh.tribuneouest.fr/">
<link rel="preload" href="/fonts/big-shoulders-display.woff" as="font" type="font/woff" crossorigin>
<link rel="preload" href="/fonts/archivo.woff" as="font" type="font/woff" crossorigin>
<style>
/* Polices hébergées sur le site (aucun appel à Google) · licence SIL OFL, voir /fonts */
@font-face{font-family:"Big Shoulders Display";src:url("/fonts/big-shoulders-display.woff") format("woff");font-weight:600 900;font-display:swap}
@font-face{font-family:"Archivo";src:url("/fonts/archivo.woff") format("woff");font-weight:400 700;font-display:swap}
</style>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 90 60'%3E%3Crect width='90' height='60' fill='%23121417'/%3E%3Crect y='6.67' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='20' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='33.33' width='90' height='6.67' fill='%23fff'/%3E%3Crect y='46.67' width='90' height='6.67' fill='%23fff'/%3E%3Crect width='40' height='33.33' fill='%23fff'/%3E%3C/svg%3E">
<style>:root{color-scheme:light;box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}html{scroll-padding-top:env(safe-area-inset-top,0px)}body{margin:0;padding:0;font:14px -apple-system,BlinkMacSystemFont,sans-serif;background:#fff;color:#000}img{max-width:100%}[hidden]:not([hidden=until-found i]){display:none!important}</style>

HTML;

/** Transforme la page publiée sur Claude en page du site ; renvoie null si la page n'a pas la forme attendue. */
function tb_build(string $src): ?string {
  $body = $src;
  if (preg_match('/^\s*<!doctype html>.*?<body>\s*/is', $body, $m)) $body = substr($body, strlen($m[0]));
  $body = preg_replace('/\s*<\/body>\s*<\/html>\s*$/i', '', $body);
  $head = [];
  foreach (['/<title>.*?<\/title>/s', '/<meta name="claude:viewport-fit-sides"[^>]*>/'] as $pat) {
    if (preg_match($pat, $body, $m)) {
      $head[] = $m[0];
      $pos = strpos($body, $m[0]);
      $body = substr($body, 0, $pos) . substr($body, $pos + strlen($m[0]));
    }
  }
  $body = preg_replace('/<link rel="preconnect" href="https:\/\/fonts\.googleapis\.com"[^>]*>\s*/', '', $body);
  $body = preg_replace('/<link rel="stylesheet" href="https:\/\/fonts\.googleapis\.com[^>]*>\s*/', '', $body);
  $head = array_map(fn($b) => str_replace('<title>Tribune Breizh</title>', '<title>Tribune Breizh · le sport breton, club par club</title>', $b), $head);
  $out = TB_HEAD . implode("\n", $head) . "\n</head>\n<body>\n" . trim($body) . "\n</body>\n</html>\n";
  if (strpos($out, 'WXvNyWF5ypo7PuAsgFRJp6') === false || strpos($out, 'const DATA=') === false) return null;
  return $out;
}

/** Reconstruit $dst à partir de $src si besoin. Renvoie le chemin de la page à servir, ou null. */
function tb_refresh(string $src, string $dst): ?string {
  if (!is_file($src)) return is_file($dst) ? $dst : null;
  $raw = (string)file_get_contents($src);
  $sum = md5($raw);                                   // empreinte de la source : on ne reconstruit que si elle a changé
  if (is_file($dst) && @file_get_contents("$dst.md5") === $sum) return $dst;
  $html = tb_build($raw);
  if ($html === null) return is_file($dst) ? $dst : null;   // page reçue anormale : on garde la version précédente
  @mkdir(dirname($dst), 0755, true);
  $tmp = $dst . '.' . getmypid() . '.tmp';
  if (file_put_contents($tmp, $html) === false || !rename($tmp, $dst)) { @unlink($tmp); return is_file($dst) ? $dst : null; }
  @file_put_contents("$dst.md5", $sum);
  return $dst;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
  $root = dirname(__DIR__);
  @unlink("$root/cache/index.html.md5");
  $r = tb_refresh("$root/src/index.html", "$root/cache/index.html");
  if ($r === null) { fwrite(STDERR, "Page source absente ou inattendue : rien de généré\n"); exit(1); }
  echo "cache/index.html généré : " . filesize($r) . " octets\n";
}
