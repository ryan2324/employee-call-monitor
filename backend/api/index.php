<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/
session_name((string)$config['app']['session_name']);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (bool)$config['app']['cookie_secure'],
    'httponly' => true,
    'samesite' => 'None',
]);

session_start();

date_default_timezone_set((string)$config['timezone']);

/*
|--------------------------------------------------------------------------
| HEADERS
|--------------------------------------------------------------------------
*/
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    hash_equals((string)$config['app']['allowed_origin'], $origin)
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function body(): array
{
    $v = json_decode(
        file_get_contents('php://input') ?: '{}',
        true
    );

    return is_array($v) ? $v : [];
}

function out(array $v, int $status = 200): never
{
    http_response_code($status);

    echo json_encode(
        $v,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function manager(): void
{
    if (empty($_SESSION['manager_id'])) {
        out([
            'error' => 'Unauthorized'
        ], 401);
    }
}

/*
|--------------------------------------------------------------------------
| SETTINGS TABLE + WARNING CYCLE COLUMN
|--------------------------------------------------------------------------
*/
function ensureSettingsTable(PDO $p): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $p->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $p->exec("
        INSERT INTO app_settings(
            setting_key,
            setting_value
        )
        VALUES(
            'auto_idle_warning_minutes',
            '0'
        )
        ON CONFLICT (setting_key) DO NOTHING
    ");

    /*
     * One-warning-per-idle-cycle flag.
     *
     * 0 = warning can be generated
     * 1 = warning already generated for current idle cycle
     *
     * It is reset when:
     * - employee starts a call
     * - automatic warning is acknowledged
     */
    $p->exec("
        ALTER TABLE devices
        ADD COLUMN IF NOT EXISTS
        idle_warning_acknowledged
        SMALLINT NOT NULL DEFAULT 0
    ");

    $done = true;
}

/*
|--------------------------------------------------------------------------
| AUTOMATIC IDLE WARNING
|--------------------------------------------------------------------------
|
| Rules:
|
| 1. Only READY employees can receive automatic idle warnings.
| 2. Timer must completely expire.
| 3. Only ONE warning can exist at a time.
| 4. Manual and automatic warnings share the same pending queue.
| 5. Once an automatic warning has been generated during an
|    idle cycle, another automatic warning is NOT generated.
| 6. After acknowledgement, state_changed_at is reset and the
|    warning-cycle flag is reset.
| 7. Transaction + FOR UPDATE prevents simultaneous heartbeat
|    requests from creating duplicate warnings.
|
|--------------------------------------------------------------------------
*/
function autoIdleWarning(
    PDO $p,
    int $employeeId,
    string $deviceId,
    string $state
): void {

    if (
        $state !== 'READY' ||
        !$employeeId ||
        !$deviceId
    ) {
        return;
    }

    ensureSettingsTable($p);

    /*
     * Get automatic warning timer.
     */
    $q = $p->prepare("
        SELECT setting_value
        FROM app_settings
        WHERE setting_key = 'auto_idle_warning_minutes'
        LIMIT 1
    ");

    $q->execute();

    $minutes = max(
        0,
        min(240, (int)$q->fetchColumn())
    );

    /*
     * 0 = automatic warning disabled.
     */
    if ($minutes <= 0) {
        return;
    }

    /*
     * Start transaction.
     *
     * This prevents two heartbeat requests from simultaneously
     * creating two automatic warnings.
     */
    $p->beginTransaction();

    try {

        /*
         * Lock the device row.
         */
        $q = $p->prepare("
            SELECT
                state_changed_at,
                idle_warning_acknowledged
            FROM devices
            WHERE device_id = :d
              AND employee_id = :e
              AND active = 1
            FOR UPDATE
        ");

        $q->execute([
            'd' => $deviceId,
            'e' => $employeeId
        ]);

        $device = $q->fetch();

        if (!$device) {
            $p->commit();
            return;
        }

        /*
         * Already warned during this idle cycle.
         */
        if (
            (int)($device['idle_warning_acknowledged'] ?? 0) === 1
        ) {
            $p->commit();
            return;
        }

        $changed = $device['state_changed_at'] ?? null;

        if (!$changed) {
            $p->commit();
            return;
        }

        /*
         * Database timestamps are UTC.
         */
        $changedTs = strtotime(
            (string)$changed . ' UTC'
        );

        if ($changedTs === false) {
            $p->commit();
            return;
        }

        /*
         * Timer has not expired.
         */
        if (
            time() - $changedTs <
            ($minutes * 60)
        ) {
            $p->commit();
            return;
        }

        /*
         * VERY IMPORTANT:
         *
         * Check for ANY pending warning.
         *
         * This means manual and automatic warnings cannot
         * stack with each other.
         */
        $q = $p->prepare("
            SELECT id
            FROM warning_commands
            WHERE device_id = :d
              AND acknowledged_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");

        $q->execute([
            'd' => $deviceId
        ]);

        if ($q->fetchColumn()) {
            $p->commit();
            return;
        }

        /*
         * Create exactly ONE automatic warning.
         */
        $msg =
            'You have been idle for ' .
            $minutes .
            ' minute' .
            ($minutes === 1 ? '' : 's') .
            '. Please resume calling now.';

        $q = $p->prepare("
            INSERT INTO warning_commands(
                employee_id,
                device_id,
                command_type,
                message
            )
            VALUES(
                :e,
                :d,
                'AUTO_IDLE_WARNING',
                :m
            )
            RETURNING id
        ");

        $q->execute([
            'e' => $employeeId,
            'd' => $deviceId,
            'm' => $msg
        ]);

        /*
         * Mark this idle cycle as already warned.
         */
        $q = $p->prepare("
            UPDATE devices
            SET idle_warning_acknowledged = 1
            WHERE device_id = :d
              AND employee_id = :e
              AND active = 1
        ");

        $q->execute([
            'd' => $deviceId,
            'e' => $employeeId
        ]);

        $p->commit();

    } catch (Throwable $e) {

        if ($p->inTransaction()) {
            $p->rollBack();
        }

        throw $e;
    }
}

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/
function db(): PDO
{
    global $config;

    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true
        ]
    );

    return $pdo;
}

/*
|--------------------------------------------------------------------------
| COLUMN CHECK
|--------------------------------------------------------------------------
*/
function hasColumn(
    PDO $p,
    string $table,
    string $column
): bool {

    static $cache = [];

    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $q = $p->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = :t
          AND column_name = :c
    ");

    $q->execute([
        't' => $table,
        'c' => $column
    ]);

    return $cache[$key] =
        ((int)$q->fetchColumn() > 0);
}

/*
|--------------------------------------------------------------------------
| AUDIT
|--------------------------------------------------------------------------
*/
function audit(
    string $action,
    array $details = []
): void {

    try {

        $q = db()->prepare("
            INSERT INTO audit_logs(
                manager_id,
                action,
                details,
                ip_address
            )
            VALUES(
                :m,
                :a,
                :d,
                :ip
            )
        ");

        $q->execute([
            'm' => $_SESSION['manager_id'] ?? null,
            'a' => $action,
            'd' => $details
                ? json_encode($details)
                : null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

    } catch (Throwable $e) {
        /*
         * Audit failure must never break the main request.
         */
    }
}

/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/
function statusFor(array $d): string
{
    global $config;

    if (empty($d['last_seen'])) {
        return 'OFFLINE';
    }

    /*
     * PostgreSQL stores these timestamps as UTC
     * TIMESTAMP WITHOUT TIME ZONE.
     */
    $seen = strtotime(
        (string)$d['last_seen'] . ' UTC'
    );

    if (
        $seen === false ||
        time() - $seen >
        (int)$config['app']['heartbeat_timeout_seconds']
    ) {
        return 'OFFLINE';
    }

    if (
        ($d['call_state'] ?? 'READY') === 'IN_CALL'
    ) {
        return 'IN_CALL';
    }

    $changed = !empty($d['state_changed_at'])
        ? strtotime(
            (string)$d['state_changed_at'] . ' UTC'
        )
        : $seen;

    return (
        $changed !== false &&
        time() - $changed >=
        (int)$config['app']['idle_threshold_seconds']
    )
        ? 'IDLE'
        : 'READY';
}

/*
|--------------------------------------------------------------------------
| DATE/TIME
|--------------------------------------------------------------------------
*/
function dtLocalToDb(?string $iso): ?string
{
    if (!$iso) {
        return null;
    }

    $ts = strtotime($iso);

    return $ts === false
        ? null
        : date('Y-m-d H:i:s', $ts);
}

/*
|--------------------------------------------------------------------------
| NUMBER
|--------------------------------------------------------------------------
*/
function normalizeNumber(?string $n): ?string
{
    if ($n === null) {
        return null;
    }

    $n = trim($n);

    if ($n === '') {
        return null;
    }

    return mb_substr($n, 0, 80);
}

/*
|--------------------------------------------------------------------------
| DAILY STATS
|--------------------------------------------------------------------------
*/
function updateDailyStats(
    PDO $p,
    int $employeeId,
    string $date,
    int $duration,
    string $result
): void {

    $success =
        $result === 'CONNECTED'
            ? 1
            : 0;

    $fail =
        $result === 'NOT_CONNECTED'
            ? 1
            : 0;

    $cols = [
        'employee_id',
        'stat_date',
        'calls_today',
        'talk_seconds_today'
    ];

    $vals = [
        ':e',
        ':d',
        '1',
        ':dur'
    ];

    $updates = [
        'calls_today = daily_employee_stats.calls_today + EXCLUDED.calls_today',
        'talk_seconds_today = daily_employee_stats.talk_seconds_today + EXCLUDED.talk_seconds_today'
    ];

    if (
        hasColumn(
            $p,
            'daily_employee_stats',
            'successful_calls_today'
        )
    ) {

        $cols[] = 'successful_calls_today';
        $vals[] = ':s';

        $updates[] =
            'successful_calls_today = daily_employee_stats.successful_calls_today + EXCLUDED.successful_calls_today';
    }

    if (
        hasColumn(
            $p,
            'daily_employee_stats',
            'unsuccessful_calls_today'
        )
    ) {

        $cols[] = 'unsuccessful_calls_today';
        $vals[] = ':f';

        $updates[] =
            'unsuccessful_calls_today = daily_employee_stats.unsuccessful_calls_today + EXCLUDED.unsuccessful_calls_today';
    }

    $sql =
        'INSERT INTO daily_employee_stats(' .
        implode(',', $cols) .
        ') VALUES(' .
        implode(',', $vals) .
        ') ON CONFLICT (employee_id,stat_date) DO UPDATE SET ' .
        implode(',', $updates);

    $p->prepare($sql)->execute([
        'e' => $employeeId,
        'd' => $date,
        's' => $success,
        'f' => $fail,
        'dur' => $duration
    ]);
}

/*
|--------------------------------------------------------------------------
| ROUTING
|--------------------------------------------------------------------------
*/
$uri = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
) ?: '/';

$path =
    '/' .
    trim(
        preg_replace(
            '#/+#',
            '/',
            $uri
        ),
        '/'
    );

/*
 * InfinityFree-safe route fallback.
 */
if (
    isset($_GET['route']) &&
    $_GET['route'] !== ''
) {

    $route =
        '/' .
        trim(
            (string)$_GET['route'],
            '/'
        );

    $path = '/api' . $route;
}

/*
 * Backward compatibility for missing slash.
 */
if (
    str_starts_with($path, '/api') &&
    !str_starts_with($path, '/api/')
) {

    $path =
        '/api/' .
        ltrim(
            substr($path, 4),
            '/'
        );
}

$method =
    $_SERVER['REQUEST_METHOD'] ?? 'GET';

/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/
try {

    $p = db();

    /*
     * Clear any previous failed transaction state.
     */
    try {
        $p->exec('ROLLBACK');
    } catch (Throwable $ignored) {
    }

    /*
    |--------------------------------------------------------------------------
    | HEALTH
    |--------------------------------------------------------------------------
    */
    if (
        (
            $path === '/api' ||
            $path === '/api/' ||
            $path === '/api/index.php' ||
            $path === '/api/health'
        ) &&
        $method === 'GET'
    ) {

        try {

            $p->query('SELECT 1');

            $tables = [];

            foreach (
                [
                    'managers',
                    'employees',
                    'devices',
                    'call_history',
                    'daily_employee_stats'
                ] as $t
            ) {

                $q = $p->prepare("
                    SELECT COUNT(*)
                    FROM information_schema.tables
                    WHERE table_schema = current_schema()
                      AND table_name = :t
                ");

                $q->execute([
                    't' => $t
                ]);

                $tables[$t] =
                    ((int)$q->fetchColumn() > 0);
            }

            out([
                'ok' => true,
                'service' => 'employee-call-monitor-api',
                'database' => true,
                'tables' => $tables,
                'php' => PHP_VERSION,
                'api_base' => $config['api_base_url'],
                'server_time' => gmdate('c')
            ]);

        } catch (Throwable $e) {

            out([
                'ok' => false,
                'database' => false,
                'error' => 'Database connection failed'
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOGIN
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/auth/login' &&
        $method === 'POST'
    ) {

        $b = body();

        $q = $p->prepare("
            SELECT
                id,
                email,
                password_hash,
                full_name,
                role
            FROM managers
            WHERE email = :e
              AND active = 1
            LIMIT 1
        ");

        $q->execute([
            'e' => trim(
                (string)($b['email'] ?? '')
            )
        ]);

        $u = $q->fetch();

        if (
            !$u ||
            !password_verify(
                (string)($b['password'] ?? ''),
                $u['password_hash']
            )
        ) {

            out([
                'error' => 'Invalid credentials'
            ], 401);
        }

        session_regenerate_id(true);

        $_SESSION['manager_id'] =
            (int)$u['id'];

        audit('LOGIN');

        out([
            'authenticated' => true,
            'manager' => [
                'id' => (int)$u['id'],
                'name' => $u['full_name'],
                'email' => $u['email'],
                'role' => $u['role']
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | LOGOUT
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/auth/logout' &&
        $method === 'POST'
    ) {

        audit('LOGOUT');

        $_SESSION = [];

        session_destroy();

        out([
            'ok' => true
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO IDLE WARNING SETTING - GET
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/settings/auto-idle-warning' &&
        $method === 'GET'
    ) {

        manager();

        ensureSettingsTable($p);

        $q = $p->prepare("
            SELECT setting_value
            FROM app_settings
            WHERE setting_key =
                'auto_idle_warning_minutes'
            LIMIT 1
        ");

        $q->execute();

        $minutes = max(
            0,
            min(
                240,
                (int)$q->fetchColumn()
            )
        );

        out([
            'minutes' => $minutes
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO IDLE WARNING SETTING - POST
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/settings/auto-idle-warning' &&
        $method === 'POST'
    ) {

        manager();

        ensureSettingsTable($p);

        $b = body();

        $minutes = max(
            0,
            min(
                240,
                (int)($b['minutes'] ?? 0)
            )
        );

        $q = $p->prepare("
            INSERT INTO app_settings(
                setting_key,
                setting_value,
                updated_at
            )
            VALUES(
                'auto_idle_warning_minutes',
                :v,
                NOW()
            )
            ON CONFLICT(setting_key)
            DO UPDATE SET
                setting_value = EXCLUDED.setting_value,
                updated_at = NOW()
        ");

        $q->execute([
            'v' => (string)$minutes
        ]);

        audit(
            'SET_AUTO_IDLE_WARNING',
            [
                'minutes' => $minutes
            ]
        );

        out([
            'ok' => true,
            'minutes' => $minutes
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | JOIN TOKEN
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/join/token' &&
        $method === 'POST'
    ) {

        manager();

        $token =
            bin2hex(random_bytes(16));

        $exp =
            time() +
            (int)$config['app']['join_token_minutes'] *
            60;

        $q = $p->prepare("
            INSERT INTO join_tokens(
                token,
                expires_at,
                created_by
            )
            VALUES(
                :t,
                :exp,
                :m
            )
        ");

        $q->execute([
            't' => $token,
            'exp' => gmdate(
                'Y-m-d H:i:s',
                $exp
            ),
            'm' => $_SESSION['manager_id']
        ]);

        audit('CREATE_JOIN_TOKEN');

        out([
            'token' => $token,
            'expires_at' => gmdate(
                'c',
                $exp
            )
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE JOIN
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/join' &&
        $method === 'POST'
    ) {

        $b = body();

        $token =
            trim(
                (string)($b['token'] ?? '')
            );

        $name =
            trim(
                (string)($b['name'] ?? '')
            );

        $device =
            trim(
                (string)($b['device_id'] ?? '')
            );

        $model =
            trim(
                (string)($b['model'] ?? '')
            );

        if (
            !$token ||
            !$name ||
            !$device
        ) {

            out([
                'joined' => false,
                'error' =>
                    'Name, token and device ID are required',
                'missing' => array_values(
                    array_filter([
                        'token' =>
                            $token ? '' : 'token',
                        'name' =>
                            $name ? '' : 'name',
                        'device_id' =>
                            $device
                                ? ''
                                : 'device_id'
                    ])
                )
            ], 422);
        }

        global $config;

        $joinPdo = null;

        $step =
            'create fresh join connection';

        try {

            $joinPdo = new PDO(
                $config['db']['dsn'],
                $config['db']['user'],
                $config['db']['password'],
                [
                    PDO::ATTR_ERRMODE =>
                        PDO::ERRMODE_EXCEPTION,

                    PDO::ATTR_DEFAULT_FETCH_MODE =>
                        PDO::FETCH_ASSOC,

                    PDO::ATTR_EMULATE_PREPARES =>
                        true
                ]
            );

            try {
                $joinPdo->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }

            $step =
                'test fresh connection';

            $joinPdo
                ->query('SELECT 1')
                ->fetchColumn();

            $step =
                'find join token';

            $q = $joinPdo->prepare("
                SELECT
                    id,
                    used_at,
                    expires_at
                FROM join_tokens
                WHERE token = :t
                LIMIT 1
            ");

            $q->execute([
                't' => $token
            ]);

            $jt = $q->fetch();

            if (!$jt) {

                out([
                    'joined' => false,
                    'error' =>
                        'Join code was not found. Generate a new QR code.'
                ], 400);
            }

            if ($jt['used_at'] !== null) {

                out([
                    'joined' => false,
                    'error' =>
                        'This join code has already been used. Generate a new QR code.'
                ], 400);
            }

            $step =
                'check token expiry';

            $q = $joinPdo->prepare("
                SELECT id
                FROM join_tokens
                WHERE id = :id
                  AND expires_at >
                      (NOW() AT TIME ZONE 'UTC')
                LIMIT 1
            ");

            $q->execute([
                'id' => $jt['id']
            ]);

            if (!$q->fetchColumn()) {

                out([
                    'joined' => false,
                    'error' =>
                        'This join code has expired. Generate a new QR code.'
                ], 400);
            }

            $step =
                'begin join transaction';

            if ($joinPdo->inTransaction()) {

                try {
                    $joinPdo->rollBack();
                } catch (Throwable $ignored) {
                }
            }

            $joinPdo->beginTransaction();

            $step =
                'claim join token';

            $q = $joinPdo->prepare("
                UPDATE join_tokens
                SET used_at = NOW()
                WHERE id = :id
                  AND used_at IS NULL
                  AND expires_at >
                      (NOW() AT TIME ZONE 'UTC')
                RETURNING id
            ");

            $q->execute([
                'id' => $jt['id']
            ]);

            $claimed =
                $q->fetchColumn();

            if (!$claimed) {

                $joinPdo->rollBack();

                out([
                    'joined' => false,
                    'error' =>
                        'This join code is no longer available. Generate a new QR code.'
                ], 400);
            }

            $step =
                'find device';

            $q = $joinPdo->prepare("
                SELECT
                    id,
                    employee_id
                FROM devices
                WHERE device_id = :d
                LIMIT 1
            ");

            $q->execute([
                'd' => $device
            ]);

            $old = $q->fetch();

            if ($old) {

                $eid =
                    (int)$old['employee_id'];

                $step =
                    'update employee';

                $q = $joinPdo->prepare("
                    UPDATE employees
                    SET
                        name = :n,
                        active = 1
                    WHERE id = :e
                ");

                $q->execute([
                    'n' => $name,
                    'e' => $eid
                ]);

                $step =
                    'update device';

                $q = $joinPdo->prepare("
                    UPDATE devices
                    SET
                        model = :model,
                        last_seen = NOW(),
                        call_state = 'READY',
                        state_changed_at = NOW(),
                        idle_warning_acknowledged = 0,
                        active = 1
                    WHERE id = :id
                ");

                $q->execute([
                    'model' =>
                        $model ?: null,
                    'id' =>
                        $old['id']
                ]);

            } else {

                $step =
                    'insert employee';

                $q = $joinPdo->prepare("
                    INSERT INTO employees(name)
                    VALUES(:n)
                    RETURNING id
                ");

                $q->execute([
                    'n' => $name
                ]);

                $eid =
                    (int)$q->fetchColumn();

                if ($eid <= 0) {
                    throw new RuntimeException(
                        'Employee record could not be created.'
                    );
                }

                $step =
                    'insert device';

                $q = $joinPdo->prepare("
                    INSERT INTO devices(
                        employee_id,
                        device_id,
                        model,
                        last_seen,
                        call_state,
                        state_changed_at,
                        idle_warning_acknowledged
                    )
                    VALUES(
                        :e,
                        :d,
                        :m,
                        NOW(),
                        'READY',
                        NOW(),
                        0
                    )
                ");

                $q->execute([
                    'e' => $eid,
                    'd' => $device,
                    'm' => $model ?: null
                ]);
            }

            $step =
                'commit';

            $joinPdo->commit();

            out([
                'joined' => true,
                'employee_id' => $eid,
                'device_id' => $device,
                'message' =>
                    'Phone connected successfully'
            ]);

        } catch (Throwable $e) {

            try {

                if (
                    $joinPdo instanceof PDO &&
                    $joinPdo->inTransaction()
                ) {
                    $joinPdo->rollBack();
                }

            } catch (Throwable $rollbackError) {

                error_log(
                    'EMPLOYEE JOIN ROLLBACK ERROR: ' .
                    get_class($rollbackError) .
                    ' | ' .
                    $rollbackError->getMessage()
                );
            }

            $info =
                $e instanceof PDOException
                    ? $e->errorInfo
                    : null;

            $requestId =
                substr(
                    bin2hex(random_bytes(6)),
                    0,
                    12
                );

            error_log(
                'EMPLOYEE JOIN ERROR' .
                ' | request_id=' .
                $requestId .
                ' | step=' .
                $step .
                ' | exception=' .
                get_class($e) .
                ' | message=' .
                $e->getMessage() .
                ' | sqlstate=' .
                ($info[0] ?? '') .
                ' | driver_code=' .
                ($info[1] ?? '') .
                ' | detail=' .
                ($info[2] ?? '') .
                ' | token_prefix=' .
                substr($token, 0, 8) .
                ' | device=' .
                substr($device, 0, 32)
            );

            $msg =
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not register device. Join step: ' .
                        $step .
                        '.';

            out([
                'joined' => false,
                'error' => $msg,
                'request_id' => $requestId
            ], 400);

        } finally {

            $joinPdo = null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE STATUS
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/status' &&
        $method === 'GET'
    ) {

        $device =
            trim(
                (string)($_GET['device_id'] ?? '')
            );

        if (!$device) {
            out([
                'joined' => false,
                'error' => 'Device ID required'
            ], 422);
        }

        $q = $p->prepare("
            SELECT
                e.id employee_id,
                e.name,
                e.department,
                d.device_id,
                d.model,
                d.battery_level,
                d.last_seen,
                d.call_state,
                d.state_changed_at,
                d.active
            FROM devices d
            JOIN employees e
              ON e.id = d.employee_id
            WHERE d.device_id = :d
            LIMIT 1
        ");

        $q->execute([
            'd' => $device
        ]);

        $r = $q->fetch();

        if (
            !$r ||
            !(int)$r['active']
        ) {

            out([
                'joined' => false,
                'error' =>
                    'Device is not registered on the server'
            ], 404);
        }

        $r['computed_status'] =
            statusFor($r);

        out([
            'joined' => true,
            'employee' => $r
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE HEARTBEAT
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/heartbeat' &&
        $method === 'POST'
    ) {

        $b = body();

        $device =
            trim(
                (string)($b['device_id'] ?? '')
            );

        $state =
            strtoupper(
                trim(
                    (string)(
                        $b['call_state'] ??
                        'READY'
                    )
                )
            );

        $battery =
            array_key_exists(
                'battery_level',
                $b
            )
                ? max(
                    0,
                    min(
                        100,
                        (int)$b['battery_level']
                    )
                )
                : null;

        if (!$device) {
            out([
                'ok' => false,
                'error' =>
                    'Device ID required'
            ], 422);
        }

        if (
            !in_array(
                $state,
                [
                    'READY',
                    'IN_CALL'
                ],
                true
            )
        ) {
            $state = 'READY';
        }

        /*
         * Update heartbeat.
         *
         * When employee enters IN_CALL:
         * - state_changed_at resets
         * - idle_warning_acknowledged resets
         *
         * This starts a fresh idle-warning cycle when
         * the employee eventually returns to READY.
         */
        $q = $p->prepare("
            UPDATE devices
            SET
                last_seen = NOW(),

                battery_level =
                    COALESCE(
                        :b,
                        battery_level
                    ),

                call_state = :s,

                state_changed_at =
                    CASE
                        WHEN call_state <> :s2
                        THEN NOW()
                        ELSE state_changed_at
                    END,

                idle_warning_acknowledged =
                    CASE
                        WHEN call_state <> :s3
                             AND :s4 = 'IN_CALL'
                        THEN 0
                        ELSE idle_warning_acknowledged
                    END,

                call_started_at =
                    CASE
                        WHEN :s5 = 'IN_CALL'
                             AND call_state <> 'IN_CALL'
                        THEN NOW()

                        WHEN :s6 <> 'IN_CALL'
                        THEN NULL

                        ELSE call_started_at
                    END

            WHERE device_id = :d
              AND active = 1
        ");

        $q->execute([
            'b' => $battery,
            's' => $state,
            's2' => $state,
            's3' => $state,
            's4' => $state,
            's5' => $state,
            's6' => $state,
            'd' => $device
        ]);

        if ($q->rowCount() === 0) {

            $x = $p->prepare("
                SELECT id
                FROM devices
                WHERE device_id = :d
                  AND active = 1
            ");

            $x->execute([
                'd' => $device
            ]);

            if (!$x->fetchColumn()) {

                out([
                    'ok' => false,
                    'joined' => false,
                    'error' =>
                        'Device is not registered on the server'
                ], 404);
            }
        }

        /*
         * Get employee ID.
         */
        $x = $p->prepare("
            SELECT employee_id
            FROM devices
            WHERE device_id = :d
              AND active = 1
            LIMIT 1
        ");

        $x->execute([
            'd' => $device
        ]);

        $employeeId =
            (int)$x->fetchColumn();

        /*
         * Automatic warning.
         */
        try {

            autoIdleWarning(
                $p,
                $employeeId,
                $device,
                $state
            );

        } catch (Throwable $e) {

            error_log(
                'AUTO IDLE WARNING ERROR: ' .
                $e->getMessage()
            );
        }

        out([
            'ok' => true,
            'joined' => true,
            'server_time' =>
                gmdate('c')
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CALL SYNC
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/calls/sync' &&
        $method === 'POST'
    ) {

        $b = body();

        $device =
            trim(
                (string)($b['device_id'] ?? '')
            );

        $calls =
            $b['calls'] ?? [];

        if (
            !$device ||
            !is_array($calls)
        ) {

            out([
                'ok' => false,
                'error' =>
                    'Device ID and calls are required'
            ], 422);
        }

        $q = $p->prepare("
            SELECT employee_id
            FROM devices
            WHERE device_id = :d
              AND active = 1
            LIMIT 1
        ");

        $q->execute([
            'd' => $device
        ]);

        $eid =
            (int)$q->fetchColumn();

        if (!$eid) {

            out([
                'ok' => false,
                'error' =>
                    'Device is not registered on the server'
            ], 404);
        }

        $insert = $p->prepare("
            INSERT INTO call_history(
                employee_id,
                device_id,
                android_call_log_id,
                direction,
                contact_number,
                result,
                started_at,
                ended_at,
                duration_seconds
            )
            VALUES(
                :e,
                :d,
                :cid,
                :dir,
                :num,
                :result,
                :start,
                :end,
                :dur
            )
            ON CONFLICT (
                device_id,
                android_call_log_id
            )
            DO NOTHING
        ");

        $update = $p->prepare("
            UPDATE call_history
            SET
                contact_number = :num,
                result = :result,
                ended_at = :end,
                duration_seconds = :dur
            WHERE device_id = :d
              AND android_call_log_id = :cid
        ");

        $added = 0;
        $updated = 0;

        foreach (
            array_slice(
                $calls,
                0,
                100
            ) as $c
        ) {

            $cid =
                (int)($c['call_log_id'] ?? 0);

            $start =
                dtLocalToDb(
                    $c['started_at'] ?? null
                );

            $end =
                dtLocalToDb(
                    $c['ended_at'] ?? null
                );

            $dur =
                max(
                    0,
                    (int)(
                        $c['duration_seconds'] ??
                        0
                    )
                );

            $dir =
                strtoupper(
                    (string)(
                        $c['direction'] ??
                        'OUTGOING'
                    )
                );

            $result =
                $dur > 0
                    ? 'CONNECTED'
                    : 'NOT_CONNECTED';

            if (
                !$cid ||
                !$start
            ) {
                continue;
            }

            if (
                !in_array(
                    $dir,
                    [
                        'INCOMING',
                        'OUTGOING',
                        'UNKNOWN'
                    ],
                    true
                )
            ) {
                $dir = 'UNKNOWN';
            }

            $params = [
                'e' =>
                    $eid,
                'd' =>
                    $device,
                'cid' =>
                    $cid,
                'dir' =>
                    $dir,
                'num' =>
                    normalizeNumber(
                        $c['contact_number'] ??
                        null
                    ),
                'result' =>
                    $result,
                'start' =>
                    $start,
                'end' =>
                    $end,
                'dur' =>
                    $dur
            ];

            $insert->execute($params);

            if (
                $insert->rowCount() === 1
            ) {

                $added++;

                updateDailyStats(
                    $p,
                    $eid,
                    substr($start, 0, 10),
                    $dur,
                    $result
                );

            } else {

                $update->execute([
                    'num' =>
                        $params['num'],
                    'result' =>
                        $result,
                    'end' =>
                        $end,
                    'dur' =>
                        $dur,
                    'd' =>
                        $device,
                    'cid' =>
                        $cid
                ]);

                $updated++;
            }
        }

        out([
            'ok' => true,
            'added' => $added,
            'updated' => $updated,
            'received' => count($calls)
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE DASHBOARD
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/dashboard' &&
        $method === 'GET'
    ) {

        $device =
            trim(
                (string)($_GET['device_id'] ?? '')
            );

        if (!$device) {
            out([
                'error' =>
                    'Device ID required'
            ], 422);
        }

        $q = $p->prepare("
            SELECT
                e.id employee_id,
                e.name,
                e.department,
                d.device_id,
                d.model,
                d.battery_level,
                d.last_seen,
                d.call_state,
                d.state_changed_at
            FROM devices d
            JOIN employees e
              ON e.id = d.employee_id
            WHERE d.device_id = :d
              AND d.active = 1
            LIMIT 1
        ");

        $q->execute([
            'd' => $device
        ]);

        $e = $q->fetch();

        if (!$e) {

            out([
                'error' =>
                    'Device is not registered'
            ], 404);
        }

        $e['status'] =
            statusFor($e);

        $successExpr =
            hasColumn(
                $p,
                'daily_employee_stats',
                'successful_calls_today'
            )
                ? 'COALESCE(SUM(successful_calls_today),0)'
                : '0';

        $failExpr =
            hasColumn(
                $p,
                'daily_employee_stats',
                'unsuccessful_calls_today'
            )
                ? 'COALESCE(SUM(unsuccessful_calls_today),0)'
                : '0';

        $q = $p->prepare("
            SELECT
                COALESCE(
                    SUM(calls_today),
                    0
                ) total_calls,

                $successExpr successful_calls,

                $failExpr unsuccessful_calls,

                COALESCE(
                    SUM(talk_seconds_today),
                    0
                ) total_duration

            FROM daily_employee_stats

            WHERE employee_id = :e
              AND stat_date = CURRENT_DATE
        ");

        $q->execute([
            'e' =>
                $e['employee_id']
        ]);

        $stats =
            $q->fetch() ?: [];

        $q = $p->prepare("
            SELECT
                id,
                contact_number,
                result,
                direction,
                started_at,
                ended_at,
                duration_seconds
            FROM call_history
            WHERE employee_id = :e
            ORDER BY started_at DESC
            LIMIT 50
        ");

        $q->execute([
            'e' =>
                $e['employee_id']
        ]);

        $calls =
            $q->fetchAll();

        out([
            'employee' => $e,

            'stats' => [
                'total_calls' =>
                    (int)(
                        $stats['total_calls'] ??
                        0
                    ),

                'successful_calls' =>
                    (int)(
                        $stats['successful_calls'] ??
                        0
                    ),

                'unsuccessful_calls' =>
                    (int)(
                        $stats['unsuccessful_calls'] ??
                        0
                    ),

                'total_duration' =>
                    (int)(
                        $stats['total_duration'] ??
                        0
                    )
            ],

            'calls' =>
                $calls,

            'server_time' =>
                gmdate('c')
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | MANAGER DASHBOARD
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/dashboard' &&
        $method === 'GET'
    ) {

        manager();

        $rows =
            $p->query("
                SELECT
                    e.id,
                    e.name,
                    e.department,
                    d.device_id,
                    d.model,
                    d.battery_level,
                    d.last_seen,
                    d.call_state,
                    d.state_changed_at,

                    COALESCE(
                        s.calls_today,
                        0
                    ) calls_today,

                    COALESCE(
                        s.successful_calls_today,
                        0
                    ) successful_calls_today,

                    COALESCE(
                        s.unsuccessful_calls_today,
                        0
                    ) unsuccessful_calls_today,

                    COALESCE(
                        s.talk_seconds_today,
                        0
                    ) talk_seconds_today,

                    COALESCE(
                        s.idle_seconds_today,
                        0
                    ) idle_seconds_today

                FROM employees e

                LEFT JOIN devices d
                    ON d.employee_id = e.id
                   AND d.active = 1

                LEFT JOIN daily_employee_stats s
                    ON s.employee_id = e.id
                   AND s.stat_date = CURRENT_DATE

                WHERE e.active = 1

                ORDER BY e.name
            ")
            ->fetchAll();

        foreach ($rows as &$r) {
            $r['computed_status'] =
                statusFor($r);
        }

        unset($r);

        out([
            'employees' =>
                $rows,

            'server_time' =>
                gmdate('c')
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE DETAIL
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/detail' &&
        $method === 'GET'
    ) {

        manager();

        $id =
            (int)(
                $_GET['id'] ?? 0
            );

        if (!$id) {
            out([
                'error' =>
                    'Employee ID required'
            ], 422);
        }

        $q = $p->prepare("
            SELECT
                e.id,
                e.name,
                e.department,
                e.active,

                d.device_id,
                d.model,
                d.battery_level,
                d.last_seen,
                d.call_state,
                d.state_changed_at,

                COALESCE(
                    s.calls_today,
                    0
                ) calls_today,

                COALESCE(
                    s.successful_calls_today,
                    0
                ) successful_calls_today,

                COALESCE(
                    s.unsuccessful_calls_today,
                    0
                ) unsuccessful_calls_today,

                COALESCE(
                    s.talk_seconds_today,
                    0
                ) talk_seconds_today,

                COALESCE(
                    s.idle_seconds_today,
                    0
                ) idle_seconds_today

            FROM employees e

            LEFT JOIN devices d
                ON d.employee_id = e.id
               AND d.active = 1

            LEFT JOIN daily_employee_stats s
                ON s.employee_id = e.id
               AND s.stat_date = CURRENT_DATE

            WHERE e.id = :id

            LIMIT 1
        ");

        $q->execute([
            'id' => $id
        ]);

        $e = $q->fetch();

        if (!$e) {
            out([
                'error' =>
                    'Employee not found'
            ], 404);
        }

        $e['computed_status'] =
            statusFor($e);

        $q = $p->prepare("
            SELECT
                id,
                contact_number,
                result,
                direction,
                started_at,
                ended_at,
                duration_seconds
            FROM call_history
            WHERE employee_id = :id
            ORDER BY started_at DESC
            LIMIT 100
        ");

        $q->execute([
            'id' => $id
        ]);

        $e['recent_calls'] =
            $q->fetchAll();

        out([
            'employee' => $e
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CALL REPORT
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/reports/calls' &&
        $method === 'GET'
    ) {

        manager();

        $from =
            $_GET['from'] ??
            date('Y-m-d');

        $to =
            $_GET['to'] ??
            date('Y-m-d');

        $employee =
            (int)(
                $_GET['employee_id'] ??
                0
            );

        $sql = "
            SELECT
                c.id,
                e.name,
                c.contact_number,
                c.result,
                c.direction,
                c.started_at,
                c.ended_at,
                c.duration_seconds

            FROM call_history c

            JOIN employees e
              ON e.id = c.employee_id

            WHERE c.started_at >= :f
              AND c.started_at < :t
        ";

        $params = [
            'f' =>
                $from .
                ' 00:00:00',

            't' =>
                date(
                    'Y-m-d',
                    strtotime(
                        $to .
                        ' +1 day'
                    )
                ) .
                ' 00:00:00'
        ];

        if ($employee > 0) {

            $sql .=
                ' AND c.employee_id = :e';

            $params['e'] =
                $employee;
        }

        $sql .=
            ' ORDER BY c.started_at DESC LIMIT 1000';

        $q = $p->prepare($sql);

        $q->execute($params);

        out([
            'calls' =>
                $q->fetchAll(),

            'from' =>
                $from,

            'to' =>
                $to
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CSV REPORT
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/reports/calls.csv' &&
        $method === 'GET'
    ) {

        manager();

        $from =
            $_GET['from'] ??
            date('Y-m-d');

        $to =
            $_GET['to'] ??
            date('Y-m-d');

        $toEx =
            date(
                'Y-m-d',
                strtotime(
                    $to .
                    ' +1 day'
                )
            );

        $q = $p->prepare("
            SELECT
                e.name,
                e.department,
                c.contact_number,
                c.result,
                c.direction,
                c.started_at,
                c.ended_at,
                c.duration_seconds

            FROM call_history c

            JOIN employees e
              ON e.id = c.employee_id

            WHERE c.started_at >= :f
              AND c.started_at < :t

            ORDER BY c.started_at DESC
        ");

        $q->execute([
            'f' =>
                $from .
                ' 00:00:00',

            't' =>
                $toEx .
                ' 00:00:00'
        ]);

        header_remove('Content-Type');

        header(
            'Content-Type: text/csv; charset=utf-8'
        );

        header(
            'Content-Disposition: attachment; filename="call-history.csv"'
        );

        $o =
            fopen(
                'php://output',
                'w'
            );

        fputcsv(
            $o,
            [
                'Employee',
                'Department',
                'Contact Number',
                'Result',
                'Direction',
                'Started',
                'Ended',
                'Duration Seconds'
            ]
        );

        while ($r = $q->fetch()) {
            fputcsv($o, $r);
        }

        fclose($o);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL WARNING
    |--------------------------------------------------------------------------
    |
    | ONE WARNING MAXIMUM.
    |
    | If manager clicks Send Warning 3 times:
    |
    | Click #1 -> creates warning
    | Click #2 -> blocked
    | Click #3 -> blocked
    |
    | The same rule applies against an automatic warning.
    |
    |--------------------------------------------------------------------------
    */
    /*
|--------------------------------------------------------------------------
| MANUAL WARNING
|--------------------------------------------------------------------------
|
| The manager dashboard may send either:
|
|   employee_id + device_id
|
| OR just:
|
|   employee_id
|
| If device_id is missing, the API automatically finds the
| active device belonging to that employee.
|
| Multiple clicks while a warning is pending will NOT create
| additional warnings.
|
|--------------------------------------------------------------------------
*/
if (
    $path === '/api/employee/warn' &&
    $method === 'POST'
) {

    manager();

    $b = body();

    $employeeId = (int)($b['employee_id'] ?? 0);
    $deviceId = trim((string)($b['device_id'] ?? ''));
    $message = trim((string)(
        $b['message'] ??
        'You have been warned. Please resume calling now.'
    ));

    if ($employeeId <= 0) {
        out([
            'ok' => false,
            'error' => 'Employee ID is required'
        ], 422);
    }

    if ($message === '') {
        $message = 'You have been warned. Please resume calling now.';
    }

    try {
        /*
         * The manager dashboard normally sends employee_id only.
         * Resolve the currently active device here.
         *
         * This route deliberately does NOT require the newer
         * idle_warning_acknowledged column, so manual warnings keep
         * working even if the database migration has not been run.
         */
        if ($deviceId === '') {
            $q = $p->prepare("\n                SELECT device_id\n                FROM devices\n                WHERE employee_id = :e\n                  AND active = 1\n                ORDER BY id DESC\n                LIMIT 1\n            ");
            $q->execute(['e' => $employeeId]);
            $deviceId = trim((string)$q->fetchColumn());
        }

        if ($deviceId === '') {
            out([
                'ok' => false,
                'error' => 'No active device is connected to this employee'
            ], 404);
        }

        /* Confirm that the device belongs to this employee. */
        $q = $p->prepare("\n            SELECT id, employee_id, device_id\n            FROM devices\n            WHERE device_id = :d\n              AND employee_id = :e\n              AND active = 1\n            LIMIT 1\n        ");
        $q->execute([
            'd' => $deviceId,
            'e' => $employeeId
        ]);
        $device = $q->fetch();

        if (!$device) {
            out([
                'ok' => false,
                'error' => 'Employee device is not registered or is inactive'
            ], 404);
        }

        /*
         * One pending warning per device.
         * Manual and automatic warnings share warning_commands.
         */
        $q = $p->prepare("\n            SELECT id, command_type\n            FROM warning_commands\n            WHERE device_id = :d\n              AND acknowledged_at IS NULL\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $q->execute(['d' => $deviceId]);
        $existing = $q->fetch();

        if ($existing) {
            out([
                'ok' => true,
                'already_pending' => true,
                'warning_id' => (int)$existing['id'],
                'employee_id' => $employeeId,
                'device_id' => $deviceId,
                'message' => 'A warning is already pending for this employee.'
            ]);
        }

        $q = $p->prepare("\n            INSERT INTO warning_commands(\n                employee_id,\n                device_id,\n                command_type,\n                message\n            )\n            VALUES(\n                :e,\n                :d,\n                'IDLE_WARNING',\n                :m\n            )\n            RETURNING id\n        ");
        $q->execute([
            'e' => $employeeId,
            'd' => $deviceId,
            'm' => $message
        ]);

        $warningId = (int)$q->fetchColumn();

        audit('MANUAL_EMPLOYEE_WARNING', [
            'employee_id' => $employeeId,
            'device_id' => $deviceId,
            'warning_id' => $warningId
        ]);

        out([
            'ok' => true,
            'already_pending' => false,
            'warning_id' => $warningId,
            'employee_id' => $employeeId,
            'device_id' => $deviceId,
            'message' => 'Warning sent successfully.'
        ]);

    } catch (Throwable $e) {
        error_log(
            'MANUAL WARNING ERROR: ' .
            get_class($e) .
            ' | ' .
            $e->getMessage()
        );

        $detail = $e->getMessage();
        $info = $e instanceof PDOException ? $e->errorInfo : null;

        out([
            'ok' => false,
            'error' => 'Could not send warning',
            'detail' => $detail,
            'sqlstate' => $info[0] ?? null
        ], 500);
    }
}

    /*
    |--------------------------------------------------------------------------
    | EMPLOYEE COMMANDS
    |--------------------------------------------------------------------------
    |
    | Deliver ONLY ONE unacknowledged warning.
    |
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/commands' &&
        $method === 'GET'
    ) {

        $device =
            trim(
                (string)(
                    $_GET['device_id'] ??
                    ''
                )
            );

        if (!$device) {
            out([
                'error' =>
                    'Device ID required'
            ], 422);
        }

        /*
         * Only the oldest pending warning is delivered.
         *
         * Since manual/automatic creation is now protected,
         * there should normally only be one.
         */
        $q = $p->prepare("
            SELECT
                id,
                command_type,
                message,
                created_at
            FROM warning_commands
            WHERE device_id = :d
              AND acknowledged_at IS NULL
              AND NOT EXISTS (
                  SELECT 1
                  FROM warning_commands w2
                  WHERE w2.device_id = :d2
                    AND w2.acknowledged_at IS NULL
                    AND w2.id <
                        warning_commands.id
              )
            ORDER BY id
            LIMIT 1
        ");

        $q->execute([
            'd' =>
                $device,

            'd2' =>
                $device
        ]);

        $c =
            $q->fetchAll();

        if ($c) {

            $q = $p->prepare("
                UPDATE warning_commands
                SET delivered_at = NOW()
                WHERE id = :id
                  AND delivered_at IS NULL
            ");

            $q->execute([
                'id' =>
                    (int)$c[0]['id']
            ]);
        }

        out([
            'commands' =>
                $c
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | COMMAND ACKNOWLEDGEMENT
    |--------------------------------------------------------------------------
    |
    | Automatic warning:
    |
    | acknowledge
    |     ↓
    | state_changed_at = NOW()
    |     ↓
    | idle_warning_acknowledged = 0
    |     ↓
    | NEW idle timer begins
    |
    | Example with 2-minute timer:
    |
    | 10:00 warning
    | 10:00 acknowledge
    | 10:00 timer reset
    | 10:02 next warning allowed
    |
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/employee/command-ack' &&
        $method === 'POST'
    ) {

        $b = body();

        $commandId =
            (int)(
                $b['command_id'] ??
                0
            );

        $deviceId =
            trim(
                (string)(
                    $b['device_id'] ??
                    ''
                )
            );

        if (
            !$commandId ||
            !$deviceId
        ) {

            out([
                'ok' => false,
                'error' =>
                    'Command ID and device ID are required'
            ], 422);
        }

        $p->beginTransaction();

        try {

            /*
             * Acknowledge exactly one command.
             */
            $q = $p->prepare("
                UPDATE warning_commands
                SET acknowledged_at = NOW()
                WHERE id = :i
                  AND device_id = :d
                  AND acknowledged_at IS NULL
                RETURNING employee_id, command_type
            ");

            $q->execute([
                'i' =>
                    $commandId,

                'd' =>
                    $deviceId
            ]);

            $command =
                $q->fetch();

            if (!$command) {

                $p->rollBack();

                out([
                    'ok' => false,
                    'error' =>
                        'Warning was already acknowledged or was not found'
                ], 404);
            }

            /*
             * Automatic warning:
             *
             * Reset the timer and allow another warning
             * after the configured idle period.
             */
            if (
                $command['command_type'] ===
                'AUTO_IDLE_WARNING'
            ) {

                $q = $p->prepare("
                    UPDATE devices
                    SET
                        state_changed_at = NOW(),
                        idle_warning_acknowledged = 0
                    WHERE device_id = :d
                      AND employee_id = :e
                      AND active = 1
                      AND call_state = 'READY'
                ");

                $q->execute([
                    'd' =>
                        $deviceId,

                    'e' =>
                        (int)$command['employee_id']
                ]);
            }

            $p->commit();

            out([
                'ok' => true,
                'acknowledged' => true,
                'timer_reset' =>
                    (
                        $command['command_type'] ===
                        'AUTO_IDLE_WARNING'
                    )
            ]);

        } catch (Throwable $e) {

            if ($p->inTransaction()) {
                $p->rollBack();
            }

            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DIAGNOSTIC
    |--------------------------------------------------------------------------
    */
    if (
        $path === '/api/diagnostic' &&
        $method === 'GET'
    ) {

        manager();

        $device =
            trim(
                (string)(
                    $_GET['device_id'] ??
                    ''
                )
            );

        $checks = [];
        $errors = [];

        try {

            $p->query('SELECT 1');

            $checks[] = [
                'name' =>
                    'Database connection',
                'ok' =>
                    true,
                'detail' =>
                    'PostgreSQL connection is working.'
            ];

        } catch (Throwable $e) {

            $errors[] =
                $e->getMessage();

            $checks[] = [
                'name' =>
                    'Database connection',
                'ok' =>
                    false,
                'detail' =>
                    'Cannot connect to PostgreSQL.'
            ];
        }

        foreach (
            [
                'managers',
                'employees',
                'devices',
                'join_tokens',
                'call_history',
                'daily_employee_stats',
                'warning_commands'
            ] as $t
        ) {

            try {

                $q =
                    $p->query(
                        'SELECT COUNT(*) FROM "' .
                        $t .
                        '"'
                    );

                $checks[] = [
                    'name' =>
                        'Table ' . $t,
                    'ok' =>
                        true,
                    'detail' =>
                        $q->fetchColumn() .
                        ' rows'
                ];

            } catch (Throwable $e) {

                $checks[] = [
                    'name' =>
                        'Table ' . $t,
                    'ok' =>
                        false,
                    'detail' =>
                        'Missing or inaccessible table.'
                ];
            }
        }

        if ($device !== '') {

            $q = $p->prepare("
                SELECT
                    d.id,
                    d.device_id,
                    d.active,
                    d.last_seen,
                    d.call_state,
                    e.id employee_id,
                    e.name
                FROM devices d
                JOIN employees e
                  ON e.id = d.employee_id
                WHERE d.device_id = :d
                LIMIT 1
            ");

            $q->execute([
                'd' => $device
            ]);

            $r =
                $q->fetch();

            if (!$r) {

                $checks[] = [
                    'name' =>
                        'Device registration',
                    'ok' =>
                        false,
                    'detail' =>
                        'No row exists in devices for this Android device ID.'
                ];

                $errors[] =
                    'DEVICE_NOT_REGISTERED';

            } else {

                $checks[] = [
                    'name' =>
                        'Device registration',
                    'ok' =>
                        true,
                    'detail' =>
                        'Registered to ' .
                        $r['name'] .
                        ' (employee #' .
                        $r['employee_id'] .
                        ').'
                ];

                $checks[] = [
                    'name' =>
                        'Device active',
                    'ok' =>
                        (bool)$r['active'],
                    'detail' =>
                        $r['active']
                            ? 'Active'
                            : 'Inactive'
                ];

                /*
                 * Database timestamp is UTC.
                 */
                $age =
                    $r['last_seen']
                        ? time() -
                            strtotime(
                                $r['last_seen'] .
                                ' UTC'
                            )
                        : null;

                $checks[] = [
                    'name' =>
                        'Heartbeat',
                    'ok' =>
                        $age !== null &&
                        $age <= 45,
                    'detail' =>
                        $r['last_seen']
                            ? 'Last seen ' .
                                $r['last_seen'] .
                                ' (' .
                                max(
                                    0,
                                    (int)$age
                                ) .
                                's ago)'
                            : 'No heartbeat recorded'
                ];

                $checks[] = [
                    'name' =>
                        'Call state',
                    'ok' =>
                        true,
                    'detail' =>
                        $r['call_state']
                ];
            }
        }

        out([
            'ok' =>
                count(
                    array_filter(
                        $checks,
                        fn($c) =>
                            !$c['ok']
                    )
                ) === 0,

            'checks' =>
                $checks,

            'errors' =>
                $errors,

            'server_time' =>
                gmdate('c')
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | NOT FOUND
    |--------------------------------------------------------------------------
    */
    out([
        'error' =>
            'Not found',
        'path' =>
            $path,
        'method' =>
            $method,
        'hint' =>
            'Use /api/health for API health checks.'
    ], 404);

} catch (Throwable $e) {

    error_log(
        'API ERROR: ' .
        $e->getMessage()
    );

    $isHealth =
        (
            $path === '/api' ||
            $path === '/api/' ||
            $path === '/api/health' ||
            $path === '/api/index.php'
        );

    if ($isHealth) {

        out([
            'ok' => false,
            'error' =>
                'Database connection failed',
            'detail' =>
                'The API is reachable, but PHP could not connect to PostgreSQL. Check the DB_* environment variables and database status.',
            'php' =>
                PHP_VERSION
        ], 500);
    }

    if (
        !empty(
            $_SESSION['manager_id']
        )
    ) {

        out([
            'error' =>
                'Server error',
            'code' =>
                'API_SERVER_ERROR',
            'detail' =>
                'Database/API query failed: ' .
                $e->getMessage()
        ], 500);
    }

    out([
        'error' =>
            'Server error',
        'code' =>
            'API_SERVER_ERROR',
        'detail' =>
            'The API was reached but could not complete the request. Open /api/health to diagnose the server.'
    ], 500);
}
