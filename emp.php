<?php
require __DIR__ . '/config.php';
if (!empty($_SESSION['user_id'])) redirect('inter.php');
const POSITIONS = ['Teacher', 'Non-teaching staff'];

$error = ''; $tab = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'staff_signup') {
        $tab = 'signup';
        $emp_id = trim($_POST['employee_id'] ?? '');  $full_name = trim($_POST['full_name'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? ''); $phone = trim($_POST['phone_number'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? '')); $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';         $confirm = $_POST['confirm_password'] ?? '';
        $pin = $_POST['pin'] ?? '';                   $pin2 = $_POST['confirm_pin'] ?? '';
        $code = (string)($_POST['invite_code'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $digits = preg_replace('/\D/', '', $emp_id);
        $bd = valid_date($birthdate);
        $gate_ident = 'staffreg:' . ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!configured(STAFF_INVITE_CODE)) $error = 'Staff registration is not set up yet. Ask the administrator.';
        elseif (login_blocked($conn, $gate_ident)) $error = 'Too many attempts. Try again in 15 minutes.';
        elseif (!hash_equals(STAFF_INVITE_CODE, $code)) { login_fail($conn, $gate_ident); $error = 'Invalid staff registration code.'; }
        elseif (!preg_match('/^[A-Za-z0-9-]{3,30}$/', $emp_id)) $error = 'Employee ID may use letters, numbers and dashes (3 to 30 characters).';
        elseif (strlen($digits) < 3 || strlen($digits) > 11) $error = 'Employee ID must contain 3 to 11 digits so it works on the gate keypad.';
        elseif (mb_strlen($full_name) < 2 || mb_strlen($full_name) > 100) $error = 'Enter your full name.';
        elseif (!in_array($position, POSITIONS, true)) $error = 'Choose whether you are a teacher or non-teaching staff.';
        elseif (!$bd || age_of($bd) < 18 || age_of($bd) > 90) $error = 'Enter a valid date of birth.';
        elseif (!valid_phone($phone)) $error = 'Phone number must look like 09123456789.';
        elseif (!valid_username($username)) $error = 'Username must start with a letter and use 4 to 30 letters, numbers or underscores.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $error = 'Enter a valid email address.';
        elseif (!valid_password($password)) $error = 'Password must be 8 to 72 characters.';
        elseif ($password !== $confirm) $error = 'Passwords do not match.';
        elseif (pin_error($pin)) $error = pin_error($pin);
        elseif ($pin !== $pin2) $error = 'PINs do not match.';
        elseif (empty($_POST['consent'])) $error = 'Please read and accept the Privacy Notice to register.';
        else {
            $idl = strtolower($emp_id);
            $s = $conn->prepare("SELECT id FROM users WHERE LOWER(employee_id) IN (?, ?) OR LOWER(username) IN (?, ?) OR employee_num = ? OR LOWER(email) = ?");
            $s->bind_param("ssssss", $idl, $username, $idl, $username, $digits, $email); $s->execute();
            $dup1 = $s->get_result()->num_rows;          // read each result before running the next query
            $t = $conn->prepare("SELECT id FROM rfid_tags WHERE uid = ?"); $t->bind_param("s", $digits); $t->execute();
            $dup2 = $t->get_result()->num_rows;
            if ($dup1 || $dup2) {
                $error = 'An account with that Employee ID, email or username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT); $pinh = password_hash($pin, PASSWORD_BCRYPT); $bdf = $bd->format('Y-m-d'); $pv = PRIVACY_VERSION;
                $i = $conn->prepare("INSERT INTO users (employee_id, employee_num, username, email, password, full_name, birthdate, phone_number, pin_hash, `position`, privacy_consent_at, privacy_version, role) VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), ?, 'staff')");
                $i->bind_param("sssssssssss", $emp_id, $digits, $username, $email, $hash, $full_name, $bdf, $phone, $pinh, $position, $pv);
                if ($i->execute()) { login_user($conn->insert_id); redirect('inter.php'); }
                $error = 'Staff registration failed. Please try again.';
            }
        }
    } elseif ($action === 'staff_login') {
        $ident = strtolower(trim($_POST['login_input'] ?? '')); $password = $_POST['password'] ?? '';
        if (login_blocked($conn, $ident)) {
            $error = 'Too many failed attempts. Try again in 15 minutes.';
        } else {
            $s = $conn->prepare("SELECT id, password FROM users WHERE role IN ('staff','admin') AND (LOWER(employee_id) = ? OR LOWER(username) = ? OR LOWER(email) = ?)");
            $s->bind_param("sss", $ident, $ident, $ident); $s->execute();
            $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $found = null;
            foreach ($rows as $r) if (password_verify($password, $r['password'])) { $found = $r['id']; break; }
            if ($found) { login_ok($conn, $ident); login_user($found); if (!empty($_POST['remember'])) remember_issue($conn, (int)$found); redirect('inter.php'); }
            login_fail($conn, $ident);
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LNCHS Staff and Faculty Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="bg staff">
<div class="wrap"><div class="card">
    <div class="brand"><img src="<?php echo e(LOGO_URL); ?>" alt="LNCHS logo"><h2>Faculty and Staff Portal</h2><p>LNCHS administration sign in</p></div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <div class="tabs">
        <button type="button" class="tab-btn <?php echo $tab === 'login' ? 'active' : ''; ?>" data-tab="loginSection">Sign in</button>
        <button type="button" class="tab-btn <?php echo $tab === 'signup' ? 'active' : ''; ?>" data-tab="signupSection">Register staff</button>
    </div>
    <div id="loginSection" class="form-section <?php echo $tab === 'login' ? 'active' : ''; ?>">
        <form method="POST" action="emp.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="staff_login">
            <div class="fg"><label>Employee ID, username or email</label><input type="text" name="login_input" class="fc" required autocomplete="username"></div>
            <div class="fg"><label>Password</label><input type="password" name="password" class="fc" required autocomplete="current-password"></div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:6px"><input type="checkbox" name="remember" value="1"> Keep me signed in for 30 days</label>
            <p class="hint" style="margin:0 0 12px">Do not tick this on a shared or school computer.</p>
            <button type="submit" class="btn btn-staff">Staff sign in</button>
            <p class="hint" style="text-align:center">Forgot your password? Ask an administrator to reset it.</p>
        </form>
    </div>
    <div id="signupSection" class="form-section <?php echo $tab === 'signup' ? 'active' : ''; ?>">
        <form method="POST" action="emp.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="staff_signup">
            <div class="fg"><label>Staff registration code</label><input type="password" name="invite_code" class="fc" required autocomplete="off"><p class="hint">Ask the school administrator for this code.</p></div>
            <div class="row">
                <div class="fg"><label>Employee ID</label><input type="text" name="employee_id" class="fc" value="<?php echo old('employee_id'); ?>" maxlength="30" required><p class="hint">Must contain 3 to 11 digits.</p></div>
                <div class="fg"><label>Date of birth</label><input type="date" name="birthdate" class="fc" value="<?php echo old('birthdate'); ?>" required></div>
            </div>
            <div class="fg"><label>Full name</label><input type="text" name="full_name" class="fc" value="<?php echo old('full_name'); ?>" maxlength="100" placeholder="Maria Santos" required></div>
            <div class="fg"><label>Position</label><select name="position" class="fc" required><option value="">Select position</option><?php foreach (POSITIONS as $p): ?><option <?php echo (($_POST['position'] ?? '') === $p) ? 'selected' : ''; ?>><?php echo e($p); ?></option><?php endforeach; ?></select></div>
            <div class="row">
                <div class="fg"><label>Phone number</label><input type="tel" name="phone_number" class="fc" value="<?php echo old('phone_number'); ?>" placeholder="09123456789" required></div>
                <div class="fg"><label>Username</label><input type="text" name="username" class="fc" value="<?php echo old('username'); ?>" maxlength="30" placeholder="msantos" required autocomplete="username"></div>
            </div>
            <div class="fg"><label>Email address</label><input type="email" name="email" class="fc" value="<?php echo old('email'); ?>" maxlength="100" placeholder="staff@lnchs.site.je" required></div>
            <div class="row">
                <div class="fg"><label>Password (8+ characters)</label><input type="password" name="password" class="fc" required autocomplete="new-password"></div>
                <div class="fg"><label>Confirm password</label><input type="password" name="confirm_password" class="fc" required autocomplete="new-password"></div>
            </div>
            <div class="row">
                <div class="fg"><label>Kiosk PIN (4 to 6 digits)</label><input type="password" name="pin" class="fc" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required autocomplete="off"></div>
                <div class="fg"><label>Confirm PIN</label><input type="password" name="confirm_pin" class="fc" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required autocomplete="off"></div>
            </div>
            <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.5;margin-bottom:14px"><input type="checkbox" name="consent" value="1" required style="margin-top:3px"><span>I have read the <a href="privacy.php" target="_blank" rel="noopener" style="color:var(--staff)">Privacy Notice</a> and I agree.</span></label>
            <button type="submit" class="btn btn-staff">Register faculty account</button>
        </form>
    </div>
<p style="text-align:center;margin-top:14px;font-size:12px"><a href="privacy.php" target="_blank" rel="noopener" style="color:var(--muted)">Privacy Notice</a></p>
</div></div>
<script>
document.querySelectorAll('.tab-btn').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn').forEach(function (x) { x.classList.remove('active'); });
        document.querySelectorAll('.form-section').forEach(function (x) { x.classList.remove('active'); });
        b.classList.add('active'); document.getElementById(b.dataset.tab).classList.add('active');
    });
});
</script>
</body>
</html>
