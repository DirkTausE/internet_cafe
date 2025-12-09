<?php
// web/head.php
// Shared head + header fragment for the web frontend.
// Ensure correct timezone for displayed "Letzte Aktualisierung" timestamp.

// Set timezone from config or environment if provided, otherwise default to Europe/Berlin.
// You can override by defining APP_TIMEZONE in ../config.php or exporting APP_TIMEZONE env var.
$tz = null;
if (defined('APP_TIMEZONE')) {
    $tz = APP_TIMEZONE;
} elseif (($env = getenv('APP_TIMEZONE')) !== false && $env !== '') {
    $tz = $env;
}

// Fallback to Europe/Berlin if nothing provided and if PHP has no default set to local CET/CEST.
if ($tz) {
    @date_default_timezone_set($tz);
} else {
    // If PHP already has a default timezone set, leave it; otherwise set sensible default.
    if (ini_get('date.timezone') === '') {
        date_default_timezone_set('Europe/Berlin');
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Internetcafe — Übersicht</title>
  <meta http-equiv="refresh" content="30">
  <style>
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; margin: 16px; color:#222; background:#f7f7f7;}
    .container { max-width:1100px; margin:0 auto; }
    table { width:100%; border-collapse:collapse; background:#fff; margin-bottom:12px; }
    th,td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; font-size:14px; }
    th { background:#fafafa; font-weight:600; }
    .muted { color:#777; font-size:13px; }
    .small { font-size:12px; color:#666; }
    .status-on { color:#0a0; font-weight:600; }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <h1>Internetcafe — Übersicht</h1>
      <p class="muted">Letzte Aktualisierung: <?php echo htmlspecialchars((string)date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?> — Auto-Refresh jede 30s.</p>
    </header>
