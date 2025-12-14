<?php
// web/index.php
// Haupt-Dashboard — Tabellenstruktur aktualisiert:
// - Zeigt current_state (aktueller Zustand) an;
// - set_state ändert weiterhin die admin-"state" Spalte.
// - Wenn admin-state !== current_state bekommt der Zustand-Button einen roten Rand.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Während Entwicklung lokal Fehler zeigen (bei Produktion entfernen)
if (php_sapi_name() !== 'cli') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// PDO besorgen
$pdo = db_get_pdo();
if (!$pdo) {
    $cfg = function_exists('load_db_config') ? load_db_config() : [];
    $host = $cfg['host'] ?? '127.0.0.1';
    $port = $cfg['port'] ?? null;
    $name = $cfg['name'] ?? '';
    $user = $cfg['user'] ?? '';
    $pass = $cfg['pass'] ?? '';
    $dsn = $cfg['pdo_dsn'] ?? ($port ? "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4" : "mysql:host={$host};dbname={$name};charset=utf8mb4");

    try {
        $tmp = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo = $tmp;
    } catch (Throwable $e) {
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Datenbankfehler</title></head><body>\n";
        echo "<h1>Verbindung zur Datenbank fehlgeschlagen</h1>\n";
        echo "<p><strong>Fehler:</strong> {$msg}</p>\n";
        echo "</body></html>\n";
        exit(1);
    }
}

// Daten laden
$computers = fetch_computers($pdo);
$customers = fetch_customers($pdo);

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Internet Cafe — Dashboard</title>
  <?php
    // head.php weiterhin einbinden, falls vorhanden
    $headFile = __DIR__ . '/head.php';
    if (is_file($headFile)) {
        include $headFile;
    }
  ?>
  <style>
    body { font-family: sans-serif; margin: 1rem; background:#fafafa; color:#222; }
    .grid { display: grid; grid-template-columns: 1fr 420px; gap: 1rem; align-items: start; }
    section { background: #fff; padding: 0.8rem; border: 1px solid #e6e6e6; border-radius: 4px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #e6e6e6; padding: 0.45rem 0.6rem; vertical-align: top; }
    th { background: #f2f2f2; text-align: left; }
    pre { margin: 0; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; word-break:break-word; }
    .state { font-weight: 700; }
    .empty { color: #666; padding: 1rem 0; }
    .small { font-size: 0.95rem; color:#333; }

    /* PC name button and tooltip */
    .pc-name-cell { position: relative; }
    .pc-btn {
      background: #1976d2;
      color: #fff;
      border: none;
      padding: 0.35rem 0.6rem;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 600;
      font-size: 0.95rem;
    }
    .pc-btn:focus { outline: 2px solid #80bfff; outline-offset: 2px; }
    .desc-tooltip {
      display: none;
      position: absolute;
      left: 0;
      top: calc(100% + 0.4rem);
      width: 28rem;
      max-width: calc(100vw - 3rem);
      background: #fff;
      border: 1px solid #ccc;
      padding: 0.6rem;
      box-shadow: 0 6px 18px rgba(0,0,0,0.08);
      z-index: 60;
      font-size: 0.95rem;
      white-space: pre-wrap;
    }
    .pc-name-cell:hover .desc-tooltip,
    .pc-name-cell:focus-within .desc-tooltip {
      display: block;
    }

    /* State button */
    .state-btn {
      border: none;
      color: #fff;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      cursor: pointer;
      font-weight: 700;
      display: inline-block;
    }
    .state-off { background: #d32f2f; }   /* rot */
    .state-on  { background: #2e7d32; }   /* grün */
    .state-maint { background: #f9a825; color: #000; } /* gelb */

    /* mismatch visual: red outline if admin-state != current_state */
    .state-btn.mismatch {
      box-shadow: 0 0 0 2px rgba(211,47,47,0.9) inset;
      outline: 2px solid rgba(211,47,47,0.9);
    }

    /* Modal */
    .modal-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.4);
      z-index: 2000;
      align-items: center;
      justify-content: center;
    }
    .modal {
      background: #fff;
      padding: 1rem;
      border-radius: 6px;
      width: 360px;
      max-width: calc(100% - 2rem);
      box-shadow: 0 10px 30px rgba(0,0,0,0.2);
      text-align: center;
    }
    .modal h3 { margin-top: 0; }
    .modal .actions { display:flex; gap:0.5rem; justify-content:center; margin-top:0.75rem; }
    .btn { padding: 0.5rem 0.8rem; border-radius: 5px; border: none; cursor: pointer; font-weight:600; }
    .btn.off { background:#d32f2f; color:#fff; }
    .btn.on  { background:#2e7d32; color:#fff; }
    .btn.maint { background:#f9a825; color:#000; }
    .btn.cancel { background:#eee; color:#111; }
    .muted { color:#666; font-size:0.9rem; margin-top:0.5rem; }
  </style>
</head>
<body>
  <h1>Dashboard — Internet Cafe</h1>

  <div class="grid">
    <!-- Haupttabelle: Arbeitsplätze -->
    <section aria-labelledby="computers-heading">
      <h2 id="computers-heading">Arbeitsplätze</h2>

      <?php if (empty($computers)): ?>
        <p class="empty">Keine Arbeitsplätze in der Datenbank gefunden.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Name / Host</th>
              <th style="width:10rem">Zustand</th>
              <th style="width:10rem">Belegt von</th>
              <th style="width:12rem">Letzte Aktivität</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($computers as $idx => $c):
              $name = (string)($c['name'] ?? ($c['hostname'] ?? ''));
              $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              $urlName = rawurlencode($name);
              $desc = $c['raw']['description'] ?? $c['description'] ?? '';
              $descSafe = htmlspecialchars((string)$desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              $lastSeen = $c['raw']['last_seen'] ?? $c['raw']['updated_at'] ?? $c['raw']['last_activity'] ?? '';
              $adminState = $c['state'] ?? null;           // admin-set canonical state
              $currentState = $c['current_state'] ?? null; // actual canonical state

              // determine display state: prefer current_state for display; fallback to adminState or unknown
              $displayState = $currentState ?? $adminState ?? null;
              $displayLabel = $displayState !== null ? ucfirst((string)$displayState) : 'unbekannt';

              // determine color class based on display state (on/off/maint)
              $stateClass = 'state-on';
              if ($displayState === null || $displayState === 'unbekannt') {
                $stateClass = 'state-maint';
              } elseif (mb_strpos((string)$displayState, 'off') !== false) {
                $stateClass = 'state-off';
              } elseif (mb_strpos((string)$displayState, 'wartung') !== false) {
                $stateClass = 'state-maint';
              } else {
                $stateClass = 'state-on';
              }

              // mismatch detection: admin-state differs from current-state (and both are not null)
              $mismatch = ($adminState !== null && $currentState !== null && $adminState !== $currentState);
              $mismatchClass = $mismatch ? ' mismatch' : '';
            ?>
              <tr data-pc-name="<?php echo $safeName; ?>">
                <td class="pc-name-cell">
                  <button class="pc-btn" onclick="location.href='admin/pc_config.php?name=<?php echo $urlName; ?>'"><?php echo $safeName !== '' ? $safeName : '—'; ?></button>
                  <?php if ($desc !== ''): ?>
                    <div class="desc-tooltip"><?php echo $descSafe; ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="state-btn <?php echo $stateClass . $mismatchClass; ?>" type="button" data-pc="<?php echo htmlspecialchars($name, ENT_QUOTES|ENT_SUBSTITUTE); ?>" onclick="openStateModal(this)">
                    <?php echo htmlspecialchars($displayLabel, ENT_QUOTES|ENT_SUBSTITUTE); ?>
                  </button>
                </td>
                <td><?php echo htmlspecialchars((string)($c['occupied_by'] ?? '')); ?></td>
                <td class="small"><?php echo htmlspecialchars((string)$lastSeen); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <!-- Nebenbereich: Kunden -->
    <section aria-labelledby="customers-heading">
      <h2 id="customers-heading">Kunden</h2>

      <?php if (empty($customers)): ?>
        <p class="empty">Keine Kunden in der Datenbank gefunden.</p>
      <?php else: ?>
        <table class="customers-table">
          <thead>
            <tr>
              <th class="name">Name</th>
              <th class="balance" style="text-align:right">Guthaben</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($customers as $u):
              $name = htmlspecialchars((string)($u['name'] ?? ($u['username'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
              $balance = htmlspecialchars((string)($u['balance'] ?? ($u['credit'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
              <tr>
                <td class="name"><?php echo $name; ?></td>
                <td class="balance" style="text-align:right"><?php echo $balance !== '' ? $balance : '—'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </div>

  <!-- Modal markup (hidden) -->
  <div id="stateModalBackdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal" role="document" aria-labelledby="modalTitle">
      <h3 id="modalTitle">Zustand ändern: <span id="modalPcName"></span></h3>
      <p class="muted">Wähle eine Aktion, um den Zustand des Rechners zu ändern.</p>
      <div class="actions">
        <button class="btn off" data-state="off" onclick="submitState('off')">Ausschalten (OFF)</button>
        <button class="btn on" data-state="on" onclick="submitState('on')">Einschalten (ON)</button>
        <button class="btn maint" data-state="wartung" onclick="submitState('wartung')">Wartung</button>
      </div>
      <div style="margin-top:0.8rem;">
        <button class="btn cancel" onclick="closeStateModal()">Abbrechen</button>
      </div>
      <div id="modalMsg" class="muted" style="margin-top:0.6rem"></div>
    </div>
  </div>

<script>
(function(){
  window.currentModalTarget = null;

  window.openStateModal = function(btn) {
    var pcName = btn.getAttribute('data-pc') || btn.closest('tr')?.dataset?.pcName || '';
    window.currentModalTarget = btn;
    document.getElementById('modalPcName').textContent = pcName || '(unbekannt)';
    document.getElementById('modalMsg').textContent = '';
    var bd = document.getElementById('stateModalBackdrop');
    bd.style.display = 'flex';
    bd.setAttribute('aria-hidden', 'false');
  };

  window.closeStateModal = function() {
    var bd = document.getElementById('stateModalBackdrop');
    bd.style.display = 'none';
    bd.setAttribute('aria-hidden', 'true');
    window.currentModalTarget = null;
  };

  window.submitState = function(actionState) {
    if (!window.currentModalTarget) return;
    var pcName = window.currentModalTarget.getAttribute('data-pc') || window.currentModalTarget.closest('tr')?.dataset?.pcName || '';
    if (!pcName) {
      document.getElementById('modalMsg').textContent = 'Kein Rechnername gefunden.';
      return;
    }
    var payload = new URLSearchParams();
    payload.append('name', pcName);
    payload.append('state', actionState);

    document.getElementById('modalMsg').textContent = 'Sende...';

    fetch('admin/set_state.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString(),
      credentials: 'same-origin'
    }).then(function(resp){
      return resp.json();
    }).then(function(data){
      if (data && data.ok) {
        document.getElementById('modalMsg').textContent = 'Erfolgreich aktualisiert.';
        var btn = window.currentModalTarget;
        if (btn) {
          var newLabel = data.new_state_label || actionState;
          btn.textContent = newLabel.charAt(0).toUpperCase() + newLabel.slice(1);
          btn.classList.remove('state-off','state-on','state-maint','mismatch');
          if (actionState === 'off') btn.classList.add('state-off');
          else if (actionState === 'wartung') btn.classList.add('state-maint');
          else btn.classList.add('state-on');
        }
        setTimeout(closeStateModal, 800);
      } else {
        document.getElementById('modalMsg').textContent = data && data.error ? data.error : 'Unbekannter Fehler';
      }
    }).catch(function(err){
      document.getElementById('modalMsg').textContent = 'Fehler: ' + (err.message || err);
    });
  };

  // close modal when clicking backdrop
  document.getElementById('stateModalBackdrop').addEventListener('click', function(e){
    if (e.target === this) closeStateModal();
  });

  // keyboard ESC to close
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeStateModal();
  });

})();
</script>

</body>
</html>
