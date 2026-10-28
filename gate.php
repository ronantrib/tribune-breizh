<?php
/*
 * Tribune Breizh · ouverture du site.
 * Avant l'heure d'ouverture, tout visiteur voit la page d'attente (attente.html) avec son compte à rebours.
 * Après, le vrai site (index.html) est servi, sans autre changement.
 * Aperçu du vrai site avant l'ouverture : https://breizh.tribuneouest.fr/?apercu=CLE
 *
 * Après l'ouverture, ce fichier reste utile : c'est lui qui fabrique la page du site à partir de src/index.html
 * (voir bot/build.php). Ne pas le retirer.
 */
const OPEN = '2026-10-30 12:00:00';      // heure de Paris
const KEY  = '307dd3be7a';                // clé d'aperçu
date_default_timezone_set('Europe/Paris');

$open = (new DateTime(getenv('TB_OPEN') ?: OPEN, new DateTimeZone('Europe/Paris')))->getTimestamp();   // TB_OPEN : tests seulement
$now = time();
$preview = isset($_GET['apercu']) && hash_equals(KEY, (string)$_GET['apercu']);
$status = (int)($_SERVER['REDIRECT_STATUS'] ?? 200);   // 404 quand on arrive ici depuis ErrorDocument
// La page du site est fabriquée ici à partir de src/index.html (déposée telle quelle par les tâches automatiques),
// puis gardée dans cache/index.html. Sans src/index.html, on sert l'ancien index.html.
require_once __DIR__ . '/bot/build.php';
$site = tb_refresh(__DIR__ . '/src/index.html', __DIR__ . '/cache/index.html') ?? (__DIR__ . '/index.html');

header('Content-Type: text/html; charset=utf-8');

if ($now < $open && !$preview) {
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  http_response_code(200);
  $page = @file_get_contents(__DIR__ . '/attente.html');
  if ($page === false) { http_response_code(503); echo 'Tribune Breizh ouvre vendredi 30 octobre à 12h.'; exit; }
  echo str_replace(['__NOW__', '__OPEN__'], [$now * 1000, $open * 1000], $page);
  exit;
}

if (!is_file($site)) { http_response_code(503); header('Retry-After: 300'); echo 'Tribune Breizh revient dans un instant.'; exit; }

if ($status >= 400) http_response_code($status);
header('Cache-Control: no-cache');
if ($preview) header('X-Robots-Tag: noindex');
$etag = '"' . md5(filemtime($site) . '-' . filesize($site)) . '"';
if (!$preview) {
  header("ETag: $etag");
  if ($status < 400 && ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
}
readfile($site);
