<?php
// web/index.php
// Statusseite mit Bedien‑Links / Push‑Buttons für Computersteuerung, Kundenübersicht und Rechnungserstellung.
// Spalten '#' entfernt; obere Tabelle zeigt kein "Info"-Feld mehr.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/head.php';

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$dbNotice = '';
$pdo = function_exists('db_get_pdo') ? db_get_pdo() : null;
$computers = [];
$customers = [];

if ($pdo) {
    try {
        if (function_exists('fetch_computers')) {
            $computers = fetch_computers($pdo);
        }
        if (function_exists('fetch_customers')) {
            $customers = fetch_customers($pdo);
        }
        try {
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $dbNotice = 'Connected to DB: ' . ($dbName ?? '');
        } catch (Throwable $e) {
            // ignore
        }
    } catch (Throwable $e) {
        error_log('index.php fetch error: ' . $e->getMessage());
        $dbNotice = 'DB error: ' . $e->getMessage();
    }
}

// Fallback demo data when DB/tables missing or empty
if (empty($computers)) {
    $computers = [
        ['id'=>1,'name'=>'PC-01','is_on'=>true,'occupied_by'=>101],
        ['id'=>2,'name'=>'PC-02','is_on'=>true,'occupied_by'=>null],
        ['id'=>3,'name'=>'PC-03','is_on'=>false,'occupied_by'=>null],
    ];
    $dbNotice = $dbNotice !== '' ? $dbNotice : 'No computers table found — showing demo data';
}
if (empty($customers)) {
    $customers = [
        ['id'=>101,'name'=>'Alice Meier','due'=>12.50],
        ['id'=>102,'name'=>'Bernd Schulz','due'=>5.00],
    ];
    $dbNotice = $dbNotice !== '' ? $dbNotice : 'No unpaid customers found or DB tables missing — showing demo data';
}

$onComputers = array_values(array_filter($computers, function($c){
    return isset($c['is_on']) && $c['is_on'] === true;
}));
?>
<header style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
  <div>
    <h1>Internetcafe — Übersicht</h1>
    <?php if ($dbNotice !== ''): ?>
      <p class="small muted"><?= esc($dbNotice) ?></p>
    <?php endif; ?>
  </div>
  <nav aria-label="Schnellzugriff" style="text-align:right;">
    <a href="/admin/computers.php" style="margin-right:10px;">Computersteuerung</a>
    <a href="/admin/customers.php" style="margin-right:10px;">Kundenübersicht</a>
    <a href="/admin/invoices/new.php" style="background:#0b79d0;color:#fff;padding:6px 10px;border-radius:4px;text-decoration:none;">Neue Rechnung</a>
  </nav>
</header>

<section aria-labelledby="computers-heading">
  <h2 id="computers-heading">Rechner</h2>

  <div id="monitor-msg" style="margin-bottom:8px;"></div>

  <table aria-describedby="computers" id="computers-table">
    <thead>
      <tr><th>Rechner</th><th>Status</th><th>Benutzer</th><th>Aktion</th></tr>
    </thead>
    <tbody>
<?php if (empty($computers)): ?>
      <tr><td colspan="4" class="muted small">Keine Rechner gefunden.</td></tr>
<?php else: foreach ($computers as $c):
    $id = $c['id'] ?? '';
    $name = $c['name'] ?? $c['hostname'] ?? "pc-{$id}";
    $occ = $c['occupied_by'] ?? $c['client_id'] ?? $c['user_id'] ?? null;
    $is_on = $c['is_on'] ?? null;
?>
      <tr data-computer-id="<?= esc((string)$id) ?>">
        <td><?= esc((string)$name) ?></td>
        <td>
          <?php if ($is_on === true): ?>
            <span class="status-on">ON</span>
          <?php elseif ($is_on === false): ?>
            <span class="muted">OFF</span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= $occ ? esc((string)$occ) : '<span class="muted">frei</span>' ?></td>
        <td>
          <div style="display:flex;gap:6px;align-items:center;">
            <?php if ($is_on === true): ?>
              <button class="action-btn" data-action="stop" data-id="<?= esc((string)$id) ?>">Stop</button>
              <button class="action-btn" data-action="restart" data-id="<?= esc((string)$id) ?>">Restart</button>
            <?php else: ?>
              <button class="action-btn" data-action="start" data-id="<?= esc((string)$id) ?>">Start</button>
            <?php endif; ?>
            <a class="small" href="/admin/computers.php#<?= urlencode((string)$id) ?>">Details</a>
          </div>
        </td>
      </tr>
<?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

<section aria-labelledby="customers-heading" style="margin-top:18px;">
  <h2 id="customers-heading">Kunden mit offenen Rechnungen</h2>
  <table aria-describedby="customers">
    <thead><tr><th>Kunde</th><th>Betrag</th><th>Aktion</th></tr></thead>
    <tbody>
<?php if (empty($customers)): ?>
      <tr><td colspan="3" class="muted small">Keine offenen Rechnungen gefunden.</td></tr>
<?php else: foreach ($customers as $cust): ?>
      <tr data-customer-id="<?= esc((string)($cust['id'] ?? '')) ?>">
        <td><?= esc((string)($cust['name'] ?? '—')) ?></td>
        <td><?= esc(number_format((float)($cust['due'] ?? 0), 2, ',', '.')) ?> €</td>
        <td><a href="/admin/customers.php?id=<?= urlencode((string)($cust['id'] ?? '')) ?>">Ansehen</a> &nbsp; <a href="/admin/invoices/new.php?customer=<?= urlencode((string)($cust['id'] ?? '')) ?>">Rechnung</a></td>
      </tr>
<?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

<script>
(function(){
  'use strict';
  const msgEl = document.getElementById('monitor-msg');
  function showMessage(txt, ttl = 4000) {
    if (!msgEl) return;
    msgEl.textContent = txt;
    msgEl.style.padding = '6px';
    msgEl.style.background = '#fff7cc';
    msgEl.style.border = '1px solid #f0e6b8';
    setTimeout(()=>{ msgEl.textContent = ''; msgEl.style.padding=''; msgEl.style.background=''; msgEl.style.border=''; }, ttl);
  }

  async function sendAction(id, action) {
    const endpoint = `/api/computers/${encodeURIComponent(id)}/action`;
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action })
      });
      if (!res.ok) {
        showMessage(`Aktion ${action} für ${id} fehlgeschlagen (HTTP ${res.status}) — öffne Adminseite.`);
        window.location.href = `/admin/computers.php?id=${encodeURIComponent(id)}`;
        return;
      }
      const j = await res.json().catch(()=>({ok:false}));
      if (j && (j.ok || j.success)) {
        showMessage(`Aktion ${action} für ${id} erfolgreich.`);
        setTimeout(()=>location.reload(), 1200);
      } else {
        showMessage(`Aktion ${action} für ${id} ausgeführt (keine weitere Info).`);
        setTimeout(()=>location.reload(), 1200);
      }
    } catch (err) {
      console.warn('action error', err);
      showMessage(`Aktion ${action} für ${id} nicht erreichbar — öffne Adminseite.`);
      window.location.href = `/admin/computers.php?id=${encodeURIComponent(id)}`;
    }
  }

  document.querySelectorAll('.action-btn').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      const id = btn.getAttribute('data-id');
      const action = btn.getAttribute('data-action');
      if (!id || !action) return;
      if (!confirm(`Sollen wir "${action}" auf Rechner ${id} ausführen?`)) return;
      btn.disabled = true;
      sendAction(id, action).finally(()=>btn.disabled = false);
    });
  });
})();
</script>

<?php
echo "</div>\n</body>\n</html>\n";
?>