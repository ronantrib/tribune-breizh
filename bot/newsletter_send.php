<?php
/*
 * Tribune Breizh · envoi hebdomadaire de la newsletter aux inscrits de cache/abonnes.csv.
 * Prévu pour un cron o2switch, une fois par semaine (ex. lundi 8h) :
 *   php ~/repositories/tribune-breizh/bot/newsletter_send.php
 *
 * Nécessite une clé API Brevo (gratuite, https://www.brevo.com), jamais dans ce dépôt, dans :
 *   ~/newsletter/config.php :
 *     <?php
 *     define('TB_BREVO_KEY', 'xkeysib-…');          // clé API Brevo (Paramètres > Clés API)
 *     define('TB_BREVO_FROM', 'vous@votredomaine.fr'); // adresse expéditrice validée dans Brevo
 * Sans ce fichier, le script s'arrête et le dit : rien n'est envoyé à moitié configuré.
 *
 * Idempotent : au plus un envoi par semaine ISO (cache/newsletter-last.json), sauf avec "force".
 * À la main :
 *   php bot/newsletter_send.php apercu   → affiche le HTML sans rien envoyer ni rien marquer comme fait
 *   php bot/newsletter_send.php force    → envoie même si déjà fait cette semaine
 */
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/newsletter_common.php';
require_once __DIR__ . '/newsletter_digest.php';

$root = dirname(__DIR__);
$arg = (string)($argv[1] ?? '');

$configFile = rtrim((string)(getenv('HOME') ?: ''), '/') . '/newsletter/config.php';
if (is_file($configFile)) require_once $configFile;

$d = tb_seo_data("$root/src/index.html", "$root/cache/seo-data.json");
if (!$d) { fwrite(STDERR, "Données du site illisibles (src/index.html absent ou inattendu), abandon\n"); exit(1); }

$digest = tb_newsletter_digest($d);

if ($arg === 'apercu') { echo str_replace('__UNSUB__', '#', $digest['html']); exit; }

$week = date('o-\WW');
$lastFile = "$root/cache/newsletter-last.json";
$last = is_file($lastFile) ? json_decode((string)file_get_contents($lastFile), true) : null;
if ($arg !== 'force' && is_array($last) && ($last['week'] ?? '') === $week) {
  echo "Déjà envoyée cette semaine ($week) le " . ($last['at'] ?? '?') . ", rien à faire (« force » pour forcer).\n";
  exit;
}

if (!defined('TB_BREVO_KEY') || TB_BREVO_KEY === '') {
  fwrite(STDERR, "TB_BREVO_KEY absente : créer $configFile (voir l'en-tête de ce fichier)\n");
  exit(1);
}
$from = defined('TB_BREVO_FROM') && TB_BREVO_FROM !== '' ? TB_BREVO_FROM : 'newsletter@breizh.tribuneouest.fr';

$subsFile = "$root/cache/abonnes.csv";
if (!is_file($subsFile)) { echo "Aucun fichier d'abonnés, rien à envoyer.\n"; exit; }
$emails = [];
foreach ((file($subsFile, FILE_IGNORE_NEW_LINES) ?: []) as $line) {
  $parts = explode(',', $line, 2);
  $e = strtolower(trim($parts[1] ?? ''));
  if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[$e] = true;
}
$emails = array_keys($emails);
if (!$emails) { echo "Aucun abonné valide, rien à envoyer.\n"; exit; }

function tb_brevo_send(string $to, string $from, string $subject, string $html, string $text): array {
  $payload = json_encode([
    'sender' => ['name' => 'Tribune Breizh', 'email' => $from],
    'to' => [['email' => $to]],
    'subject' => $subject,
    'htmlContent' => $html,
    'textContent' => $text,
  ], JSON_UNESCAPED_UNICODE);
  $ch = curl_init('https://api.brevo.com/v3/smtp/email');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'api-key: ' . TB_BREVO_KEY],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $res, 'err' => $err];
}

$sent = 0; $failed = [];
foreach ($emails as $email) {
  $html = str_replace('__UNSUB__', tb_unsub_url($email), $digest['html']);
  $text = str_replace('__UNSUB__', tb_unsub_url($email), $digest['text']);
  $r = tb_brevo_send($email, $from, $digest['subject'], $html, $text);
  if ($r['ok']) { $sent++; } else { $failed[] = $email; fwrite(STDERR, "Échec pour $email : HTTP {$r['code']} {$r['err']} {$r['body']}\n"); }
  usleep(150000);   // 150 ms entre deux envois : large marge sous la limite Brevo (300/jour en gratuit)
}

@mkdir(dirname($lastFile), 0755, true);
file_put_contents($lastFile, json_encode(['week' => $week, 'sent' => $sent, 'failed' => count($failed), 'at' => date('c')]));
$msg = "Newsletter $week : $sent envoyé(s)" . ($failed ? ', ' . count($failed) . ' échec(s) : ' . implode(', ', $failed) : '') . "\n";
echo $msg;
@file_put_contents("$root/cache/newsletter-log.txt", date('c') . ' ' . $msg, FILE_APPEND | LOCK_EX);
