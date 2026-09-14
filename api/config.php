<?php
declare(strict_types=1);

function envv(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$host = envv('DB_HOST', 'db');
$port = envv('DB_PORT', '3306');
$name = envv('DB_NAME', 'callermonitor');
$user = envv('DB_USER', 'callermonitor');
$password = envv('DB_PASSWORD', 'change-me');
$origin = envv('WEB_ORIGIN', 'https://callcentermonitoring.gt.tc');
$timezone = envv('APP_TIMEZONE', 'Asia/Manila');
$cookieSecure = filter_var(envv('COOKIE_SECURE', 'true'), FILTER_VALIDATE_BOOL);

return [
    'db' => [
        'dsn' => "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        'user' => $user,
        'password' => $password,
    ],
    'app' => [
        'session_name' => envv('SESSION_NAME', 'employee_monitor_manager'),
        'allowed_origin' => $origin,
        'idle_threshold_seconds' => (int)envv('IDLE_THRESHOLD_SECONDS', '300'),
        'heartbeat_timeout_seconds' => (int)envv('HEARTBEAT_TIMEOUT_SECONDS', '45'),
        'join_token_minutes' => (int)envv('JOIN_TOKEN_MINUTES', '10'),
        'api_rate_limit' => (int)envv('API_RATE_LIMIT', '120'),
        'timezone' => $timezone,
        'cookie_secure' => $cookieSecure,
    ],
    'install_key' => envv('INSTALL_KEY', ''),
    'timezone' => $timezone,
    'api_base_url' => rtrim(envv('PUBLIC_API_BASE_URL', ''), '/') . '/',
];
