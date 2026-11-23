<?php
/** Old endpoint kept for compatibility — the bell now uses api/notifications.php. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_role(ROLE_CHECKIN);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$n = staff_notifications()['checkin'];
echo json_encode(['count' => $n['count'], 'passengers' => $n['items']]);
