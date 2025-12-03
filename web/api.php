<?php
// web/api.php - minimal API for internet_cafe
// Endpoints: ?q=ping, ?q=status, ?q=version
// Also supports action=... for client.d requests (POST or GET)
// API key support: X-API-KEY header or ?api_key=... if configured in config.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Load optional config (do not raise errors if not present)
$expectedApiKey = null;
$apiKeyRequired = false;
$appVersion = '0.1.0';
$appName = 'internetcafe';
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    @include $configPath;
    if (defined('API_KEY')) {
        $expectedApiKey = API_KEY;
        $apiKeyRequired = true;
    } elseif (isset($config) && is_array($config) && isset($config['api_key'])) {
        $expectedApiKey = $config['api_key'];
        $apiKeyRequired = true;
    }
    if (defined('APP_VERSION')) {
        $appVersion = APP_VERSION;
    } elseif (isset($config['version'])) {
        $appVersion = (string)$config['version'];
    }
    if (defined('APP_NAME')) {
        $appName = APP_NAME;
    } elseif (isset($config['name'])) {
        $appName = (string)$config['name'];
    }
}

// Helper: read git short sha if available (optional)
$gitSha = null;
$gitHeadFile = __DIR__ . '/../.git/HEAD';
if (is_readable($gitHeadFile)) {
    $head = trim(@file_get_contents($gitHeadFile));
    if (preg_match('/^ref: (.+)$/', $head, $m)) {
        $ref = __DIR__ . '/../.git/' . $m[1];
        if (is_readable($ref)) {
            $gitSha = substr(trim(@file_get_contents($ref)), 0, 12);
        }
    }
}

// API key check
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
if ($apiKeyRequired && !$providedKey) {
    http_response_code(401);
    echo json_encode(['error' => 'api_key_required']);
    exit;
}
if ($apiKeyRequired && $expectedApiKey !== null && $providedKey !== $expectedApiKey) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_api_key']);
    exit;
}

// Read parameters (GET/POST), also accept JSON body for POST
$q = $_GET['q'] ?? $_POST['q'] ?? null;
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$inputJson = null;
if (empty($q) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $inputJson = $decoded;
            if (isset($decoded['q'])) {
                $q = $decoded['q'];
            }
            if (isset($decoded['action'])) {
                $action = $decoded['action'];
            }
        }
    }
}

// Simple Action handler for client.d requests
class ActionHandler
{
    // Handle action and return an array to be json-encoded
    public function handle(string $action, $params = null): array
    {
        // Add your action implementations here. Example placeholder actions:
        switch ($action) {
            case 'echo':
                // returns whatever was sent
                return ['ok' => true, 'action' => 'echo', 'data' => $params];
            case 'status_check':
                // lightweight status check for clients
                return ['ok' => true, 'action' => 'status_check', 'time' => date('c')];
            // Add actual client.d actions below, e.g. 'start_session', 'stop_session', ...
            default:
                return ['ok' => false, 'error' => 'unknown_action', 'action' => $action];
        }
    }
}

// Handler for q endpoints
if ($q === 'ping') {
    echo json_encode(['status' => 'ok', 'time' => date('c')]);
    exit;
}

if ($q === 'status') {
    // Try to get system uptime (Linux) as an extra field, optional
    $uptime = null;
    if (is_readable('/proc/uptime')) {
        $parts = preg_split('/\s+/', trim(@file_get_contents('/proc/uptime')));
        if (isset($parts[0])) {
            $uptime = (float)$parts[0];
        }
    }
    $payload = [
        'status' => 'ok',
        'time' => date('c'),
        'name' => $appName,
        'version' => $appVersion,
        'git' => $gitSha,
        'uptime_seconds' => $uptime,
    ];
    echo json_encode($payload);
    exit;
}

if ($q === 'version') {
    $payload = [
        'name' => $appName,
        'version' => $appVersion,
        'git' => $gitSha,
        'time' => date('c'),
    ];
    echo json_encode($payload);
    exit;
}

// Handle action=... (client.d)
if (!empty($action)) {
    $handler = new ActionHandler();
    // Prefer JSON body params if available, else GET/POST params
    $params = $inputJson['params'] ?? $_POST['params'] ?? $_GET['params'] ?? null;
    // If params is JSON string, attempt decode
    if (is_string($params)) {
        $decoded = json_decode($params, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $params = $decoded;
        }
    }
    $result = $handler->handle((string)$action, $params);
    echo json_encode($result);
    exit;
}

// Default: invalid request
http_response_code(400);
echo json_encode(['error' => 'invalid_request', 'usage' => '?q=ping|status|version or action=...']);