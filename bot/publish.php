<?php
/*
 * Tribune Breizh · publication automatique sur Instagram (API Instagram avec Instagram Login).
 * Lancé par le cron d'o2switch toutes les 10 minutes. Il lit la file d'attente insta/queue.json
 * (déposée sur GitHub par les mises à jour automatiques) et publie chaque post à son heure.
 *
 * Commandes (dans le terminal de cPanel) :
 *   php publish.php init LE_JETON   enregistre le jeton d'accès (une seule fois)
 *   php publish.php check           vérifie le jeton et montre la file, sans rien publier
 *   php publish.php apercu ID       dessine tout de suite les cartes du post ID (insta/gen/) et donne leurs adresses
 *   php publish.php                 publication normale (c'est ce que lance le cron)
 *   php publish.php fbcheck | fbtest | fbapercu ID   canal Facebook (voir facebook.php)
 * Reel Instagram : {"type":"reel", "video":"insta/reels/xxx.mp4", "cover":"insta/reels/xxx.jpg" (facultatif), "caption":…}
 *
 * Le jeton et l'historique sont rangés dans ~/insta/, hors du site et hors de GitHub.
 */

define('API', getenv('TB_API') ?: 'https://graph.instagram.com/v26.0');   // TB_API/TB_SITE : seulement pour les tests
define('SITE', getenv('TB_SITE') ?: 'https://breizh.tribuneouest.fr/');
const ALERT_TO = 'admin@tribuneouest.fr';
const WINDOW = 6 * 3600;        // un post en retard de plus de 6 h n'est plus publié
const MAX_TRIES = 3;

$home = getenv('HOME') ?: dirname(__DIR__, 3);
$STATE = $home . '/insta';
$REPO = dirname(__DIR__);
@mkdir($STATE, 0700, true);
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/card.php';
require_once __DIR__ . '/visuels.php';

/* Images d'un post : « visuels » (visuels d'origine du site : derby, classement, resultats, buteurs, face, rhum, story,
   redessinés ici avec les données de src/index.html au moment de publier), « images » (JPEG déjà prêts dans insta/img/)
   ou « cards » (cartes décrites en JSON, dessinées ici dans insta/gen/ par bot/card.php). Renvoie les adresses publiques ; lance une exception si une carte échoue. */
function post_images($p, $REPO) {
  if (!empty($p['visuels']) && is_array($p['visuels'])) {          // visuels d'origine du site, redessinés ici (bot/visuels.php)
    $page = "$REPO/src/index.html"; $sum = substr(md5((string)@file_get_contents($page)), 0, 8); $rel = [];
    foreach (array_values($p['visuels']) as $k => $v) {
      $v = preg_replace('/[^a-z]/', '', (string)$v);
      $f = 'insta/gen/' . preg_replace('/[^a-z0-9-]/', '', $p['id']) . '-' . ($k + 1) . "-$v-$sum.jpg";
      if (!is_file("$REPO/$f")) tb_render_visuel($v, $page, "$REPO/$f");
      $rel[] = $f;
    }
  } elseif (!empty($p['cards']) && is_array($p['cards'])) {
    $rel = [];
    foreach (array_values($p['cards']) as $k => $spec) {
      if (($p['type'] ?? '') === 'story') $spec['format'] = 'story';
      $f = 'insta/gen/' . preg_replace('/[^a-z0-9-]/', '', $p['id']) . '-' . ($k + 1) . '-' . substr(md5(json_encode($spec)), 0, 8) . '.jpg';
      if (!is_file("$REPO/$f")) tb_render($spec, "$REPO/$f");
      $rel[] = $f;
    }
  } else $rel = $p['images'] ?? [];
  return array_map(fn($f) => SITE . ltrim($f, '/') . '?v=' . rawurlencode($p['id']), $rel);
}

function logline($m) { global $STATE; file_put_contents("$STATE/log.txt", date('Y-m-d H:i:s') . " $m\n", FILE_APPEND); if (PHP_SAPI === 'cli') echo "$m\n"; }
function jread($f, $def) { if (!is_file($f)) return $def; $j = json_decode(file_get_contents($f), true); return is_array($j) ? $j : $def; }
function jwrite($f, $d) { file_put_contents("$f.tmp", json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); rename("$f.tmp", $f); @chmod($f, 0600); }
function alert($subject, $body) {
  global $STATE; $last = jread("$STATE/alerts.json", []);
  $k = md5($subject); if (isset($last[$k]) && time() - $last[$k] < 6 * 3600) return; // pas plus d'un mail toutes les 6 h par problème
  $last[$k] = time(); jwrite("$STATE/alerts.json", $last);
  @mail(ALERT_TO, '=?UTF-8?B?' . base64_encode("[Tribune Breizh] $subject") . '?=', $body,
        "From: Tribune Breizh <" . ALERT_TO . ">\r\nContent-Type: text/plain; charset=UTF-8");
  logline("ALERTE : $subject");
}
function http($method, $url, $params = []) {
  $ch = curl_init();
  if ($method === 'GET') { $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params); }
  else { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); }
  curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15]);
  $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $j = json_decode((string)$body, true);
  if ($body === false || $code >= 400 || !is_array($j) || isset($j['error'])) {
    $msg = $j['error']['message'] ?? ($err ?: "HTTP $code");
    throw new Exception($msg);
  }
  return $j;
}
function head_ok($url, $video = false) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
  curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
  return $code === 200 && stripos($type, $video ? 'video/mp4' : 'image/jpeg') !== false;
}

$cmd = $argv[1] ?? 'run';

/* ---------- init : enregistre le jeton ---------- */
if ($cmd === 'init') {
  $tok = trim($argv[2] ?? '');
  if ($tok === '') { echo "Indique le jeton : php publish.php init LE_JETON\n"; exit(1); }
  try {
    $me = http('GET', API . '/me', ['fields' => 'user_id,username', 'access_token' => $tok]);
  } catch (Exception $e) { echo "Jeton refusé par Instagram : " . $e->getMessage() . "\n"; exit(1); }
  jwrite("$STATE/token.json", ['access_token' => $tok, 'user_id' => $me['user_id'] ?? $me['id'], 'username' => $me['username'] ?? '',
                               'refreshed_at' => time(), 'expires_at' => time() + 60 * 86400]);
  logline("Jeton enregistré pour @" . ($me['username'] ?? '?') . " (compte " . ($me['user_id'] ?? $me['id']) . ").");
  exit(0);
}

/* ---------- commandes Facebook (voir bot/facebook.php) ---------- */
if (in_array($cmd, ['fbcheck', 'fbtest', 'fbapercu'])) {
  try { require_once __DIR__ . '/facebook.php'; exit(tb_fb_cmd($cmd, $argv, $STATE, $REPO)); }
  catch (Throwable $e) { echo "Facebook : " . $e->getMessage() . "\n"; exit(1); }
}

/* ---------- un seul passage à la fois ---------- */
$lock = fopen("$STATE/lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$T = jread("$STATE/token.json", null);
if (!$T) { if ($cmd === 'check') echo "Aucun jeton : lance d'abord php publish.php init LE_JETON\n"; exit(0); }

/* ---------- renouvellement du jeton (valable 60 jours) ---------- */
if (time() - $T['refreshed_at'] > 5 * 86400) {
  try {
    $r = http('GET', (getenv('TB_API') ? API : 'https://graph.instagram.com') . '/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $T['access_token']]);
    $T['access_token'] = $r['access_token']; $T['refreshed_at'] = time(); $T['expires_at'] = time() + (int)($r['expires_in'] ?? 60 * 86400);
    jwrite("$STATE/token.json", $T); logline("Jeton renouvelé, valable jusqu'au " . date('d/m/Y', $T['expires_at']) . ".");
  } catch (Exception $e) {
    logline("Renouvellement du jeton impossible : " . $e->getMessage());
    if ($T['expires_at'] - time() < 10 * 86400)
      alert("jeton Instagram bientôt expiré", "Le jeton d'accès Instagram expire le " . date('d/m/Y', $T['expires_at']) . " et n'a pas pu être renouvelé (" . $e->getMessage() . ").\nIl faut en générer un nouveau dans l'appli Meta, puis lancer : php publish.php init LE_JETON");
  }
}

$Q = jread("$REPO/insta/queue.json", ['posts' => []]);
$D = jread("$STATE/done.json", []);
$now = time();

if ($cmd === 'apercu') {
  foreach ($Q['posts'] as $p) if (($p['id'] ?? '') === ($argv[2] ?? '')) {
    try { foreach (post_images($p, $REPO) as $u) echo "$u\n"; } catch (Exception $e) { echo "Carte impossible : " . $e->getMessage() . "\n"; }
    exit(0);
  }
  echo "Aucun post " . ($argv[2] ?? '') . " dans la file.\n"; exit(1);
}

if ($cmd === 'check') {
  echo function_exists('imagettftext') ? "Dessin des cartes : OK (GD + FreeType).\n" : "Dessin des cartes : IMPOSSIBLE, GD avec FreeType manque sur ce serveur.\n";
  try { $me = http('GET', API . '/me', ['fields' => 'username', 'access_token' => $T['access_token']]); echo "Jeton valide pour @{$me['username']} jusqu'au " . date('d/m/Y', $T['expires_at']) . ".\n"; }
  catch (Exception $e) { echo "Jeton refusé : " . $e->getMessage() . "\n"; }
  foreach ($Q['posts'] as $p) {
    $st = $D[$p['id']]['status'] ?? (!empty($p['cancel']) ? 'annulé' : (strtotime($p['at']) > $now ? 'prévu' : 'en attente'));
    echo "- {$p['at']}  {$p['type']}  {$p['id']}  : $st\n";
  }
  exit(0);
}

/* ---------- publication ---------- */
function container($T, $params, $tries = 10) {
  $c = http('POST', API . "/{$T['user_id']}/media", $params + ['access_token' => $T['access_token']]);
  for ($i = 0; $i < $tries; $i++) { // 10 essais : jusqu'à 5 minutes (16 pour un Reel : jusqu'à 8 minutes)
    $s = http('GET', API . "/{$c['id']}", ['fields' => 'status_code', 'access_token' => $T['access_token']]);
    if (($s['status_code'] ?? '') === 'FINISHED') return $c['id'];
    if (in_array($s['status_code'] ?? '', ['ERROR', 'EXPIRED'])) throw new Exception("conteneur {$s['status_code']}");
    sleep($i < 3 ? 5 : 30);
  }
  throw new Exception("conteneur pas prêt après " . ($tries > 10 ? 8 : 5) . " minutes");
}

foreach ($Q['posts'] as $p) {
  $id = $p['id'] ?? ''; if ($id === '' || !empty($p['cancel']) || ($p['type'] ?? '') === 'facebook') continue;   // type « facebook » : réservé à Facebook
  $at = strtotime($p['at'] ?? ''); if (!$at || $at > $now) continue;
  $d = $D[$id] ?? ['status' => 'en attente', 'tries' => 0];
  if (in_array($d['status'], ['publié', 'abandonné', 'à vérifier'])) continue;
  if ($now - $at > WINDOW) {
    $D[$id] = ['status' => 'abandonné', 'tries' => $d['tries'], 'note' => 'trop en retard', 'time' => date('c')];
    jwrite("$STATE/done.json", $D); logline("$id : abandonné (plus de 6 h de retard)."); continue;
  }
  try {
    $type = $p['type'] ?? 'feed';
    if ($type === 'reel') {   // Reel : une vidéo MP4 déposée dans le dépôt (insta/reels/…), 9:16, 3 à 90 s
      $video = SITE . ltrim((string)($p['video'] ?? ''), '/') . '?v=' . rawurlencode($id);
      if (empty($p['video']) || !head_ok($video, true)) { logline("$id : vidéo pas encore en ligne ($video), nouvel essai au prochain passage."); continue; }
      $cover = !empty($p['cover']) ? SITE . ltrim((string)$p['cover'], '/') . '?v=' . rawurlencode($id) : '';
      $cid = container($T, ['media_type' => 'REELS', 'video_url' => $video, 'caption' => $p['caption'] ?? '', 'share_to_feed' => 'true'] + ($cover !== '' && head_ok($cover) ? ['cover_url' => $cover] : []), 16);
    } else {
    $urls = post_images($p, $REPO);
    if (!$urls) throw new Exception('aucune image ni carte');
    foreach ($urls as $u) if (!head_ok($u)) { logline("$id : image pas encore en ligne ($u), nouvel essai au prochain passage."); continue 2; }

    if ($type === 'story') {
      $cid = container($T, ['image_url' => $urls[0], 'media_type' => 'STORIES']);
    } elseif ($type === 'carousel' && count($urls) > 1) {
      $kids = [];
      foreach ($urls as $u) $kids[] = container($T, ['image_url' => $u, 'is_carousel_item' => 'true']);
      $cid = container($T, ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $p['caption'] ?? '']);
    } else {
      $cid = container($T, ['image_url' => $urls[0], 'caption' => $p['caption'] ?? ''] + (!empty($p['alt']) ? ['alt_text' => $p['alt']] : []));
    }
    }
    // On note « à vérifier » avant de publier : si le script s'arrête pile ici, il ne republiera jamais deux fois.
    $D[$id] = ['status' => 'à vérifier', 'tries' => $d['tries'] + 1, 'time' => date('c')]; jwrite("$STATE/done.json", $D);
    $m = http('POST', API . "/{$T['user_id']}/media_publish", ['creation_id' => $cid, 'access_token' => $T['access_token']]);
    $D[$id] = ['status' => 'publié', 'media_id' => $m['id'], 'time' => date('c')]; jwrite("$STATE/done.json", $D);
    logline("$id : publié ($type, média {$m['id']}).");
  } catch (Exception $e) {
    $tries = $d['tries'] + 1;
    $D[$id] = ['status' => $tries >= MAX_TRIES ? 'abandonné' : 'en attente', 'tries' => $tries, 'error' => $e->getMessage(), 'time' => date('c')];
    jwrite("$STATE/done.json", $D);
    logline("$id : échec n°$tries : " . $e->getMessage());
    if ($tries >= MAX_TRIES) alert("post Instagram non publié", "Le post « $id » prévu le {$p['at']} n'a pas pu être publié après " . MAX_TRIES . " essais.\nDernière erreur : " . $e->getMessage() . "\n\nJournal complet : ~/insta/log.txt sur o2switch.");
  }
}

/* ---------- Facebook : seulement si ~/insta/facebook.json existe ; une erreur ici ne gêne jamais Instagram ---------- */
if (is_file("$STATE/facebook.json") && is_file(__DIR__ . '/facebook.php')) {
  try { require_once __DIR__ . '/facebook.php'; tb_fb_run($Q, $STATE, $REPO, time()); }
  catch (Throwable $e) { logline('Facebook : ' . $e->getMessage()); }
}
