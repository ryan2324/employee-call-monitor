<?php
declare(strict_types=1);
// Root entry point: never expose the dashboard to unauthenticated visitors.
// The dashboard itself also verifies the session through the API.
session_name('employee_monitor_manager');
session_start();
if (!empty($_SESSION['manager_id'])) {
    header('Location: dashboard.html', true, 302);
    exit;
}
header('Location: login.html', true, 302);
exit;
