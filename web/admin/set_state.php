<?php
// web/admin/set_state.php
// Endpoint to change PC state (admin 'state' or measured 'current_state').
// - Accepts POST: name (hostname) and either 'state' (admin request: off|on|wartung) or 'current_state' (client reports actual state).
// - Tries to update the most appropriate column in the first matching table.
// - Returns JSON { ok: true, new_state_label: "...", updated_column: "..." } or an error payload.
//
// WARNING: This endpoint has no authentication. Protect it before exposing it publicly.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// prevent stray output from breaking JSON
ob_start();

// make warnings/fatal -> handled as JSON
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() === 0) return false; // respect @
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    http_response_code(500);
    @ob_end_clean();
    echo json_encode([
        'ok' => false,
        'error' => 'Unhandled exception: ' . $e->getMessage(),
        'type' => get_class($e),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null) {
        http_response_code(500);
        @ob_end_clean();
        echo json_encode([
            'ok' => false,
            'error' => 'Fatal error: ' . ($err['message'] ?? 'unknown'),
            'file' => $err['file'] ?? null,
            'line' => $err['line'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    require_once __DIR__ . '/../db.php';

    $name = trim((string)($_POST['name'] ?? ''));
    $incoming_state = isset($_POST['state']) ? trim((string)$_POST['state']) : null;
    $incoming_current = isset($_POST['current_state']) ? trim((string)$_POST['current_state']) : null;

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing name']);
        exit;
    }
    if ($incoming_state === null && $incoming_current === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No state or current_state supplied']);
        exit;
    }

    // Normalize / validate incoming_state (admin command)
    $admin_map = ['off' => 'off', 'on' => 'frei', 'wartung' => 'wartung'];

    $wantAdminWrite = false;
    $wantCurrentWrite = false;
    $storeVal = null;

    if ($incoming_state !== null) {
        $s = strtolower($incoming_state);
        if (!array_key_exists($s, $admin_map)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid admin state value']);
            exit;
        }
        $wantAdminWrite = true;
        $storeVal = $admin_map[$s];
        $outLabel = $s;
    }

    if ($incoming_current !== null) {
        // use normalize_state() from db.php to canonicalize; if not available, accept raw
        if (function_exists('normalize_state')) {
            $norm = normalize_state($incoming_current);
            if ($norm === null) {
                // allow storing the raw incoming_current as fallback, but prefer normalized
                $norm = $incoming_current;
            }
        } else {
            $norm = $incoming_current;
        }
        $wantCurrentWrite = true;
        $currentToStore = $norm;
        $outLabel = $incoming_current;
    }

    $pdo = db_get_pdo();
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database connection not available']);
        exit;
    }

    // Candidate tables to inspect and update
    $candidates = ['computers', 'workstations', 'terminals', 'machines'];
    $found = false;
    $updated = false;
    $lastError = null;
    $updatedColumn = null;
    $updatedTable = null;

    foreach ($candidates as $table) {
        // check that table exists in current DB
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() === 0) continue;

        // try to find row by common identifying columns
        $colsToTry = ['name', 'hostname', 'label', 'id'];
        $row = null;
        $foundCol = null;
        foreach ($colsToTry as $col) {
            if ($col === 'id' && !is_numeric($name)) continue;
            $sql = "SELECT * FROM `" . str_replace('`', '', $table) . "` WHERE `$col` = ? LIMIT 1";
            $q = $pdo->prepare($sql);
            $q->execute([$name]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && $row !== null) {
                $found = true;
                $foundCol = $col;
                break;
            }
        }

        if (!$found || !$row) {
            continue;
        }

        // determine available columns (lowercased)
        $cols = array_map('strtolower', array_keys($row));

        // prioritize updating according to requested write type
        if ($wantCurrentWrite) {
            if (in_array('current_state', $cols, true)) {
                $targetCol = 'current_state';
            } elseif (in_array('state', $cols, true)) {
                // fallback: if table lacks current_state, update state column as fallback
                $targetCol = 'state';
            } elseif (in_array('status', $cols, true)) {
                $targetCol = 'status';
            } else {
                $lastError = 'No suitable column (current_state/state/status) found in table ' . $table;
                // do not give up yet — continue to other tables
                continue;
            }
            // perform update
            $updateSql = "UPDATE `" . str_replace('`', '', $table) . "` SET `$targetCol` = ? WHERE `$foundCol` = ? LIMIT 1";
            $u = $pdo->prepare($updateSql);
            $ok = $u->execute([$currentToStore, $name]);
            if ($ok) {
                $updated = true;
                $updatedColumn = $targetCol;
                $updatedTable = $table;
                break;
            } else {
                $lastError = 'Update failed for ' . $table . ' (current_state)';
                continue;
            }
        }

        if ($wantAdminWrite) {
            // for admin writes prefer 'state' then 'status' then 'current_state' as fallback
            if (in_array('state', $cols, true)) {
                $targetCol = 'state';
            } elseif (in_array('status', $cols, true)) {
                $targetCol = 'status';
            } elseif (in_array('current_state', $cols, true)) {
                $targetCol = 'current_state';
            } else {
                $lastError = 'No suitable admin state column found in table ' . $table;
                continue;
            }

            $updateSql = "UPDATE `" . str_replace('`', '', $table) . "` SET `$targetCol` = ? WHERE `$foundCol` = ? LIMIT 1";
            $u = $pdo->prepare($updateSql);
            $ok = $u->execute([$storeVal, $name]);
            if ($ok) {
                $updated = true;
                $updatedColumn = $targetCol;
                $updatedTable = $table;
                break;
            } else {
                $lastError = 'Update failed for ' . $table . ' (admin state)';
                continue;
            }
        }
    }

    if ($updated) {
        echo json_encode([
            'ok' => true,
            'new_state_label' => $outLabel ?? null,
            'updated_column' => $updatedColumn,
            'updated_table' => $updatedTable,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'PC not found']);
        exit;
    }

    // found but not updated
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $lastError ?: 'Unknown error']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    @ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'Exception: ' . $e->getMessage(), 'type' => get_class($e)], JSON_UNESCAPED_UNICODE);
    exit;
}
