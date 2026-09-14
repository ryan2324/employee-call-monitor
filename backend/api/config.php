<?php
declare(strict_types=1);

function envv(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$databaseUrl = envv('DATABASE_URL', '');
$host = envv('DB_HOST', 'localhost');
$port = envv('DB_PORT', '5432');
$name = envv('DB_NAME', 'callermonitor');
$user = envv('DB_USER', 'callermonitor');
$password = envv('DB_PASSWORD', 'change-me');
$sslmode = envv('DB_SSLMODE', 'require');

if ($databaseUrl !== '') {
    $parts = parse_url($databaseUrl);
    if (is_array($parts) && !empty($parts['host'])) {
        $host = $parts['host'];
        $port = (string)($parts['port'] ?? 5432);
        $name = ltrim((string)($parts['path'] ?? '/callermonitor'), '/');
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : $user;
        $password = isset($parts['pass']) ? rawurldecode($parts['pass']) : $password;
        parse_str((string)($parts['query'] ?? ''), $query);
        if (!empty($query['sslmode'])) $sslmode = (string)$query['sslmode'];
    }
}

$origin = envv('WEB_ORIGIN', 'https://callcentermonitoring.gt.tc');
$timezone = envv('APP_TIMEZONE', 'Asia/Manila');
$cookieSecure = filter_var(envv('COOKIE_SECURE', 'true'), FILTER_VALIDATE_BOOL);

return [
    'db' => [
        'dsn' => "pgsql:host={$host};port={$port};dbname={$name};sslmode={$sslmode}",
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
