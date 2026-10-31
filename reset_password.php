<?php
require __DIR__ . '/config.php';
$me = require_staff($conn);
$uid = (int)$me['id']; $is_admin = $me['role'] === 'admin';

function temp_password() {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; $p = '';
    for ($i = 0; $i < 10; $i++) $p .= $chars[random_int(0, strlen($chars) - 1)];
    return $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $who = strtolower(trim($_POST['who'] ?? '')); $digits = preg_replace('/\D/', '', $who);
    $s = $conn->prepare("SELECT id, full_name, role, lrn, username, email, employee_id FROM users WHERE lrn = ? OR LOWER(employee_id) = ? OR employee_num = ? LIMIT 2");
    $s->bind_param("sss", $who, $who, $digits); $s->execute(); $found = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($who === '') flash('error', 'Enter the LRN or employee ID.');
    elseif (count($found) !== 1) flash('error', count($found) ? 'More than one account matches. Use the LRN or the full employee ID.' : 'No account matches that LRN or employee ID.');
    elseif ((int)$found[0]['id'] === $uid) flash('error', 'Use Settings > Change password for your own account.');
    elseif ($found[0]['role'] !== 'student' && !$is_admin) flash('error', 'Only an administrator can reset a staff password.');
    else {
        $t = $found[0]; $pw = temp_password(); $h = password_hash($pw, PASSWORD_BCRYPT);
        $u = $conn->prepare("UPDATE users SET password = ?, must_change_password = 1, pin_failed_count = 0, pin_locked_until = NULL WHERE id = ?");
        $u->bind_param("si", $h, $t['id']); $u->execute();
        remember_clear($conn, (int)$t['id']);
        $idents = array_filter([strtolower((string)$t['lrn']), strtolower((string)$t['username']), strtolower((string)$t['email']), strtolower((string)$t['employee_id'])]);
        $d = $conn->prepare("DELETE FROM login_attempts WHERE ident = ?");
        foreach ($idents as $id) { $d->bind_param("s", $id); $d->execute(); }
        $l = $conn->prepare("INSERT INTO password_resets (user_id, reset_by) VALUES (?, ?)"); $l->bind_param("ii", $t['id'], $uid); $l->execute();
        flash('success', 'Temporary password for ' . $t['full_name'] . ': ' . $pw . '  (shown once; they must choose a new one when they sign in)');
    }
    redirect('reset_password.php');
}
$flash = flash();
$log = $conn->query("SELECT r.created_at, u.full_name AS target, b.full_name AS byname FROM password_resets r JOIN users u ON u.id = r.user_id LEFT JOIN users b ON b.id = r.reset_by ORDER BY r.id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset a password | LNCHS staff</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">
    <div class="topbar"><h2>Reset a password</h2><a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--staff)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>" <?php echo $flash[0] === 'success' ? 'style="font-size:15px"' : ''; ?>><?php echo e($flash[1]); ?></div><?php endif; ?>
    <div class="plain">
        <p class="hint" style="margin-bottom:12px">Check who the person is first (for example their school ID). This creates a temporary password, shows it to you once, and forces them to choose a new one at their next sign-in. It also clears any lockout. Resets are logged below.<?php echo $is_admin ? '' : ' Teachers can reset student accounts. An administrator is needed for staff accounts.'; ?></p>
        <form method="POST" action="reset_password.php" onsubmit="return confirm('Create a new temporary password for this account?')"><?php echo csrf_field(); ?>
            <div class="fg"><label>Student LRN or employee ID</label><input type="text" name="who" class="fc" maxlength="50" autocomplete="off" required></div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Create temporary password</button>
        </form>
    </div>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:10px">Recent resets</h3>
        <div class="tbl" style="margin:0"><table><thead><tr><th>When</th><th>Account</th><th>Reset by</th></tr></thead><tbody>
            <?php if (!$log): ?><tr><td colspan="3" class="muted" style="text-align:center">None yet.</td></tr><?php endif; ?>
            <?php foreach ($log as $r): ?><tr><td><?php echo e(date('M j, g:i A', strtotime($r['created_at']))); ?></td><td><?php echo e($r['target']); ?></td><td><?php echo e($r['byname'] ?? '-'); ?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
</div>
</body>
</html>
