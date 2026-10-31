<?php
// Gate kiosk. Works with a card/tag reader (types the code + Enter) and a numeric keypad.
define('NO_SESSION', true);
require __DIR__ . '/config.php';

function kiosk_device($conn) {
    $t = $_COOKIE['kiosk_token'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) return null;
    $h = hash('sha256', $t);
    $s = $conn->prepare("SELECT id, gate FROM kiosk_devices WHERE token_hash = ? AND active = 1");
    $s->bind_param("s", $h); $s->execute();
    $d = $s->get_result()->fetch_assoc();
    if ($d) {
        $u = $conn->prepare("UPDATE kiosk_devices SET last_seen = NOW() WHERE id = ?"); $u->bind_param("i", $d['id']); $u->execute();
        setcookie('kiosk_token', $t, ['expires' => time() + 31536000, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);  // renew yearly
    }
    return $d;
}
function jout($a) { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($a); exit; }
function res($type, $msg, $name = '', $bday = false) { return ['status' => 'done', 'type' => $type, 'message' => $msg, 'name' => $name, 'birthday' => $bday]; }
function log_scan($conn, $uid, $gate, $method, $result, $tail) {
    $s = $conn->prepare("INSERT INTO scan_log (user_id, gate, method, result, ident_tail) VALUES (?,?,?,?,?)");
    $s->bind_param("iisss", $uid, $gate, $method, $result, $tail); $s->execute();
}
function is_birthday($bd) {
    if (!$bd) return false;
    $m = date('m-d', strtotime($bd)); $today = date('m-d');
    if ($m === $today) return true;
    return $m === '02-29' && $today === '02-28' && date('L') !== '1';
}

// Records the next step for this person today. Returns [type, message, code].
function do_attendance($conn, $uid, $ts = null) {
    $ts = $ts ?? time(); $today = date('Y-m-d', $ts); $now = date('H:i:s', $ts);
    $nc = no_class_title($conn, $today);
    if ($nc !== null) return ['info', 'NO CLASSES TODAY: ' . strtoupper($nc), 'no_class'];
    $s = $conn->prepare("SELECT id, time_in, lunch_out, lunch_in, time_out FROM attendance WHERE user_id = ? AND `date` = ?");
    $s->bind_param("is", $uid, $today); $s->execute(); $att = $s->get_result()->fetch_assoc();

    if (!$att) {
        $status = ($now > LATE_AFTER) ? 'Late' : 'Present';
        $i = $conn->prepare("INSERT IGNORE INTO attendance (user_id, `date`, time_in, status) VALUES (?,?,?,?)");
        $i->bind_param("isss", $uid, $today, $now, $status); $i->execute();
        if ($i->affected_rows < 1) return ['info', 'ALREADY SCANNED A MOMENT AGO', 'cooldown'];
        return [$status === 'Late' ? 'warning' : 'success', 'CHECKED IN ' . date('g:i A', $ts) . ($status === 'Late' ? ' (LATE)' : ''), 'checkin'];
    }
    if (!$att['time_in']) return ['error', 'YOU ARE MARKED ABSENT TODAY. PLEASE SEE THE OFFICE.', 'absent'];

    $last = max(array_filter([$att['time_in'], $att['lunch_out'], $att['lunch_in'], $att['time_out']]));
    if (strtotime($now) - strtotime($last) < SCAN_COOLDOWN) return ['info', 'ALREADY SCANNED A MOMENT AGO', 'cooldown'];
    if ($att['time_out']) return ['info', 'ALL LOGS FOR TODAY ARE COMPLETE', 'complete'];

    $afternoon = $now >= '14:00:00';
    if ($att['lunch_out'] === null && !$afternoon) {
        $u = $conn->prepare("UPDATE attendance SET lunch_out = ? WHERE id = ? AND lunch_out IS NULL"); $u->bind_param("si", $now, $att['id']); $u->execute();
        return ['warning', 'LUNCH OUT ' . date('g:i A', $ts), 'lunch_out'];
    }
    if ($att['lunch_out'] !== null && $att['lunch_in'] === null && !$afternoon) {
        $u = $conn->prepare("UPDATE attendance SET lunch_in = ? WHERE id = ? AND lunch_in IS NULL"); $u->bind_param("si", $now, $att['id']); $u->execute();
        return ['success', 'LUNCH RETURN ' . date('g:i A', $ts), 'lunch_in'];
    }
    $why = ($now >= OT_FROM) ? 'Overtime' : 'Regular Dismissal';
    $u = $conn->prepare("UPDATE attendance SET time_out = ?, overtime_reason = ? WHERE id = ? AND time_out IS NULL"); $u->bind_param("ssi", $now, $why, $att['id']); $u->execute();
    return ['success', 'CHECKED OUT ' . date('g:i A', $ts), 'checkout'];
}

$dev = kiosk_device($conn);

// ---------------- scan requests from the page ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SERVER['HTTP_X_KIOSK'] ?? '') !== '1') { http_response_code(400); exit; }
    if (!$dev) { http_response_code(403); jout(['status' => 'unpaired']); }
    $gate = (int)$dev['gate'];
    $action = $_POST['action'] ?? 'scan';
    if ($action === 'ping') jout(['status' => 'ok']);

    // Card or tag scans saved on this kiosk while the internet was down. Each has a random ref so it is never counted twice.
    if ($action === 'replay') {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $done = [];
        if (is_array($items)) {
            $items = array_slice($items, 0, 100);
            usort($items, function ($x, $y) { return ((int)($x['ts'] ?? 0)) <=> ((int)($y['ts'] ?? 0)); });
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $ref = (string)($it['ref'] ?? ''); $code = strtolower((string)($it['uid'] ?? '')); $ts = (int)($it['ts'] ?? 0);
                if (!preg_match('/^[a-z0-9]{8,40}$/', $ref)) continue;
                $done[] = $ref;
                if (!preg_match('/^[a-z0-9]{1,32}$/', $code) || $ts > time() + 120 || $ts < time() - 172800) { log_scan($conn, null, $gate, 'unknown', 'offline_rejected', substr($code, -4)); continue; }
                $n = $conn->prepare("INSERT IGNORE INTO offline_refs (device_id, scan_ref) VALUES (?, ?)"); $n->bind_param("is", $dev['id'], $ref); $n->execute();
                if ($n->affected_rows < 1) continue;
                $q = $conn->prepare("SELECT t.label, u.id FROM rfid_tags t JOIN users u ON u.id = t.user_id WHERE t.uid = ?"); $q->bind_param("s", $code); $q->execute();
                $row = $q->get_result()->fetch_assoc();
                if (!$row) { log_scan($conn, null, $gate, 'unknown', 'offline_unknown_card', substr($code, -4)); continue; }
                $r2 = do_attendance($conn, (int)$row['id'], $ts);
                log_scan($conn, (int)$row['id'], $gate, $row['label'], 'offline_' . $r2[2], substr($code, -4));
            }
        }
        jout(['status' => 'ok', 'done' => $done]);
    }

    $step = $_POST['step'] ?? 'id';
    $val = strtolower(trim($_POST['value'] ?? ''));
    $tail = substr($val, -4);
    if (!preg_match('/^[a-z0-9]{1,32}$/', $val)) { log_scan($conn, null, $gate, 'unknown', 'unknown', null); jout(res('error', 'NOT RECOGNIZED')); }

    if ($step === 'id') {
        // 1) card or tag
        $s = $conn->prepare("SELECT t.label, u.id, u.full_name, u.birthdate FROM rfid_tags t JOIN users u ON u.id = t.user_id WHERE t.uid = ?");
        $s->bind_param("s", $val); $s->execute(); $row = $s->get_result()->fetch_assoc();
        if ($row) {
            [$type, $msg, $code] = do_attendance($conn, (int)$row['id']);
            log_scan($conn, (int)$row['id'], $gate, $row['label'], $code, $tail);
            $bday = ($type === 'success' || $type === 'warning') && is_birthday($row['birthdate']);
            jout(res($type, $msg, $row['full_name'], $bday));
        }
        // 2) LRN (12 digits) or employee number (4 to 11 digits): ask for the PIN
        if (ctype_digit($val) && (strlen($val) === 12 || (strlen($val) >= 4 && strlen($val) <= 11))) jout(['status' => 'need_pin']);
        log_scan($conn, null, $gate, 'unknown', 'unknown_card', $tail);
        jout(res('error', 'CARD NOT REGISTERED. PLEASE SEE THE OFFICE.'));
    }

    // ---- PIN step ----
    $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $generic = res('error', 'INVALID ID OR PIN', '', false);
    $generic['message'] = 'INVALID ID OR PIN. NO PIN YET? SET ONE IN THE PORTAL.';
    if (!ctype_digit($val) || strlen($pin) < 4 || strlen($pin) > 6) { log_scan($conn, null, $gate, 'keypad', 'bad_format', $tail); jout($generic); }

    $f = $conn->prepare("SELECT COUNT(*) c FROM scan_log WHERE gate = ? AND result IN ('bad_pin','unknown_id') AND scanned_at > (NOW() - INTERVAL 5 MINUTE)");
    $f->bind_param("i", $gate); $f->execute();
    if ((int)$f->get_result()->fetch_assoc()['c'] >= 25) { log_scan($conn, null, $gate, 'keypad', 'throttled', $tail); jout(res('error', 'TOO MANY ATTEMPTS. PLEASE WAIT A MOMENT.')); }

    if (strlen($val) === 12) $q = $conn->prepare("SELECT id, full_name, birthdate, pin_hash, pin_failed_count, pin_locked_until FROM users WHERE lrn = ? AND role = 'student'");
    else $q = $conn->prepare("SELECT id, full_name, birthdate, pin_hash, pin_failed_count, pin_locked_until FROM users WHERE employee_num = ? AND role IN ('staff','admin')");
    $q->bind_param("s", $val); $q->execute(); $u = $q->get_result()->fetch_assoc();

    if (!$u) { password_hash($pin, PASSWORD_BCRYPT); log_scan($conn, null, $gate, 'keypad', 'unknown_id', $tail); jout($generic); }   // hash call keeps timing similar

    if ($u['pin_locked_until'] !== null) {
        if (strtotime($u['pin_locked_until']) > time()) { log_scan($conn, (int)$u['id'], $gate, 'keypad', 'locked', $tail); jout(res('error', 'TOO MANY ATTEMPTS. PLEASE SEE THE OFFICE.')); }
        $r = $conn->prepare("UPDATE users SET pin_failed_count = 0, pin_locked_until = NULL WHERE id = ?"); $r->bind_param("i", $u['id']); $r->execute();
    }
    if (!$u['pin_hash'] || !password_verify($pin, $u['pin_hash'])) {
        $max = PIN_MAX_FAILS; $mins = PIN_LOCK_MIN;
        $x = $conn->prepare("UPDATE users SET pin_failed_count = pin_failed_count + 1, pin_locked_until = IF(pin_failed_count >= ?, NOW() + INTERVAL ? MINUTE, pin_locked_until) WHERE id = ?");
        $x->bind_param("iii", $max, $mins, $u['id']); $x->execute();
        log_scan($conn, (int)$u['id'], $gate, 'keypad', $u['pin_hash'] ? 'bad_pin' : 'no_pin', $tail);
        jout($generic);
    }
    $r = $conn->prepare("UPDATE users SET pin_failed_count = 0, pin_locked_until = NULL WHERE id = ?"); $r->bind_param("i", $u['id']); $r->execute();
    [$type, $msg, $code] = do_attendance($conn, (int)$u['id']);
    log_scan($conn, (int)$u['id'], $gate, 'keypad', $code, $tail);
    $bday = ($type === 'success' || $type === 'warning') && is_birthday($u['birthdate']);
    jout(res($type, $msg, $u['full_name'], $bday));
}

// ---------------- page ----------------
if (!$dev) redirect('kiosk_setup.php');
$gate_name = GATES[(int)$dev['gate']] ?? ('Gate ' . (int)$dev['gate']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LNCHS Gate Kiosk</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;cursor:none;user-select:none}
body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;color:#fff;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;
     background:linear-gradient(rgba(9,13,22,.86),rgba(9,13,22,.9)),url('bg.png') center/cover #090D16}
.k{width:100%;max-width:880px}
.k img{width:96px;height:96px;border-radius:18px;object-fit:cover;margin-bottom:14px}
h1{font-size:34px;font-weight:800}
.gate{font-size:20px;color:#94A3B8;margin-top:4px}
.clock{font-size:64px;font-weight:800;color:#38BDF8;margin:18px 0 6px;letter-spacing:1px}
.date{font-size:20px;color:#94A3B8;margin-bottom:26px}
.prompt{font-size:24px;font-weight:600;color:#E2E8F0;min-height:36px}
.display{margin:18px auto 0;max-width:560px;font-size:44px;font-weight:700;letter-spacing:6px;padding:14px;border:3px solid #38BDF8;border-radius:16px;background:#0F172A;min-height:84px;overflow:hidden}
#entry{position:fixed;opacity:0;pointer-events:none;left:-999px}
.res{margin:26px auto 0;max-width:760px;padding:26px;border-radius:18px;display:none}
.res.show{display:block}
.res .who{font-size:34px;font-weight:800;margin-bottom:6px}
.res .msg{font-size:30px;font-weight:700;line-height:1.3}
.res-success{background:#065F46;color:#6EE7B7;border:2px solid #059669}
.res-warning{background:#78350F;color:#FCD34D;border:2px solid #D97706}
.res-error{background:#7F1D1D;color:#FECACA;border:2px solid #DC2626}
.res-info{background:#1E3A8A;color:#BFDBFE;border:2px solid #2563EB}
.bday{display:none;background:linear-gradient(135deg,#EC4899,#8B5CF6);color:#fff;padding:14px;border-radius:14px;margin-bottom:14px;font-size:30px;font-weight:800;animation:pulse 1s infinite alternate}
.bday.show{display:block}
@keyframes pulse{from{transform:scale(1)}to{transform:scale(1.03)}}
@media(prefers-reduced-motion:reduce){.bday{animation:none}}
.dot{position:fixed;right:16px;bottom:16px;width:14px;height:14px;border-radius:50%;background:#10B981}
.dot.off{background:#EF4444}
.pending{position:fixed;left:16px;bottom:14px;font-size:15px;color:#FCD34D;font-weight:700}
</style>
</head>
<body>
<div class="k">
    <img src="<?php echo e(LOGO_URL); ?>" alt="LNCHS">
    <h1>LNCHS attendance</h1>
    <div class="gate"><?php echo e($gate_name); ?></div>
    <div class="clock" id="clock">--:--:--</div>
    <div class="date" id="date"></div>
    <div class="prompt" id="prompt"></div>
    <div class="display" id="display">&nbsp;</div>
    <div class="res" id="res">
        <div class="bday" id="bday"></div>
        <div class="who" id="who"></div>
        <div class="msg" id="msg"></div>
    </div>
</div>
<input id="entry" type="text" inputmode="numeric" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="32">
<div class="dot" id="dot" title="Connection"></div>
<div class="pending" id="pending"></div>
<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var inp = $('entry'), disp = $('display'), prompt = $('prompt'), box = $('res'), who = $('who'), msg = $('msg'), bday = $('bday'), dot = $('dot'), pend = $('pending');
    var step = 'id', idVal = '', busy = false, pinTimer = null, hideTimer = null, tFirst = 0, tLast = 0, flushing = false;
    var P_ID = 'Tap your card or tag, or type your ID number and press ENTER';
    var P_PIN = 'Type your PIN and press ENTER';

    function render() {
        var v = inp.value, t;
        if (step === 'pin') t = new Array(v.length + 1).join('\u2022');
        else t = v.length > 4 ? new Array(v.length - 3).join('\u2022') + v.slice(-4) : v;
        disp.textContent = t || '\u00a0';
    }
    function hideResult() { clearTimeout(hideTimer); box.className = 'res'; }
    function reset() { step = 'id'; idVal = ''; inp.value = ''; tFirst = 0; tLast = 0; prompt.textContent = P_ID; clearTimeout(pinTimer); render(); }
    function armPin() { clearTimeout(pinTimer); pinTimer = setTimeout(reset, 15000); }
    function speak(text) { try { if ('speechSynthesis' in window) window.speechSynthesis.speak(new SpeechSynthesisUtterance(text)); } catch (e) {} }
    function show(r) {
        hideResult();
        box.className = 'res show res-' + (r.type || 'error');
        who.textContent = r.name || '';
        msg.textContent = r.message || '';
        if (r.birthday && r.name) {
            bday.textContent = '\uD83C\uDF82 Happy birthday, ' + r.name + '! \uD83C\uDF88';
            bday.className = 'bday show';
            speak('Happy birthday, ' + r.name + '! Welcome to Lopez National Comprehensive High School!');
        } else { bday.className = 'bday'; }
        hideTimer = setTimeout(hideResult, r.birthday ? 8000 : 4000);
    }

    function post(data) {
        var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timer = ctl ? setTimeout(function () { ctl.abort(); }, 8000) : null;
        return fetch('kiosk.php', { method: 'POST', headers: { 'X-Kiosk': '1', 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data).toString(), credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
            .then(function (resp) { if (timer) clearTimeout(timer); if (resp.status === 403) { location.href = 'kiosk_setup.php'; throw new Error('unpaired'); } return resp.json(); },
                  function (err) { if (timer) clearTimeout(timer); throw err; });
    }

    // ---- offline queue: card/tag scans only (a PIN cannot be checked without the server) ----
    function qGet() { try { return JSON.parse(localStorage.getItem('lnchs_q') || '[]'); } catch (e) { return []; } }
    function showPending() { var n = qGet().length; pend.textContent = n ? n + ' scan' + (n === 1 ? '' : 's') + ' waiting to upload' : ''; }
    function qSet(a) { try { localStorage.setItem('lnchs_q', JSON.stringify(a.slice(-500))); } catch (e) {} showPending(); }
    function rnd() { var c = 'abcdefghijklmnopqrstuvwxyz0123456789', s = ''; for (var i = 0; i < 20; i++) s += c.charAt(Math.floor(Math.random() * c.length)); return s; }
    function qAdd(code) { var a = qGet(); a.push({ ref: rnd(), uid: code, ts: Math.floor(Date.now() / 1000) }); qSet(a); }
    function flush() {
        if (flushing) return;
        var a = qGet(); if (!a.length) return;
        flushing = true; var ok = false;
        post({ action: 'replay', items: JSON.stringify(a.slice(0, 50)) }).then(function (r) {
            var d = r.done || []; qSet(qGet().filter(function (x) { return d.indexOf(x.ref) < 0; })); ok = true;
        }).catch(function () {}).then(function () { flushing = false; if (ok && qGet().length) setTimeout(flush, 500); });
    }

    function submit() {
        if (busy) return;
        var v = inp.value.trim();
        if (!v) return;
        if (step === 'pin' && !/^[0-9]{4,6}$/.test(v)) { show({ type: 'error', message: 'PIN MUST BE 4 TO 6 DIGITS' }); inp.value = ''; render(); armPin(); return; }
        var wasStep = step;
        var reader = (step === 'id' && v.length >= 4 && (tLast - tFirst) <= 40 * v.length);   // a reader types the whole code almost instantly
        busy = true;
        var req = step === 'id' ? post({ step: 'id', value: v }) : post({ step: 'pin', value: idVal, pin: v });
        req.then(function (r) {
            if (r.status === 'need_pin') { step = 'pin'; idVal = v; inp.value = ''; prompt.textContent = P_PIN; hideResult(); render(); armPin(); }
            else { show(r); reset(); }
            flush();
        }).catch(function () {
            if (reader) {
                qAdd(v.toLowerCase().replace(/[^0-9a-z]/g, ''));
                show({ type: 'info', message: 'SCAN SAVED. IT WILL BE RECORDED WHEN THE CONNECTION RETURNS.' });
            } else {
                show({ type: 'error', message: wasStep === 'pin' ? 'SYSTEM OFFLINE. USE YOUR CARD OR TAG, OR SEE THE GUARD.' : 'SYSTEM OFFLINE. TAP YOUR CARD OR TAG, OR SEE THE GUARD.' });
            }
            reset();
        }).then(function () { busy = false; });
    }

    inp.addEventListener('input', function () {
        var v = inp.value, now = Date.now();
        v = (step === 'pin') ? v.replace(/\D/g, '').slice(0, 6) : v.replace(/[^0-9a-zA-Z]/g, '').slice(0, 32);
        if (v !== inp.value) inp.value = v;
        if (v.length <= 1) tFirst = now;
        tLast = now;
        if (v.length === 1 && step === 'id') hideResult();
        if (step === 'pin') armPin();
        render();
    });
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); submit(); }
        else if (e.key === 'Escape' || e.key === '*' || e.key === 'Delete') { e.preventDefault(); reset(); hideResult(); }
    });
    inp.addEventListener('blur', function () { setTimeout(function () { inp.focus(); }, 50); });
    setInterval(function () { if (document.activeElement !== inp) inp.focus(); }, 1000);
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    function tick() {
        var n = new Date();
        $('clock').textContent = n.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        $('date').textContent = n.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }
    setInterval(tick, 1000); tick();

    function ping() { post({ action: 'ping' }).then(function () { dot.className = 'dot'; flush(); }).catch(function () { dot.className = 'dot off'; }); }
    setInterval(ping, 60000);
    window.addEventListener('online', ping);

    if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function () {}); }
    showPending(); reset(); inp.focus(); setTimeout(flush, 2000);
})();
</script>
</body>
</html>
