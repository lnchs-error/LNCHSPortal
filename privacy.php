<?php
define('NO_SESSION', true);
require __DIR__ . '/config.php';
$c = e(SCHOOL_PRIVACY_CONTACT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Notice | LNCHS Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>.doc h3{font-size:16px;margin:22px 0 6px}.doc p,.doc li{font-size:14px;line-height:1.7;color:#334155}.doc ul{padding-left:22px}</style>
</head>
<body>
<div class="page doc" style="max-width:800px">
    <div class="topbar"><div style="display:flex;align-items:center;gap:12px"><img src="<?php echo e(LOGO_URL); ?>" alt="" style="width:44px;height:44px;border-radius:10px;object-fit:cover"><h2 style="font-size:22px">Privacy Notice</h2></div>
        <a href="javascript:history.back()" style="font-size:13px;text-decoration:none;color:var(--primary)">&larr; Back</a></div>
    <div class="plain">
        <p class="muted" style="font-size:12px">Version <?php echo e(PRIVACY_VERSION); ?> | Lopez National Comprehensive High School (LNCHS), Lopez, Quezon</p>
        <p style="margin-top:10px">This portal helps LNCHS record attendance, share school information and communicate with students and families. This notice explains what personal information we collect, why, and what your rights are under the Data Privacy Act of 2012 (Republic Act No. 10173).</p>

        <h3>What we collect</h3>
        <p><strong>Students:</strong></p>
        <ul><li>Learner Reference Number (LRN), full name, date of birth, grade level, section, phone number, email and username.</li>
            <li>Your password and kiosk PIN, stored only in scrambled (hashed) form. School staff cannot read them.</li>
            <li>Attendance times (morning in, lunch, final out), the gate used, late and absence records, and absence notes you write.</li>
            <li>Codes of the ID card or tag registered to you, and a log of gate scans.</li>
            <li>Parent meeting notices, cutting-class records, class officer roles, the schedule items you add, and reminders (exams, quizzes, projects) you or your teachers create.</li>
            <li>A sign-in cookie. If you choose &ldquo;Keep me signed in&rdquo;, a secure token cookie is kept on that device for 30 days.</li></ul>
        <p><strong>Teachers and staff:</strong> employee ID, name, date of birth, phone, email, position, attendance times and the same scan logs.</p>

        <h3>Why we collect it</h3>
        <ul><li>To record daily attendance and know who is on campus for safety.</li>
            <li>To tell advisers and teachers about lateness, absences and cutting class, and to call parents or guardians when needed.</li>
            <li>To run school services such as schedules, class officers, the calendar and announcements.</li>
            <li>To show a birthday greeting at the gate kiosk.</li></ul>

        <h3>Who can see it</h3>
        <ul><li>Your own records are visible to you after you sign in.</li>
            <li>Teachers and staff can see information they need for their work. Advisers see attendance reports for their own class. Administrators can see all classes.</li>
            <li>We do not sell your information, and it is not shown on the public pages. News articles are public, so they should not contain private student information.</li>
            <li>The portal is hosted on a third-party web hosting service, and its pages load fonts and the school logo from Google servers. These services can see ordinary visit information such as your IP address.</li></ul>

        <h3>How long we keep it</h3>
        <p>We keep records for as long as needed for school administration, and as required by school and Department of Education record rules. After that they are deleted or anonymized.</p>

        <h3>How we protect it</h3>
        <p>Access requires a sign-in, staff pages are limited to staff accounts, passwords and PINs are hashed, repeated wrong attempts are locked, and gate devices must be registered. No system is perfectly secure, so please keep your password and PIN private and sign out on shared devices.</p>

        <h3>Your consent</h3>
        <p>By registering, you agree to this notice. If you are under 18, your parent or guardian should read it and agree on your behalf.</p>

        <h3>Your rights</h3>
        <p>You may ask to be informed about how your data is used, to see it, to correct mistakes, to object to or limit its use, and to ask for it to be blocked or deleted where the law allows. You may also file a complaint with the National Privacy Commission (privacy.gov.ph) if you believe your data has been misused.</p>

        <h3>Contact</h3>
        <p>For questions or requests about your information, contact <?php echo $c; ?>.</p>
        <p class="muted" style="font-size:12px;margin-top:18px">We may update this notice. When the version changes, you will be asked to read and accept it again.</p>
    </div>
</div>
</body>
</html>
