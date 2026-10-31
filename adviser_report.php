<?php
require __DIR__ . '/config.php';
require __DIR__ . '/attlib.php';
$me = require_staff($conn);
$uid = (int)$me['id']; $is_admin = $me['role'] === 'admin';
$classes = class_list($conn);

$s = $conn->prepare("SELECT id, class_key, grade_level, section FROM advisories WHERE user_id = ?"); $s->bind_param("i", $uid); $s->execute();
$mine = $s->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a = $_POST['action'] ?? ''; $key = (string)($_POST['class'] ?? ''); $back = 'adviser_report.php';
    if ($a === 'claim') {
        if ($mine) flash('error', 'You already advise a class. Release it first to pick another.');
        elseif (!isset($classes[$key])) flash('error', 'Choose a class from the list.');
        else {
            $c = $classes[$key]; $i = $conn->prepare("INSERT INTO advisories (user_id, class_key, grade_level, section) VALUES (?,?,?,?)");
            $i->bind_param("isss", $uid, $key, $c['grade'], $c['section']);
            if ($i->execute()) { flash('success', 'You are now the adviser of ' . class_label($c) . '.'); $back .= '?class=' . urlencode($key); }
            else flash('error', 'That class already has an adviser. An administrator can change it.');
        }
    } elseif ($a === 'release') {
        $d = $conn->prepare("DELETE FROM advisories WHERE user_id = ?"); $d->bind_param("i", $uid); $d->execute(); flash('success', 'Advisory released.');
    } elseif ($a === 'assign' && $is_admin) {
        $tid = (int)($_POST['staff_id'] ?? 0);
        $t = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('staff','admin')"); $t->bind_param("i", $tid); $t->execute();
        if (!isset($classes[$key]) || !$t->get_result()->num_rows) flash('error', 'Choose a staff member and a class.');
        else {
            $d = $conn->prepare("DELETE FROM advisories WHERE user_id = ? OR class_key = ?"); $d->bind_param("is", $tid, $key); $d->execute();
            $c = $classes[$key]; $i = $conn->prepare("INSERT INTO advisories (user_id, class_key, grade_level, section) VALUES (?,?,?,?)");
            $i->bind_param("isss", $tid, $key, $c['grade'], $c['section']); $i->execute(); flash('success', 'Adviser saved.');
        }
    } elseif ($a === 'unassign' && $is_admin) {
        $d = $conn->prepare("DELETE FROM advisories WHERE id = ?"); $id = (int)($_POST['id'] ?? 0); $d->bind_param("i", $id); $d->execute(); flash('success', 'Adviser removed.');
    }
    redirect($back);
}
$flash = flash();

// which classes may this person open?
$allowed = $classes;
if (!$is_admin) { $allowed = ($mine && isset($classes[$mine['class_key']])) ? [$mine['class_key'] => $classes[$mine['class_key']]] : []; }
$ck = (string)($_GET['class'] ?? '');
if (!isset($allowed[$ck])) $ck = $allowed ? array_key_first($allowed) : '';
$cur = $ck !== '' ? $allowed[$ck] : null;

$taken = [];
foreach ($conn->query("SELECT a.id, a.class_key, a.grade_level, a.section, u.full_name FROM advisories a JOIN users u ON u.id = a.user_id ORDER BY a.grade_level, a.section")->fetch_all(MYSQLI_ASSOC) as $r) $taken[$r['class_key']] = $r;

$view = ($_GET['view'] ?? 'daily') === 'monthly' ? 'monthly' : 'daily';
$today = date('Y-m-d');
$students = [];
if ($cur) {
    $p = explode('|', $ck, 2);
    $q = $conn->prepare("SELECT id, lrn, full_name, created_at FROM users WHERE role = 'student' AND LOWER(TRIM(grade_level)) = ? AND LOWER(TRIM(section)) = ? ORDER BY full_name");
    $q->bind_param("ss", $p[0], $p[1]); $q->execute(); $students = $q->get_result()->fetch_all(MYSQLI_ASSOC);
}
function csv_cell($v) { $v = (string)$v; return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v; }
function csv_out($name, $rows) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '"');
    echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w');
    foreach ($rows as $r) fputcsv($o, array_map('csv_cell', $r)); exit;
}

$ids = array_map('intval', array_column($students, 'id'));
$daily = []; $date = $today; $no_class = null; $tot = ['present' => 0, 'late' => 0, 'note' => 0, 'none' => 0];
$stats = []; $days = []; $month = date('Y-m'); $detail = null;

if ($cur && $view === 'daily') {
    $date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : $today; if ($date > $today) $date = $today;
    $no_class = no_class_title($conn, $date);
    $att = [];
    if ($ids) {
        $in = implode(',', $ids);
        $s = $conn->prepare("SELECT user_id, time_in, lunch_out, lunch_in, time_out, status, overtime_reason, absence_reason FROM attendance WHERE `date` = ? AND user_id IN ($in)");
        $s->bind_param("s", $date); $s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $att[$r['user_id']] = $r;
    }
    $tm = function ($v) { return $v ? date('g:i A', strtotime($v)) : '-'; };
    foreach ($students as $u) {
        $r = $att[$u['id']] ?? null; $row = ['u' => $u, 'status' => '', 'in' => '-', 'lo' => '-', 'li' => '-', 'out' => '-', 'note' => ''];
        if (substr((string)$u['created_at'], 0, 10) > $date) { $row['status'] = 'Not registered yet'; }
        elseif ($r && $r['time_in']) {
            $row['status'] = $r['status'] === 'Late' ? 'Late' : 'Present'; $tot[$r['status'] === 'Late' ? 'late' : 'present']++;
            $row['in'] = $tm($r['time_in']); $row['lo'] = $tm($r['lunch_out']); $row['li'] = $tm($r['lunch_in']); $row['out'] = $tm($r['time_out']); $row['note'] = (string)$r['overtime_reason'];
        } elseif ($r && trim((string)$r['absence_reason']) !== '') { $row['status'] = 'Absent (note filed)'; $row['note'] = $r['absence_reason']; $tot['note']++; }
        elseif ($no_class !== null) { $row['status'] = '-'; }
        elseif ($date === $today) { $row['status'] = 'Not in yet'; }
        else { $row['status'] = 'Absent (no note)'; $tot['none']++; }
        $daily[] = $row;
    }
    if (($_GET['export'] ?? '') === '1') {
        $out = [['LRN', 'Name', 'Status', 'Time in', 'Lunch out', 'Lunch in', 'Time out', 'Note']];
        foreach ($daily as $r) $out[] = [$r['u']['lrn'], $r['u']['full_name'], $r['status'], $r['in'], $r['lo'], $r['li'], $r['out'], $r['note']];
        csv_out('attendance-' . preg_replace('/[^a-z0-9]+/i', '-', class_label($cur)) . '-' . $date . '.csv', $out);
    }
}
if ($cur && $view === 'monthly') {
    $month = preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m'); if ($month > date('Y-m')) $month = date('Y-m');
    $from = $month . '-01'; $to = min(date('Y-m-t', strtotime($from)), $today);
    [$days, $stats] = month_stats($conn, $students, $from, $to);
    $sid = (int)($_GET['student'] ?? 0);
    foreach ($students as $u) if ((int)$u['id'] === $sid) $detail = $u;
    if (($_GET['export'] ?? '') === '1') {
        $out = [['LRN', 'Name', 'School days', 'Present', 'Late', 'Absent (note filed)', 'Absent (no note)', 'Cut class', 'Attendance %']];
        foreach ($students as $u) { $st = $stats[$u['id']]; $out[] = [$u['lrn'], $u['full_name'], $st['days'], $st['present'], count($st['late']), count($st['abs_note']), count($st['abs_none']), count($st['cuts']), $st['days'] ? round($st['present'] / $st['days'] * 100) : '']; }
        csv_out('attendance-' . preg_replace('/[^a-z0-9]+/i', '-', class_label($cur)) . '-' . $month . '.csv', $out);
    }
}
$months = []; for ($i = 0; $i <= 11; $i++) $months[] = date('Y-m', strtotime("first day of -$i month"));
$qs = function ($extra) use ($ck, $view) { return 'adviser_report.php?' . http_build_query(array_merge(['class' => $ck, 'view' => $view], $extra)); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class attendance report | LNCHS staff</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>.nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin:12px 0}.num{border:1px solid var(--line);border-radius:10px;padding:10px;text-align:center}.num b{display:block;font-size:22px}.num span{font-size:11px;color:var(--muted)}
.vt{display:flex;gap:8px;margin:0 0 14px}.vt a{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;background:#F1F5F9;color:var(--dark)}.vt a.on{background:var(--staff);color:#fff}
.bad{color:#B91C1C;font-weight:700}.warn{color:#B45309;font-weight:700}</style>
</head>
<body>
<div class="page" style="max-width:1000px">
    <div class="topbar"><h2>Class attendance report</h2><a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--staff)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>

    <?php if (!$is_admin && !$mine): ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:4px">Choose the class you advise</h3>
        <p class="hint" style="margin-bottom:12px">You can open reports only for the class you advise. A class can have one adviser. If yours is taken, ask an administrator.</p>
        <form method="POST" action="adviser_report.php"><?php echo csrf_field(); ?><input type="hidden" name="action" value="claim">
            <div class="fg"><label>Class</label><select name="class" class="fc" required><option value="">Select class</option>
                <?php foreach ($classes as $k => $c): if (isset($taken[$k])) continue; ?><option value="<?php echo e($k); ?>"><?php echo e(class_label($c)); ?> (<?php echo $c['n']; ?> students)</option><?php endforeach; ?></select></div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">I am the adviser of this class</button>
        </form>
    </div>
    <?php elseif (!$cur): ?>
        <div class="plain muted" style="text-align:center">No classes with students yet.</div>
    <?php else: ?>
    <div class="plain">
        <form method="GET" action="adviser_report.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="view" value="<?php echo e($view); ?>">
            <div class="fg" style="margin:0;flex:1;min-width:220px"><label>Class</label><select name="class" class="fc" onchange="this.form.submit()">
                <?php foreach ($allowed as $k => $c): ?><option value="<?php echo e($k); ?>" <?php echo $k === $ck ? 'selected' : ''; ?>><?php echo e(class_label($c)); ?> (<?php echo $c['n']; ?>)<?php echo isset($taken[$k]) ? ' - ' . e($taken[$k]['full_name']) : ''; ?></option><?php endforeach; ?></select></div>
        </form>
        <?php if (!$is_admin && $mine): ?>
            <form method="POST" action="adviser_report.php" style="margin-top:8px" onsubmit="return confirm('Release your advisory?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="release"><button class="link-del" style="color:var(--muted);font-weight:600">Release my advisory</button></form>
        <?php endif; ?>
    </div>

    <div class="vt"><a class="<?php echo $view === 'daily' ? 'on' : ''; ?>" href="<?php echo e('adviser_report.php?' . http_build_query(['class' => $ck, 'view' => 'daily'])); ?>">Daily roll</a>
        <a class="<?php echo $view === 'monthly' ? 'on' : ''; ?>" href="<?php echo e('adviser_report.php?' . http_build_query(['class' => $ck, 'view' => 'monthly'])); ?>">Monthly summary</a></div>

    <?php if ($view === 'daily'): ?>
    <div class="plain">
        <form method="GET" action="adviser_report.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="class" value="<?php echo e($ck); ?>"><input type="hidden" name="view" value="daily">
            <div class="fg" style="margin:0"><label>Date</label><input type="date" name="date" class="fc" value="<?php echo e($date); ?>" max="<?php echo e($today); ?>" onchange="this.form.submit()"></div>
            <a class="btn btn-dark" style="width:auto;padding:10px 18px;margin-bottom:0" href="<?php echo e($qs(['date' => $date, 'export' => 1])); ?>">Download CSV</a>
        </form>
        <?php if ($no_class !== null): ?><div class="alert alert-warn" style="margin:12px 0 0">No classes on this day: <?php echo e($no_class); ?></div><?php endif; ?>
        <div class="nums"><div class="num"><b><?php echo $tot['present']; ?></b><span>Present</span></div><div class="num"><b class="warn"><?php echo $tot['late']; ?></b><span>Late</span></div>
            <div class="num"><b><?php echo $tot['note']; ?></b><span>Absent, note filed</span></div><div class="num"><b class="bad"><?php echo $tot['none']; ?></b><span>Absent, no note</span></div></div>
        <div class="tbl" style="margin:0;max-height:560px"><table><thead><tr><th>Student</th><th>LRN</th><th>Status</th><th>In</th><th>Lunch out</th><th>Lunch in</th><th>Out</th><th>Note</th></tr></thead><tbody>
            <?php if (!$daily): ?><tr><td colspan="8" class="muted" style="text-align:center">No students.</td></tr><?php endif; ?>
            <?php foreach ($daily as $r): $cls = $r['status'] === 'Late' ? 'warn' : (strpos($r['status'], 'no note') !== false ? 'bad' : ''); ?>
                <tr><td><?php echo e($r['u']['full_name']); ?></td><td><?php echo e($r['u']['lrn']); ?></td><td class="<?php echo $cls; ?>"><?php echo e($r['status']); ?></td><td><?php echo e($r['in']); ?></td><td><?php echo e($r['lo']); ?></td><td><?php echo e($r['li']); ?></td><td><?php echo e($r['out']); ?></td><td style="white-space:normal"><?php echo e($r['note']); ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </div>
    <?php else: ?>
    <div class="plain">
        <form method="GET" action="adviser_report.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="class" value="<?php echo e($ck); ?>"><input type="hidden" name="view" value="monthly">
            <div class="fg" style="margin:0"><label>Month</label><select name="month" class="fc" onchange="this.form.submit()"><?php foreach ($months as $m): ?><option value="<?php echo e($m); ?>" <?php echo $m === $month ? 'selected' : ''; ?>><?php echo e(date('F Y', strtotime($m . '-01'))); ?></option><?php endforeach; ?></select></div>
            <a class="btn btn-dark" style="width:auto;padding:10px 18px;margin-bottom:0" href="<?php echo e($qs(['month' => $month, 'export' => 1])); ?>">Download CSV</a>
        </form>
        <p class="hint" style="margin:10px 0"><?php echo count($days); ?> school days counted in <?php echo e(date('F Y', strtotime($month . '-01'))); ?> so far. Weekends and no-class days on the calendar are excluded.</p>
        <div class="tbl" style="margin:0;max-height:560px"><table><thead><tr><th>Student</th><th>LRN</th><th>Days</th><th>Present</th><th>Late</th><th>Absent (note)</th><th>Absent (no note)</th><th>Cut class</th><th>Rate</th></tr></thead><tbody>
            <?php if (!$students): ?><tr><td colspan="9" class="muted" style="text-align:center">No students.</td></tr><?php endif; ?>
            <?php foreach ($students as $u): $st = $stats[$u['id']]; $rate = $st['days'] ? round($st['present'] / $st['days'] * 100) : null; ?>
                <tr><td><a href="<?php echo e($qs(['month' => $month, 'student' => $u['id']])); ?>" style="color:var(--staff);font-weight:600;text-decoration:none"><?php echo e($u['full_name']); ?></a></td><td><?php echo e($u['lrn']); ?></td>
                    <td><?php echo $st['days']; ?></td><td><?php echo $st['present']; ?></td><td class="<?php echo count($st['late']) ? 'warn' : ''; ?>"><?php echo count($st['late']); ?></td>
                    <td><?php echo count($st['abs_note']); ?></td><td class="<?php echo count($st['abs_none']) ? 'bad' : ''; ?>"><?php echo count($st['abs_none']); ?></td>
                    <td class="<?php echo count($st['cuts']) ? 'bad' : ''; ?>"><?php echo count($st['cuts']); ?></td><td><?php echo $rate === null ? '-' : $rate . '%'; ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
    </div>
    <?php if ($detail): $st = $stats[$detail['id']]; ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:10px"><?php echo e($detail['full_name']); ?> | <?php echo e(date('F Y', strtotime($month . '-01'))); ?></h3>
        <?php if (!$st['abs_none'] && !$st['abs_note'] && !$st['late'] && !$st['cuts']): ?><p class="muted" style="font-size:13px">Nothing to report this month.</p><?php endif; ?>
        <?php if ($st['abs_none'] || $st['abs_note']): ?><h4 style="font-size:13px;margin:10px 0 4px">Absences</h4><ul style="padding-left:20px;font-size:13px;line-height:1.7">
            <?php foreach ($st['abs_none'] as $a): ?><li><?php echo e(date('D, M j', strtotime($a[0]))); ?> <span class="bad">(no note)</span></li><?php endforeach; ?>
            <?php foreach ($st['abs_note'] as $a): ?><li><?php echo e(date('D, M j', strtotime($a[0]))); ?> <span class="muted">(<?php echo e($a[1]); ?>)</span></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($st['late']): ?><h4 style="font-size:13px;margin:10px 0 4px">Late arrivals</h4><ul style="padding-left:20px;font-size:13px;line-height:1.7">
            <?php foreach ($st['late'] as $a): ?><li><?php echo e(date('D, M j', strtotime($a[0]))); ?> <span class="muted">(in at <?php echo e($a[1]); ?>)</span></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($st['cuts']): ?><h4 style="font-size:13px;margin:10px 0 4px">Cutting class</h4><ul style="padding-left:20px;font-size:13px;line-height:1.7">
            <?php foreach ($st['cuts'] as $a): ?><li><?php echo e(date('D, M j', strtotime($a['cut_date']))); ?>: <?php echo e(trim($a['period'] . ($a['subject'] ? ' - ' . $a['subject'] : ''))); ?><?php echo $a['note'] ? ' <span class="muted">(' . e($a['note']) . ')</span>' : ''; ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php endif; endif; endif; ?>

    <?php if ($is_admin): ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:10px">Advisers (administrator)</h3>
        <form method="POST" action="adviser_report.php" class="row" style="align-items:end"><?php echo csrf_field(); ?><input type="hidden" name="action" value="assign">
            <div class="fg"><label>Teacher</label><select name="staff_id" class="fc" required><option value="">Select</option>
                <?php foreach ($conn->query("SELECT id, full_name FROM users WHERE role IN ('staff','admin') ORDER BY full_name")->fetch_all(MYSQLI_ASSOC) as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['full_name']); ?></option><?php endforeach; ?></select></div>
            <div class="fg"><label>Class</label><select name="class" class="fc" required><option value="">Select</option><?php foreach ($classes as $k => $c): ?><option value="<?php echo e($k); ?>"><?php echo e(class_label($c)); ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px">Save adviser</button>
        </form>
        <div class="tbl" style="margin:0"><table><thead><tr><th>Class</th><th>Adviser</th><th></th></tr></thead><tbody>
            <?php if (!$taken): ?><tr><td colspan="3" class="muted" style="text-align:center">No advisers yet.</td></tr><?php endif; ?>
            <?php foreach ($taken as $t): ?><tr><td><?php echo e($t['grade_level'] . ' - ' . $t['section']); ?></td><td><?php echo e($t['full_name']); ?></td>
                <td><form method="POST" action="adviser_report.php" onsubmit="return confirm('Remove this adviser?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="unassign"><input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>"><button class="link-del">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
