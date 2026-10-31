<?php
// Pair this mini PC to a gate. Needs the setup code from config.php (KIOSK_SETUP_CODE).
define('NO_SESSION', true);
require __DIR__ . '/config.php';

$msg = ''; $ok = false;
if (!configured(KIOSK_SETUP_CODE)) exit('Set KIOSK_SETUP_CODE in config.php first.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = 'kioskcode:' . ($_SERVER['REMOTE_ADDR'] ?? '');
    $gate = (int)($_POST['gate'] ?? 0);
    if (login_blocked($conn, $ident)) {
        $msg = 'Too many attempts. Wait 15 minutes.';
    } elseif (!hash_equals(KIOSK_SETUP_CODE, (string)($_POST['code'] ?? ''))) {
        login_fail($conn, $ident); sleep(2); $msg = 'Wrong setup code.';
    } elseif (!isset(GATES[$gate])) {
        $msg = 'Choose a gate.';
    } else {
        $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token);
        $off = $conn->prepare("UPDATE kiosk_devices SET active = 0 WHERE gate = ?"); $off->bind_param("i", $gate); $off->execute();   // a swapped mini PC replaces the old one
        $i = $conn->prepare("INSERT INTO kiosk_devices (token_hash, gate, last_seen) VALUES (?, ?, NOW())"); $i->bind_param("si", $hash, $gate);
        if ($i->execute()) {
            setcookie('kiosk_token', $token, ['expires' => time() + 31536000, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
            $ok = true; $msg = 'Paired as ' . GATES[$gate] . '. You can unplug the mouse.';
        } else { $msg = 'Could not save. Run setup.php first.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pair kiosk | LNCHS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="bg">
<div class="wrap"><div class="card">
    <div class="brand"><img src="<?php echo e(LOGO_URL); ?>" alt="LNCHS logo"><h2>Pair this kiosk to a gate</h2><p>Use a mouse for this step. It is only needed once per mini PC.</p></div>
    <?php if ($msg): ?><div class="alert <?php echo $ok ? 'alert-success' : 'alert-danger'; ?>"><?php echo e($msg); ?></div><?php endif; ?>
    <?php if ($ok): ?>
        <a href="kiosk.php" class="btn btn-primary">Open the kiosk</a>
    <?php else: ?>
    <form method="POST" action="kiosk_setup.php">
        <div class="fg"><label>Setup code</label><input type="password" name="code" class="fc" required autofocus autocomplete="off"></div>
        <div class="fg"><label>Gate</label>
            <select name="gate" class="fc" required><option value="">Choose a gate</option>
                <?php foreach (GATES as $n => $name): ?><option value="<?php echo $n; ?>"><?php echo e($name); ?></option><?php endforeach; ?>
            </select></div>
        <button type="submit" class="btn btn-primary">Pair this device</button>
    </form>
    <?php endif; ?>
</div></div>
</body>
</html>
