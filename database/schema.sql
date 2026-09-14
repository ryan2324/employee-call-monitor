-- Employee Call Monitor - InfinityFree deployment schema
-- Import once in phpMyAdmin. Do NOT run this from normal API requests.

CREATE TABLE IF NOT EXISTS managers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 full_name VARCHAR(160) NOT NULL,
 role ENUM('ADMIN','MANAGER') NOT NULL DEFAULT 'MANAGER',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(160) NOT NULL,
 department VARCHAR(120) DEFAULT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 employee_id BIGINT UNSIGNED NOT NULL,
 device_id VARCHAR(190) NOT NULL UNIQUE,
 model VARCHAR(190) DEFAULT NULL,
 battery_level INT DEFAULT NULL,
 last_seen DATETIME DEFAULT NULL,
 call_state ENUM('READY','IN_CALL','IDLE','OFFLINE') NOT NULL DEFAULT 'OFFLINE',
 call_started_at DATETIME DEFAULT NULL,
 state_changed_at DATETIME DEFAULT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id), KEY idx_devices_employee(employee_id), KEY idx_devices_seen(last_seen),
 CONSTRAINT fk_devices_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS join_tokens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 token VARCHAR(128) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 created_by BIGINT UNSIGNED DEFAULT NULL,
 used_at DATETIME DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_join_tokens_expires(expires_at),
 CONSTRAINT fk_join_tokens_manager FOREIGN KEY(created_by) REFERENCES managers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 employee_id BIGINT UNSIGNED NOT NULL,
 device_id VARCHAR(190) NOT NULL,
 android_call_log_id BIGINT UNSIGNED DEFAULT NULL,
 direction ENUM('INCOMING','OUTGOING','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
 contact_number VARCHAR(80) DEFAULT NULL,
 result ENUM('CONNECTED','NOT_CONNECTED','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
 started_at DATETIME NOT NULL,
 ended_at DATETIME DEFAULT NULL,
 duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_device_android_call(device_id,android_call_log_id),
 KEY idx_calls_employee_time(employee_id,started_at), KEY idx_calls_time(started_at), KEY idx_calls_device(device_id), KEY idx_calls_result(result),
 CONSTRAINT fk_call_history_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_employee_stats (
 employee_id BIGINT UNSIGNED NOT NULL,
 stat_date DATE NOT NULL,
 calls_today INT UNSIGNED NOT NULL DEFAULT 0,
 successful_calls_today INT UNSIGNED NOT NULL DEFAULT 0,
 unsuccessful_calls_today INT UNSIGNED NOT NULL DEFAULT 0,
 talk_seconds_today INT UNSIGNED NOT NULL DEFAULT 0,
 idle_seconds_today INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(employee_id,stat_date),
 CONSTRAINT fk_daily_stats_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warning_commands (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, device_id VARCHAR(190) NOT NULL,
 command_type VARCHAR(40) NOT NULL DEFAULT 'IDLE_WARNING', message VARCHAR(255) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, delivered_at DATETIME DEFAULT NULL, acknowledged_at DATETIME DEFAULT NULL,
 PRIMARY KEY(id), KEY idx_warning_device(device_id,delivered_at), KEY idx_warning_employee(employee_id,created_at),
 CONSTRAINT fk_warning_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, manager_id BIGINT UNSIGNED DEFAULT NULL, action VARCHAR(100) NOT NULL,
 details TEXT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_audit_time(created_at), KEY idx_audit_manager(manager_id),
 CONSTRAINT fk_audit_logs_manager FOREIGN KEY(manager_id) REFERENCES managers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- After importing, create your manager using setup.php or: 
-- php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
