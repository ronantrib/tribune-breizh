<?php
/*
 * Tribune Breizh · canal Facebook (Page « Tribune Breizh - Sport breton »).
 * Chargé par publish.php SEULEMENT si ~/insta/facebook.json existe ; une erreur ici ne touche jamais Instagram.
 *
 * ~/insta/facebook.json (hors du dépôt, jamais dans GitHub) : {"page_id":"…","access_token":"…"}
 *
 * Un post de insta/queue.json est repris sur Facebook s'il porte un champ « facebook » :
 *   "facebook": {
 *     "message": "Texte du post (question, légende…)",
 *     "link":    "https://breizh.tribuneouest.fr/…",     (facultatif, https obligatoire)
 *     "image":   "first" | "all" | "none",                (par défaut : first = la 1re image du post)
 *     "at":      "2026-11-02T12:30+01:00",                (par défaut : l'heure du post Instagram)
 *     "cancel":  true                                     (facultatif)
 *   }
 * Un post de type « facebook » (sans image ou avec) n'est publié que sur Facebook, jamais sur Instagram.
 *
 * Commandes (terminal cPanel, dans bot/) :
 *   php publish.php fbcheck      vérifie le jeton de la page et montre les posts Facebook de la file
 *   php publish.php fbtest       crée un post NON publié puis le supprime (teste le droit de publier)
 *   php publish.php fbapercu ID  montre ce qui serait publié pour le post ID, sans rien envoyer
 */

define('FB_API', getenv('TB_FBAPI') ?: 'https://graph.facebook.com/v26.0');   // TB_FBAPI : seulement pour les tests

function fb_call($method, $path, $params, $token) {
  $url = FB_API . '/' . ltrim($path, '/');
  $params['access_token'] = $token;
  $ch = curl_init();
  if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); }
  else {
    $url .= '?' . http_build_query($params);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  }
  curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15]);
  $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $j = json_decode((string)$body, true);
  if ($body === false || $code >= 400 || $j === null || (is_array($j) && isset($j['error']))) {
    throw new Exception(is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : ($err ?: "HTTP $code"));
  }
  return is_array($j) ? $j : ['success' => (bool)$j];
}

function fb_config($STATE) {
  $C = jread("$STATE/facebook.json", null);
  if (!$C || !preg_match('/^\d+$/', (string)($C['page_id'] ?? '')) || trim((string)($C['access_token'] ?? '')) === '') return null;
  return ['page_id' => (string)$C['page_id'], 'access_token' => trim($C['access_token'])];
}

/* Ce qui sera publié pour un post : message, lien, images. Renvoie null si le post n'a pas de champ facebook. */
function fb_plan($p, $REPO) {
  $f = $p['facebook'] ?? null;
  if (!is_array($f)) return null;
  $msg = trim((string)($f['message'] ?? ''));
  $link = trim((string)($f['link'] ?? ''));
  if ($link !== '' && !preg_match('#^https://[^\s]+$#', $link)) throw new Exception('lien Facebook invalide (https obligatoire)');
  $mode = in_array($f['image'] ?? 'first', ['first', 'all', 'none'], true) ? ($f['image'] ?? 'first') : 'first';
  $urls = [];
  $hasImg = !empty($p['visuels']) || !empty($p['cards']) || !empty($p['images']);
  if ($mode !== 'none' && $hasImg) { $urls = post_images($p, $REPO); if ($mode === 'first') $urls = array_slice($urls, 0, 1); }
  if ($msg === '' && $link === '') throw new Exception('post Facebook sans texte ni lien');
  if (mb_strlen($msg) > 5000) $msg = mb_substr($msg, 0, 5000);
  return ['message' => $msg, 'link' => $link, 'urls' => $urls];
}

function fb_publish($C, $plan) {
  $tok = $C['access_token']; $page = $C['page_id']; $msg = $plan['message']; $link = $plan['link']; $urls = $plan['urls'];
  // Avec une image, Facebook n'affiche pas d'aperçu de lien : le lien est donc ajouté au texte.
  if ($urls && $link !== '' && strpos($msg, $link) === false) $msg = rtrim($msg) . "\n\n" . $link;
  if (!$urls) {
    $params = ['message' => $msg]; if ($link !== '') $params['link'] = $link;
    $r = fb_call('POST', "$page/feed", $params, $tok); return $r['id'] ?? '';
  }
  if (count($urls) === 1) {
    $r = fb_call('POST', "$page/photos", ['url' => $urls[0], 'message' => $msg, 'published' => 'true'], $tok); return $r['post_id'] ?? ($r['id'] ?? '');
  }
  $att = [];
  foreach ($urls as $u) { $r = fb_call('POST', "$page/photos", ['url' => $u, 'published' => 'false'], $tok); $att[] = json_encode(['media_fbid' => $r['id']]); }
  $r = fb_call('POST', "$page/feed", ['message' => $msg, 'attached_media' => $att], $tok); return $r['id'] ?? '';
}

/* Passage normal (lancé à la fin de publish.php) */
function tb_fb_run($Q, $STATE, $REPO, $now) {
  $C = fb_config($STATE); if (!$C) return;
  $D = jread("$STATE/fb_done.json", []);
  foreach ($Q['posts'] as $p) {
    $id = $p['id'] ?? ''; $f = $p['facebook'] ?? null;
    if ($id === '' || !is_array($f) || !empty($p['cancel']) || !empty($f['cancel'])) continue;
    $at = strtotime($f['at'] ?? ($p['at'] ?? '')); if (!$at || $at > $now) continue;
    $d = $D[$id] ?? ['status' => 'en attente', 'tries' => 0];
    if (in_array($d['status'], ['publié', 'abandonné', 'à vérifier'])) continue;
    if ($now - $at > WINDOW) {
      $D[$id] = ['status' => 'abandonné', 'tries' => $d['tries'], 'note' => 'trop en retard', 'time' => date('c')];
      jwrite("$STATE/fb_done.json", $D); logline("Facebook $id : abandonné (plus de 6 h de retard)."); continue;
    }
    try {
      $plan = fb_plan($p, $REPO);
      foreach ($plan['urls'] as $u) if (!head_ok($u)) { logline("Facebook $id : image pas encore en ligne ($u), nouvel essai au prochain passage."); continue 2; }
      // « à vérifier » avant d'envoyer : si le script s'arrête pile ici, il ne republiera jamais deux fois.
      $D[$id] = ['status' => 'à vérifier', 'tries' => $d['tries'] + 1, 'time' => date('c')]; jwrite("$STATE/fb_done.json", $D);
      $pid = fb_publish($C, $plan);
      $D[$id] = ['status' => 'publié', 'post_id' => $pid, 'time' => date('c')]; jwrite("$STATE/fb_done.json", $D);
      logline("Facebook $id : publié (post $pid).");
    } catch (Exception $e) {
      $tries = $d['tries'] + 1;
      $D[$id] = ['status' => $tries >= MAX_TRIES ? 'abandonné' : 'en attente', 'tries' => $tries, 'error' => $e->getMessage(), 'time' => date('c')];
      jwrite("$STATE/fb_done.json", $D);
      logline("Facebook $id : échec n°$tries : " . $e->getMessage());
      if ($tries >= MAX_TRIES) alert("post Facebook non publié", "Le post Facebook « $id » prévu le " . ($f['at'] ?? $p['at'] ?? '?') . " n'a pas pu être publié après " . MAX_TRIES . " essais.\nDernière erreur : " . $e->getMessage() . "\n\nSi l'erreur parle de jeton ou d'autorisation, le jeton de la page est à refaire (Explorateur de l'API Graph).\nJournal complet : ~/insta/log.txt sur o2switch.");
    }
  }
}

/* Commandes fbcheck / fbtest / fbapercu */
function tb_fb_cmd($cmd, $argv, $STATE, $REPO) {
  $C = fb_config($STATE);
  if (!$C) { echo "Fichier ~/insta/facebook.json absent ou incomplet (il faut page_id et access_token).\n"; return 1; }
  $Q = jread("$REPO/insta/queue.json", ['posts' => []]);
  if ($cmd === 'fbcheck') {
    try { $r = fb_call('GET', $C['page_id'], ['fields' => 'name,fan_count'], $C['access_token']); echo "Page « {$r['name']} » atteinte (" . ($r['fan_count'] ?? '?') . " mentions j'aime). Jeton accepté.\n"; }
    catch (Exception $e) { echo "Jeton ou page refusés : " . $e->getMessage() . "\n"; return 1; }
    $D = jread("$STATE/fb_done.json", []); $n = 0;
    foreach ($Q['posts'] as $p) if (is_array($p['facebook'] ?? null)) {
      $n++; $f = $p['facebook'];
      $st = $D[$p['id']]['status'] ?? (!empty($p['cancel']) || !empty($f['cancel']) ? 'annulé' : (strtotime($f['at'] ?? $p['at']) > time() ? 'prévu' : 'en attente'));
      echo "- " . ($f['at'] ?? $p['at']) . "  {$p['id']}  : $st\n";
    }
    if (!$n) echo "Aucun post Facebook dans la file pour l'instant.\n";
    return 0;
  }
  if ($cmd === 'fbtest') {
    try {
      $r = fb_call('POST', "{$C['page_id']}/feed", ['message' => 'Test technique Tribune Breizh (non publié, supprimé aussitôt).', 'published' => 'false'], $C['access_token']);
      echo "Création d'un post non publié : OK ({$r['id']}).\n";
      fb_call('DELETE', $r['id'], [], $C['access_token']);
      echo "Suppression : OK. Le bot a le droit de publier sur la page.\n";
    } catch (Exception $e) { echo "Échec : " . $e->getMessage() . "\n"; return 1; }
    return 0;
  }
  if ($cmd === 'fbapercu') {
    foreach ($Q['posts'] as $p) if (($p['id'] ?? '') === ($argv[2] ?? '')) {
      try { $plan = fb_plan($p, $REPO); } catch (Exception $e) { echo "Post Facebook impossible : " . $e->getMessage() . "\n"; return 1; }
      if (!$plan) { echo "Ce post n'a pas de champ facebook.\n"; return 1; }
      echo "Texte : {$plan['message']}\nLien : " . ($plan['link'] ?: '(aucun)') . "\nImages :\n";
      foreach ($plan['urls'] as $u) echo "  $u\n"; if (!$plan['urls']) echo "  (aucune)\n";
      return 0;
    }
    echo "Aucun post " . ($argv[2] ?? '') . " dans la file.\n"; return 1;
  }
  return 1;
}
