<?php
require __DIR__ . '/config.php';
$me = require_login($conn);
$uid = (int)$me['id'];
$is_staff = is_staff_role($me['role']);
const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

function mins($t) { $p = explode(':', $t); return (int)$p[0] * 60 + (int)$p[1]; }
function fmt($t) { return date('g:i', strtotime($t)); }
function parse_start($slot) {                       // "8:00 AM - 9:00 AM" or "1:20-2:20" -> minutes since midnight
    if (!preg_match('/(\d{1,2})[:.](\d{2})\s*([ap]\.?m\.?)?/i', $slot, $m)) return null;
    $h = (int)$m[1]; $mi = (int)$m[2]; $ap = strtolower($m[3] ?? '');
    if ($ap !== '') { $pm = $ap[0] === 'p'; if ($pm && $h < 12) $h += 12; if (!$pm && $h === 12) $h = 0; }
    elseif ($h < 7) $h += 12;                       // no AM/PM: 1:20 means afternoon
    return $h * 60 + $mi;
}
function valid_time($t) { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a = $_POST['action'] ?? '';

    if ($a === 'add_schedule') {
        $day = trim($_POST['day_of_week'] ?? ''); $st = trim($_POST['start'] ?? ''); $en = trim($_POST['end'] ?? '');
        $subject = trim($_POST['subject_title'] ?? ''); $room = trim($_POST['room_number'] ?? '');
        if (!in_array($day, array_merge(DAYS, ['Saturday']), true) || !valid_time($st) || !valid_time($en) || $subject === '') flash('error', 'Fill in the day, start time, end time and subject.');
        elseif ($en <= $st) flash('error', 'The end time must be after the start time.');
        elseif (mb_strlen($subject) > 100 || mb_strlen($room) > 50) flash('error', 'Subject or room is too long.');
        else {
            $slot = date('g:i A', strtotime($st)) . ' - ' . date('g:i A', strtotime($en));
            $s = $conn->prepare("INSERT INTO schedules (user_id, day_of_week, time_slot, subject_title, room_number) VALUES (?,?,?,?,?)");
            $s->bind_param("issss", $uid, $day, $slot, $subject, $room);
            if ($s->execute()) flash('success', 'Added to your schedule.'); else flash('error', 'Could not add it.');
        }
    } elseif ($a === 'delete_schedule') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $conn->prepare("DELETE FROM schedules WHERE id = ? AND user_id = ?"); $s->bind_param("ii", $id, $uid); $s->execute();
        flash('success', 'Removed from your schedule.');
    } elseif ($a === 'base_save') {
        $day = trim($_POST['day_of_week'] ?? ''); $st = trim($_POST['start'] ?? ''); $en = trim($_POST['end'] ?? ''); $subject = trim($_POST['subject'] ?? '');
        $all = !empty($_POST['all_days']);
        if (!$is_staff) flash('error', 'Only staff can change the class timetable.');
        elseif ((!$all && !in_array($day, DAYS, true)) || !valid_time($st) || !valid_time($en) || $subject === '') flash('error', 'Fill in the day, start time, end time and subject.');
        elseif ($en <= $st) flash('error', 'The end time must be after the start time.');
        elseif (mb_strlen($subject) > 100) flash('error', 'Subject is too long (100 characters max).');
        else {
            $st .= ':00'; $en .= ':00';
            $s = $conn->prepare("INSERT INTO base_schedule (day_of_week, start_time, end_time, subject) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE end_time = VALUES(end_time), subject = VALUES(subject)");
            foreach ($all ? DAYS : [$day] as $d) { $s->bind_param("ssss", $d, $st, $en, $subject); $s->execute(); }
            flash('success', 'Timetable updated.');
        }
    } elseif ($a === 'base_delete') {
        if (!$is_staff) flash('error', 'Only staff can change the class timetable.');
        else { $id = (int)($_POST['id'] ?? 0); $s = $conn->prepare("DELETE FROM base_schedule WHERE id = ?"); $s->bind_param("i", $id); $s->execute(); flash('success', 'Removed from the timetable.'); }
    }
    redirect('schedule.php');
}
$flash = flash();

// ---- baseline grid ----
$rows = [];
$res = $conn->query("SELECT id, day_of_week, start_time, end_time, subject FROM base_schedule ORDER BY start_time, end_time");
while ($res && ($b = $res->fetch_assoc())) {
    $k = $b['start_time'] . '|' . $b['end_time'];
    if (!isset($rows[$k])) $rows[$k] = ['start' => $b['start_time'], 'end' => $b['end_time'], 'cells' => [], 'mine' => []];
    $rows[$k]['cells'][$b['day_of_week']] = $b;
}
ksort($rows);

// ---- my additions ----
$s = $conn->prepare("SELECT id, day_of_week, time_slot, subject_title, room_number FROM schedules WHERE user_id = ?");
$s->bind_param("i", $uid); $s->execute();
$mine = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$dayno = array_flip(array_merge(DAYS, ['Saturday', 'Sunday']));
usort($mine, function ($x, $y) use ($dayno) {
    $d = ($dayno[$x['day_of_week']] ?? 9) <=> ($dayno[$y['day_of_week']] ?? 9);
    return $d ?: ((parse_start($x['time_slot']) ?? 0) <=> (parse_start($y['time_slot']) ?? 0));
});
foreach ($mine as $p) {
    $m = parse_start($p['time_slot']);
    if ($m === null || !in_array($p['day_of_week'], DAYS, true)) continue;
    foreach ($rows as $k => $r) if (mins($r['start']) <= $m && $m < mins($r['end'])) { $rows[$k]['mine'][$p['day_of_week']][] = $p; break; }
}

$today_name = date('l'); $now_min = (int)date('G') * 60 + (int)date('i');
function cell_class($subject) {
    $u = strtoupper($subject);
    if ($u === 'RECESS' || $u === 'LUNCH') return 'break';
    if ($u === 'VACANT') return 'vacant';
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class timetable | LNCHS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
.scroll{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:#fff;margin-bottom:20px}
.tt{width:100%;min-width:660px;border-collapse:collapse}
.tt th,.tt td{border:1px solid var(--line);padding:12px 10px;text-align:center;font-size:14px;white-space:normal;vertical-align:middle;position:static}
.tt th{background:#F1F5F9;color:var(--dark);font-weight:700}
.tt th.today{background:var(--primary);color:#fff}
.tt td.time{text-align:left;font-weight:700;white-space:nowrap;background:#F8FAFC}
.tt td.today{background:#EEF2FF}
.tt td.break{background:#FFF7ED;color:#9A3412;font-weight:700}
.tt td.vacant{color:var(--muted);font-style:italic}
.tt td.now{outline:3px solid var(--primary);outline-offset:-3px;font-weight:700}
.mine{margin-top:6px;font-size:11px;font-weight:600;background:#EDE9FE;color:#5B21B6;border-radius:6px;padding:3px 6px}
.x{background:none;border:none;color:#94A3B8;cursor:pointer;font-size:13px;margin-left:4px;font-family:inherit}
.x:hover{color:var(--danger)}
.legend{font-size:12px;display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px}
.legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:5px;vertical-align:-1px}
</style>
</head>
<body>
<div class="page" style="max-width:960px">
    <div class="topbar">
        <div style="display:flex;align-items:center;gap:12px">
            <img src="<?php echo e(LOGO_URL); ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover">
            <div><h2 style="font-size:22px">Class timetable</h2><p style="font-size:12px" class="muted">Monday to Friday | times shown without AM or PM</p></div>
        </div>
        <a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--primary)">&larr; Back to portal</a>
    </div>

    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>

    <div class="legend">
        <span><i style="background:#EEF2FF;border:1px solid #C7D2FE"></i>Today</span>
        <span><i style="border:3px solid var(--primary)"></i>Happening now</span>
        <span><i style="background:#FFF7ED;border:1px solid #FDBA74"></i>Break</span>
        <span><i style="background:#EDE9FE"></i>Your own additions</span>
    </div>

    <?php if (!$rows): ?>
        <div class="plain muted" style="text-align:center">The timetable is empty. <?php echo $is_staff ? 'Add the first class below.' : 'Ask a teacher to set it up.'; ?></div>
    <?php else: ?>
    <div class="scroll"><table class="tt">
        <thead><tr><th>Time</th><?php foreach (DAYS as $d): ?><th class="<?php echo $d === $today_name ? 'today' : ''; ?>"><?php echo e($d); ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $is_now_row = mins($r['start']) <= $now_min && $now_min < mins($r['end']); ?>
            <tr>
                <td class="time"><?php echo e(fmt($r['start']) . '-' . fmt($r['end'])); ?></td>
                <?php foreach (DAYS as $d):
                    $c = $r['cells'][$d] ?? null;
                    $cls = trim(($d === $today_name ? 'today ' : '') . ($c ? cell_class($c['subject']) . ' ' : '') . ($d === $today_name && $is_now_row ? 'now' : '')); ?>
                    <td class="<?php echo e($cls); ?>">
                        <?php if ($c): ?>
                            <?php echo e($c['subject']); ?>
                            <?php if ($is_staff): ?><form method="POST" action="schedule.php" style="display:inline" onsubmit="return confirm('Remove this cell?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="base_delete"><input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>"><button class="x" title="Remove" aria-label="Remove">&times;</button></form><?php endif; ?>
                        <?php else: ?><span class="muted">-</span><?php endif; ?>
                        <?php foreach ($r['mine'][$d] ?? [] as $p): ?><div class="mine">+ <?php echo e($p['subject_title']); ?><?php echo $p['room_number'] !== '' ? ' (' . e($p['room_number']) . ')' : ''; ?></div><?php endforeach; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>

    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:4px">Add to my schedule</h3>
        <p class="hint" style="margin-bottom:12px">Club meetings, tutoring, practice and anything else. Items that start during a timetable row appear inside that row for you only.</p>
        <form method="POST" action="schedule.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_schedule">
            <div class="row">
                <div class="fg"><label>Day</label><select name="day_of_week" class="fc" required><option value="">Select day</option><?php foreach (array_merge(DAYS, ['Saturday']) as $d): ?><option><?php echo e($d); ?></option><?php endforeach; ?></select></div>
                <div class="fg"><label>Subject or activity</label><input type="text" name="subject_title" class="fc" maxlength="100" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Starts</label><input type="time" name="start" class="fc" required></div>
                <div class="fg"><label>Ends</label><input type="time" name="end" class="fc" required></div>
            </div>
            <div class="fg"><label>Room or place (optional)</label><input type="text" name="room_number" class="fc" maxlength="50"></div>
            <button type="submit" class="btn btn-sched" style="width:auto;padding:10px 22px;margin-bottom:0">Add to my schedule</button>
        </form>
        <?php if ($mine): ?>
        <div class="tbl" style="margin:16px 0 0"><table>
            <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Room</th><th></th></tr></thead><tbody>
            <?php foreach ($mine as $p): ?>
                <tr><td><strong><?php echo e($p['day_of_week']); ?></strong></td><td><?php echo e($p['time_slot']); ?></td><td><?php echo e($p['subject_title']); ?></td><td><?php echo e($p['room_number']); ?></td>
                    <td><form method="POST" action="schedule.php" onsubmit="return confirm('Remove this item?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_schedule"><input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>"><button class="link-del">Remove</button></form></td></tr>
            <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

    <?php if ($is_staff): ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:4px">Edit the class timetable (staff)</h3>
        <p class="hint" style="margin-bottom:12px">This changes the timetable for everyone. Saving a class that starts at the same time on the same day replaces the old one. Use &times; in the table to remove a cell.</p>
        <form method="POST" action="schedule.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="base_save">
            <div class="row">
                <div class="fg"><label>Day</label><select name="day_of_week" class="fc"><?php foreach (DAYS as $d): ?><option><?php echo e($d); ?></option><?php endforeach; ?></select></div>
                <div class="fg"><label>Subject (e.g. Math, RECESS)</label><input type="text" name="subject" class="fc" maxlength="100" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Starts</label><input type="time" name="start" class="fc" required></div>
                <div class="fg"><label>Ends</label><input type="time" name="end" class="fc" required></div>
            </div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:14px"><input type="checkbox" name="all_days" value="1"> Apply to every weekday (good for recess and lunch)</label>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Save to timetable</button>
        </form>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
