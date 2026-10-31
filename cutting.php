<?php
require __DIR__ . '/config.php';
$me = require_staff($conn);
$uid = (int)$me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a = $_POST['action'] ?? '';
    if ($a === 'add_cut') {
        $lrn = trim($_POST['lrn'] ?? ''); $date = trim($_POST['cut_date'] ?? ''); $period = trim($_POST['period'] ?? '');
        $subject = trim($_POST['subject'] ?? ''); $note = trim($_POST['note'] ?? '');
        $today = date('Y-m-d');
        if (!preg_match('/^[0-9]{12}$/', $lrn)) flash('error', 'LRN must be exactly 12 digits.');
        elseif (!valid_date($date) || $date > $today) flash('error', 'Choose today or an earlier date.');
        elseif ($date < date('Y-m-d', strtotime('-60 days'))) flash('error', 'You can only record incidents from the last 60 days.');
        elseif ((int)date('N', strtotime($date)) >= 6) flash('error', 'That date is a weekend.');
        elseif ($period === '' || mb_strlen($period) > 60 || mb_strlen($subject) > 100 || mb_strlen($note) > 255) flash('error', 'Enter the period (up to 60 characters). Subject is up to 100 and the note up to 255.');
        else {
            $s = $conn->prepare("SELECT id, full_name FROM users WHERE lrn = ? AND role = 'student'"); $s->bind_param("s", $lrn); $s->execute();
            $stu = $s->get_result()->fetch_assoc();
            if (!$stu) flash('error', 'No student account has that LRN.');
            else {
                $i = $conn->prepare("INSERT INTO class_cuts (user_id, cut_date, period, subject, note, reported_by) VALUES (?,?,?,?,?,?)");
                $i->bind_param("issssi", $stu['id'], $date, $period, $subject, $note, $uid);
                if ($i->execute()) flash('success', 'Recorded for ' . $stu['full_name'] . '. It will appear in that month\'s report.');
                else flash('error', 'That student already has this period recorded for that day.');
            }
        }
    } elseif ($a === 'delete_cut') {
        $id = (int)($_POST['id'] ?? 0); $admin = $me['role'] === 'admin' ? 1 : 0;
        $s = $conn->prepare("DELETE FROM class_cuts WHERE id = ? AND (reported_by = ? OR ? = 1)"); $s->bind_param("iii", $id, $uid, $admin); $s->execute();
        flash($s->affected_rows ? 'success' : 'error', $s->affected_rows ? 'Record removed.' : 'You can only remove records you entered.');
    }
    redirect('cutting.php');
}
$flash = flash();
$list = $conn->query("SELECT c.id, c.cut_date, c.period, c.subject, c.note, c.reported_by, u.full_name, u.lrn, u.grade_level, u.section, r.full_name AS rname
                      FROM class_cuts c JOIN users u ON u.id = c.user_id LEFT JOIN users r ON r.id = c.reported_by ORDER BY c.cut_date DESC, c.id DESC LIMIT 60")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cutting class records | LNCHS staff</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">
    <div class="topbar"><h2>Cutting class records</h2><a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--staff)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:4px">Record a student caught cutting class</h3>
        <p class="hint" style="margin-bottom:12px">These records show up in the adviser's class attendance report.</p>
        <form method="POST" action="cutting.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_cut">
            <div class="row">
                <div class="fg"><label>Student LRN</label><input type="text" name="lrn" class="fc" pattern="[0-9]{12}" maxlength="12" inputmode="numeric" required></div>
                <div class="fg"><label>Date</label><input type="date" name="cut_date" class="fc" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Period or time</label><input type="text" name="period" class="fc" maxlength="60" placeholder="9:20-10:20" required></div>
                <div class="fg"><label>Subject (optional)</label><input type="text" name="subject" class="fc" maxlength="100" placeholder="Math"></div>
            </div>
            <div class="fg"><label>Note (optional)</label><input type="text" name="note" class="fc" maxlength="255" placeholder="Where the student was found"></div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Save record</button>
        </form>
    </div>
    <h3 style="margin-bottom:12px">Latest records</h3>
    <div class="plain" style="padding:0"><div class="tbl" style="margin:0;border:0;max-height:480px"><table>
        <thead><tr><th>Date</th><th>Student</th><th>Class</th><th>Period</th><th>Subject</th><th>Note</th><th>By</th><th></th></tr></thead><tbody>
        <?php if (!$list): ?><tr><td colspan="8" class="muted" style="text-align:center">No records yet.</td></tr><?php endif; ?>
        <?php foreach ($list as $r): ?>
            <tr><td><?php echo e(date('M j, Y', strtotime($r['cut_date']))); ?></td><td><?php echo e($r['full_name']); ?></td><td><?php echo e(trim($r['grade_level'] . ' - ' . $r['section'], ' -')); ?></td>
                <td><?php echo e($r['period']); ?></td><td><?php echo e($r['subject']); ?></td><td><?php echo e($r['note']); ?></td><td><?php echo e($r['rname'] ?? '-'); ?></td>
                <td><?php if ((int)$r['reported_by'] === $uid || $me['role'] === 'admin'): ?><form method="POST" action="cutting.php" onsubmit="return confirm('Remove this record?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_cut"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="link-del">Remove</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
</div>
</body>
</html>
