<?php
require __DIR__ . '/config.php';
$me = require_login($conn);
$uid = (int)$me['id'];
$is_staff = is_staff_role($me['role']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a = $_POST['action'] ?? '';
    if (!$is_staff) { flash('error', 'Only teachers and staff can change classroom officers.'); }
    elseif ($a === 'assign_officer') {
        $lrn = trim($_POST['lrn'] ?? ''); $office = trim($_POST['office'] ?? '');
        if (!preg_match('/^[0-9]{12}$/', $lrn)) flash('error', 'LRN must be exactly 12 digits.');
        elseif (!in_array($office, OFFICER_POSITIONS, true)) flash('error', 'Choose an office from the list.');
        else {
            $s = $conn->prepare("SELECT id, full_name, grade_level, section FROM users WHERE lrn = ? AND role = 'student'");
            $s->bind_param("s", $lrn); $s->execute(); $stu = $s->get_result()->fetch_assoc();
            if (!$stu) flash('error', 'No student account has that LRN.');
            elseif (trim((string)$stu['grade_level']) === '' || trim((string)$stu['section']) === '') flash('error', 'That student has no grade level or section on the account.');
            else {
                $g = strtolower(trim($stu['grade_level'])); $sec = strtolower(trim($stu['section']));
                if (!in_array($office, OFFICER_MULTI, true)) {   // one holder per office per class
                    $d = $conn->prepare("DELETE o FROM class_officers o JOIN users u ON u.id = o.user_id WHERE o.office = ? AND LOWER(TRIM(u.grade_level)) = ? AND LOWER(TRIM(u.section)) = ?");
                    $d->bind_param("sss", $office, $g, $sec); $d->execute();
                }
                $i = $conn->prepare("INSERT INTO class_officers (user_id, office, assigned_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE office = VALUES(office), assigned_by = VALUES(assigned_by), created_at = NOW()");
                $i->bind_param("isi", $stu['id'], $office, $uid);
                if ($i->execute()) flash('success', $stu['full_name'] . ' is now ' . $office . ' of ' . trim($stu['grade_level']) . ' - ' . trim($stu['section']) . '.');
                else flash('error', 'Could not save the officer.');
            }
        }
    } elseif ($a === 'remove_officer') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("DELETE FROM class_officers WHERE id = ?"); $s->bind_param("i", $id); $s->execute();
        flash('success', 'Officer removed.');
    }
    redirect('officers.php');
}
$flash = flash();

if ($is_staff) {
    $all = $conn->query("SELECT o.id, o.office, u.full_name, u.lrn, u.grade_level, u.section FROM class_officers o JOIN users u ON u.id = o.user_id LIMIT 1000")->fetch_all(MYSQLI_ASSOC);
} elseif (trim((string)$me['grade_level']) !== '' && trim((string)$me['section']) !== '') {
    $g = strtolower(trim($me['grade_level'])); $sec = strtolower(trim($me['section']));
    $s = $conn->prepare("SELECT o.id, o.office, u.full_name, u.lrn, u.grade_level, u.section FROM class_officers o JOIN users u ON u.id = o.user_id WHERE LOWER(TRIM(u.grade_level)) = ? AND LOWER(TRIM(u.section)) = ?");
    $s->bind_param("ss", $g, $sec); $s->execute(); $all = $s->get_result()->fetch_all(MYSQLI_ASSOC);
} else { $all = []; }

$groups = [];
foreach ($all as $r) {
    $k = strtolower(trim($r['grade_level'])) . '|' . strtolower(trim($r['section']));
    if (!isset($groups[$k])) $groups[$k] = ['label' => trim($r['grade_level']) . ' - ' . trim($r['section']), 'grade' => (int)preg_replace('/\D/', '', $r['grade_level']), 'sec' => strtolower(trim($r['section'])), 'rows' => []];
    $groups[$k]['rows'][] = $r;
}
uasort($groups, function ($x, $y) { return [$x['grade'], $x['sec']] <=> [$y['grade'], $y['sec']]; });
foreach ($groups as $k => $gr) {
    usort($groups[$k]['rows'], function ($x, $y) {
        return [array_search($x['office'], OFFICER_POSITIONS), $x['full_name']] <=> [array_search($y['office'], OFFICER_POSITIONS), $y['full_name']];
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Classroom officers | LNCHS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
details.plain summary{cursor:pointer;font-weight:700;font-size:15px;list-style:none;display:flex;justify-content:space-between;gap:10px}
details.plain summary::-webkit-details-marker{display:none}
details.plain[open] summary{margin-bottom:12px}
</style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:12px">
            <img src="<?php echo e(LOGO_URL); ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover">
            <div><h2 style="font-size:22px">Classroom officers</h2><p style="font-size:12px" class="muted"><?php echo $is_staff ? 'All classes' : 'Your class'; ?></p></div>
        </div>
        <a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--primary)">&larr; Back to portal</a>
    </div>

    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>

    <?php if ($is_staff): ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:4px">Assign an officer</h3>
        <p class="hint" style="margin-bottom:12px">The class comes from the student's account. Offices with one holder (like President) replace the current holder. A student can hold one office, so assigning again moves them.</p>
        <form method="POST" action="officers.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="assign_officer">
            <div class="row">
                <div class="fg"><label>Student LRN</label><input type="text" name="lrn" class="fc" pattern="[0-9]{12}" maxlength="12" inputmode="numeric" required></div>
                <div class="fg"><label>Office</label><select name="office" class="fc" required><option value="">Select office</option><?php foreach (OFFICER_POSITIONS as $p): ?><option><?php echo e($p); ?></option><?php endforeach; ?></select></div>
            </div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Save officer</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$groups): ?>
        <div class="plain muted" style="text-align:center">
            <?php if (!$is_staff && trim((string)$me['grade_level']) === ''): ?>Your account has no grade level or section yet.
            <?php else: ?>No officers have been set<?php echo $is_staff ? ' yet. Assign the first one above.' : ' for your class yet. Your adviser can add them.'; ?><?php endif; ?>
        </div>
    <?php else: foreach ($groups as $gr): ?>
        <details class="plain" <?php echo (!$is_staff || count($groups) === 1) ? 'open' : ''; ?>>
            <summary><span><?php echo e($gr['label']); ?></span><span class="muted" style="font-weight:500;font-size:12px"><?php echo count($gr['rows']); ?> officer<?php echo count($gr['rows']) === 1 ? '' : 's'; ?></span></summary>
            <div class="tbl" style="margin-bottom:0;max-height:none"><table>
                <thead><tr><th>Office</th><th>Name</th><?php if ($is_staff): ?><th>LRN</th><th></th><?php endif; ?></tr></thead><tbody>
                <?php foreach ($gr['rows'] as $r): ?>
                    <tr><td><strong><?php echo e($r['office']); ?></strong></td><td><?php echo e($r['full_name']); ?></td>
                    <?php if ($is_staff): ?><td><?php echo e($r['lrn']); ?></td>
                        <td><form method="POST" action="officers.php" onsubmit="return confirm('Remove this officer?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="remove_officer"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="link-del">Remove</button></form></td><?php endif; ?></tr>
                <?php endforeach; ?></tbody></table></div>
        </details>
    <?php endforeach; endif; ?>
</div>
</body>
</html>
