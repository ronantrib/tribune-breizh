<?php
/*
 * Tribune Breizh · garde d'espacement : pas de nouveau post sur un réseau moins de 90 minutes après le précédent.
 * Chargé par publish.php (Instagram) et utilisé aussi par facebook.php (Facebook) ; chaque réseau a son propre historique
 * (done.json pour Instagram, fb_done.json pour Facebook). Valable pour TOUS les posts, urgents compris, stories comprises.
 *
 * Un post qui doit attendre n'est pas abandonné et ne compte pas comme un essai : il repart au premier passage du cron
 * (toutes les 10 minutes) où les 90 minutes sont écoulées. S'il attend au point de dépasser la fenêtre de retard (6 h après
 * son heure), il est abandonné comme les autres. Les posts attendent dans l'ordre de leur heure.
 * Un statut « à vérifier » compte comme une publication : on ne sait pas s'il est parti, mieux vaut espacer.
 */

const GARDE = 90 * 60;

/* Heure (timestamp) de la dernière publication d'un réseau d'après son historique, 0 s'il n'y en a pas. */
function tb_last_pub($D) {
  $t = 0;
  foreach ($D as $d) if (in_array($d['status'] ?? '', ['publié', 'à vérifier'], true)) $t = max($t, (int)strtotime($d['time'] ?? ''));
  return $t;
}

/* Secondes à attendre avant de pouvoir publier sur ce réseau (0 = c'est possible tout de suite). */
function tb_garde_wait($D, $now) {
  $last = tb_last_pub($D);
  return ($last && $now - $last < GARDE) ? GARDE - ($now - $last) : 0;
}

/* Posts triés par heure de publication (celle du champ facebook pour Facebook si $fb), sans changer la file elle-même. */
function tb_by_time($posts, $fb = false) {
  usort($posts, function ($a, $b) use ($fb) {
    $ta = strtotime(($fb ? ($a['facebook']['at'] ?? null) : null) ?? ($a['at'] ?? '')) ?: 0;
    $tb = strtotime(($fb ? ($b['facebook']['at'] ?? null) : null) ?? ($b['at'] ?? '')) ?: 0;
    return $ta <=> $tb;
  });
  return $posts;
}

/*
 * Route du Rhum sur Facebook (règle du 4 novembre 2026) : pendant la course, le post « -rhum » du jour (visuel « rhum ») est repris
 * automatiquement sur Facebook, en plus du post Facebook habituel (la garde de 90 minutes les espace). Le message vient de la légende
 * Instagram du post au moment de publier (donc de la légende mise à jour le soir), sans la ligne « lien en bio » ni les hashtags,
 * avec 2 hashtags Facebook. Un post qui a déjà un champ « facebook » (même annulé) n'est pas touché. Appliqué dès le 5 novembre
 * (les posts d'avant ne sont pas rattrapés), jusqu'au 30 novembre 2026. Appelé par publish.php juste après la lecture de la file.
 */
function tb_fb_auto($Q) {
  foreach ($Q['posts'] ?? [] as $k => $p) {
    $id = (string)($p['id'] ?? ''); $at = substr((string)($p['at'] ?? ''), 0, 10);
    if (!preg_match('/-rhum$/', $id) || isset($p['facebook']) || !empty($p['cancel'])) continue;
    if (($p['type'] ?? 'feed') === 'story' || !in_array('rhum', (array)($p['visuels'] ?? []), true)) continue;
    if ($at < '2026-11-05' || $at > '2026-11-30') continue;
    $lines = [];
    foreach (preg_split('/\R/u', trim((string)($p['caption'] ?? ''))) as $l) {
      $l = trim($l);
      if ($l === '' || stripos($l, 'lien en bio') !== false || preg_match('/^(#\S+\s*)+$/u', $l)) continue;
      $lines[] = $l;
    }
    if (!$lines) continue;
    $Q['posts'][$k]['facebook'] = [
      'message' => implode("\n", $lines) . "\n\n#TribuneBreizh #RouteDuRhum2026",
      'link' => 'https://breizh.tribuneouest.fr/evenement/route-du-rhum-2026',
      'image' => 'first',
    ];
  }
  return $Q;
}
