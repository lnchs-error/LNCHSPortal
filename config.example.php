<?php
// ============================================================
//  LNCHS shared configuration. EVERY page includes this file.
//  Edit the CHANGE-ME values below, then upload.
// ============================================================
date_default_timezone_set('Asia/Manila');
mysqli_report(MYSQLI_REPORT_OFF);

define('IS_LOCAL', isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost:8000', 'localhost', '127.0.0.1:8000'], true));
define('DEBUG', false);   // set to true ONLY while hunting a bug: errors are then shown on the page. Set it back to false afterwards.
ini_set('display_errors', (IS_LOCAL || DEBUG) ? '1' : '0');   // never show errors to visitors on the live site
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---- Database ----
if (IS_LOCAL) {
    $DB = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'attendance_db'];
} else {
    $DB = [
        'host' => 'YOUR_DB_HOST',
        'user' => 'YOUR_DB_USER',
        'pass' => 'CHANGE-ME-database-password',   
        'name' => 'YOUR_DB_NAME',
    ];
}

// ---- Secrets (use long random phrases, share only with the right people) ----
define('LOGO_URL', 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQtxIRsQxL3JZDXQ74N3F3N-AeRIaO47xg5Bw70bp_B7L-foKJ8PCOLSbQ&s=10');   // school logo used on every page
define('SETUP_KEY',        'CHANGE-ME-setup-key');        // opens setup.php?key=...
define('STAFF_INVITE_CODE','CHANGE-ME-staff-code');       // given to real faculty to register
define('KIOSK_SETUP_CODE', 'CHANGE-ME-kiosk-code');       // typed on kiosk_setup.php when pairing a gate

// ---- School rules ----
define('LATE_AFTER',    '07:00:00');   // check-in after this time = Late
define('OT_FROM',       '15:25:00');   // check-out from this time = Overtime
define('SCAN_COOLDOWN', 120);          // seconds; ignore a repeat scan by the same person
define('PIN_MAX_FAILS', 5);            // wrong PINs before lock
define('PIN_LOCK_MIN',  15);           // lock length in minutes
const GATES  = [1 => 'Main Gate', 2 => 'Secondary Gate', 3 => 'Small Gate', 4 => 'Other Gate'];
const OFFICER_POSITIONS = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'Public Information Officer', 'Peace Officer', 'Sergeant at Arms', 'Muse', 'Escort'];
const OFFICER_MULTI = ['Peace Officer', 'Sergeant at Arms'];   // offices that can have several holders per class
const GRADES = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];

define('PRIVACY_VERSION', '1.0');                          // raise this when the notice changes; everyone is asked to accept again
define('SCHOOL_PRIVACY_CONTACT', 'the LNCHS school office');   // who people contact about their data (edit, e.g. the Data Protection Officer)
function configured($v) { return strpos($v, 'CHANGE-ME') !== 0; }

// ---- Connect ----
$conn = @new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['name']);
if ($conn->connect_error) {
    error_log('DB connect failed: ' . $conn->connect_error);
    http_response_code(500);
    exit('Service temporarily unavailable. Please try again shortly.');
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// ---- Session ----
function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
if (!defined('NO_SESSION')) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// ---- Helpers ----
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function old($k) { return e($_POST[$k] ?? ''); }
function redirect($url) { header('Location: ' . $url); exit; }

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify() {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}
function flash($type = null, $msg = null) {
    if ($type !== null) { $_SESSION['flash'] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function login_user($id) { session_regenerate_id(true); $_SESSION['user_id'] = (int)$id; }
function current_user($conn) {
    if (empty($_SESSION['user_id'])) return null;
    $s = $conn->prepare("SELECT id, lrn, employee_id, employee_num, full_name, role, `position`, must_change_password, privacy_consent_at, privacy_version, grade_level, section, birthdate, (pin_hash IS NOT NULL) AS has_pin FROM users WHERE id = ?");
    if (!$s) {   // a column is missing: setup.php has not been run since the last upload
        error_log('current_user query failed: ' . $conn->error);
        http_response_code(500);
        exit('The portal database needs an update. Ask the administrator to run setup.php once.');
    }
    $s->bind_param("i", $_SESSION['user_id']); $s->execute();
    $u = $s->get_result()->fetch_assoc();
    if (!$u) { $_SESSION = []; session_destroy(); return null; }
    return $u;                                   // role always comes fresh from the database
}
function require_login($conn) {
    $u = current_user($conn);
    if (!$u) redirect('signin.php');
    if ((int)$u['must_change_password'] === 1 && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') redirect('change_password.php');
    return $u;
}
function is_staff_role($r) { return in_array($r, ['staff', 'admin'], true); }
function require_staff($conn) {
    $u = require_login($conn);
    if (!is_staff_role($u['role'])) { http_response_code(403); exit("Access denied. Staff only. <a href='emp.php'>Staff sign in</a>"); }
    return $u;
}

function valid_date($s) { $d = DateTime::createFromFormat('!Y-m-d', (string)$s); return ($d && $d->format('Y-m-d') === $s) ? $d : null; }
function age_of(DateTime $d) { return $d->diff(new DateTime('today'))->y; }
function valid_phone($p) { return (bool)preg_match('/^(09|\+639)[0-9]{9}$/', $p); }
function valid_username($u) { return (bool)preg_match('/^[a-z][a-z0-9_]{3,29}$/', $u); }
function valid_password($p) { return strlen($p) >= 8 && strlen($p) <= 72; }
function pin_error($pin) {
    if (!preg_match('/^[0-9]{4,6}$/', $pin)) return 'PIN must be 4 to 6 digits.';
    if (preg_match('/^(\d)\1+$/', $pin)) return 'PIN cannot be one repeated digit.';
    if (strpos('0123456789', $pin) !== false || strpos('9876543210', $pin) !== false) return 'PIN cannot be a simple sequence like 1234.';
    if (in_array($pin, ['1212', '1122', '2580', '6969', '1004'], true)) return 'That PIN is too easy to guess. Choose another.';
    return '';
}

// Title of a "no classes" calendar entry for that date, or null.
function no_class_title($conn, $date) {
    $s = @$conn->prepare("SELECT title FROM calendar_events WHERE event_date = ? AND no_class = 1 LIMIT 1");
    if (!$s) return null;
    $s->bind_param("s", $date); $s->execute();
    $r = $s->get_result()->fetch_assoc();
    return $r ? $r['title'] : null;
}

// ---- "Keep me signed in": cookie holds selector:validator, the database keeps only a hash of the validator ----
define('REMEMBER_DAYS', 30);
function remember_cookie_set($value, $expires) {
    setcookie('lnchs_remember', $value, ['expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function remember_issue($conn, $uid) {
    $sel = bin2hex(random_bytes(12)); $val = bin2hex(random_bytes(32)); $hash = hash('sha256', $val);
    $exp = date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400);
    $s = @$conn->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?,?,?,?)");
    if (!$s) return;
    $s->bind_param("isss", $uid, $sel, $hash, $exp); $s->execute();
    @$conn->query("DELETE FROM remember_tokens WHERE expires_at < NOW()");
    remember_cookie_set($sel . ':' . $val, time() + REMEMBER_DAYS * 86400);
}
// $uid null = this device only; otherwise every device of that user (the cookie is removed only if it is the current user's)
function remember_clear($conn, $uid = null) {
    if ($uid === null) {
        $p = explode(':', (string)($_COOKIE['lnchs_remember'] ?? ''));
        if (preg_match('/^[a-f0-9]{24}$/', $p[0])) { $s = @$conn->prepare("DELETE FROM remember_tokens WHERE selector = ?"); if ($s) { $s->bind_param("s", $p[0]); $s->execute(); } }
    } else {
        $s = @$conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?"); if ($s) { $s->bind_param("i", $uid); $s->execute(); }
    }
    if (isset($_COOKIE['lnchs_remember']) && ($uid === null || (int)$uid === (int)($_SESSION['user_id'] ?? 0))) remember_cookie_set('', time() - 3600);
}
function remember_login($conn) {
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', (string)($_COOKIE['lnchs_remember'] ?? ''), $m)) return;
    $s = @$conn->prepare("SELECT id, user_id, token_hash FROM remember_tokens WHERE selector = ? AND expires_at > NOW()");
    if (!$s) return;
    $s->bind_param("s", $m[1]); $s->execute(); $row = $s->get_result()->fetch_assoc();
    if (!$row) { remember_cookie_set('', time() - 3600); return; }
    if (!hash_equals($row['token_hash'], hash('sha256', $m[2]))) { remember_clear($conn, (int)$row['user_id']); remember_cookie_set('', time() - 3600); return; }   // wrong secret for a real selector: sign that user out everywhere
    $d = $conn->prepare("DELETE FROM remember_tokens WHERE id = ?"); $d->bind_param("i", $row['id']); $d->execute();
    login_user((int)$row['user_id']);
    remember_issue($conn, (int)$row['user_id']);
}

// ---- Login rate limiting (needs the login_attempts table from setup.php) ----
function login_blocked($conn, $ident) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $s = $conn->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ident = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
    $s->bind_param("s", $ident); $s->execute();
    if ((int)$s->get_result()->fetch_assoc()['c'] >= 5) return true;
    $s = $conn->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)");
    $s->bind_param("s", $ip); $s->execute();
    return (int)$s->get_result()->fetch_assoc()['c'] >= 30;
}
function login_fail($conn, $ident) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $s = $conn->prepare("INSERT INTO login_attempts (ident, ip) VALUES (?, ?)");
    $s->bind_param("ss", $ident, $ip); $s->execute();
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
}
function login_ok($conn, $ident) {
    $s = $conn->prepare("DELETE FROM login_attempts WHERE ident = ?");
    $s->bind_param("s", $ident); $s->execute();
}

if (!defined('NO_SESSION') && empty($_SESSION['user_id'])) remember_login($conn);
