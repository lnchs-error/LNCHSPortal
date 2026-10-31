<?php
// One-time installer. Open  setup.php?key=YOUR_SETUP_KEY  once, then DELETE this file from the server.
define('NO_SESSION', true);
require __DIR__ . '/config.php';
if (!configured(SETUP_KEY)) { http_response_code(403); exit('Set SETUP_KEY in config.php first.'); }
if (!hash_equals(SETUP_KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('Forbidden'); }

$log = [];
function q($sql) { global $conn, $log; if (!$conn->query($sql)) { $log[] = 'ERROR: ' . $conn->error . '  <= ' . substr($sql, 0, 80); return false; } return true; }
function col_exists($t, $c) { global $conn; $s = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"); $s->bind_param("ss", $t, $c); $s->execute(); return $s->get_result()->num_rows > 0; }
function idx_exists($t, $i) { global $conn; $s = $conn->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"); $s->bind_param("ss", $t, $i); $s->execute(); return $s->get_result()->num_rows > 0; }
function add_col($t, $c, $def) { global $log; if (!col_exists($t, $c) && q("ALTER TABLE `$t` ADD COLUMN `$c` $def")) $log[] = "added $t.$c"; }

// ---- Tables ----
q("CREATE TABLE IF NOT EXISTS users (
  id INT NOT NULL AUTO_INCREMENT, lrn VARCHAR(12) DEFAULT NULL, employee_id VARCHAR(50) DEFAULT NULL, employee_num VARCHAR(11) DEFAULT NULL,
  username VARCHAR(50) NOT NULL, email VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL, full_name VARCHAR(100) NOT NULL,
  grade_level VARCHAR(20) DEFAULT NULL, section VARCHAR(50) DEFAULT NULL, phone_number VARCHAR(20) DEFAULT NULL, birthdate DATE DEFAULT NULL,
  role VARCHAR(20) DEFAULT 'student', pin_hash VARCHAR(255) DEFAULT NULL, pin_failed_count INT NOT NULL DEFAULT 0, pin_locked_until DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY lrn (lrn), UNIQUE KEY employee_id (employee_id), UNIQUE KEY username (username), UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS attendance (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, `date` DATE NOT NULL, time_in TIME DEFAULT NULL, lunch_out TIME DEFAULT NULL, lunch_in TIME DEFAULT NULL,
  time_out TIME DEFAULT NULL, status VARCHAR(20) DEFAULT 'Present', overtime_reason VARCHAR(255) DEFAULT NULL, absence_reason TEXT DEFAULT NULL, PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS parent_notices (
  id INT NOT NULL AUTO_INCREMENT, student_lrn VARCHAR(12) NOT NULL, issued_by VARCHAR(100) NOT NULL, reason TEXT NOT NULL, meeting_date DATE NOT NULL,
  meeting_time VARCHAR(50) NOT NULL, status VARCHAR(20) DEFAULT 'Pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_lrn (student_lrn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS schedules (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, day_of_week VARCHAR(20) NOT NULL, time_slot VARCHAR(50) NOT NULL,
  subject_title VARCHAR(100) NOT NULL, room_number VARCHAR(50) NOT NULL, PRIMARY KEY (id), KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS articles (
  id INT NOT NULL AUTO_INCREMENT, title VARCHAR(255) NOT NULL, category VARCHAR(50) DEFAULT 'General Announcement', content TEXT NOT NULL,
  author_name VARCHAR(100) NOT NULL, author_id INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS rfid_tags (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, uid VARCHAR(32) NOT NULL, label VARCHAR(10) NOT NULL DEFAULT 'card',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uid (uid), KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS kiosk_devices (
  id INT NOT NULL AUTO_INCREMENT, token_hash CHAR(64) NOT NULL, gate TINYINT NOT NULL, last_seen DATETIME DEFAULT NULL, active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS scan_log (
  id INT NOT NULL AUTO_INCREMENT, user_id INT DEFAULT NULL, gate TINYINT NOT NULL, method VARCHAR(10) NOT NULL, result VARCHAR(40) NOT NULL,
  ident_tail VARCHAR(8) DEFAULT NULL, scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_gate_time (gate, scanned_at), KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS login_attempts (
  id INT NOT NULL AUTO_INCREMENT, ident VARCHAR(100) NOT NULL, ip VARCHAR(45) NOT NULL, attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_ident (ident, attempted_at), KEY idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS base_schedule (
  id INT NOT NULL AUTO_INCREMENT, day_of_week VARCHAR(10) NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL, subject VARCHAR(100) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_slot (day_of_week, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$has_base = $conn->query("SELECT 1 FROM base_schedule LIMIT 1");
if ($has_base && $has_base->num_rows === 0) {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $seed = [
        ['07:00', '08:00', ['HGP', 'Fil', 'Fil', 'Fil', 'Fil']],
        ['08:00', '09:00', ['TLE', 'TLE', 'TLE', 'TLE', 'Science']],
        ['09:00', '09:20', ['RECESS', 'RECESS', 'RECESS', 'RECESS', 'RECESS']],
        ['09:20', '10:20', ['Science', 'Math', 'Math', 'Math', 'Math']],
        ['10:20', '11:20', ['MAPEH', 'Science', 'MAPEH', 'MAPEH', 'MAPEH']],
        ['11:20', '12:20', ['LUNCH', 'LUNCH', 'LUNCH', 'LUNCH', 'LUNCH']],
        ['12:20', '13:20', ['Eng', 'Eng', 'Science', 'Eng', 'Eng']],
        ['13:20', '14:20', ['AP', 'AP', 'AP', 'VE', 'AP']],
        ['14:20', '15:20', ['VE', 'VE', 'VE', 'VACANT', 'VACANT']],
    ];
    $ins = $conn->prepare("INSERT INTO base_schedule (day_of_week, start_time, end_time, subject) VALUES (?,?,?,?)");
    foreach ($seed as $row) foreach ($days as $i => $d) { $st = $row[0] . ':00'; $en = $row[1] . ':00'; $sub = $row[2][$i]; $ins->bind_param("ssss", $d, $st, $en, $sub); $ins->execute(); }
    $log[] = 'filled the starting class timetable (Monday to Friday)';
}
q("CREATE TABLE IF NOT EXISTS class_officers (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, office VARCHAR(40) NOT NULL, assigned_by INT DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS class_cuts (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, cut_date DATE NOT NULL, period VARCHAR(60) NOT NULL, subject VARCHAR(100) DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL, reported_by INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uniq_cut (user_id, cut_date, period), KEY idx_date (cut_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS calendar_events (
  id INT NOT NULL AUTO_INCREMENT, event_date DATE NOT NULL, title VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'School event',
  no_class TINYINT NOT NULL DEFAULT 0, created_by INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uniq_event (event_date, title), KEY idx_noclass (no_class, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$old_days = $conn->query("SHOW TABLES LIKE 'no_class_days'");
if ($old_days && $old_days->num_rows) {
    if ($conn->query("INSERT IGNORE INTO calendar_events (event_date, title, kind, no_class) SELECT off_date, reason, 'Suspension', 1 FROM no_class_days")) $log[] = 'copied the old no-class days into the calendar';
}
q("CREATE TABLE IF NOT EXISTS advisories (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, class_key VARCHAR(120) NOT NULL, grade_level VARCHAR(20) NOT NULL, section VARCHAR(50) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_user (user_id), UNIQUE KEY uniq_class (class_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS password_resets (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, reset_by INT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS offline_refs (
  device_id INT NOT NULL, scan_ref VARCHAR(40) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (device_id, scan_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("DELETE FROM offline_refs WHERE created_at < (NOW() - INTERVAL 14 DAY)");
q("CREATE TABLE IF NOT EXISTS reminders (
  id INT NOT NULL AUTO_INCREMENT, created_by INT NOT NULL, class_key VARCHAR(120) DEFAULT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(100) NOT NULL,
  subject VARCHAR(60) DEFAULT NULL, due_date DATE NOT NULL, due_time TIME DEFAULT NULL, notes VARCHAR(500) DEFAULT NULL, remind_days TINYINT NOT NULL DEFAULT 3,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_due (due_date), KEY idx_class (class_key), KEY idx_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS reminder_done (
  reminder_id INT NOT NULL, user_id INT NOT NULL, done_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (reminder_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
q("CREATE TABLE IF NOT EXISTS remember_tokens (
  id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, selector CHAR(24) NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uniq_selector (selector), KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$conn->query("DELETE FROM reminders WHERE due_date < (CURDATE() - INTERVAL 180 DAY)");
$log[] = 'tables checked';

// ---- Columns for databases created by the older files ----
add_col('users', 'employee_id', 'VARCHAR(50) DEFAULT NULL');
add_col('users', 'birthdate', 'DATE DEFAULT NULL');
add_col('users', "role", "VARCHAR(20) DEFAULT 'student'");
add_col('users', 'employee_num', 'VARCHAR(11) DEFAULT NULL');
add_col('users', 'position', 'VARCHAR(30) DEFAULT NULL');
add_col('users', 'must_change_password', 'TINYINT NOT NULL DEFAULT 0');
add_col('users', 'privacy_consent_at', 'DATETIME DEFAULT NULL');
add_col('users', 'privacy_version', 'VARCHAR(10) DEFAULT NULL');
add_col('users', 'created_at', 'DATETIME DEFAULT CURRENT_TIMESTAMP');
add_col('users', 'pin_hash', 'VARCHAR(255) DEFAULT NULL');
add_col('users', 'pin_failed_count', 'INT NOT NULL DEFAULT 0');
add_col('users', 'pin_locked_until', 'DATETIME DEFAULT NULL');
add_col('articles', 'author_id', 'INT DEFAULT NULL');

// ---- One attendance row per person per day ----
$conn->query("DELETE a FROM attendance a JOIN attendance b ON a.user_id = b.user_id AND a.`date` = b.`date` AND a.id > b.id");
if ($conn->affected_rows > 0) $log[] = 'removed ' . $conn->affected_rows . ' duplicate attendance rows (kept the earliest per person per day)';
if (!idx_exists('attendance', 'uniq_user_date') && q("ALTER TABLE attendance ADD UNIQUE KEY uniq_user_date (user_id, `date`)")) $log[] = 'added unique key attendance(user_id, date)';

// ---- employee_num backfill (digits only, used by the kiosk keypad) ----
$res = $conn->query("SELECT id, employee_id FROM users WHERE employee_id IS NOT NULL AND employee_id <> '' AND employee_num IS NULL");
$filled = 0;
while ($res && ($r = $res->fetch_assoc())) {
    $digits = preg_replace('/\D/', '', $r['employee_id']);
    if (strlen($digits) < 3 || strlen($digits) > 11) { $log[] = "CHECK: employee_id '{$r['employee_id']}' (user {$r['id']}) has no usable 3-11 digit number for the keypad"; continue; }
    $c = $conn->prepare("SELECT id FROM users WHERE employee_num = ?"); $c->bind_param("s", $digits); $c->execute();
    if ($c->get_result()->num_rows) { $log[] = "CHECK: employee number $digits already used (user {$r['id']})"; continue; }
    $u = $conn->prepare("UPDATE users SET employee_num = ? WHERE id = ?"); $u->bind_param("si", $digits, $r['id']); $u->execute(); $filled++;
}
$log[] = "employee_num filled for $filled staff account(s)";
if (!idx_exists('users', 'uniq_employee_num') && q("ALTER TABLE users ADD UNIQUE KEY uniq_employee_num (employee_num)")) $log[] = 'added unique key users.employee_num';

// ---- Reports ----
$n = $conn->query("SELECT COUNT(*) c FROM users WHERE pin_hash IS NULL")->fetch_assoc()['c'];
$log[] = "$n account(s) have no kiosk PIN yet (they set one in the portal at next login; cards and tags still work)";
$bad = $conn->query("SELECT id, username FROM users WHERE username REGEXP '^[0-9]+\$'");
while ($bad && ($b = $bad->fetch_assoc())) $log[] = "CHECK: username '{$b['username']}' (user {$b['id']}) is all digits; it could clash with an LRN. Ask the user to change it.";
$t = $conn->query("SELECT @@session.time_zone tz, NOW() n")->fetch_assoc();
$log[] = "database connection time zone {$t['tz']}, current time {$t['n']} (should match Philippine time)";
$log[] = 'DONE. Now delete setup.php from the server.';
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>LNCHS setup</title></head>
<body style="font-family:monospace;padding:20px"><pre><?php echo e(implode("\n", $log)); ?></pre></body></html>
