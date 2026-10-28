<?php
/*
 * Tribune Breizh · publication automatique sur Instagram (API Instagram avec Instagram Login).
 * Lancé par le cron d'o2switch toutes les 10 minutes. Il lit la file d'attente insta/queue.json
 * (déposée sur GitHub par les mises à jour automatiques) et publie chaque post à son heure.
 *
 * Commandes (dans le terminal de cPanel) :
 *   php publish.php init LE_JETON   enregistre le jeton d'accès (une seule fois)
 *   php publish.php fbinit JETON_UTILISATEUR ID_APP CLE_SECRETE_APP
 *                                   relie la page Facebook (une seule fois) : le jeton est échangé
 *                                   contre une autorisation permanente de la page, la clé n'est pas gardée
 *   php publish.php check           vérifie le jeton et montre la file, sans rien publier
 *   php publish.php                 publication normale (c'est ce que lance le cron)
 *
 * Le jeton et l'historique sont rangés dans ~/insta/, hors du site et hors de GitHub.
 */

define('API', getenv('TB_API') ?: 'https://graph.instagram.com/v26.0');   // TB_API/TB_SITE : seulement pour les tests
define('FBAPI', getenv('TB_FBAPI') ?: 'https://graph.facebook.com/v26.0');
define('SITE', getenv('TB_SITE') ?: 'https://breizh.tribuneouest.fr/');
const ALERT_TO = 'admin@tribuneouest.fr';
const WINDOW = 6 * 3600;        // un post en retard de plus de 6 h n'est plus publié
const MAX_TRIES = 3;

$home = getenv('HOME') ?: dirname(__DIR__, 3);
$STATE = $home . '/insta';
$REPO = dirname(__DIR__);
@mkdir($STATE, 0700, true);
date_default_timezone_set('Europe/Paris');

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
function head_ok($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
  curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
  return $code === 200 && stripos($type, 'image/jpeg') !== false;
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


/* ---------- fbinit : relie la page Facebook ---------- */
if ($cmd === 'fbinit') {
  [$ut, $app, $sec] = [trim($argv[2] ?? ''), trim($argv[3] ?? ''), trim($argv[4] ?? '')];
  if ($ut === '' || $app === '' || $sec === '') { echo "Usage : php publish.php fbinit JETON_UTILISATEUR ID_APP CLE_SECRETE_APP\n"; exit(1); }
  try {
    $long = http('GET', FBAPI . '/oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $app, 'client_secret' => $sec, 'fb_exchange_token' => $ut]);
    $pages = http('GET', FBAPI . '/me/accounts', ['fields' => 'id,name,access_token,tasks', 'access_token' => $long['access_token']]);
  } catch (Exception $e) { echo "Refusé par Facebook : " . $e->getMessage() . "\n"; exit(1); }
  $list = $pages['data'] ?? [];
  $pick = null;
  foreach ($list as $pg) if (stripos($pg['name'], 'tribune') !== false) { $pick = $pg; break; }
  if (!$pick && count($list) === 1) $pick = $list[0];
  if (!$pick) { echo "Page introuvable. Pages vues : " . implode(', ', array_map(fn($x) => $x['name'], $list)) . "\n"; exit(1); }
  jwrite("$STATE/fb.json", ['page_id' => $pick['id'], 'page_name' => $pick['name'], 'access_token' => $pick['access_token'], 'since' => time()]);
  logline("Page Facebook « {$pick['name']} » reliée (page {$pick['id']}).");
  exit(0);
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
$F = jread("$STATE/fb.json", null);
$DF = jread("$STATE/fbdone.json", []);
$now = time();

if ($cmd === 'check') {
  try { $me = http('GET', API . '/me', ['fields' => 'username', 'access_token' => $T['access_token']]); echo "Jeton valide pour @{$me['username']} jusqu'au " . date('d/m/Y', $T['expires_at']) . ".\n"; }
  catch (Exception $e) { echo "Jeton refusé : " . $e->getMessage() . "\n"; }
  if ($F) {
    try { $pg = http('GET', FBAPI . "/{$F['page_id']}", ['fields' => 'name', 'access_token' => $F['access_token']]); echo "Page Facebook reliée : {$pg['name']}.\n"; }
    catch (Exception $e) { echo "Page Facebook : autorisation refusée (" . $e->getMessage() . ").\n"; }
  } else echo "Page Facebook : pas encore reliée.\n";
  foreach ($Q['posts'] as $p) {
    $st = $D[$p['id']]['status'] ?? (!empty($p['cancel']) ? 'annulé' : (strtotime($p['at']) > $now ? 'prévu' : 'en attente'));
    $fs = $F ? ' · Facebook : ' . ($DF[$p['id']]['status'] ?? (strtotime($p['at']) < $F['since'] ? 'non concerné' : '—')) : '';
    echo "- {$p['at']}  {$p['type']}  {$p['id']}  : Instagram : $st$fs\n";
  }
  exit(0);
}

/* ---------- publication ---------- */
function container($T, $params) {
  $c = http('POST', API . "/{$T['user_id']}/media", $params + ['access_token' => $T['access_token']]);
  for ($i = 0; $i < 10; $i++) { // jusqu'à 5 minutes
    $s = http('GET', API . "/{$c['id']}", ['fields' => 'status_code', 'access_token' => $T['access_token']]);
    if (($s['status_code'] ?? '') === 'FINISHED') return $c['id'];
    if (in_array($s['status_code'] ?? '', ['ERROR', 'EXPIRED'])) throw new Exception("conteneur {$s['status_code']}");
    sleep($i < 3 ? 5 : 30);
  }
  throw new Exception("conteneur pas prêt après 5 minutes");
}

function fb_publish($F, $type, $urls, $caption) {
  $tok = ['access_token' => $F['access_token']];
  if ($type === 'story') {
    $ph = http('POST', FBAPI . "/{$F['page_id']}/photos", ['url' => $urls[0], 'published' => 'false'] + $tok);
    $r = http('POST', FBAPI . "/{$F['page_id']}/photo_stories", ['photo_id' => $ph['id']] + $tok);
    return $r['post_id'] ?? ($r['id'] ?? $ph['id']);
  }
  if (count($urls) === 1) {
    $r = http('POST', FBAPI . "/{$F['page_id']}/photos", ['url' => $urls[0], 'message' => $caption] + $tok);
    return $r['post_id'] ?? $r['id'];
  }
  $att = [];
  foreach ($urls as $k => $u) {
    $ph = http('POST', FBAPI . "/{$F['page_id']}/photos", ['url' => $u, 'published' => 'false'] + $tok);
    $att["attached_media[$k]"] = json_encode(['media_fbid' => $ph['id']]);
  }
  $r = http('POST', FBAPI . "/{$F['page_id']}/feed", ['message' => $caption] + $att + $tok);
  return $r['id'];
}

foreach ($Q['posts'] as $p) {
  $id = $p['id'] ?? ''; if ($id === '' || !empty($p['cancel'])) continue;
  $at = strtotime($p['at'] ?? ''); if (!$at || $at > $now) continue;
  $type = $p['type'] ?? 'feed';
  $d = $D[$id] ?? ['status' => 'en attente', 'tries' => 0];
  $igTodo = !in_array($d['status'], ['publié', 'abandonné', 'à vérifier']);
  $f = $DF[$id] ?? ['status' => 'en attente', 'tries' => 0];
  $fbTodo = $F && $at >= $F['since'] - 600 && !in_array($f['status'], ['publié', 'abandonné', 'à vérifier']);
  if (!$igTodo && !$fbTodo) continue;
  if ($now - $at > WINDOW) {
    if ($igTodo) { $D[$id] = ['status' => 'abandonné', 'tries' => $d['tries'], 'note' => 'trop en retard', 'time' => date('c')]; jwrite("$STATE/done.json", $D); }
    if ($fbTodo) { $DF[$id] = ['status' => 'abandonné', 'tries' => $f['tries'], 'note' => 'trop en retard', 'time' => date('c')]; jwrite("$STATE/fbdone.json", $DF); }
    logline("$id : abandonné (plus de 6 h de retard)."); continue;
  }
  $urls = array_map(fn($x) => SITE . ltrim($x, '/') . '?v=' . rawurlencode($id), $p['images'] ?? []);
  if (!$urls) continue;
  foreach ($urls as $u) if (!head_ok($u)) { logline("$id : image pas encore en ligne ($u), nouvel essai au prochain passage."); continue 2; }

  /* Instagram */
  if ($igTodo) try {
    if ($type === 'story') {
      $cid = container($T, ['image_url' => $urls[0], 'media_type' => 'STORIES']);
    } elseif ($type === 'carousel' && count($urls) > 1) {
      $kids = [];
      foreach ($urls as $u) $kids[] = container($T, ['image_url' => $u, 'is_carousel_item' => 'true']);
      $cid = container($T, ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $p['caption'] ?? '']);
    } else {
      $cid = container($T, ['image_url' => $urls[0], 'caption' => $p['caption'] ?? ''] + (!empty($p['alt']) ? ['alt_text' => $p['alt']] : []));
    }
    // On note « à vérifier » avant de publier : si le script s'arrête pile ici, il ne republiera jamais deux fois.
    $D[$id] = ['status' => 'à vérifier', 'tries' => $d['tries'] + 1, 'time' => date('c')]; jwrite("$STATE/done.json", $D);
    $m = http('POST', API . "/{$T['user_id']}/media_publish", ['creation_id' => $cid, 'access_token' => $T['access_token']]);
    $D[$id] = ['status' => 'publié', 'media_id' => $m['id'], 'time' => date('c')]; jwrite("$STATE/done.json", $D);
    logline("$id : publié sur Instagram ($type, média {$m['id']}).");
  } catch (Exception $e) {
    $tries = $d['tries'] + 1;
    $D[$id] = ['status' => $tries >= MAX_TRIES ? 'abandonné' : 'en attente', 'tries' => $tries, 'error' => $e->getMessage(), 'time' => date('c')];
    jwrite("$STATE/done.json", $D);
    logline("$id : échec Instagram n°$tries : " . $e->getMessage());
    if ($tries >= MAX_TRIES) alert("post Instagram non publié", "Le post « $id » prévu le {$p['at']} n'a pas pu être publié sur Instagram après " . MAX_TRIES . " essais.\nDernière erreur : " . $e->getMessage() . "\n\nJournal complet : ~/insta/log.txt sur o2switch.");
  }

  /* Facebook */
  if ($fbTodo) try {
    $DF[$id] = ['status' => 'à vérifier', 'tries' => $f['tries'] + 1, 'time' => date('c')]; jwrite("$STATE/fbdone.json", $DF);
    $pid = fb_publish($F, $type, $urls, $p['caption'] ?? '');
    $DF[$id] = ['status' => 'publié', 'post_id' => $pid, 'time' => date('c')]; jwrite("$STATE/fbdone.json", $DF);
    logline("$id : publié sur Facebook ($type, $pid).");
  } catch (Exception $e) {
    $tries = $f['tries'] + 1;
    $DF[$id] = ['status' => $tries >= MAX_TRIES ? 'abandonné' : 'en attente', 'tries' => $tries, 'error' => $e->getMessage(), 'time' => date('c')];
    jwrite("$STATE/fbdone.json", $DF);
    logline("$id : échec Facebook n°$tries : " . $e->getMessage());
    if ($tries >= MAX_TRIES) alert("post Facebook non publié", "Le post « $id » prévu le {$p['at']} n'a pas pu être publié sur la page Facebook après " . MAX_TRIES . " essais.\nDernière erreur : " . $e->getMessage() . "\n\nJournal complet : ~/insta/log.txt sur o2switch.");
  }
}
