<?php

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'service' => 'employee-call-monitor-api',
    'message' => 'API is running',
    'health' => '/api/health'
]);
