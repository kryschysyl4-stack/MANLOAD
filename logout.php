<?php
require_once __DIR__ . '/config/auth.php';

session_start_once();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!empty($_SESSION['user_id'])) audit('Logout', 'user', (int)$_SESSION['user_id']);
    $_SESSION = [];
    session_destroy();
}
header('Location: ' . BASE_URL . '/login.php');
exit;
