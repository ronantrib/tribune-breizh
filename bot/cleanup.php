<?php
/*
 * Tribune Breizh · nettoyage des fichiers d'un post une fois qu'il est publié.
 * Chargé par publish.php. Supprime, sur le serveur, les fichiers que la file désigne dans insta/img/ et insta/reels/
 * (champs « images », « video » et « cover ») quand le post n'en a plus besoin :
 *   - Instagram a fini avec lui : statut « publié » dans ~/insta/done.json (un post « abandonné » ou « à vérifier » garde ses fichiers) ;
 *   - si le post a un volet Facebook (et que Facebook est configuré), Facebook a fini aussi : « publié » ou « abandonné » dans fb_done.json ;
 *   - un délai de grâce (CLEAN_DELAY) s'est écoulé depuis la publication ;
 *   - aucun autre post encore à traiter n'utilise le même fichier.
 * Ne touche jamais à insta/queue.json, ni à .gitkeep, ni à rien hors de insta/img/, insta/reels/ et insta/gen/.
 * Les cartes dessinées par le serveur (insta/gen/ID-N-….jpg) sont nettoyées avec le post qui les a produites ; si un autre post pas fini
 * pourrait porter le même préfixe (ids qui se ressemblent), le fichier est gardé.
 *
 * À la main : php publish.php nettoyage   montre ce qui serait supprimé, sans rien supprimer.
 */

const CLEAN_DELAY = 3600;                       // on attend 1 h après la publication avant de supprimer
const CLEAN_DIRS = ['insta/img', 'insta/reels', 'insta/gen'];

/* Fichiers (chemins relatifs au dépôt) qu'un post désigne dans les dossiers à nettoyer. */
function tb_post_files($p) {
  $f = [];
  foreach (array_merge((array)($p['images'] ?? []), [$p['video'] ?? '', $p['cover'] ?? '']) as $x) {
    $x = ltrim((string)$x, '/');
    if ($x === '' || strpos($x, '..') !== false) continue;
    foreach (CLEAN_DIRS as $d) if (strpos($x, "$d/") === 0 && basename($x) !== '.gitkeep') { $f[$x] = true; break; }
  }
  return array_keys($f);
}

/* Cartes dessinées par le serveur pour un post (insta/gen/ID-N-….jpg). */
function tb_gen_files($id, $REPO) {
  $out = []; if ($id === '' || !is_dir("$REPO/insta/gen")) return $out;
  $re = '/^' . preg_quote(preg_replace('/[^a-z0-9-]/', '', $id), '/') . '-\d+-[a-z0-9-]+\.jpg$/';
  foreach (scandir("$REPO/insta/gen") as $f) if (preg_match($re, $f)) $out[] = "insta/gen/$f";
  return $out;
}

/* Le post a-t-il fini (Instagram, et Facebook si besoin) depuis assez longtemps ? */
function tb_post_done($p, $D, $FD, $fbOn, $now) {
  $id = $p['id'] ?? ''; if ($id === '') return false;
  $ig = ($p['type'] ?? '') !== 'facebook';
  $last = 0;
  if ($ig) {
    $d = $D[$id] ?? null;
    if (!$d || ($d['status'] ?? '') !== 'publié') return false;
    $last = max($last, (int)strtotime($d['time'] ?? ''));
  }
  $f = $p['facebook'] ?? null;
  if ($fbOn && is_array($f) && empty($p['cancel']) && empty($f['cancel'])) {
    $s = $FD[$id]['status'] ?? '';
    if (!in_array($s, ['publié', 'abandonné'], true)) return false;
    $last = max($last, (int)strtotime($FD[$id]['time'] ?? ''));
  } elseif (!$ig) return false;                 // post réservé à Facebook alors que Facebook n'est pas actif : on ne touche à rien
  return $last > 0 && $now - $last >= CLEAN_DELAY;
}

/* Renvoie la liste des fichiers à supprimer ; les supprime sauf si $dry. */
function tb_cleanup($Q, $D, $FD, $fbOn, $REPO, $now, $dry = false) {
  $gone = []; $keep = [];
  foreach ($Q['posts'] ?? [] as $p) {
    $files = array_merge(tb_post_files($p), tb_gen_files($p['id'] ?? '', $REPO));
    if (tb_post_done($p, $D, $FD, $fbOn, $now)) foreach ($files as $f) $gone[$f] = true;
    else foreach ($files as $f) $keep[$f] = true;   // encore utile à un post pas fini : on ne le touche pas
  }
  $out = [];
  foreach (array_keys($gone) as $f) {
    if (isset($keep[$f])) continue;
    $path = "$REPO/$f";
    if (!is_file($path) || is_link($path)) continue;
    $real = realpath($path); $ok = false;
    foreach (CLEAN_DIRS as $d) { $base = realpath("$REPO/$d"); if ($base && $real && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) $ok = true; }
    if (!$ok) continue;
    if ($dry || @unlink($path)) $out[] = $f;
  }
  return $out;
}
