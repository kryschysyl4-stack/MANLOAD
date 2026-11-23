<?php
function set_flash(string $type, string $msg): void {
    session_start_once();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function show_flash(): void {
    session_start_once();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $cls = match($f['type']) {
            'success' => 'alert-success',
            'error'   => 'alert-danger',
            'warning' => 'alert-warning',
            default   => 'alert-info',
        };
        echo "<div class=\"alert $cls alert-dismissible alert-auto-dismiss\">"
           . htmlspecialchars($f['msg'])
           . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}
