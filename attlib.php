<?php
// Shared helpers for classes, school days and attendance statistics.
function class_key($g, $s) { return strtolower(trim((string)$g)) . '|' . strtolower(trim((string)$s)); }

function class_list($conn) {
    $r = $conn->query("SELECT MIN(grade_level) AS g, MIN(section) AS s, COUNT(*) AS n FROM users
                       WHERE role = 'student' AND TRIM(IFNULL(grade_level,'')) <> '' AND TRIM(IFNULL(section,'')) <> ''
                       GROUP BY LOWER(TRIM(grade_level)), LOWER(TRIM(section))");
    $out = [];
    while ($r && ($x = $r->fetch_assoc())) {
        $k = class_key($x['g'], $x['s']);
        $out[$k] = ['key' => $k, 'grade' => trim($x['g']), 'section' => trim($x['s']), 'n' => (int)$x['n'], 'gn' => (int)preg_replace('/\D/', '', $x['g'])];
    }
    uasort($out, function ($a, $b) { return [$a['gn'], strtolower($a['section'])] <=> [$b['gn'], strtolower($b['section'])]; });
    return $out;
}
function class_label($c) { return $c['grade'] . ' - ' . $c['section']; }

// Monday to Friday dates between two dates, minus calendar days marked "no classes".
function school_days_between($conn, $from, $to) {
    $off = [];
    $s = @$conn->prepare("SELECT DISTINCT event_date FROM calendar_events WHERE no_class = 1 AND event_date BETWEEN ? AND ?");
    if ($s) { $s->bind_param("ss", $from, $to); $s->execute(); foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $off[$r['event_date']] = true; }
    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        if ((int)date('N', $t) < 6 && !isset($off[$d])) $days[] = $d;
    }
    return $days;
}

// Per-student numbers and dates for a date range. $students rows need: id, created_at.
function month_stats($conn, $students, $from, $to) {
    $days = school_days_between($conn, $from, $to);
    $out = [];
    $ids = array_map('intval', array_column($students, 'id'));
    if (!$ids) return [$days, $out];
    $in = implode(',', $ids);
    $att = []; $cuts = [];
    $s = $conn->prepare("SELECT user_id, `date`, time_in, status, absence_reason FROM attendance WHERE user_id IN ($in) AND `date` BETWEEN ? AND ?");
    $s->bind_param("ss", $from, $to); $s->execute();
    foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $att[$r['user_id']][$r['date']] = $r;
    $c = $conn->prepare("SELECT user_id, cut_date, period, subject, note FROM class_cuts WHERE user_id IN ($in) AND cut_date BETWEEN ? AND ? ORDER BY cut_date, id");
    $c->bind_param("ss", $from, $to); $c->execute();
    foreach ($c->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $cuts[$r['user_id']][] = $r;
    foreach ($students as $u) {
        $id = (int)$u['id'];
        $start = max($from, substr((string)($u['created_at'] ?? $from), 0, 10));   // days before the account existed are not counted
        $st = ['days' => 0, 'present' => 0, 'late' => [], 'abs_note' => [], 'abs_none' => [], 'cuts' => $cuts[$id] ?? []];
        foreach ($days as $d) {
            if ($d < $start) continue;
            $st['days']++;
            $r = $att[$id][$d] ?? null;
            if ($r && $r['time_in']) { $st['present']++; if ($r['status'] === 'Late') $st['late'][] = [$d, date('g:i A', strtotime($r['time_in']))]; }
            elseif ($r && trim((string)$r['absence_reason']) !== '') $st['abs_note'][] = [$d, $r['absence_reason']];
            else $st['abs_none'][] = [$d, null];
        }
        $out[$id] = $st;
    }
    return [$days, $out];
}

// Merges consecutive calendar days with the same title into one entry (for display).
function group_events($rows) {
    $out = [];
    foreach ($rows as $r) {
        $n = count($out);
        if ($n && $out[$n - 1]['title'] === $r['title'] && $out[$n - 1]['kind'] === $r['kind'] && (int)$out[$n - 1]['no_class'] === (int)$r['no_class']
            && date('Y-m-d', strtotime($out[$n - 1]['end'] . ' +1 day')) === $r['event_date']) {
            $out[$n - 1]['end'] = $r['event_date']; $out[$n - 1]['ids'][] = (int)$r['id'];
        } else {
            $out[] = ['title' => $r['title'], 'kind' => $r['kind'], 'no_class' => (int)$r['no_class'], 'start' => $r['event_date'], 'end' => $r['event_date'], 'ids' => [(int)$r['id']]];
        }
    }
    return $out;
}
function range_label($g) {
    return $g['start'] === $g['end'] ? date('M j', strtotime($g['start'])) : date('M j', strtotime($g['start'])) . ' - ' . date('M j', strtotime($g['end']));
}

const REMINDER_KINDS = ['Exam', 'Quiz', 'Project', 'Assignment', 'Performance task', 'Report', 'Meeting', 'Other'];
function kind_class($k) { return 'k-' . str_replace(' ', '-', strtolower($k)); }
function my_class_key($me) {
    return (trim((string)($me['grade_level'] ?? '')) !== '' && trim((string)($me['section'] ?? '')) !== '') ? class_key($me['grade_level'], $me['section']) : '';
}
// Reminders this person can see: their own, plus ones teachers posted to their class. Last 30 days and later.
function visible_reminders($conn, $me) {
    $uid = (int)$me['id']; $ck = my_class_key($me);
    $s = @$conn->prepare("SELECT r.*, (d.user_id IS NOT NULL) AS done, u.full_name AS by_name FROM reminders r
        LEFT JOIN reminder_done d ON d.reminder_id = r.id AND d.user_id = ?
        LEFT JOIN users u ON u.id = r.created_by
        WHERE r.due_date >= (CURDATE() - INTERVAL 30 DAY) AND (r.created_by = ? OR r.class_key = ?)
        ORDER BY r.due_date, r.due_time, r.id LIMIT 300");
    if (!$s) return [];
    $s->bind_param("iis", $uid, $uid, $ck); $s->execute();
    return $s->get_result()->fetch_all(MYSQLI_ASSOC);
}
function due_info($date, $time = null) {
    $n = (int)round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    $t = $time ? ' at ' . date('g:i A', strtotime($time)) : '';
    if ($n < 0) return ['Overdue by ' . abs($n) . ' day' . (abs($n) === 1 ? '' : 's'), 'over'];
    if ($n === 0) return ['Today' . $t, 'today'];
    if ($n === 1) return ['Tomorrow' . $t, 'soon'];
    return ['In ' . $n . ' days (' . date('D, M j', strtotime($date)) . ')' . $t, $n <= 3 ? 'soon' : 'later'];
}
