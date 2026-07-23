<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/registration.php';

// Must arrive here from Google callback
if (empty($_SESSION['google_pending'])) {
    header('Location: ' . SITE_URL . '/login.php');
    exit;
}

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . dashboard_url($_SESSION['role']));
    exit;
}

$g_email = (string)$_SESSION['google_pending']['email'];
$g_first = (string)$_SESSION['google_pending']['first'];
$g_last  = (string)$_SESSION['google_pending']['last'];

$error = '';

// ─── POST Handlers ────────────────────────────────────────────────────────────

$action = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? post_str('action') : '';

if ($action === 'back1') {
    // Back to form — clear pending google-reg data (keep google_pending)
    unset($_SESSION['greg'], $_SESSION['greg_matches'], $_SESSION['greg_selection']);

} elseif ($action === 'back2') {
    // Back to match screen — keep form data, clear selection
    unset($_SESSION['greg_selection']);

} elseif ($action === 'step1') {
    verify_csrf();

    $first    = trim(post_str('first_name'));
    $last     = trim(post_str('last_name'));
    $dob      = trim(post_str('date_of_birth'));
    $username = trim(post_str('username'));

    if (!$first || !$last || !$dob || !$username) {
        $error = 'All fields are required.';
    } else {
        $chk = db()->prepare('SELECT id FROM users WHERE username = ?');
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'That username is already taken. Please choose another.';
        } else {
            // Email is locked to Google account — not editable
            $_SESSION['greg'] = [
                'first_name'    => $first,
                'last_name'     => $last,
                'date_of_birth' => $dob,
                'email'         => $g_email,
                'username'      => $username,
                // Random, unusable password — account accessed only via Google OAuth
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            ];
            $_SESSION['greg_matches'] = find_matches($first, $last, $dob, $g_email);
            // No matches → automatically select "new student" and skip to confirm
            if (empty($_SESSION['greg_matches'])) {
                $_SESSION['greg_selection'] = ['type' => 'new', 'student_id' => null];
            }
        }
    }

} elseif ($action === 'select') {
    verify_csrf();
    $sel_type   = post_str('selection_type');
    $student_id = post_int('student_id');

    if (!in_array($sel_type, ['claim', 'new', 'not_listed'], true)) {
        $error = 'Please make a selection to continue.';
    } elseif ($sel_type === 'claim' && !$student_id) {
        $error = 'Please select a student record.';
    } else {
        $_SESSION['greg_selection'] = [
            'type'       => $sel_type,
            'student_id' => ($sel_type === 'claim') ? $student_id : null,
        ];
    }

} elseif ($action === 'confirm') {
    verify_csrf();
    $reg = $_SESSION['greg']            ?? null;
    $sel = $_SESSION['greg_selection']  ?? null;

    if (!$reg || !$sel) {
        $error = 'Session expired. Please start over.';
        unset($_SESSION['greg'], $_SESSION['greg_matches'], $_SESSION['greg_selection']);
    } else {
        try {
            db()->beginTransaction();

            // Determine student type from claimed record (or guest for new)
            if ($sel['type'] === 'claim') {
                $rs = db()->prepare('SELECT student_type FROM students WHERE id = ? AND user_id IS NULL');
                $rs->execute([$sel['student_id']]);
                $stype = $rs->fetchColumn();
                if (!$stype) {
                    throw new Exception('That student record was already claimed. Please go back and try again.');
                }
                // Guard: student_type='admin' is no longer valid; treat as student
                if ($stype === 'admin') $stype = 'student';
            } else {
                $stype = 'guest';
            }

            // 1. Create user
            db()->prepare(
                'INSERT INTO users (username, password_hash, email, first_name, last_name, date_of_birth)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $reg['username'],
                $reg['password_hash'],
                $reg['email'],
                $reg['first_name'],
                $reg['last_name'],
                $reg['date_of_birth'] ?: null,
            ]);
            $user_id = (int)db()->lastInsertId();

            // 2. Link or create student record
            if ($sel['type'] === 'claim') {
                db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')
                     ->execute([$user_id, $sel['student_id']]);
                $student_id = (int)$sel['student_id'];
                $alert_type = 'claimed_existing';
            } else {
                db()->prepare(
                    'INSERT INTO students
                         (user_id, first_name, last_name, date_of_birth, email,
                          student_type, registration_date, waiver_signed, injury_waiver, active)
                     VALUES (?, ?, ?, ?, ?, \'guest\', CURDATE(), 0, 0, 1)'
                )->execute([
                    $user_id,
                    $reg['first_name'],
                    $reg['last_name'],
                    $reg['date_of_birth'] ?: null,
                    $reg['email'],
                ]);
                $student_id = (int)db()->lastInsertId();
                $alert_type = ($sel['type'] === 'new') ? 'new_student' : 'needs_linking';
            }

            // 3. Create admin dashboard alert
            db()->prepare(
                'INSERT INTO link_requests (user_id, student_id, request_type) VALUES (?, ?, ?)'
            )->execute([$user_id, $student_id, $alert_type]);

            // Stamp last_login
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user_id]);
            db()->commit();

            // 4. Log user in immediately
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user_id;
            $_SESSION['role']     = $stype;
            $_SESSION['username'] = $reg['username'];
            audit('google_register', 'user', $user_id, "type=$alert_type");

            unset($_SESSION['greg'], $_SESSION['greg_matches'], $_SESSION['greg_selection'], $_SESSION['google_pending']);
            header('Location: ' . dashboard_url($stype));
            exit;

        } catch (Exception $e) {
            db()->rollBack();
            log_event('error', 'auth', 'Google registration failed', ['message' => $e->getMessage()]);
            $error = $e->getMessage();
        }
    }
}

// ─── Determine display step ───────────────────────────────────────────────────

if (!empty($_SESSION['greg'])) {
    if (!empty($_SESSION['greg_selection'])) {
        $step = 'confirm';
    } elseif (!empty($_SESSION['greg_matches'])) {
        $step = 'match';
    } else {
        $_SESSION['greg_selection'] = ['type' => 'new', 'student_id' => null];
        $step = 'confirm';
    }
} else {
    $step = 'form';
}

$reg_data = $_SESSION['greg']           ?? [];
$matches  = $_SESSION['greg_matches']   ?? [];
$sel      = $_SESSION['greg_selection'] ?? [];

// Load claimed student details for the confirm screen
$confirm_student = null;
if ($step === 'confirm' && ($sel['type'] ?? '') === 'claim') {
    $cs = db()->prepare(
        'SELECT s.first_name, s.last_name, s.date_of_birth, s.city_state_zip, s.email, s.student_type,
                (SELECT r.name FROM student_ranks sr
                 JOIN ranks r ON r.id = sr.rank_id
                 WHERE sr.student_id = s.id
                 ORDER BY r.rank_order DESC LIMIT 1) AS rank_name
         FROM students s WHERE s.id = ?'
    );
    $cs->execute([$sel['student_id']]);
    $confirm_student = $cs->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register — <?= htmlspecialchars(SITE_NAME) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style nonce="<?= csp_nonce() ?>">
        body { background: #f0f0f0; }
        .register-card { max-width: 520px; margin: 48px auto 80px; }
        .register-card .card-header {
            background: #6f42c1; color: #fff;
            text-align: center; padding: 1.25rem 1.25rem .75rem;
        }
        .register-card .card-header h4  { margin: 0; font-weight: 600; }
        .register-card .card-header small { opacity: .85; }

        /* Step dots */
        .step-dots { display: flex; gap: 6px; justify-content: center; margin-top: .5rem; }
        .step-dot  { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,.35); }
        .step-dot.active { background: #fff; }

        /* Clickable option / match cards */
        .option-card {
            cursor: pointer;
            border: 2px solid #dee2e6 !important;
            border-radius: .375rem;
            transition: border-color .15s, background-color .15s;
            user-select: none;
        }
        .option-card:hover    { border-color: #6f42c1 !important; background: #faf7ff; }
        .option-card.selected { border-color: #6f42c1 !important; background-color: #f3eeff !important; }

        /* Match card details row */
        .card-meta { font-size: .8rem; color: #6c757d; }

        /* Confirm detail box */
        .confirm-detail {
            background: #f8f4ff;
            border: 1px solid #d6c8f5;
            border-radius: .5rem;
            padding: 1.25rem;
        }
        .confirm-detail .c-label {
            font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
            color: #7c68a8; font-weight: 600; margin-bottom: 1px;
        }
        .confirm-detail .c-value { font-size: .95rem; color: #212529; }
    </style>
</head>
<body>

<div class="register-card">
<div class="card shadow">

    <!-- ── Card header with step indicator ── -->
    <div class="card-header">
        <h4>Shotokan Karate</h4>
        <small>
            <?php if ($step === 'form')   echo 'Complete Your Registration';
            elseif ($step === 'match')    echo 'Find Your Record';
            else                          echo 'Confirm &amp; Create Account'; ?>
        </small>
        <div class="step-dots">
            <div class="step-dot <?= $step === 'form'    ? 'active' : '' ?>"></div>
            <div class="step-dot <?= $step === 'match'   ? 'active' : '' ?>"></div>
            <div class="step-dot <?= $step === 'confirm' ? 'active' : '' ?>"></div>
        </div>
    </div>

    <div class="card-body p-4">

    <?php if ($error): ?>
        <div class="alert alert-danger py-2 mb-3"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════ -->
    <?php if ($step === 'form'): ?>
    <!-- ── Step 1: Registration Form ─────────────────────────────── -->

        <p class="text-muted small mb-3">
            Signed in as <strong><?= htmlspecialchars($g_email) ?></strong> via Google.
            Fill in a few more details to finish setting up your account.
        </p>

        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="step1">
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" required
                           value="<?= htmlspecialchars((string)($reg_data['first_name'] ?? $g_first)) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" required
                           value="<?= htmlspecialchars((string)($reg_data['last_name'] ?? $g_last)) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                    <input type="date" name="date_of_birth" class="form-control" required
                           value="<?= htmlspecialchars((string)($reg_data['date_of_birth'] ?? '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control bg-light"
                           value="<?= htmlspecialchars($g_email) ?>" disabled>
                    <div class="form-text">Provided by Google — used to sign in.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Choose a Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required autocomplete="off"
                           value="<?= htmlspecialchars((string)($reg_data['username'] ?? '')) ?>">
                    <div class="form-text">Used for display within the portal.</div>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100">Next &rarr;</button>
                </div>
            </div>
        </form>

    <!-- ════════════════════════════════════════════════════════════ -->
    <?php elseif ($step === 'match'): ?>
    <!-- ── Step 2: Match Records ──────────────────────────────────── -->

        <p class="text-muted small mb-3">
            We found existing records that might be you.
            Select yours to link your account, or choose an option below.
        </p>

        <div class="d-flex flex-column gap-2 mb-3" id="matchList">

            <?php foreach ($matches as $m):
                $dob_fmt = $m['date_of_birth'] ? date('d M', (int) strtotime($m['date_of_birth'])) : null;
                $masked  = $m['email'] ? mask_email($m['email']) : null;
            ?>
            <div class="option-card match-card select-match-card p-3"
                 data-type="claim" data-student-id="<?= (int)$m['id'] ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <span class="fw-semibold"><?= hn($m['first_name'].' '.$m['last_name']) ?></span>
                    <?php if ($m['rank_name']): ?>
                    <span class="badge bg-secondary" style="font-size:.7rem"><?= htmlspecialchars($m['rank_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-meta mt-1 d-flex flex-wrap gap-2">
                    <?php if ($dob_fmt):             ?><span>🎂 <?= $dob_fmt ?></span><?php endif; ?>
                    <?php if ($m['city_state_zip']): ?><span>📍 <?= htmlspecialchars($m['city_state_zip']) ?></span><?php endif; ?>
                    <?php if ($masked):              ?><span>✉ <?= htmlspecialchars($masked) ?></span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="option-card select-match-card p-3 d-flex align-items-start gap-3"
                 data-type="new" data-student-id="0">
                <span class="fs-4 lh-1 mt-1">🥋</span>
                <div>
                    <div class="fw-semibold">I'm a new student</div>
                    <div class="text-muted small">I don't have any existing records here</div>
                </div>
            </div>

            <div class="option-card select-match-card p-3 d-flex align-items-start gap-3"
                 data-type="not_listed" data-student-id="0">
                <span class="fs-4 lh-1 mt-1">🔍</span>
                <div>
                    <div class="fw-semibold">My record isn't listed</div>
                    <div class="text-muted small">I've trained here before but don't see my record above</div>
                </div>
            </div>

        </div>

        <form method="post" id="selectForm">
            <?= csrf_input() ?>
            <input type="hidden" name="action"         value="select">
            <input type="hidden" name="selection_type" id="selType"      value="">
            <input type="hidden" name="student_id"     id="selStudentId" value="0">
            <button type="submit" class="btn btn-primary w-100" id="continueBtn" disabled>
                Continue &rarr;
            </button>
        </form>

        <form method="post" class="text-center mt-2">
            <input type="hidden" name="action" value="back1">
            <button type="submit" class="btn btn-link btn-sm text-muted p-0">&larr; Back</button>
        </form>

    <!-- ════════════════════════════════════════════════════════════ -->
    <?php else: ?>
    <!-- ── Step 3: Confirm ────────────────────────────────────────── -->

        <?php if (($sel['type'] ?? '') === 'claim' && $confirm_student): ?>
            <!-- Claiming an existing record -->

            <p class="text-muted small mb-3">
                Please confirm this is your record. Once you click
                <strong>Yes, this is me</strong> your account will be
                created and linked to it.
            </p>

            <div class="confirm-detail mb-3">
                <div class="row g-2">
                    <div class="col-12">
                        <div class="c-label">Name</div>
                        <div class="c-value fw-semibold">
                            <?= hn($confirm_student['first_name'].' '.$confirm_student['last_name']) ?>
                        </div>
                    </div>
                    <?php if ($confirm_student['rank_name']): ?>
                    <div class="col-6">
                        <div class="c-label">Current Belt</div>
                        <div class="c-value"><?= htmlspecialchars($confirm_student['rank_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($confirm_student['date_of_birth']): ?>
                    <div class="col-6">
                        <div class="c-label">Date of Birth</div>
                        <div class="c-value"><?= date('d M', (int) strtotime($confirm_student['date_of_birth'])) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($confirm_student['city_state_zip']): ?>
                    <div class="col-12">
                        <div class="c-label">Location</div>
                        <div class="c-value"><?= htmlspecialchars($confirm_student['city_state_zip']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($confirm_student['email']): ?>
                    <div class="col-12">
                        <div class="c-label">Email on File</div>
                        <div class="c-value"><?= htmlspecialchars(mask_email($confirm_student['email'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="confirm">
                <button class="btn btn-primary w-100">Yes, this is me — Create Account</button>
            </form>
            <form method="post" class="text-center mt-2">
                <input type="hidden" name="action" value="back2">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">&larr; That's not me, go back</button>
            </form>

        <?php elseif (($sel['type'] ?? '') === 'not_listed'): ?>
            <!-- Not listed — will create guest record, flag for Noji -->

            <p class="text-muted small mb-3">
                We'll create a new record from your info and flag it so
                Noji can link it to your actual records. You'll have access right away.
            </p>

            <div class="confirm-detail mb-3">
                <div class="row g-2">
                    <div class="col-12">
                        <div class="c-label">Name</div>
                        <div class="c-value fw-semibold"><?= hn((string)$reg_data['first_name'].' '.(string)$reg_data['last_name']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="c-label">Username</div>
                        <div class="c-value"><?= htmlspecialchars((string)$reg_data['username']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="c-label">Date of Birth</div>
                        <div class="c-value"><?= $reg_data['date_of_birth'] ? date('d M', (int) strtotime((string)$reg_data['date_of_birth'])) : '—' ?></div>
                    </div>
                    <div class="col-12">
                        <div class="c-label">Email</div>
                        <div class="c-value"><?= htmlspecialchars((string)$reg_data['email']) ?></div>
                    </div>
                </div>
            </div>

            <div class="alert alert-info py-2 small mb-3">
                Noji will be notified to manually link your account to your training history.
            </div>

            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="confirm">
                <button class="btn btn-primary w-100">Create Account</button>
            </form>
            <form method="post" class="text-center mt-2">
                <input type="hidden" name="action" value="back2">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">&larr; Back</button>
            </form>

        <?php else: ?>
            <!-- New student (no existing record / no matches found) -->

            <p class="text-muted small mb-3">
                <?= empty($matches)
                    ? 'No existing records matched your information. We\'ll create a fresh student record for you.'
                    : 'We\'ll create a new student record from your info.' ?>
                You&rsquo;ll have access right away.
            </p>

            <div class="confirm-detail mb-3">
                <div class="row g-2">
                    <div class="col-12">
                        <div class="c-label">Name</div>
                        <div class="c-value fw-semibold"><?= hn((string)$reg_data['first_name'].' '.(string)$reg_data['last_name']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="c-label">Username</div>
                        <div class="c-value"><?= htmlspecialchars((string)$reg_data['username']) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="c-label">Date of Birth</div>
                        <div class="c-value"><?= $reg_data['date_of_birth'] ? date('d M', (int) strtotime((string)$reg_data['date_of_birth'])) : '—' ?></div>
                    </div>
                    <div class="col-12">
                        <div class="c-label">Email</div>
                        <div class="c-value"><?= htmlspecialchars((string)$reg_data['email']) ?></div>
                    </div>
                </div>
            </div>

            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="confirm">
                <button class="btn btn-primary w-100">Create Account</button>
            </form>
            <?php if (!empty($matches)): ?>
            <form method="post" class="text-center mt-2">
                <input type="hidden" name="action" value="back2">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">&larr; Back</button>
            </form>
            <?php else: ?>
            <form method="post" class="text-center mt-2">
                <input type="hidden" name="action" value="back1">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">&larr; Back</button>
            </form>
            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

    </div><!-- /card-body -->

    <div class="card-footer text-center text-muted small py-2">
        <?php if ($step === 'form'): ?>
            Wrong account? <a href="<?= SITE_URL ?>/login.php">Go back</a>
        <?php else: ?>
            &nbsp;
        <?php endif; ?>
    </div>

</div><!-- /card -->
</div><!-- /register-card -->

<?php if ($step === 'match'): ?>
<script nonce="<?= csp_nonce() ?>">
function selectMatch(type, studentId, el) {
    document.querySelectorAll('.option-card').forEach(function(c) { c.classList.remove('selected'); });
    el.classList.add('selected');
    document.getElementById('selType').value      = type;
    document.getElementById('selStudentId').value = studentId;
    var btn = document.getElementById('continueBtn');
    btn.disabled    = false;
    btn.textContent = (type === 'claim') ? 'This is me — Continue →' : 'Continue →';
}
document.querySelectorAll('.select-match-card').forEach(function(card) {
    card.addEventListener('click', function() {
        selectMatch(card.dataset.type, parseInt(card.dataset.studentId, 10), card);
    });
});
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
</body>
</html>
