<?php
declare(strict_types=1);
/**
 * web/config.php
 *
 * Application configuration loader.
 *
 * Behavior:
 *  - Reads configuration from environment variables first (recommended for secrets).
 *  - If web/config.local.php exists, it is included and may override values (use for local config,
 *    do NOT commit config.local.php).
 *  - Falls back to safe defaults.
 *
 * Usage:
 *  $cfg = require __DIR__ . '../config.php';
 *  echo $cfg['BASE_URL'];
 *
 * Security:
 *  - Do NOT commit secrets into this file or into the repo.
 *  - Prefer environment variables or an /etc/internetcafe-web.conf outside the repo.
 */

/* Restrict direct HTTP access: allow only localhost requests (127.0.0.1 / ::1).
   This permits including the file from your app (normal flow) while preventing
   arbitrary remote clients from requesting the file directly.
*/
if (php_sapi_name() !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    // Permitted loopback addresses
    $allowedLocal = ['127.0.0.1', '::1'];

    if (!in_array($remoteAddr, $allowedLocal, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/* Helper: read env with default and trim. */
$env = static function (string $name, $default = null) {
    $v = getenv($name);
    if ($v === false) {
        return $default;
    }
    return is_string($v) ? trim($v) : $v;
};

/* Defaults (safe, non-secret) */
$defaults = [
    /* App */
    'DEBUG' => filter_var($env('DEBUG', '0'), FILTER_VALIDATE_BOOLEAN),
    'BASE_URL' => $env('BASE_URL', 'http://localhost'),

    /* Timezone (optional) */
    'TIMEZONE' => $env('TIMEZONE', 'UTC'),

    /* Database (use env or supply via config.local.php) */
    'DB_HOST' => $env('DB_HOST', '127.0.0.1'),
    'DB_PORT' => $env('DB_PORT', '3306'),
    'DB_NAME' => $env('DB_NAME', 'internetcafe'),
    'DB_USER' => $env('DB_USER', 'internetcafe'),
    'DB_PASS' => $env('DB_PASS', ''),

    /* API / Auth */
    'API_KEY' => $env('API_KEY', ''),

    /* Allowed hosts (comma separated). Empty = allow all (not recommended in production). */
    'ALLOWED_HOSTS' => array_filter(array_map('trim', explode(',', $env('ALLOWED_HOSTS', '')))),

    /* Other optional settings */
    'SESSION_COOKIE_SECURE' => filter_var($env('SESSION_COOKIE_SECURE', '0'), FILTER_VALIDATE_BOOLEAN),
    'SENTRY_DSN' => $env('SENTRY_DSN', ''),
];

/* If config.local.php exists, it may return an associative array with overrides.
   Example config.local.php:
   <?php
   return [
       'DB_PASS' => 'supersecret',
       'BASE_URL' => 'https://example.com',
   ];
*/
$localFile = __DIR__ . '/config.local.php';
$local = [];
if (is_file($localFile)) {
    $maybe = include $localFile;
    if (is_array($maybe)) {
        $local = $maybe;
    }
}

/* Merge: environment/defaults first, then local overrides */
$config = array_merge($defaults, $local);

/* Apply timezone setting (best-effort) */
if (!empty($config['TIMEZONE'])) {
    @date_default_timezone_set($config['TIMEZONE']);
}

/* Convenience: build DSN (not opened here) */
$config['PDO_DSN'] = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $config['DB_HOST'],
    $config['DB_PORT'],
    $config['DB_NAME']
);

/* Helper to create a PDO instance (call when needed). Does not throw on include. */
$config['create_pdo'] = function () use ($config) {
    $opts = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new \PDO($config['PDO_DSN'], $config['DB_USER'], $config['DB_PASS'], $opts);
};

/* Small helper function for other scripts: config(key, default) */
$config['get'] = function (string $key, $default = null) use (&$config) {
    return array_key_exists($key, $config) ? $config[$key] : $default;
};

/* NOTE:
 * - Keep secrets out of source control.
 * - Prefer setting: DB_PASS, API_KEY, etc. via environment variables on the server,
 *   or place them in web/config.local.php which must NOT be committed.
 */

/* Return the configuration array to the includer. */
return $config;
