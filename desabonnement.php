<?php
/*
 * Tribune Breizh · désabonnement de la newsletter (lien envoyé dans chaque email, bot/newsletter_digest.php).
 * Retire l'adresse de cache/abonnes.csv si le jeton correspond (voir bot/newsletter_common.php).
 */
date_default_timezone_set('Europe/Paris');
require_once __DIR__ . '/bot/newsletter_common.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$email = strtolower(trim((string)($_GET['email'] ?? '')));
$token = (string)($_GET['token'] ?? '');
$ok = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && tb_unsub_verify($email, $token);

$msg = 'Lien de désabonnement invalide ou expiré. Répondez à un email plus récent de la newsletter, ou écrivez-nous.';
if ($ok) {
  $file = __DIR__ . '/cache/abonnes.csv';
  if (is_file($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $kept = array_values(array_filter($lines, function ($l) use ($email) {
      $parts = explode(',', $l, 2);
      return !(isset($parts[1]) && strtolower(trim($parts[1])) === $email);
    }));
    if (count($kept) !== count($lines)) {
      $tmp = $file . '.' . getmypid() . '.tmp';
      $out = $kept ? implode("\n", $kept) . "\n" : '';
      if (@file_put_contents($tmp, $out) !== false) @rename($tmp, $file);
    }
  }
  $msg = 'Vous êtes désinscrit : vous ne recevrez plus la newsletter de Tribune Breizh.';
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tribune Breizh · désabonnement</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#121417;color:#fff;font:16px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;padding:24px;box-sizing:border-box}
.card{max-width:420px;text-align:center}
a{color:#fff;font-weight:700}
</style>
</head>
<body>
<div class="card">
<p><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
<p><a href="/">← Retour à Tribune Breizh</a></p>
</div>
</body>
</html>
