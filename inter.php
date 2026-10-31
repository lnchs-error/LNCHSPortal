<?php
require __DIR__ . '/config.php';
require __DIR__ . '/attlib.php';
$me = require_login($conn);
$uid = (int)$me['id'];
$is_staff = is_staff_role($me['role']);
$full_name = $me['full_name'];

function done($type, $msg) { flash($type, $msg); redirect('inter.php'); }
function start_minutes($slot) {
    $first = preg_split('/\s*(?:-|–|—|\bto\b)\s*/iu', $slot)[0];
    $t = strtotime($first);
    return $t === false ? 0 : (int)date('G', $t) * 60 + (int)date('i', $t);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d'); $now = date('H:i:s');

    if ($action === 'logout') {
        remember_clear($conn);
        $_SESSION = []; session_destroy(); redirect('signin.php');
    }

    if ($action === 'set_pin') {
        $cur = $_POST['current_password'] ?? ''; $pin = $_POST['pin'] ?? ''; $pin2 = $_POST['confirm_pin'] ?? '';
        $s = $conn->prepare("SELECT password FROM users WHERE id = ?"); $s->bind_param("i", $uid); $s->execute();
        $row = $s->get_result()->fetch_assoc();
        if (!$row || !password_verify($cur, $row['password'])) done('error', 'Your current password is incorrect.');
        if (pin_error($pin)) done('error', pin_error($pin));
        if ($pin !== $pin2) done('error', 'PINs do not match.');
        $h = password_hash($pin, PASSWORD_BCRYPT);
        $u = $conn->prepare("UPDATE users SET pin_hash = ?, pin_failed_count = 0, pin_locked_until = NULL WHERE id = ?");
        $u->bind_param("si", $h, $uid); $u->execute();
        done('success', 'Kiosk PIN saved.');
    }

    if ($action === 'accept_privacy') {
        $pv = PRIVACY_VERSION;
        $u = $conn->prepare("UPDATE users SET privacy_consent_at = NOW(), privacy_version = ? WHERE id = ?"); $u->bind_param("si", $pv, $uid); $u->execute();
        done('success', 'Thank you. Your acceptance of the Privacy Notice was saved.');
    }

    if ($action === 'add_schedule') {
        $day = trim($_POST['day_of_week'] ?? ''); $time = trim($_POST['time_slot'] ?? '');
        $subject = trim($_POST['subject_title'] ?? ''); $room = trim($_POST['room_number'] ?? '');
        if (!in_array($day, ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'], true) || $time === '' || $subject === '') done('error', 'Fill in day, time and subject.');
        if (mb_strlen($time) > 50 || mb_strlen($subject) > 100 || mb_strlen($room) > 50) done('error', 'One of the fields is too long.');
        $s = $conn->prepare("INSERT INTO schedules (user_id, day_of_week, time_slot, subject_title, room_number) VALUES (?,?,?,?,?)");
        $s->bind_param("issss", $uid, $day, $time, $subject, $room);
        done($s->execute() ? 'success' : 'error', $s->errno ? 'Could not add the schedule item.' : 'Schedule item added.');
    }

    if ($action === 'delete_schedule') {
        $sid = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("DELETE FROM schedules WHERE id = ? AND user_id = ?"); $s->bind_param("ii", $sid, $uid); $s->execute();
        done('success', 'Schedule item deleted.');
    }

    if ($action === 'issue_summon') {
        if (!$is_staff) done('error', 'Only staff can issue parent notices.');
        $lrn = trim($_POST['target_lrn'] ?? ''); $reason = trim($_POST['summon_reason'] ?? '');
        $mdate = trim($_POST['meeting_date'] ?? ''); $mtime = trim($_POST['meeting_time'] ?? '');
        $d = valid_date($mdate);
        if (!preg_match('/^[0-9]{12}$/', $lrn)) done('error', 'LRN must be exactly 12 digits.');
        if (!$d || $mdate < $today) done('error', 'Choose a meeting date from today onward.');
        if ($mtime === '' || mb_strlen($mtime) > 50) done('error', 'Enter the meeting time (up to 50 characters).');
        if ($reason === '' || mb_strlen($reason) > 1000) done('error', 'Enter the reason (up to 1000 characters).');
        $s = $conn->prepare("SELECT full_name FROM users WHERE lrn = ? AND role = 'student'"); $s->bind_param("s", $lrn); $s->execute();
        $stu = $s->get_result()->fetch_assoc();
        if (!$stu) done('error', 'No student account has that LRN. Check the number and try again.');
        $i = $conn->prepare("INSERT INTO parent_notices (student_lrn, issued_by, reason, meeting_date, meeting_time) VALUES (?,?,?,?,?)");
        $i->bind_param("sssss", $lrn, $full_name, $reason, $mdate, $mtime);
        $i->execute();
        done('success', 'Parent notice sent to ' . $stu['full_name'] . '.');
    }

    if ($action === 'resolve_notice') {
        if (!$is_staff) done('error', 'Only staff can do that.');
        $nid = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("UPDATE parent_notices SET status = 'Resolved' WHERE id = ?"); $s->bind_param("i", $nid); $s->execute();
        done('success', 'Notice marked as resolved.');
    }

    if ($action === 'submit_absence') {
        $adate = trim($_POST['absent_date'] ?? ''); $reason = trim($_POST['absence_reason'] ?? '');
        if (!valid_date($adate) || $reason === '') done('error', 'Enter the date and the reason.');
        if ($adate > date('Y-m-d', strtotime('+1 day'))) done('error', 'You can only file an absence for today or tomorrow.');
        if ((int)date('N', strtotime($adate)) >= 6) done('error', 'Saturdays and Sundays are not school days.');
        if ($adate < date('Y-m-d', strtotime('-30 days'))) done('error', 'Absence notes can only be filed for the last 30 days.');
        if (mb_strlen($reason) > 500) done('error', 'Reason is too long (500 characters max).');
        $s = $conn->prepare("SELECT id, time_in FROM attendance WHERE user_id = ? AND `date` = ?"); $s->bind_param("is", $uid, $adate); $s->execute();
        $att = $s->get_result()->fetch_assoc();
        if ($att && $att['time_in']) done('error', 'You have a check-in on that date, so it cannot be marked absent.');
        if ($att) { $u = $conn->prepare("UPDATE attendance SET status = 'Absent', absence_reason = ? WHERE id = ?"); $u->bind_param("si", $reason, $att['id']); $u->execute(); }
        else { $i = $conn->prepare("INSERT INTO attendance (user_id, `date`, status, absence_reason) VALUES (?, ?, 'Absent', ?)"); $i->bind_param("iss", $uid, $adate, $reason); $i->execute(); }
        done('success', 'Absence note submitted.');
    }

    if (in_array($action, ['attendance_checkin', 'lunch_out', 'lunch_in', 'attendance_checkout'], true)) {
        $nc = no_class_title($conn, $today);
        if ($nc !== null) done('error', 'No classes today: ' . $nc . '.');
        $s = $conn->prepare("SELECT id, time_in, lunch_out, lunch_in, time_out FROM attendance WHERE user_id = ? AND `date` = ?");
        $s->bind_param("is", $uid, $today); $s->execute(); $att = $s->get_result()->fetch_assoc();

        if ($action === 'attendance_checkin') {
            if ($att) done('error', 'Today already has a record.');
            $status = ($now > LATE_AFTER) ? 'Late' : 'Present';
            $i = $conn->prepare("INSERT IGNORE INTO attendance (user_id, `date`, time_in, status) VALUES (?,?,?,?)");
            $i->bind_param("isss", $uid, $today, $now, $status); $i->execute();
            if ($i->affected_rows < 1) done('error', 'Today already has a record.');
            done('success', 'Morning check-in recorded at ' . date('g:i A') . ($status === 'Late' ? ' (Late)' : '') . '.');
        }
        if (!$att || !$att['time_in']) done('error', 'Check in first.');
        if ($action === 'lunch_out') {
            if ($att['lunch_out'] !== null) done('error', 'Lunch out is already logged.');
            $u = $conn->prepare("UPDATE attendance SET lunch_out = ? WHERE id = ? AND lunch_out IS NULL"); $u->bind_param("si", $now, $att['id']); $u->execute();
            done('success', 'Lunch out logged at ' . date('g:i A') . '.');
        }
        if ($action === 'lunch_in') {
            if ($att['lunch_out'] === null) done('error', 'Log lunch out first.');
            if ($att['lunch_in'] !== null) done('error', 'Lunch return is already logged.');
            $u = $conn->prepare("UPDATE attendance SET lunch_in = ? WHERE id = ? AND lunch_in IS NULL"); $u->bind_param("si", $now, $att['id']); $u->execute();
            done('success', 'Lunch return logged at ' . date('g:i A') . '.');
        }
        if ($action === 'attendance_checkout') {
            if ($att['time_out'] !== null) done('error', 'Final check-out is already logged.');
            $reasons = ['Cleaners / Classroom Duty', 'Practice (Dance / Play)', 'Sports Training'];
            $why = 'Regular Dismissal';
            if ($now >= OT_FROM) { $why = $_POST['overtime_reason'] ?? ''; if (!in_array($why, $reasons, true)) $why = 'Overtime'; }
            $u = $conn->prepare("UPDATE attendance SET time_out = ?, overtime_reason = ? WHERE id = ? AND time_out IS NULL"); $u->bind_param("ssi", $now, $why, $att['id']); $u->execute();
            done('success', 'Final check-out logged at ' . date('g:i A') . '.');
        }
    }
    redirect('inter.php');
}

// ---------- page data ----------
$flash = flash();
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$absence_min = date('Y-m-d', strtotime('-30 days'));

$notices = [];
if (!$is_staff && $me['lrn']) {
    $s = $conn->prepare("SELECT * FROM parent_notices WHERE student_lrn = ? AND status = 'Pending' ORDER BY meeting_date DESC");
    $s->bind_param("s", $me['lrn']); $s->execute(); $notices = $s->get_result()->fetch_all(MYSQLI_ASSOC);
}
$issued = [];
if ($is_staff) {
    $issued = $conn->query("SELECT n.id, n.student_lrn, n.reason, n.meeting_date, n.meeting_time, n.status, u.full_name AS student_name
                            FROM parent_notices n LEFT JOIN users u ON u.lrn = n.student_lrn ORDER BY n.id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
}

$no_class_today = no_class_title($conn, $today);
$upcoming = [];
$s = @$conn->prepare("SELECT id, event_date, title, kind, no_class FROM calendar_events WHERE event_date >= ? AND event_date <= ? ORDER BY event_date, id LIMIT 40");
if ($s) { $lim = date('Y-m-d', strtotime('+45 days')); $s->bind_param("ss", $today, $lim); $s->execute(); $upcoming = array_slice(group_events($s->get_result()->fetch_all(MYSQLI_ASSOC)), 0, 4); }
$rem_due = [];
foreach (visible_reminders($conn, $me) as $r) {
    if ($r['done']) continue;
    $n = (int)round((strtotime($r['due_date']) - strtotime($today)) / 86400);
    if ($n < 0 || $n <= (int)$r['remind_days']) $rem_due[] = $r;
}
$rem_due = array_slice($rem_due, 0, 6);
$officers = [];
if (!$is_staff && trim((string)$me['grade_level']) !== '' && trim((string)$me['section']) !== '') {
    $og = strtolower(trim($me['grade_level'])); $osec = strtolower(trim($me['section']));
    $s = $conn->prepare("SELECT o.office, u.full_name FROM class_officers o JOIN users u ON u.id = o.user_id WHERE LOWER(TRIM(u.grade_level)) = ? AND LOWER(TRIM(u.section)) = ?");
    $s->bind_param("ss", $og, $osec); $s->execute(); $officers = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    usort($officers, function ($x, $y) { return array_search($x['office'], OFFICER_POSITIONS) <=> array_search($y['office'], OFFICER_POSITIONS); });
}
$s = $conn->prepare("SELECT * FROM schedules WHERE user_id = ?"); $s->bind_param("i", $uid); $s->execute();
$my_schedules = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$dayno = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
usort($my_schedules, function ($a, $b) use ($dayno) {
    $d = ($dayno[$a['day_of_week']] ?? 9) <=> ($dayno[$b['day_of_week']] ?? 9);
    return $d ?: start_minutes($a['time_slot']) <=> start_minutes($b['time_slot']);
});

$s = $conn->prepare("SELECT time_in, lunch_out, lunch_in, time_out, status FROM attendance WHERE user_id = ? AND `date` = ?");
$s->bind_param("is", $uid, $today); $s->execute(); $t = $s->get_result()->fetch_assoc();

$s = $conn->prepare("SELECT `date`, time_in, lunch_out, lunch_in, time_out, status, overtime_reason, absence_reason FROM attendance WHERE user_id = ? ORDER BY `date` DESC LIMIT 10");
$s->bind_param("i", $uid); $s->execute(); $history = $s->get_result()->fetch_all(MYSQLI_ASSOC);

$is_ot = date('H:i:s') >= OT_FROM;
function tm($v) { return $v ? date('g:i A', strtotime($v)) : null; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LNCHS Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="bg">
<div class="wrap wide"><div class="card">
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:12px">
            <img src="<?php echo e(LOGO_URL); ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover">
            <div>
                <h2 style="font-size:20px;font-weight:700"><?php echo e($full_name); ?></h2>
                <p style="font-size:12px" class="muted">
                    <?php if ($is_staff): ?>Employee ID: <strong><?php echo e($me['employee_id'] ?? 'N/A'); ?></strong> | <?php echo e($me['position'] ?: 'Staff'); ?>
                    <?php else: ?>LRN: <strong><?php echo e($me['lrn'] ?? 'N/A'); ?></strong><?php $gs = trim(($me['grade_level'] ?? '') . ' - ' . ($me['section'] ?? ''), ' -'); if ($gs) echo ' | ' . e($gs); ?>
                    <?php endif; ?> | <?php echo date('F j, Y'); ?>
                </p>
            </div>
        </div>
        <div class="dd">
            <button type="button" class="btn btn-dark btn-sm" id="setBtn" aria-haspopup="true" aria-expanded="false">⚙️ Settings ▾</button>
            <div class="dd-menu" id="setMenu">
                <button type="button" class="menu-item" data-open="pinTab">🔐 Kiosk PIN</button>
                <a href="change_password.php" class="menu-item" style="text-decoration:none">🔑 Change password</a>
                <form method="POST" action="inter.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="logout"><button type="submit" class="menu-item danger">Sign out</button></form>
            </div>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>
    <?php if (!$me['has_pin']): ?><div class="alert alert-warn"><strong>Set your kiosk PIN.</strong> You need a 4 to 6 digit PIN to check in at the gate with your <?php echo $is_staff ? 'employee number' : 'LRN'; ?>. Open Settings (top right) and choose &ldquo;Kiosk PIN&rdquo;.</div><?php endif; ?>
    <?php if (!$me['privacy_consent_at'] || ($me['privacy_version'] ?? '') !== PRIVACY_VERSION): ?>
        <div class="alert alert-info"><strong>Privacy Notice.</strong> Please read the <a href="privacy.php" target="_blank" rel="noopener" style="color:inherit;font-weight:700">Privacy Notice</a> and confirm. If you are under 18, your parent or guardian should read it too.
            <form method="POST" action="inter.php" style="margin-top:8px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="accept_privacy"><button type="submit" class="btn btn-primary btn-sm">I have read it and I agree</button></form></div>
    <?php endif; ?>
    <?php if ($no_class_today !== null): ?><div class="alert alert-warn"><strong>No classes today:</strong> <?php echo e($no_class_today); ?>. Check-ins are closed.</div><?php endif; ?>

        <div id="pinTab" class="collapsible <?php echo $me['has_pin'] ? '' : 'show'; ?>">
            <div style="font-size:12px;font-weight:700;margin-bottom:10px" class="muted">Kiosk PIN</div>
            <form method="POST" action="inter.php"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="set_pin">
                <div class="fg"><label>Current account password</label><input type="password" name="current_password" class="fc" required autocomplete="current-password"></div>
                <div class="row">
                    <div class="fg"><label><?php echo $me['has_pin'] ? 'New PIN' : 'PIN'; ?> (4 to 6 digits)</label><input type="password" name="pin" class="fc" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required autocomplete="off"></div>
                    <div class="fg"><label>Confirm PIN</label><input type="password" name="confirm_pin" class="fc" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required autocomplete="off"></div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom:0">Save PIN</button>
            </form>
        </div>


    <?php foreach ($notices as $n): ?>
        <div class="summon">
            <h4>🚨 PINAPATAWAG ANG MAGULANG / GUARDIAN NOTICE</h4>
            <p style="font-size:13px;color:#7F1D1D;line-height:1.5;margin-bottom:10px">Your parent or legal guardian is asked to report to the LNCHS Faculty Office.</p>
            <div style="font-size:12px;background:#fff;padding:12px;border-radius:8px;border:1px solid #FCA5A5">
                <div><strong>Issued by:</strong> <?php echo e($n['issued_by']); ?></div>
                <div><strong>Reason:</strong> <?php echo e($n['reason']); ?></div>
                <div><strong>Meeting:</strong> <?php echo e(date('M d, Y', strtotime($n['meeting_date']))); ?> at <?php echo e($n['meeting_time']); ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$is_staff && trim((string)$me['grade_level']) !== ''): ?>
        <div class="panel soft">
            <h4>Your class officers (<?php echo e(trim($me['grade_level']) . ' - ' . trim($me['section'])); ?>)</h4>
            <?php if (!$officers): ?><p class="muted" style="font-size:13px">No officers have been set for your class yet. Your adviser can add them.</p>
            <?php else: ?><div class="chips"><?php foreach ($officers as $o): ?><div class="chip"><small><?php echo e($o['office']); ?></small><strong><?php echo e($o['full_name']); ?></strong></div><?php endforeach; ?></div><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="panel soft">
        <h4>⏰ Reminders <a href="reminders.php" style="color:inherit;font-weight:600">(see all or add)</a></h4>
        <?php if (!$rem_due): ?><p class="muted" style="font-size:13px">Nothing due soon. <a href="reminders.php" style="color:var(--primary)">Add an exam, quiz or project reminder</a>.</p>
        <?php else: foreach ($rem_due as $r): [$lbl, $urg] = due_info($r['due_date'], $r['due_time']); ?>
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px">
                <div><span class="rb <?php echo e(kind_class($r['kind'])); ?>"><?php echo e($r['kind']); ?></span> <strong><?php echo e($r['title']); ?></strong><?php echo $r['subject'] ? ' <span class="muted">(' . e($r['subject']) . ')</span>' : ''; ?>
                    <div class="due <?php echo $urg; ?>"><?php echo e($lbl); ?></div></div>
                <form method="POST" action="reminders.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_done"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="done" value="1"><input type="hidden" name="back" value="inter"><button class="link-del" style="color:#166534">Done</button></form>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($upcoming): ?>
        <div class="panel soft">
            <h4>Coming up (<a href="calendar.php" style="color:inherit">full calendar</a>)</h4>
            <?php foreach ($upcoming as $g): ?><div style="font-size:13px;padding:4px 0"><strong><?php echo e(range_label($g)); ?></strong> | <?php echo e($g['title']); ?> <span class="muted">(<?php echo e($g['kind']); ?><?php echo $g['no_class'] ? ', no classes' : ''; ?>)</span></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($is_staff): ?>
        <div class="panel red">
            <h4>Issue a parent meeting notice (Pinapatawag ang Magulang)</h4>
            <form method="POST" action="inter.php"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="issue_summon">
                <div class="grid2" style="margin-bottom:8px">
                    <input type="text" name="target_lrn" class="fc" placeholder="Student 12-digit LRN" pattern="[0-9]{12}" maxlength="12" inputmode="numeric" required>
                    <input type="date" name="meeting_date" class="fc" value="<?php echo e($today); ?>" min="<?php echo e($today); ?>" required>
                </div>
                <input type="text" name="meeting_time" class="fc" placeholder="Meeting time (e.g. 9:00 AM)" maxlength="50" required style="margin-bottom:8px">
                <textarea name="summon_reason" class="fc" rows="2" maxlength="1000" placeholder="Reason for calling the parent or guardian" required style="margin-bottom:8px"></textarea>
                <button type="submit" class="btn btn-danger" style="margin-bottom:0">Send parent notice</button>
            </form>
            <?php if ($issued): ?>
                <div class="tbl" style="margin:14px 0 0;max-height:180px"><table>
                    <thead><tr><th>Student</th><th>Meeting</th><th>Status</th><th></th></tr></thead><tbody>
                    <?php foreach ($issued as $n): ?>
                        <tr><td><?php echo e($n['student_name'] ?? $n['student_lrn']); ?></td>
                            <td><?php echo e(date('M d', strtotime($n['meeting_date'])) . ' ' . $n['meeting_time']); ?></td>
                            <td><?php echo e($n['status']); ?></td>
                            <td><?php if ($n['status'] === 'Pending'): ?><form method="POST" action="inter.php" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="resolve_notice"><input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>"><button class="link-del" style="color:#166534">Mark resolved</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h3 style="font-size:16px;font-weight:700;margin-bottom:12px">📋 Attendance</h3>
    <div class="panel">
        <div style="font-size:12px;margin-bottom:12px;line-height:1.7">
            <div>Morning in: <strong><?php echo e(tm($t['time_in'] ?? null) ?? 'Not recorded'); ?></strong></div>
            <div>Lunch out: <strong><?php echo e(tm($t['lunch_out'] ?? null) ?? 'Not recorded'); ?></strong></div>
            <div>Lunch in: <strong><?php echo e(tm($t['lunch_in'] ?? null) ?? 'Not recorded'); ?></strong></div>
            <div>Final out: <strong><?php echo e(tm($t['time_out'] ?? null) ?? 'Not recorded'); ?></strong></div>
        </div>
        <?php if (!$t): ?>
            <form method="POST" action="inter.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="attendance_checkin"><button type="submit" class="btn btn-ok" style="margin-bottom:0">Morning check-in (late after 7:00 AM)</button></form>
        <?php elseif (!$t['time_in']): ?>
            <div style="font-size:13px;text-align:center;color:#DC2626;font-weight:600">Marked absent today.</div>
        <?php elseif (!$t['time_out']): ?>
            <div class="grid2">
                <form method="POST" action="inter.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="lunch_out"><button type="submit" class="btn btn-warn" style="margin-bottom:0" <?php echo $t['lunch_out'] !== null ? 'disabled' : ''; ?>>Lunch out</button></form>
                <form method="POST" action="inter.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="lunch_in"><button type="submit" class="btn btn-primary" style="margin-bottom:0" <?php echo ($t['lunch_out'] === null || $t['lunch_in'] !== null) ? 'disabled' : ''; ?>>Lunch return</button></form>
            </div>
            <form method="POST" action="inter.php" style="margin-top:10px"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="attendance_checkout">
                <?php if ($is_ot): ?>
                    <select name="overtime_reason" class="fc" style="margin-bottom:8px">
                        <option>Cleaners / Classroom Duty</option><option>Practice (Dance / Play)</option><option>Sports Training</option>
                    </select>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="margin-bottom:0">Final check-out</button>
            </form>
        <?php else: ?>
            <div style="font-size:13px;text-align:center;color:var(--ok);font-weight:600">Today's logs are complete.</div>
        <?php endif; ?>
    </div>

    <div class="panel red">
        <h4>File an absence note</h4>
        <form method="POST" action="inter.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="submit_absence">
            <input type="date" name="absent_date" id="absDate" class="fc" value="<?php echo (int)date('N') < 6 ? e($today) : ''; ?>" min="<?php echo e($absence_min); ?>" max="<?php echo e($tomorrow); ?>" required>
            <p class="hint" style="margin:3px 0 8px">Today or tomorrow only. Weekends are not school days.</p>
            <textarea name="absence_reason" class="fc" rows="2" maxlength="500" placeholder="Reason (e.g. illness, family emergency)" required style="margin-bottom:8px"></textarea>
            <button type="submit" class="btn btn-danger" style="margin-bottom:0">Submit absence note</button>
        </form>
    </div>

    <div style="font-size:12px;font-weight:700;margin-bottom:8px" class="muted">Attendance history (last 10)</div>
    <div class="tbl" style="margin-bottom:0"><table>
        <thead><tr><th>Date</th><th>Status</th><th>In</th><th>Lunch out</th><th>Lunch in</th><th>Out</th><th>Notes</th></tr></thead><tbody>
        <?php if (!$history): ?><tr><td colspan="7" style="text-align:center" class="muted">No records yet.</td></tr>
        <?php else: foreach ($history as $r): ?>
            <tr><td><?php echo e(date('M d, Y', strtotime($r['date']))); ?></td><td><?php echo e($r['status']); ?></td>
                <td><?php echo e(tm($r['time_in']) ?? '-'); ?></td><td><?php echo e(tm($r['lunch_out']) ?? '-'); ?></td><td><?php echo e(tm($r['lunch_in']) ?? '-'); ?></td><td><?php echo e(tm($r['time_out']) ?? '-'); ?></td>
                <td><?php echo e($r['status'] === 'Absent' ? ($r['absence_reason'] ?? 'Unspecified') : ($r['overtime_reason'] ?? 'Regular')); ?></td></tr>
        <?php endforeach; endif; ?></tbody></table></div>

    <div class="panel soft" style="margin:24px 0 0">
        <h4>Quick access</h4>
        <a href="http://10.0.0.1" target="_blank" rel="noopener" class="btn btn-wifi">📶 Lopesay PisoWiFi Manager (pause time)</a>
        <button type="button" class="btn btn-ws" data-toggle="workspaceTab">📁 Google Workspace apps</button>
        <div id="workspaceTab" class="collapsible">
            <div class="ws-grid">
                <a href="https://drive.google.com" target="_blank" rel="noopener" class="ws-item"><span>📁</span>Drive</a>
                <a href="https://meet.google.com" target="_blank" rel="noopener" class="ws-item"><span>📹</span>Meet</a>
                <a href="https://calendar.google.com" target="_blank" rel="noopener" class="ws-item"><span>📅</span>Calendar</a>
                <a href="https://chat.google.com" target="_blank" rel="noopener" class="ws-item"><span>💬</span>Chat</a>
                <a href="https://gemini.google.com" target="_blank" rel="noopener" class="ws-item"><span>✦</span>Gemini</a>
                <a href="https://docs.google.com" target="_blank" rel="noopener" class="ws-item"><span>📄</span>Docs</a>
                <a href="https://sheets.google.com" target="_blank" rel="noopener" class="ws-item"><span>📊</span>Sheets</a>
                <a href="https://slides.google.com" target="_blank" rel="noopener" class="ws-item"><span>🖼️</span>Slides</a>
            </div>
        </div>

        <a href="schedule.php" class="btn btn-sched">🗓️ Class timetable</a>
        <a href="officers.php" class="btn btn-primary">🏅 Classroom officers</a>
        <a href="calendar.php" class="btn btn-primary">📆 School calendar</a>
        <a href="reminders.php" class="btn btn-primary">⏰ Reminders</a>
        <button type="button" class="btn btn-sched" data-toggle="scheduleTab">📅 My custom schedule</button>
        <div id="scheduleTab" class="collapsible">
            <form method="POST" action="inter.php" style="margin-bottom:15px;background:#F8FAFC;padding:12px;border-radius:8px;border:1px solid var(--line)"><?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_schedule">
                <div class="grid2" style="margin-bottom:8px">
                    <select name="day_of_week" class="fc" required><option value="">Select day</option>
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?><option><?php echo $d; ?></option><?php endforeach; ?></select>
                    <input type="text" name="time_slot" class="fc" maxlength="50" placeholder="8:00 AM - 9:30 AM" required>
                </div>
                <div class="grid2" style="margin-bottom:8px">
                    <input type="text" name="subject_title" class="fc" maxlength="100" placeholder="Subject / title" required>
                    <input type="text" name="room_number" class="fc" maxlength="50" placeholder="Room / location" required>
                </div>
                <button type="submit" class="btn btn-sched" style="margin-bottom:0;padding:8px">Add to my schedule</button>
            </form>
            <div class="tbl" style="max-height:180px;margin-bottom:0"><table>
                <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Room</th><th></th></tr></thead><tbody>
                <?php if (!$my_schedules): ?><tr><td colspan="5" style="text-align:center" class="muted">Nothing here yet. Add your first class above.</td></tr>
                <?php else: foreach ($my_schedules as $sc): ?>
                    <tr><td><strong><?php echo e($sc['day_of_week']); ?></strong></td><td><?php echo e($sc['time_slot']); ?></td><td><?php echo e($sc['subject_title']); ?></td><td><?php echo e($sc['room_number']); ?></td>
                        <td><form method="POST" action="inter.php" onsubmit="return confirm('Delete this class?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="id" value="<?php echo (int)$sc['id']; ?>"><button class="link-del">Delete</button></form></td></tr>
                <?php endforeach; endif; ?></tbody></table></div>
        </div>

        <?php if ($is_staff): ?>
            <div class="grid2" style="margin-top:2px">
                <a href="manage_news.php" class="btn btn-dark" style="margin-bottom:0">✍️ Publish an article</a>
                <a href="manage_tags.php" class="btn btn-dark" style="margin-bottom:0">🏷️ Cards, tags and scan log</a>
            </div>
            <div class="grid2" style="margin-top:10px">
                <a href="cutting.php" class="btn btn-dark" style="margin-bottom:0">🚷 Record cutting class</a>
                <a href="adviser_report.php" class="btn btn-dark" style="margin-bottom:0">📊 Class attendance report</a>
            </div>
            <div class="grid2" style="margin-top:10px">
                <a href="reset_password.php" class="btn btn-dark" style="margin-bottom:0;grid-column:1/-1">🔑 Reset a password</a>
            </div>
        <?php endif; ?>
    </div>

<p style="text-align:center;margin-top:16px;font-size:12px"><a href="privacy.php" target="_blank" rel="noopener" style="color:var(--muted)">Privacy Notice</a></p>
</div></div>
<script>
var ad = document.getElementById('absDate');
function checkAbs() {
    var d = new Date(ad.value + 'T00:00:00');
    ad.setCustomValidity(ad.value && (d.getDay() === 0 || d.getDay() === 6) ? 'Saturdays and Sundays are not school days.' : '');
}
ad.addEventListener('input', checkAbs); checkAbs();
var sb = document.getElementById('setBtn'), sm = document.getElementById('setMenu');
function toggleSet(open) { sm.classList.toggle('show', open); sb.setAttribute('aria-expanded', open ? 'true' : 'false'); }
sb.addEventListener('click', function (e) { e.stopPropagation(); toggleSet(!sm.classList.contains('show')); });
document.addEventListener('click', function (e) { if (!sm.contains(e.target)) toggleSet(false); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggleSet(false); });
document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () { var t = document.getElementById(b.dataset.open); t.classList.add('show'); toggleSet(false); t.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
});
document.querySelectorAll('[data-toggle]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById(b.dataset.toggle).classList.toggle('show'); });
});
</script>
</body>
</html>
