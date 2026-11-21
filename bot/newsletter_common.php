<?php
/*
 * Tribune Breizh · outils communs à l'inscription, l'envoi et le désabonnement de la newsletter.
 * Le secret de signature des liens de désabonnement est généré une fois et gardé dans cache/
 * (ignoré par Git, jamais régénéré ensuite tant que le fichier existe).
 */

function tb_newsletter_secret(): string {
  $dir = dirname(__DIR__) . '/cache';
  $file = $dir . '/newsletter-secret.txt';
  if (is_file($file)) {
    $s = trim((string)file_get_contents($file));
    if ($s !== '') return $s;
  }
  @mkdir($dir, 0755, true);
  $s = bin2hex(random_bytes(32));
  $tmp = $file . '.' . getmypid() . '.tmp';
  if (@file_put_contents($tmp, $s) !== false) @rename($tmp, $file);
  return $s;
}

/** Jeton de désabonnement pour une adresse : permet à n'importe qui de se désinscrire sans compte ni mot de passe. */
function tb_unsub_token(string $email): string {
  return substr(hash_hmac('sha256', strtolower(trim($email)), tb_newsletter_secret()), 0, 32);
}

function tb_unsub_verify(string $email, string $token): bool {
  return hash_equals(tb_unsub_token($email), (string)$token);
}

function tb_unsub_url(string $email): string {
  return 'https://breizh.tribuneouest.fr/desabonnement.php?email=' . rawurlencode($email) . '&token=' . tb_unsub_token($email);
}
