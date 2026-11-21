<?php
/*
 * Tribune Breizh · reçoit les inscriptions à la newsletter (bandeau de bot/newsletter_widget.php).
 * POST uniquement. Stocke dans cache/abonnes.csv (ignoré par Git, non servi : .htaccess 404 /cache/).
 * Pour récupérer la liste : téléchargement de cache/abonnes.csv via cPanel (gestionnaire de fichiers ou FTP).
 * Format d'une ligne : date ISO,email
 */
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
  exit;
}

// Piège à robots : un champ caché rempli = formulaire automatisé. On répond "ok" sans rien enregistrer,
// pour ne pas indiquer au robot que le piège a fonctionné.
if (trim((string)($_POST['site_web'] ?? '')) !== '') {
  echo json_encode(['ok' => true]);
  exit;
}

$email = trim((string)($_POST['email'] ?? ''));
if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Adresse email invalide']);
  exit;
}
$email = strtolower($email);

$dir = __DIR__ . '/cache';
$file = $dir . '/abonnes.csv';
@mkdir($dir, 0755, true);

// Petit frein anti-rafale par IP : au plus 5 inscriptions par IP par heure.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$rateFile = $dir . '/abonnes-rate.json';
$fp = @fopen($rateFile, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
  $raw = stream_get_contents($fp);
  $rate = json_decode((string)$raw, true);
  if (!is_array($rate)) $rate = [];
  $now = time();
  foreach ($rate as $k => $times) { $rate[$k] = array_values(array_filter((array)$times, fn($t) => $now - (int)$t < 3600)); if (!$rate[$k]) unset($rate[$k]); }
  $hits = $rate[$ip] ?? [];
  if (count($hits) >= 5) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Trop de tentatives, réessayez plus tard']);
    exit;
  }
  $hits[] = $now;
  $rate[$ip] = $hits;
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($rate));
  flock($fp, LOCK_UN);
  fclose($fp);
}

// Déduplication : on ne réécrit rien si l'adresse est déjà inscrite (réponse "ok" quand même).
if (is_file($file)) {
  $existing = @file($file, FILE_IGNORE_NEW_LINES);
  if (is_array($existing)) {
    foreach ($existing as $line) {
      $parts = explode(',', $line, 2);
      if (isset($parts[1]) && strtolower(trim($parts[1])) === $email) {
        echo json_encode(['ok' => true]);
        exit;
      }
    }
  }
}

$line = date('c') . ',' . $email . "\n";
if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Erreur serveur, réessayez']);
  exit;
}

echo json_encode(['ok' => true]);
