<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'service' => 'employee-call-monitor-api',
    'message' => 'API is running. Use /api/health for the health check.'
], JSON_UNESCAPED_SLASHES);
