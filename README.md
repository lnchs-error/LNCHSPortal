# LNCHS Portal

Web portal for **Lopez National Comprehensive High School** (Lopez, Quezon, Philippines): attendance, class schedule and calendar, classroom officers, reminders, parent notices, news, and RFID/keypad attendance kiosks at the school gates.

Plain **PHP 8 + mysqli (MariaDB)**, no framework, built to run on free shared hosting (InfinityFree). The data belongs to minors, so security and privacy come first.

## Features
- **Accounts:** students (12-digit LRN) and teachers/staff (employee ID), PIN for the kiosk, "keep me signed in", password reset by staff, privacy-notice consent.
- **Attendance:** daily check-in, lunch and check-out in the portal and at the gate kiosks, late and overtime rules, absence notes, no-class days from the calendar.
- **Gate kiosks:** card/tag scan or LRN/employee number + PIN, 4 gates, device pairing, lockouts, birthday greeting, offline queue for card/tag scans.
- **Teachers/staff:** parent notices, class attendance reports (daily and monthly, CSV), cutting-class records, cards and tags, news, calendar, reminders for a class, officers.
- **Students:** schedule (with the class timetable), reminders for exams/quizzes/projects, officers, calendar.

## Layout
| Path | Purpose |
|---|---|
| `config.example.php` | Shared bootstrap: DB, sessions, CSRF, rate limiting, remember-me. Copy to `config.php` (git-ignored) and fill in the secrets. |
| `setup.php` | One-time installer (creates/updates tables). Delete after use. |
| `signin.php`, `emp.php` | Student and staff sign-in/registration. |
| `inter.php` | Dashboard. |
| `kiosk.php`, `kiosk_setup.php`, `sw.js` | Gate kiosk page, device pairing, service worker for offline reopen. |
| `attlib.php` | Shared helpers: classes, school days, statistics, reminders. |
| `adviser_report.php`, `calendar.php`, `reminders.php`, `schedule.php`, `officers.php`, `cutting.php`, `manage_*.php`, `reset_password.php`, `change_password.php` | Feature pages. |
| `privacy.php`, `news.php`, `index.html`, `errorcode/` | Public pages. |
| `.htaccess`, `.user.ini` | Server rules (blocks uploads and oversized requests, error pages, config protection). |

## Setup
1. Create a MySQL/MariaDB database. Copy `config.example.php` to `config.php` and replace every `YOUR_*` and `CHANGE-ME-*` value.
2. Upload the files, open `setup.php?key=<SETUP_KEY>` once, then delete `setup.php`.
3. Pair each kiosk at `/kiosk_setup.php`.

## Security design (please review critically)
- Prepared statements everywhere; output escaped with `e()`.
- CSRF token on every POST form; logins and kiosk PINs are rate-limited and locked after repeated failures.
- Roles are read from the database on every request; staff-only pages call `require_staff()`.
- Passwords and PINs are bcrypt hashes. Remember-me stores only a hash of a random validator.
- Kiosk is authenticated by a hashed device token in an HttpOnly cookie; scan results never show a name before the PIN passes.
- No file uploads anywhere (the web server refuses multipart requests).

## Known limits and decisions
- Free hosting: no cron, no outbound email, one PHP version managed by the host.
- Offline kiosk queue covers card/tag scans only, because a PIN cannot be checked without the server.
- Section names are typed by students, so classes are matched case-insensitively on `grade_level` + `section`.
- Not covered by tests yet; reviews that suggest simple, host-friendly improvements are especially welcome.

## Secrets
`config.php`, `diag.php` and any `.zip` are git-ignored. Never commit database credentials or the setup/staff/kiosk codes.
