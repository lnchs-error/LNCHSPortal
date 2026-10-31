<?php
require __DIR__ . '/config.php';
require __DIR__ . '/attlib.php';
$me = require_login($conn);
$is_staff = is_staff_role($me['role']);
const EVENT_KINDS = ['Holiday', 'Suspension', 'School event', 'Exam', 'Deadline'];

$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $m)) $m = date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $a = $_POST['action'] ?? '';
    $back = 'calendar.php?m=' . urlencode($m);
    if (!$is_staff) { flash('error', 'Only teachers and staff can change the calendar.'); redirect($back); }
    if ($a === 'add_event') {
        $title = trim($_POST['title'] ?? ''); $kind = trim($_POST['kind'] ?? ''); $start = trim($_POST['start'] ?? ''); $end = trim($_POST['end'] ?? '');
        if ($end === '') $end = $start;
        $no = (!empty($_POST['no_class']) || in_array($kind, ['Holiday', 'Suspension'], true)) ? 1 : 0;
        if ($title === '' || mb_strlen($title) > 100) flash('error', 'Enter a title (up to 100 characters).');
        elseif (!in_array($kind, EVENT_KINDS, true)) flash('error', 'Choose a type.');
        elseif (!valid_date($start) || !valid_date($end) || $end < $start) flash('error', 'Check the dates. The last day cannot be before the first day.');
        elseif ((strtotime($end) - strtotime($start)) / 86400 > 31) flash('error', 'An entry can cover at most 32 days. Add longer breaks in parts.');
        else {
            $i = $conn->prepare("INSERT IGNORE INTO calendar_events (event_date, title, kind, no_class, created_by) VALUES (?,?,?,?,?)");
            $by = (int)$me['id'];
            for ($t = strtotime($start); $t <= strtotime($end); $t += 86400) { $d = date('Y-m-d', $t); $i->bind_param("sssii", $d, $title, $kind, $no, $by); $i->execute(); }
            flash('success', 'Added to the calendar.' . ($no ? ' Check-ins are blocked on those days.' : ''));
            $back = 'calendar.php?m=' . urlencode(substr($start, 0, 7));
        }
    } elseif ($a === 'delete_events') {
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))), 0, 40);
        if ($ids) { $conn->query("DELETE FROM calendar_events WHERE id IN (" . implode(',', $ids) . ")"); flash('success', 'Removed.'); }
    }
    redirect($back);
}
$flash = flash();

$first = $m . '-01'; $last = date('Y-m-t', strtotime($first));
$s = $conn->prepare("SELECT id, event_date, title, kind, no_class FROM calendar_events WHERE event_date BETWEEN ? AND ? ORDER BY event_date, id");
$s->bind_param("ss", $first, $last); $s->execute();
$rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$byday = [];
foreach ($rows as $r) $byday[$r['event_date']][] = $r;
$groups = group_events($rows);
$prev = date('Y-m', strtotime($first . ' -1 month')); $next = date('Y-m', strtotime($first . ' +1 month'));
$lead = (int)date('w', strtotime($first)); $dim = (int)date('t', strtotime($first)); $today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>School calendar | LNCHS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
.scroll{overflow-x:auto;margin-bottom:20px}
.cal{width:100%;min-width:640px;border-collapse:collapse;table-layout:fixed;background:#fff;border:1px solid var(--line)}
.cal th{background:#F1F5F9;font-size:12px;padding:8px;border:1px solid var(--line);position:static}
.cal td{border:1px solid var(--line);height:96px;vertical-align:top;padding:6px;white-space:normal;font-size:12px}
.cal td.empty{background:#F8FAFC}.cal td.off{background:#FEF2F2}.cal td.weekend .dn{color:var(--muted)}
.cal td.today{outline:3px solid var(--primary);outline-offset:-3px}
.dn{font-weight:700;font-size:13px;margin-bottom:4px}
.ev{display:block;border-radius:5px;padding:2px 5px;margin-bottom:3px;font-size:11px;font-weight:600;line-height:1.3;background:#E0F2FE;color:#075985}
.ev.Holiday,.ev.Suspension{background:#FEE2E2;color:#991B1B}.ev.Exam{background:#FEF3C7;color:#92400E}.ev.Deadline{background:#EDE9FE;color:#5B21B6}
.nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.nav a{color:var(--primary);text-decoration:none;font-weight:600;font-size:13px}
</style>
</head>
<body>
<div class="page" style="max-width:960px">
    <div class="topbar"><div style="display:flex;align-items:center;gap:12px"><img src="<?php echo e(LOGO_URL); ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover"><h2 style="font-size:22px">School calendar</h2></div>
        <a href="inter.php" style="font-size:13px;text-decoration:none;color:var(--primary)">&larr; Back to portal</a></div>
    <?php if ($flash): ?><div class="alert <?php echo $flash[0] === 'error' ? 'alert-danger' : 'alert-success'; ?>"><?php echo e($flash[1]); ?></div><?php endif; ?>

    <div class="nav"><a href="calendar.php?m=<?php echo e($prev); ?>">&larr; <?php echo e(date('F', strtotime($prev . '-01'))); ?></a>
        <strong style="font-size:18px"><?php echo e(date('F Y', strtotime($first))); ?></strong>
        <a href="calendar.php?m=<?php echo e($next); ?>"><?php echo e(date('F', strtotime($next . '-01'))); ?> &rarr;</a></div>
    <div class="scroll"><table class="cal">
        <thead><tr><?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><th><?php echo $d; ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php $cell = 0; echo '<tr>';
        for ($i = 0; $i < $lead; $i++) { echo '<td class="empty"></td>'; $cell++; }
        for ($d = 1; $d <= $dim; $d++) {
            $ds = sprintf('%s-%02d', $m, $d); $evs = $byday[$ds] ?? []; $off = false;
            foreach ($evs as $ev) if ($ev['no_class']) $off = true;
            $wk = in_array($cell % 7, [0, 6], true);
            echo '<td class="' . ($off ? 'off ' : '') . ($wk ? 'weekend ' : '') . ($ds === $today ? 'today' : '') . '"><div class="dn">' . $d . '</div>';
            foreach ($evs as $ev) echo '<span class="ev ' . e($ev['kind']) . '">' . e($ev['title']) . '</span>';
            echo '</td>'; $cell++;
            if ($cell % 7 === 0 && $d < $dim) echo '</tr><tr>';
        }
        while ($cell % 7 !== 0) { echo '<td class="empty"></td>'; $cell++; }
        echo '</tr>'; ?>
    </tbody></table></div>
    <p class="hint" style="margin:-8px 0 16px">Red days have no classes. Check-ins at the gates and in the portal are blocked on those days, and they are not counted as absences.</p>

    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:10px">This month</h3>
        <?php if (!$groups): ?><p class="muted" style="font-size:13px">Nothing scheduled.</p><?php endif; ?>
        <?php foreach ($groups as $g): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px">
                <div><strong><?php echo e($g['title']); ?></strong> <span class="muted">(<?php echo e($g['kind']); ?><?php echo $g['no_class'] ? ', no classes' : ''; ?>)</span><div class="muted" style="font-size:12px"><?php echo e(range_label($g)); ?></div></div>
                <?php if ($is_staff): ?><form method="POST" action="calendar.php?m=<?php echo e($m); ?>" onsubmit="return confirm('Remove this entry?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_events"><input type="hidden" name="ids" value="<?php echo e(implode(',', $g['ids'])); ?>"><button class="link-del">Remove</button></form><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($is_staff): ?>
    <div class="plain">
        <h3 style="font-size:16px;margin-bottom:4px">Add to the calendar</h3>
        <p class="hint" style="margin-bottom:12px">Holidays and suspensions always mean no classes. For a longer break, set a last day.</p>
        <form method="POST" action="calendar.php?m=<?php echo e($m); ?>"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add_event">
            <div class="row">
                <div class="fg"><label>Title</label><input type="text" name="title" class="fc" maxlength="100" placeholder="Class suspension (typhoon)" required></div>
                <div class="fg"><label>Type</label><select name="kind" id="kind" class="fc"><?php foreach (EVENT_KINDS as $k): ?><option><?php echo e($k); ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="row">
                <div class="fg"><label>First day</label><input type="date" name="start" class="fc" required></div>
                <div class="fg"><label>Last day (optional)</label><input type="date" name="end" class="fc"></div>
            </div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:14px"><input type="checkbox" name="no_class" id="noClass" value="1"> No classes on these days (turns on automatically for holidays and suspensions)</label>
            <button type="submit" class="btn btn-staff" style="width:auto;padding:10px 22px;margin-bottom:0">Add</button>
        </form>
    </div>
    <script>
    var kind = document.getElementById('kind'), nc = document.getElementById('noClass');
    function syncKind() { var f = kind.value === 'Holiday' || kind.value === 'Suspension'; if (f) nc.checked = true; nc.disabled = f; }
    kind.addEventListener('change', syncKind); syncKind();
    </script>
    <?php endif; ?>
</div>
</body>
</html>
