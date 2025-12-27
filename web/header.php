<?php
// web/header.php — zentraler, CI-konformer Header-Include
// Erwartungen vor dem include:
// - optional: $page_title (string)
// - optional: $no_refresh (bool)  -> wenn true, kein <meta http-equiv="refresh">
// - optional: $page_css (array of href strings)  -> zusätzliche CSS pro Seite
// - optional: $page_js (array of src strings)   -> zusätzliche JS per Seite
// - optional: $brand (string) -> Marken-/Firmennamen
declare(strict_types=1);
//var_dump($no_refresh);
//exit;

// No Refresh absichern
//$no_refresh = isset($no_refresh) ? (bool)$no_refresh : false;

// sichere Session-Initialisierung (falls noch nicht gestartet)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Timezone initialisieren wenn konfiguriert
$tz = null;
if (defined('APP_TIMEZONE')) {
    $tz = APP_TIMEZONE;
} elseif (($env = getenv('APP_TIMEZONE')) !== false && $env !== '') {
    $tz = $env;
}
if ($tz) {
    @date_default_timezone_set($tz);
} else {
    if (ini_get('date.timezone') === '') {
        @date_default_timezone_set('Europe/Berlin');
    }
}

// Defaults
$page_title = isset($page_title) ? (string)$page_title : 'Internetcafé Verwaltung';
$no_refresh = !empty($no_refresh);
$brand = isset($brand) ? (string)$brand : 'Internetcafé Verwaltung';
$page_css = isset($page_css) && is_array($page_css) ? $page_css : [];
$page_js = isset($page_js) && is_array($page_js) ? $page_js : [];

// Standard-Assets (projektweit)
$global_css = [ '/assets/css/style.css' ];
$global_js = [ '/assets/js/dashboard.js' ];

// Helper: esc (nur definieren, wenn nicht bereits vorhanden)
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5); }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo h($page_title); ?></title>
<?php if (!$no_refresh): ?>
  <meta http-equiv="refresh" content="30">
<?php endif; ?>
<?php foreach ($global_css as $css): ?>
  <link rel="stylesheet" href="<?php echo h($css); ?>">
<?php endforeach; ?>
<?php foreach ($page_css as $css): ?>
  <link rel="stylesheet" href="<?php echo h((string)$css); ?>">
<?php endforeach; ?>
<?php foreach ($global_js as $js): ?>
  <script src="<?php echo h($js); ?>" defer></script>
<?php endforeach; ?>
<?php foreach ($page_js as $js): ?>
  <script src="<?php echo h((string)$js); ?>" defer></script>
<?php endforeach; ?>
  <style>
    /* kleine, neutrale Default-Styles falls assets fehlen */
    body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;margin:0;color:#222;background:#f7f7f7}
    .topbar{background:#0b5; padding:10px 16px; color:#fff}
    .topbar .brand{font-weight:700}
    .container { max-width:1100px; margin:0 auto; padding:0 12px; }
    main.container{max-width:1100px;margin:18px auto;padding:0 12px}
    .muted { color:#666; font-size:0.95rem; }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="container" style="display:flex;align-items:center;justify-content:space-between;">
      <div class="brand"><h1><?php echo h($brand); ?></h1></div>
      <div class="muted small">Letzte Aktualisierung: <?php echo h((string)date('Y-m-d H:i:s')); ?></div>
    </div>
    
    <div class="nav-buttons" style="display: flex; gap: 10px; margin: 10px 0;">
      <button onclick="window.location.href='/admin/kunden.php'" style="padding: 10px 15px;">Kunden anlegen</button>
      <button onclick="window.location.href='/admin/bestellung.php'" style="padding: 10px 15px;">Bestellung</button>
      <button onclick="window.location.href='/admin/abrechnung.php'" style="padding: 10px 15px;">Abrechnung</button>
      <button onclick="window.location.href='/admin/stand.php'" style="padding: 10px 15px;">Kostenstand</button>
      <button onclick="window.location.href='/admin/blocked_sites.php'" style="padding: 10px 15px;">Seite sperren</button>
      <button onclick="window.location.href='/admin/computers.php'" style="padding: 10px 15px;">PC einrichten</button>
      <button onclick="window.location.href='/admin/rechnung.php'" style="padding: 10px 15px;">Rechnung</button>
      <button onclick="window.location.href='/index.php'" style="padding: 10px 15px;">Übersicht</button>
    </div>
        
  </header>
  <main class="container">
<?php
// Ende header.php — Seite setzt jetzt ihren Content unterhalb <main>
// Footer must close </main></body></html>
