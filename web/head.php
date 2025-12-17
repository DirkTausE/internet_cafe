<?php
// web/head.php — Kompatibilitäts-Wrapper: setzt typische Variablen und inkludiert header.php
// Bestehende Seiten, die noch `head.php` include'en, funktionieren weiter.
// Wenn du head.php irgendwann entfernen willst, können wir Seiten umstellen auf header.php direkt.
declare(strict_types=1);

// Setze Standardwerte wie alte head.php vermutlich erwartet
if (!isset($page_title)) $page_title = 'Internetcafe — Übersicht';
if (!isset($no_refresh)) $no_refresh = false;

// Weiterleiten an den neuen canonical header
$headerFile = __DIR__ . '/header.php';
if (file_exists($headerFile)) {
    require_once $headerFile;
    return;
}

// Fallback minimal (falls header.php fehlt)
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5); ?></title>
<?php if (!$no_refresh): ?>
  <meta http-equiv="refresh" content="30">
<?php endif; ?>
  <style>body{font-family:system-ui;margin:16px}</style>
</head>
<body>
  <div class="container">
    <header>
      <h1><?php echo htmlspecialchars((string)$page_title, ENT_QUOTES | ENT_HTML5); ?></h1>
      <p class="muted">Letzte Aktualisierung: <?php echo htmlspecialchars((string)date('Y-m-d H:i:s'), ENT_QUOTES | ENT_HTML5); ?> — Auto-Refresh jede 30s.</p>
    </header>
<?php
// Ende wrapper
