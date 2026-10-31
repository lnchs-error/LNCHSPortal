<?php
require __DIR__ . '/config.php';
$me = require_staff($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_tag') {
        $who = strtolower(trim($_POST['who'] ?? '')); $uidv = strtolower(trim($_POST['uid'] ?? ''));
        $label = ($_POST['label'] ?? '') === 'tag' ? 'tag' : 'card';
        $digits = preg_replace('/\D/', '', $who);
        $s = $conn->prepare("SELECT id, full_name FROM users WHERE lrn = ? OR LOWER(employee_id) = ? OR employee_num = ? LIMIT 2");
        $s->bind_param("sss", $who, $who, $digits); $s->execute(); $found = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!preg_match('/^[a-z0-9]{4,32}$/', $uidv)) { flash('error', 'The card or tag code must be 4 to 32 letters or numbers. Scan it again.'); }
        elseif (strlen($uidv) === 12 && ctype_digit($uidv)) { flash('error', 'That code looks like an LRN, so it cannot be used as a card code.'); }
        elseif (count($found) !== 1) { flash('error', count($found) ? 'More than one person matches. Use the LRN or the full employee ID.' : 'No account matches that LRN or employee ID.'); }
        else {
            $c = $conn->prepare("SELECT id FROM users WHERE employee_num = ? OR lrn = ?"); $c->bind_param("ss", $uidv, $uidv); $c->execute();
            if ($c->get_result()->num_rows) { flash('error', 'That code equals someone\'s employee number or LRN, so it would confuse the keypad.'); }
            else {
                $i = $conn->prepare("INSERT INTO rfid_tags (user_id, uid, label) VALUES (?,?,?)");
                $i->bind_param("iss", $found[0]['id'], $uidv, $label);
                if ($i->execute()) flash('success', ucfirst($label) . ' registered for ' . $found[0]['full_name'] . '.');
                else flash('error', 'That card or tag is already registered.');
            }
        }
    } elseif ($action === 'delete_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("DELETE FROM rfid_tags WHERE id = ?"); $s->bind_param("i", $id); $s->execute();
        flash('success', 'Removed.');
    }
    redirect('manage_tags.php');
}
$flash = flash();
$tags = $conn->query("SELECT t.id, t.uid, t.label, t.created_at, u.full_name, u.role FROM rfid_tags t JOIN users u ON u.id = t.user_id ORDER BY t.id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$scans = $conn->query("SELECT l.gate, l.method, l.result, l.scanned_at, u.full_name FROM scan_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 40")->fetch_all(MYSQLI_ASSOC);
$devs = $conn->query("SELECT gate, last_seen FROM kiosk_devices WHERE active = 1 ORDER BY gate")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cards, tags and scan log | LNCHS staff</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">
    <div class="topbar"><h2>Cards, tags and scan log</h2><a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--staff)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>

    <div class="plain">
        <h4 style="margin-bottom:10px">Register a card or tag</h4>
        <form method="POST" action="manage_tags.php" id="tagForm"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_tag">
            <div class="row">
                <div class="fg"><label>Student LRN or employee ID</label><input type="text" name="who" id="who" class="fc" maxlength="50" autocomplete="off" required></div>
                <div class="fg"><label>Type</label><select name="label" class="fc"><option value="card">Card</option><option value="tag">Tag</option></select></div>
            </div>
            <div class="fg"><label>Card or tag code</label><input type="text" name="uid" id="uid" class="fc" maxlength="32" autocomplete="off" placeholder="Click here, then tap the card or tag on the reader" required>
                <p class="hint">The reader types the code and presses Enter, which saves it. One person can have both a card and a tag.</p></div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Register</button>
        </form>
    </div>

    <div class="plain">
        <h4 style="margin-bottom:10px">Gates</h4>
        <?php if (!$devs): ?><p class="muted" style="font-size:13px">No gate is paired yet. Open kiosk_setup.php on each mini PC.</p><?php endif; ?>
        <div class="grid2">
        <?php foreach ($devs as $d): $online = $d['last_seen'] && strtotime($d['last_seen']) > time() - 150; ?>
            <div style="font-size:13px;padding:10px;border:1px solid var(--line);border-radius:8px">
                <strong><?php echo e(GATES[$d['gate']] ?? ('Gate ' . $d['gate'])); ?></strong>
                <div style="color:<?php echo $online ? '#059669' : '#DC2626'; ?>;font-size:12px"><?php echo $online ? 'Online' : 'No contact since ' . e($d['last_seen'] ? date('M j, g:i A', strtotime($d['last_seen'])) : 'ever'); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <div class="plain">
        <h4 style="margin-bottom:10px">Registered cards and tags</h4>
        <div class="tbl" style="margin-bottom:0"><table>
            <thead><tr><th>Person</th><th>Type</th><th>Code</th><th>Added</th><th></th></tr></thead><tbody>
            <?php if (!$tags): ?><tr><td colspan="5" class="muted" style="text-align:center">None registered yet.</td></tr><?php endif; ?>
            <?php foreach ($tags as $t): ?>
                <tr><td><?php echo e($t['full_name']); ?></td><td><?php echo e($t['label']); ?></td><td><?php echo e(str_repeat('•', max(0, strlen($t['uid']) - 4)) . substr($t['uid'], -4)); ?></td><td><?php echo e(date('M j, Y', strtotime($t['created_at']))); ?></td>
                    <td><form method="POST" action="manage_tags.php" onsubmit="return confirm('Remove this card or tag?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_tag"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><button class="link-del">Remove</button></form></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </div>

    <div class="plain">
        <h4 style="margin-bottom:10px">Latest 40 scans (all gates)</h4>
        <div class="tbl" style="margin-bottom:0;max-height:320px"><table>
            <thead><tr><th>Time</th><th>Gate</th><th>Person</th><th>How</th><th>Result</th></tr></thead><tbody>
            <?php if (!$scans): ?><tr><td colspan="5" class="muted" style="text-align:center">No scans yet.</td></tr><?php endif; ?>
            <?php foreach ($scans as $r): ?>
                <tr><td><?php echo e(date('M j, g:i:s A', strtotime($r['scanned_at']))); ?></td><td><?php echo e(GATES[$r['gate']] ?? $r['gate']); ?></td><td><?php echo e($r['full_name'] ?? '-'); ?></td><td><?php echo e($r['method']); ?></td><td><?php echo e($r['result']); ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </div>
</div>
<script>
document.getElementById('who').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('uid').focus(); } });
</script>
</body>
</html>
