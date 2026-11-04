<?php
/*
 * Tribune Breizh · routeur des pages par club (/club/NOM), par sport (/sport/NOM) et du sitemap (/sitemap.xml).
 * Les règles de .htaccess envoient ici ces adresses ; tout le travail est dans bot/seo.php.
 * Une adresse inconnue retombe sur gate.php, comme avant (le site, avec le code 404).
 */
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/bot/seo.php';

function tb_pages_fallback(): never {
  $_SERVER['REDIRECT_STATUS'] = '404';
  require __DIR__ . '/gate.php';
  exit;
}

$t = (string)($_GET['t'] ?? '');
$id = strtolower((string)($_GET['id'] ?? ''));
if (!in_array($t, ['club', 'sport', 'sitemap'], true)) tb_pages_fallback();

// Une adresse avec « / » final est ramenée à l'adresse canonique (sans « / »)
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
if ($t !== 'sitemap' && substr($path, -1) === '/') { header('Location: ' . rtrim($path, '/'), true, 301); exit; }

$d = tb_seo_data(__DIR__ . '/src/index.html', __DIR__ . '/cache/seo-data.json');
if (!$d) { http_response_code(503); header('Retry-After: 300'); echo 'Tribune Breizh revient dans un instant.'; exit; }

if ($t === 'sitemap') {
  $type = 'application/xml; charset=utf-8';
  $body = tb_seo_sitemap($d);
} else {
  // L'identifiant du club (ex. « rennes ») renvoie vers l'adresse définitive (ex. « stade-rennais »)
  if ($t === 'club') {
    foreach (tb_seo_clubs($d) as $c) {
      if (strtolower((string)$c['id']) === $id && $c['slug'] !== $id) { header('Location: ' . $c['url'], true, 301); exit; }
    }
  }
  $type = 'text/html; charset=utf-8';
  $body = $t === 'club' ? tb_seo_club_page($d, $id) : tb_seo_sport_page($d, $id);
  if ($body === null) tb_pages_fallback();
}

header('Content-Type: ' . $type);
header('Cache-Control: no-cache');
$etag = '"' . md5($d['sha'] . $t . $id) . '"';
header("ETag: $etag");
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
echo $body;
