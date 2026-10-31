<?php
require __DIR__ . '/config.php';
$me = require_login($conn);
$forced = (int)$me['must_change_password'] === 1;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'logout') { remember_clear($conn); $_SESSION = []; session_destroy(); redirect('signin.php'); }
    $cur = $_POST['current_password'] ?? ''; $new = $_POST['new_password'] ?? ''; $new2 = $_POST['confirm_password'] ?? '';
    $s = $conn->prepare("SELECT password FROM users WHERE id = ?"); $s->bind_param("i", $me['id']); $s->execute();
    $row = $s->get_result()->fetch_assoc();
    if (!$row || !password_verify($cur, $row['password'])) $error = 'Your current password is incorrect.';
    elseif (!valid_password($new)) $error = 'The new password must be 8 to 72 characters.';
    elseif ($new !== $new2) $error = 'The new passwords do not match.';
    elseif (hash_equals($cur, $new)) $error = 'Choose a password different from the current one.';
    else {
        $h = password_hash($new, PASSWORD_BCRYPT);
        $u = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?"); $u->bind_param("si", $h, $me['id']); $u->execute();
        remember_clear($conn, (int)$me['id']);   // other remembered devices must sign in again
        flash('success', 'Your password was changed.');
        redirect('inter.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change password | LNCHS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="bg">
<div class="wrap"><div class="card">
    <div class="brand"><img src="<?php echo e(LOGO_URL); ?>" alt="LNCHS logo"><h2>Change password</h2><p><?php echo $forced ? 'You must choose a new password before continuing.' : e($me['full_name']); ?></p></div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($forced): ?><div class="alert alert-info">Type the temporary password you were given as the current password.</div><?php endif; ?>
    <form method="POST" action="change_password.php"><?php echo csrf_field(); ?>
        <div class="fg"><label>Current password</label><input type="password" name="current_password" class="fc" required autocomplete="current-password"></div>
        <div class="fg"><label>New password (8+ characters)</label><input type="password" name="new_password" class="fc" required autocomplete="new-password"></div>
        <div class="fg"><label>Confirm new password</label><input type="password" name="confirm_password" class="fc" required autocomplete="new-password"></div>
        <button type="submit" class="btn btn-primary">Save new password</button>
    </form>
    <form method="POST" action="change_password.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="logout">
        <button type="submit" class="btn btn-sm" style="width:100%;background:#F1F5F9;color:var(--dark);margin:0;padding:10px"><?php echo $forced ? 'Sign out' : 'Cancel and sign out'; ?></button></form>
    <?php if (!$forced): ?><p style="text-align:center;margin-top:12px"><a href="inter.php" style="font-size:13px;color:var(--primary)">&larr; Back to portal</a></p><?php endif; ?>
</div></div>
</body>
</html>
