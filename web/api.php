<?php
// web/api.php - minimal API for internet_cafe
// Usage: GET /web/api.php?q=ping
// Optional API key support: provide X-API-KEY header or ?api_key=... if set in config.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// try to load optional config (do not error if missing)
$expectedApiKey = null;
$apiKeyRequired = false;
$configPath = __DIR__ . '/../config.php';
if (file_exists($configPath)) {
    // config.php may define API_KEY or $config['api_key']
    @include $configPath;
    if (defined('API_KEY')) {
        $expectedApiKey = API_KEY;
        $apiKeyRequired = true;
    } elseif (isset($config) && is_array($config) && isset($config['api_key'])) {
        $expectedApiKey = $config['api_key'];
        $apiKeyRequired = true;
    }
}

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

$q = $_GET['q'] ?? $_POST['q'] ?? null;
if ($q === 'ping') {
    echo json_encode(['status' => 'ok', 'time' => date('c')]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid_request', 'usage' => '?q=ping']);
