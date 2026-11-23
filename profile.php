<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/flash.php';
require_login();

$page_title = 'My Profile';
$db  = db();
$uid = (int)$_SESSION['user_id'];

function pf_load(int $uid): ?array {
    $st = db()->prepare('SELECT u.user_id, u.username, u.full_name, u.email, u.role, u.last_login, u.created_at, u.password_hash,
            p.port_name, p.port_code
        FROM user u LEFT JOIN port p ON p.port_id = u.port_id WHERE u.user_id = ?');
    $st->bind_param('i', $uid); $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}
$profile = pf_load($uid);
$errors = []; $form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $profile) {
    $form = $_POST['form'] ?? '';
    $current = str_param($_POST['current_password'] ?? '');
    if (!password_verify($current, $profile['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    } elseif ($form === 'password') {
        $new = str_param($_POST['new_password'] ?? '');
        $confirm = str_param($_POST['confirm_password'] ?? '');
        if (strlen($new) < 8) $errors[] = 'The new password must be at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'The new passwords do not match.';
        if ($new !== '' && password_verify($new, $profile['password_hash'])) $errors[] = 'The new password must be different from the current one.';
        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $st = $db->prepare('UPDATE user SET password_hash=? WHERE user_id=?');
            $st->bind_param('si', $hash, $uid); $st->execute();
            session_regenerate_id(true);
            audit('Change own password', 'user', $uid);
            set_flash('success', 'Password changed.');
            header('Location: ' . BASE_URL . '/profile.php'); exit;
        }
    } elseif ($form === 'email') {
        $email = trim(str_param($_POST['email'] ?? ''));
        if ($email !== '' && (!valid_email($email) || mb_strlen($email) > 100)) $errors[] = 'Enter a valid email address (or leave it blank to remove it).';
        if (!$errors) {
            $email = $email ?: null;
            $st = $db->prepare('UPDATE user SET email=? WHERE user_id=?');
            $st->bind_param('si', $email, $uid); $st->execute();
            audit('Change own email', 'user', $uid, ['email' => $profile['email']], ['email' => $email]);
            set_flash('success', $email ? 'Email address saved.' : 'Email address removed.');
            header('Location: ' . BASE_URL . '/profile.php'); exit;
        }
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="main-content">
  <?php show_flash(); ?>
  <div class="page-header"><i class="bi bi-person-circle fs-3 text-primary"></i><div><h4>My Profile</h4><div class="text-muted small">Your account, email and password</div></div></div>

  <?php if (!$profile): ?>
    <div class="alert alert-warning">Your account record could not be found. Please <a href="<?= e(BASE_URL) ?>/login.php">log in again</a> or contact the administrator.</div>
  <?php else: ?>
  <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div><?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="quick-panel text-center">
        <span class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width:72px;height:72px;font-size:2rem"><i class="bi bi-person-fill"></i></span>
        <h5 class="mb-0"><?= e($profile['full_name']) ?></h5>
        <div class="text-muted small">@<?= e($profile['username']) ?></div>
        <span class="badge bg-primary mt-1"><?= e($profile['role']) ?></span>
        <table class="table table-sm small text-start mt-3 mb-0">
          <tr><th class="text-muted fw-normal">Email</th><td><?= $profile['email'] ? e($profile['email']) : '<span class="text-warning-emphasis">not set</span>' ?></td></tr>
          <tr><th class="text-muted fw-normal">Port</th><td><?= e($profile['port_name'] ?? '—') ?></td></tr>
          <tr><th class="text-muted fw-normal">Last login</th><td><?= $profile['last_login'] ? e(date('M j, Y g:i A', strtotime($profile['last_login']))) : '—' ?></td></tr>
          <tr><th class="text-muted fw-normal">Member since</th><td><?= $profile['created_at'] ? e(date('M j, Y', strtotime($profile['created_at']))) : '—' ?></td></tr>
        </table>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="quick-panel">
        <h6 class="fw-bold"><i class="bi bi-envelope me-1"></i>Email address</h6>
        <p class="small text-muted">Used for system notices and password help.</p>
        <form method="post" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="form" value="email">
          <div class="col-12"><label class="form-label small">Email</label><input type="email" name="email" class="form-control" maxlength="100" value="<?= e($form === 'email' ? ($_POST['email'] ?? '') : $profile['email']) ?>"></div>
          <div class="col-12"><label class="form-label small">Current password (to confirm)</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
          <div class="col-12"><button class="btn btn-primary btn-sm">Save email</button></div>
        </form>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="quick-panel">
        <h6 class="fw-bold"><i class="bi bi-key me-1"></i>Change password</h6>
        <form method="post" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="form" value="password">
          <div class="col-12"><label class="form-label small">Current password</label><input type="password" name="current_password" class="form-control" required autocomplete="current-password"></div>
          <div class="col-12"><label class="form-label small">New password</label><input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password"><div class="form-text">At least 8 characters.</div></div>
          <div class="col-12"><label class="form-label small">Confirm new password</label><input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password"></div>
          <div class="col-12"><button class="btn btn-primary btn-sm">Change password</button></div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
<!-- /.main-content is closed by footer.php -->
<?php include __DIR__ . '/includes/footer.php'; ?>
