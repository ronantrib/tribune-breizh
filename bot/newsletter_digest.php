<?php
/*
 * Tribune Breizh · construit le contenu de la newsletter hebdomadaire.
 * Ne réécrit rien : réutilise le récit IA déjà rédigé pour chaque page /sport/NOM (premier paragraphe),
 * et celui de la Route du Rhum si l'épreuve est en cours. Rien d'autre à maintenir à la main.
 * Le lien de désabonnement est laissé en marqueur __UNSUB__, remplacé par bot/newsletter_send.php
 * (un jeton différent par destinataire : impossible à fabriquer à l'avance).
 */

function tb_newsletter_digest(array $d): array {
  require_once __DIR__ . '/seo.php';
  $sportsMeta = [];
  foreach (tb_seo_sports($d) as $s) $sportsMeta[$s['key']] = $s;

  $sections = [];
  foreach (($d['data'] ?? []) as $key => $s) {
    $recit = $s['recit'] ?? null;
    if (!$recit || empty($recit['t']) || empty($recit['p'][0])) continue;
    $sections[] = [
      'titre' => (string)($s['title'] ?? $recit['t']),
      'texte' => $recit['t'] . '. ' . $recit['p'][0],
      'url'   => $sportsMeta[$key]['url'] ?? null,
    ];
  }
  $rhum = $d['rhum'] ?? null;
  if ($rhum && !empty($rhum['recit']['t']) && !empty($rhum['recit']['p'][0])) {
    $sections[] = [
      'titre' => 'Route du Rhum',
      'texte' => $rhum['recit']['t'] . '. ' . $rhum['recit']['p'][0],
      'url'   => $rhum['url'] ?? null,
    ];
  }

  $base = 'https://breizh.tribuneouest.fr';
  $dateLabel = tb_newsletter_fr_date();
  $subject = 'Tribune Breizh · le résumé de la semaine (' . date('d/m') . ')';

  $htmlSections = '';
  $textSections = '';
  foreach ($sections as $sec) {
    $url = $sec['url'] ? $base . $sec['url'] : null;
    $htmlSections .= '<tr><td style="padding:18px 0;border-top:1px solid #e4e4e4">'
      . '<h2 style="margin:0 0 8px;font:700 18px/1.3 -apple-system,BlinkMacSystemFont,sans-serif;color:#121417">' . tb_h($sec['titre']) . '</h2>'
      . '<p style="margin:0 0 10px;font:15px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;color:#333">' . tb_h($sec['texte']) . '</p>'
      . ($url ? '<a href="' . tb_h($url) . '" style="font:700 14px -apple-system,BlinkMacSystemFont,sans-serif;color:#121417;text-decoration:underline">Lire la suite et voir le classement →</a>' : '')
      . '</td></tr>';
    $textSections .= "\n" . mb_strtoupper($sec['titre']) . "\n" . $sec['texte'] . ($url ? "\n$url\n" : '') . "\n";
  }
  if ($sections === []) {
    $htmlSections = '<tr><td style="padding:18px 0"><p style="font:15px -apple-system,BlinkMacSystemFont,sans-serif;color:#333">Pas de résumé cette semaine, mais tout le reste (résultats, classements, agenda) reste à jour sur le site.</p></td></tr>';
    $textSections = "\nPas de résumé cette semaine, mais tout le reste (résultats, classements, agenda) reste à jour sur le site.\n";
  }

  $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
    . '<body style="margin:0;padding:0;background:#f4f4f4">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4"><tr><td align="center" style="padding:24px 12px">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff">'
    . '<tr><td style="background:#121417;padding:20px 24px"><a href="' . $base . '/" style="font:800 20px -apple-system,BlinkMacSystemFont,sans-serif;color:#fff;text-decoration:none">Tribune <em style="font-style:normal;opacity:.7">Breizh</em></a>'
    . '<div style="font:13px -apple-system,BlinkMacSystemFont,sans-serif;color:#ccc;margin-top:4px">Le résumé de la semaine · ' . tb_h($dateLabel) . '</div></td></tr>'
    . '<tr><td style="padding:4px 24px">' . $htmlSections . '</td></tr>'
    . '<tr><td style="padding:20px 24px;text-align:center"><a href="' . $base . '/" style="display:inline-block;padding:10px 20px;background:#121417;color:#fff;text-decoration:none;font:700 14px -apple-system,BlinkMacSystemFont,sans-serif;border-radius:4px">Voir tous les résultats →</a></td></tr>'
    . '<tr><td style="padding:16px 24px;border-top:1px solid #e4e4e4;text-align:center;font:12px -apple-system,BlinkMacSystemFont,sans-serif;color:#999">'
    . 'Tribune Breizh · le sport breton, club par club<br><a href="__UNSUB__" style="color:#999">Se désabonner</a></td></tr>'
    . '</table></td></tr></table></body></html>';

  $text = "TRIBUNE BREIZH — le résumé de la semaine ($dateLabel)\n" . $textSections
    . "\nTous les résultats : $base/\n\nSe désabonner : __UNSUB__\n";

  return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function tb_newsletter_fr_date(): string {
  $jours = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
  $mois = [1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];
  return $jours[(int)date('N')] . ' ' . (int)date('j') . ' ' . $mois[(int)date('n')];
}
