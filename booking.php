<?php
// Old public booking page — replaced by the step-by-step online booking.
require_once __DIR__ . '/config/app.php';
header('Location: ' . BASE_URL . '/modules/booking/index.php', true, 301);
exit;
