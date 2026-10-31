<?php
require __DIR__ . '/config.php';
require __DIR__ . '/attlib.php';
$me = require_login($conn);
$uid = (int)$me['id']; $is_staff = is_staff_role($me['role']); $is_admin = $me['role'] === 'admin';
$classes = $is_staff ? class_list($conn) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a = $_POST['action'] ?? '';
    $back = (($_POST['back'] ?? '') === 'inter') ? 'inter.php' : 'reminders.php';
    if ($a === 'add_reminder') {
        $kind = trim($_POST['kind'] ?? ''); $title = trim($_POST['title'] ?? ''); $subject = trim($_POST['subject'] ?? '');
        $due = trim($_POST['due_date'] ?? ''); $time = trim($_POST['due_time'] ?? ''); $notes = trim($_POST['notes'] ?? '');
        $days = (int)($_POST['remind_days'] ?? 3); $aud = (string)($_POST['audience'] ?? 'me');
        $today = date('Y-m-d');
        $cnt = $conn->prepare("SELECT COUNT(*) c FROM reminders WHERE created_by = ?"); $cnt->bind_param("i", $uid); $cnt->execute();
        if (!in_array($kind, REMINDER_KINDS, true)) flash('error', 'Choose what kind of reminder this is.');
        elseif ($title === '' || mb_strlen($title) > 100) flash('error', 'Enter a title (up to 100 characters).');
        elseif (mb_strlen($subject) > 60 || mb_strlen($notes) > 500) flash('error', 'Subject is up to 60 characters and notes up to 500.');
        elseif (!valid_date($due) || $due < $today || $due > date('Y-m-d', strtotime('+1 year'))) flash('error', 'Choose a due date from today up to one year ahead.');
        elseif ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) flash('error', 'Check the time.');
        elseif (!in_array($days, [0, 1, 2, 3, 5, 7], true)) flash('error', 'Choose when to start showing it on your dashboard.');
        elseif ($aud !== 'me' && (!$is_staff || !isset($classes[$aud]))) flash('error', 'Choose who the reminder is for.');
        elseif ((int)$cnt->get_result()->fetch_assoc()['c'] >= 300) flash('error', 'You have reached the limit of 300 reminders. Delete some old ones.');
        else {
            $ck = $aud === 'me' ? null : $aud; $tm = $time === '' ? null : $time . ':00'; $sj = $subject === '' ? null : $subject; $nt = $notes === '' ? null : $notes;
            $i = $conn->prepare("INSERT INTO reminders (created_by, class_key, kind, title, subject, due_date, due_time, notes, remind_days) VALUES (?,?,?,?,?,?,?,?,?)");
            $i->bind_param("isssssssi", $uid, $ck, $kind, $title, $sj, $due, $tm, $nt, $days);
            if ($i->execute()) flash('success', $ck ? 'Reminder posted to ' . class_label($classes[$aud]) . '.' : 'Reminder saved.');
            else flash('error', 'Could not save the reminder.');
        }
    } elseif ($a === 'toggle_done') {
        $id = (int)($_POST['id'] ?? 0); $ok = false;
        foreach (visible_reminders($conn, $me) as $r) if ((int)$r['id'] === $id) $ok = true;
        if ($ok) {
            if (($_POST['done'] ?? '') === '1') { $s = $conn->prepare("INSERT IGNORE INTO reminder_done (reminder_id, user_id) VALUES (?,?)"); }
            else { $s = $conn->prepare("DELETE FROM reminder_done WHERE reminder_id = ? AND user_id = ?"); }
            $s->bind_param("ii", $id, $uid); $s->execute();
        }
    } elseif ($a === 'delete_reminder') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("SELECT created_by FROM reminders WHERE id = ?"); $s->bind_param("i", $id); $s->execute();
        $r = $s->get_result()->fetch_assoc();
        if ($r && ((int)$r['created_by'] === $uid || $is_admin)) {
            $d = $conn->prepare("DELETE FROM reminders WHERE id = ?"); $d->bind_param("i", $id); $d->execute();
            $d2 = $conn->prepare("DELETE FROM reminder_done WHERE reminder_id = ?"); $d2->bind_param("i", $id); $d2->execute();
            flash('success', 'Reminder deleted.');
        } else flash('error', 'You can only delete reminders you created.');
    }
    redirect($back);
}
$flash = flash();

$over = []; $up = []; $done = [];
$today = date('Y-m-d');
foreach (visible_reminders($conn, $me) as $r) {
    if ($r['done']) $done[] = $r;
    elseif ($r['due_date'] < $today) $over[] = $r;
    else $up[] = $r;
}
$done = array_slice(array_reverse($done), 0, 15);

function card($r, $uid, $is_admin) {
    [$lbl, $urg] = due_info($r['due_date'], $r['due_time']);
    $mine = (int)$r['created_by'] === $uid; $cls = $r['class_key'] !== null;
    ?>
    <div class="plain" style="margin-bottom:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;<?php echo $r['done'] ? 'opacity:.7' : ''; ?>">
        <div style="min-width:0">
            <span class="rb <?php echo e(kind_class($r['kind'])); ?>"><?php echo e($r['kind']); ?></span>
            <strong style="font-size:15px"><?php echo e($r['title']); ?></strong><?php echo $r['subject'] ? ' <span class="muted">(' . e($r['subject']) . ')</span>' : ''; ?>
            <div class="due <?php echo $r['done'] ? 'later' : $urg; ?>" style="font-size:13px;margin-top:3px"><?php echo e($r['done'] ? 'Done | was due ' . date('M j', strtotime($r['due_date'])) : $lbl); ?></div>
            <?php if ($r['notes']): ?><div style="font-size:13px;color:#334155;margin-top:6px;white-space:pre-line"><?php echo e($r['notes']); ?></div><?php endif; ?>
            <?php if ($cls && !$mine): ?><div class="muted" style="font-size:11px;margin-top:6px">Posted by <?php echo e($r['by_name'] ?? 'a teacher'); ?> for your class</div><?php endif; ?>
            <?php if ($cls && $mine): ?><div class="muted" style="font-size:11px;margin-top:6px">Class reminder you posted</div><?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;flex-shrink:0">
            <form method="POST" action="reminders.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_done"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="done" value="<?php echo $r['done'] ? '0' : '1'; ?>">
                <button class="link-del" style="color:<?php echo $r['done'] ? '#64748B' : '#166534'; ?>"><?php echo $r['done'] ? 'Undo' : 'Mark done'; ?></button></form>
            <?php if ($mine || $is_admin): ?><form method="POST" action="reminders.php" onsubmit="return confirm('Delete this reminder<?php echo $cls ? ' for the whole class' : ''; ?>?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_reminder"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="link-del">Delete</button></form><?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reminders | LNCHS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page">
    <div class="topbar"><div style="display:flex;align-items:center;gap:12px"><img src="<?php echo e(LOGO_URL); ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover"><h2 style="font-size:22px">Reminders</h2></div>
        <a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--primary)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>

    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:12px">Add a reminder</h3>
        <form method="POST" action="reminders.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_reminder">
            <div class="row">
                <div class="fg"><label>Type</label><select name="kind" class="fc" required><?php foreach (REMINDER_KINDS as $k): ?><option <?php echo (($_POST['kind'] ?? '') === $k) ? 'selected' : ''; ?>><?php echo e($k); ?></option><?php endforeach; ?></select></div>
                <div class="fg"><label>Title</label><input type="text" name="title" class="fc" maxlength="100" placeholder="Chapter 3 quiz" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Subject (optional)</label><input type="text" name="subject" class="fc" maxlength="60" placeholder="Science"></div>
                <div class="fg"><label>Due date</label><input type="date" name="due_date" class="fc" min="<?php echo e($today); ?>" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Time (optional)</label><input type="time" name="due_time" class="fc"></div>
                <div class="fg"><label>Show on my dashboard</label><select name="remind_days" class="fc"><option value="0">On the day</option><option value="1">1 day before</option><option value="2">2 days before</option><option value="3" selected>3 days before</option><option value="5">5 days before</option><option value="7">A week before</option></select></div>
            </div>
            <?php if ($is_staff && $classes): ?>
                <div class="fg"><label>Who is this for?</label><select name="audience" class="fc"><option value="me">Only me</option>
                    <?php foreach ($classes as $k => $c): ?><option value="<?php echo e($k); ?>">Everyone in <?php echo e(class_label($c)); ?></option><?php endforeach; ?></select>
                    <p class="hint">A class reminder appears for every student in that class. They can mark it done for themselves.</p></div>
            <?php else: ?><input type="hidden" name="audience" value="me"><?php endif; ?>
            <div class="fg"><label>Notes (optional)</label><textarea name="notes" class="fc" rows="2" maxlength="500" placeholder="Coverage, materials to bring, group members..."></textarea></div>
            <button type="submit" class="btn btn-primary" style="width:auto;padding:10px 22px;margin-bottom:0">Save reminder</button>
        </form>
    </div>

    <?php if ($over): ?><h3 style="margin:0 0 10px;color:#B91C1C">Overdue</h3><?php foreach ($over as $r) card($r, $uid, $is_admin); endif; ?>
    <h3 style="margin:18px 0 10px">Upcoming</h3>
    <?php if (!$up): ?><div class="plain muted" style="text-align:center;font-size:13px">Nothing coming up. Add an exam, quiz, project or anything else above.</div><?php endif; ?>
    <?php foreach ($up as $r) card($r, $uid, $is_admin); ?>
    <?php if ($done): ?><h3 style="margin:22px 0 10px" class="muted">Done (recent)</h3><?php foreach ($done as $r) card($r, $uid, $is_admin); endif; ?>
</div>
</body>
</html>
