<?php
require __DIR__ . '/config.php';
if (!empty($_SESSION['user_id'])) redirect('inter.php');

$error = ''; $tab = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'local_signup') {
        $tab = 'signup';
        $lrn = trim($_POST['lrn'] ?? '');            $full_name = trim($_POST['full_name'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? ''); $grade = trim($_POST['grade_level'] ?? '');
        $section = trim($_POST['section'] ?? '');     $phone = trim($_POST['phone_number'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? '')); $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';         $confirm = $_POST['confirm_password'] ?? '';
        $pin = $_POST['pin'] ?? '';                   $pin2 = $_POST['confirm_pin'] ?? '';
        $bd = valid_date($birthdate);

        if (!preg_match('/^[0-9]{12}$/', $lrn)) $error = 'LRN must be exactly 12 digits.';
        elseif (mb_strlen($full_name) < 2 || mb_strlen($full_name) > 100) $error = 'Enter your full name (2 to 100 characters).';
        elseif (!$bd || age_of($bd) < 8 || age_of($bd) > 40) $error = 'Enter a valid date of birth.';
        elseif (!in_array($grade, GRADES, true)) $error = 'Choose your grade level.';
        elseif ($section === '' || mb_strlen($section) > 50) $error = 'Enter your section (up to 50 characters).';
        elseif (!valid_phone($phone)) $error = 'Phone number must look like 09123456789.';
        elseif (!valid_username($username)) $error = 'Username must start with a letter and use 4 to 30 letters, numbers or underscores.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $error = 'Enter a valid email address.';
        elseif (!valid_password($password)) $error = 'Password must be 8 to 72 characters.';
        elseif ($password !== $confirm) $error = 'Passwords do not match.';
        elseif (pin_error($pin)) $error = pin_error($pin);
        elseif ($pin !== $pin2) $error = 'PINs do not match.';
        elseif (empty($_POST['consent'])) $error = 'Please read and accept the Privacy Notice to register.';
        else {
            $s = $conn->prepare("SELECT id FROM users WHERE lrn = ? OR LOWER(email) = ? OR LOWER(username) = ? OR LOWER(employee_id) = ?");
            $s->bind_param("ssss", $lrn, $email, $username, $username); $s->execute();
            if ($s->get_result()->num_rows) {
                $error = 'An account with that LRN, email or username already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pinh = password_hash($pin, PASSWORD_BCRYPT);
                $bdf = $bd->format('Y-m-d'); $pv = PRIVACY_VERSION;
                $i = $conn->prepare("INSERT INTO users (lrn, username, email, password, full_name, birthdate, grade_level, section, phone_number, pin_hash, privacy_consent_at, privacy_version, role) VALUES (?,?,?,?,?,?,?,?,?,?, NOW(), ?, 'student')");
                $i->bind_param("sssssssssss", $lrn, $username, $email, $hash, $full_name, $bdf, $grade, $section, $phone, $pinh, $pv);
                if ($i->execute()) { login_user($conn->insert_id); redirect('inter.php'); }
                $error = 'Registration failed. Please try again.';
            }
        }
    } elseif ($action === 'local_login') {
        $ident = strtolower(trim($_POST['login_input'] ?? '')); $password = $_POST['password'] ?? '';
        if (login_blocked($conn, $ident)) {
            $error = 'Too many failed attempts. Try again in 15 minutes.';
        } else {
            $s = $conn->prepare("SELECT id, password FROM users WHERE role = 'student' AND (lrn = ? OR LOWER(username) = ? OR LOWER(email) = ?)");
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
<title>LNCHS Student Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="bg">
<div class="wrap"><div class="card">
    <div class="brand"><img src="<?php echo e(LOGO_URL); ?>" alt="LNCHS logo"><h2>LNCHS Student Portal</h2><p>Student verification and access</p></div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <div class="tabs">
        <button type="button" class="tab-btn <?php echo $tab === 'login' ? 'active' : ''; ?>" data-tab="loginSection">Sign in</button>
        <button type="button" class="tab-btn <?php echo $tab === 'signup' ? 'active' : ''; ?>" data-tab="signupSection">Register</button>
    </div>
    <div id="loginSection" class="form-section <?php echo $tab === 'login' ? 'active' : ''; ?>">
        <form method="POST" action="signin.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="local_login">
            <div class="fg"><label>LRN, username or email</label><input type="text" name="login_input" class="fc" placeholder="12-digit LRN or username" required autocomplete="username"></div>
            <div class="fg"><label>Password</label><input type="password" name="password" class="fc" required autocomplete="current-password"></div>
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:6px"><input type="checkbox" name="remember" value="1"> Keep me signed in for 30 days</label>
            <p class="hint" style="margin:0 0 12px">Do not tick this on a shared or school computer.</p>
            <button type="submit" class="btn btn-primary">Sign in</button>
            <p class="hint" style="text-align:center">Forgot your password? Ask your adviser or the school office to reset it.</p>
        </form>
    </div>
    <div id="signupSection" class="form-section <?php echo $tab === 'signup' ? 'active' : ''; ?>">
        <form method="POST" action="signin.php"><?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="local_signup">
            <div class="fg"><label>12-digit LRN</label><input type="text" name="lrn" class="fc" value="<?php echo old('lrn'); ?>" pattern="[0-9]{12}" maxlength="12" inputmode="numeric" placeholder="109876543210" required></div>
            <div class="row">
                <div class="fg"><label>Full name</label><input type="text" name="full_name" class="fc" value="<?php echo old('full_name'); ?>" maxlength="100" placeholder="Juan Dela Cruz" required></div>
                <div class="fg"><label>Date of birth</label><input type="date" name="birthdate" class="fc" value="<?php echo old('birthdate'); ?>" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Grade level</label>
                    <select name="grade_level" class="fc" required><option value="">Select grade</option>
                        <?php foreach (GRADES as $g): ?><option value="<?php echo e($g); ?>" <?php echo (($_POST['grade_level'] ?? '') === $g) ? 'selected' : ''; ?>><?php echo e($g); ?></option><?php endforeach; ?>
                    </select></div>
                <div class="fg"><label>Section</label><input type="text" name="section" class="fc" value="<?php echo old('section'); ?>" maxlength="50" placeholder="Diamond" required></div>
            </div>
            <div class="row">
                <div class="fg"><label>Phone number</label><input type="tel" name="phone_number" class="fc" value="<?php echo old('phone_number'); ?>" placeholder="09123456789" required></div>
                <div class="fg"><label>Username</label><input type="text" name="username" class="fc" value="<?php echo old('username'); ?>" maxlength="30" placeholder="student123" required autocomplete="username"></div>
            </div>
            <div class="fg"><label>Email address</label><input type="email" name="email" class="fc" value="<?php echo old('email'); ?>" maxlength="100" placeholder="student@lnchs.site.je" required></div>
            <div class="row">
                <div class="fg"><label>Password (8+ characters)</label><input type="password" name="password" class="fc" required autocomplete="new-password"></div>
                <div class="fg"><label>Confirm password</label><input type="password" name="confirm_password" class="fc" required autocomplete="new-password"></div>
            </div>
            <div class="row">
                <div class="fg"><label>Kiosk PIN (4 to 6 digits)</label><input type="password" name="pin" class="fc" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required autocomplete="off"></div>
                <div class="fg"><label>Confirm PIN</label><input type="password" name="confirm_pin" class="fc" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required autocomplete="off"></div>
            </div>
            <p class="hint" style="margin:-6px 0 12px">You type your LRN and this PIN on the gate keypad. Keep it private.</p>
            <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.5;margin-bottom:14px"><input type="checkbox" name="consent" value="1" required style="margin-top:3px"><span>I have read the <a href="privacy.php" target="_blank" rel="noopener" style="color:var(--primary)">Privacy Notice</a> and I agree. If I am under 18, my parent or guardian has read it and agrees.</span></label>
            <button type="submit" class="btn btn-primary">Register student account</button>
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
