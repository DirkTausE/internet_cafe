<?php
// web/index.php
// Statusseite mit Popup zum Setzen eines der 7 Zustände pro Computer:
// canonical states: starting, frei, gast, pause, wartung, stop, off

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/head.php';

function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$state_labels = [
  'starting' => 'starting',
  'frei' => 'frei',
  'gast' => 'Gast',
  'pause' => 'Pause',
  'wartung' => 'Wartung',
  'stop' => 'STOP',
  'off' => 'OFF',
];

$dbNotice = '';
$pdo = function_exists('db_get_pdo') ? db_get_pdo() : null;
$computers = [];
$customers = [];

if ($pdo) {
    try {
        if (function_exists('fetch_computers')) $computers = fetch_computers($pdo);
        if (function_exists('fetch_customers')) $customers = fetch_customers($pdo);
        try {
            $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $dbNotice = 'Connected to DB: ' . ($dbName ?? '');
        } catch (Throwable $e) {}
    } catch (Throwable $e) {
        error_log('index.php fetch error: ' . $e->getMessage());
        $dbNotice = 'DB error: ' . $e->getMessage();
    }
}

if (empty($computers)) {
    $computers = [
        ['id'=>1,'name'=>'PC-01','state'=>'frei','occupied_by'=>101],
        ['id'=>2,'name'=>'PC-02','state'=>'gast','occupied_by'=>null],
        ['id'=>3,'name'=>'PC-03','state'=>'off','occupied_by'=>null],
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
    $state = $c['state'] ?? null;
    $label = $state !== null && isset($state_labels[$state]) ? $state_labels[$state] : ($state ?? '—');
?>
      <tr data-computer-id="<?= esc((string)$id) ?>">
        <td>
          <a href="#" class="pc-name-link" data-id="<?= esc((string)$id) ?>" data-name="<?= esc((string)$name) ?>" data-state="<?= esc((string)($state ?? '')) ?>">
            <?= esc((string)$name) ?>
          </a>
        </td>
        <td><?= esc($label) ?></td>
        <td><?= $occ ? esc((string)$occ) : '<span class="muted">frei</span>' ?></td>
        <td>
          <button class="open-modal-btn" data-id="<?= esc((string)$id) ?>" data-name="<?= esc((string)$name) ?>" data-state="<?= esc((string)($state ?? '')) ?>">Status ändern</button>
          <a class="small" href="/admin/computers.php#<?= urlencode((string)$id) ?>">Details</a>
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

<!-- Modal for editing computer state -->
<div id="pc-modal" role="dialog" aria-modal="true" aria-hidden="true" style="display:none;position:fixed;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.4);align-items:center;justify-content:center;">
  <div id="pc-modal-box" style="background:#fff;padding:16px;border-radius:6px;max-width:420px;width:90%;box-shadow:0 6px 24px rgba(0,0,0,0.2);">
    <h3 id="pc-modal-title">Rechner</h3>
    <p id="pc-modal-sub" class="small muted"></p>
    <form id="pc-modal-form">
      <input type="hidden" name="id" id="pc-modal-id" value="">
      <div style="margin-bottom:8px;">
        <label for="pc-state-select">Zustand:</label>
        <select id="pc-state-select" name="state" style="margin-left:8px;">
          <option value="starting">starting</option>
          <option value="frei">frei</option>
          <option value="gast">Gast</option>
          <option value="pause">Pause</option>
          <option value="wartung">Wartung</option>
          <option value="stop">STOP</option>
          <option value="off">OFF</option>
        </select>
      </div>
      <div style="margin-bottom:8px;">
        <label for="pc-occupied">Belegungs-ID (optional):</label>
        <input id="pc-occupied" name="occupied" type="text" placeholder="z.B. 101" style="margin-left:8px;">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" id="pc-modal-cancel">Abbrechen</button>
        <button type="submit" id="pc-modal-submit" style="background:#0b79d0;color:#fff;padding:6px 10px;border-radius:4px;border:0;">Ausführen</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  'use strict';
  const msgEl = document.getElementById('monitor-msg');
  const modal = document.getElementById('pc-modal');
  const modalBox = document.getElementById('pc-modal-box');
  const modalTitle = document.getElementById('pc-modal-title');
  const modalSub = document.getElementById('pc-modal-sub');
  const modalForm = document.getElementById('pc-modal-form');
  const modalId = document.getElementById('pc-modal-id');
  const modalState = document.getElementById('pc-state-select');
  const modalOccupied = document.getElementById('pc-occupied');
  const modalCancel = document.getElementById('pc-modal-cancel');

  function showMessage(txt, ttl = 4000) {
    if (!msgEl) return;
    msgEl.textContent = txt;
    msgEl.style.padding = '6px';
    msgEl.style.background = '#fff7cc';
    msgEl.style.border = '1px solid #f0e6b8';
    setTimeout(()=>{ msgEl.textContent = ''; msgEl.style.padding=''; msgEl.style.background=''; msgEl.style.border=''; }, ttl);
  }

  async function sendSetState(id, state, extra = {}) {
    const endpoint = `/api/computers/${encodeURIComponent(id)}/action`;
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action: 'set_state', state }, extra))
      });
      if (!res.ok) {
        showMessage(`Set state ${state} für ${id} fehlgeschlagen (HTTP ${res.status}) — öffne Adminseite.`);
        window.location.href = `/admin/computers.php?id=${encodeURIComponent(id)}`;
        return { ok:false, status: res.status, body: null };
      }
      const j = await res.json().catch(()=>null);
      return { ok: true, status: res.status, body: j };
    } catch (err) {
      console.warn('set state error', err);
      showMessage(`Set state ${state} für ${id} nicht erreichbar — öffne Adminseite.`);
      window.location.href = `/admin/computers.php?id=${encodeURIComponent(id)}`;
      return { ok:false, error: err };
    }
  }

  // open modal when clicking name or button
  function openModal(id, name, state) {
    modalId.value = id;
    modalTitle.textContent = `Aktion für ${name}`;
    modalSub.textContent = `Aktueller Status: ${state || '—'}`;
    modalState.value = state || 'starting';
    modalOccupied.value = '';
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden','false');
    modalState.focus();
    document.addEventListener('focus', focusTrap, true);
  }

  document.querySelectorAll('.pc-name-link, .open-modal-btn').forEach(el=>{
    el.addEventListener('click', (e)=>{
      e.preventDefault();
      const id = el.getAttribute('data-id');
      const name = el.getAttribute('data-name') || id;
      const state = el.getAttribute('data-state') || '';
      openModal(id, name, state);
    });
  });

  function closeModal() {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden','true');
    document.removeEventListener('focus', focusTrap, true);
  }

  function focusTrap(ev) {
    if (!modal.contains(ev.target)) {
      ev.stopPropagation();
      modalBox.focus();
    }
  }

  modalCancel.addEventListener('click', (e)=>{
    e.preventDefault();
    closeModal();
  });

  modalForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const id = modalId.value;
    const state = modalState.value;
    const occupied = modalOccupied.value.trim();
    const extra = {};
    if (occupied !== '') extra.occupied = occupied;
    document.getElementById('pc-modal-submit').disabled = true;
    const res = await sendSetState(id, state, extra);
    if (res && res.ok) {
      showMessage(`Status ${state} für ${id} ausgeführt.`);
      setTimeout(()=>location.reload(), 1000);
    } else {
      showMessage(`Aktion fehlgeschlagen.`);
      setTimeout(()=>closeModal(), 1200);
    }
    document.getElementById('pc-modal-submit').disabled = false;
  });

  modal.addEventListener('click', (e)=>{
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
  });

})();
</script>

<?php
echo "</div>\n</body>\n</html>\n";
?>