<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
session_start();
date_default_timezone_set('America/Denver');

$date = date('Y-m-d');
$ip   = $_SERVER['REMOTE_ADDR'] ?? '';

// Standalone page (no auth.php) — its own CSP nonce, one per request.
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

$__csp_nonce = csp_nonce();
$__csp_report_url = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/karate/portal/api/csp_report.php';
header("Reporting-Endpoints: csp-endpoint=\"$__csp_report_url\"");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$__csp_nonce' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.paypal.com https://www.paypalobjects.com https://accounts.google.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src 'self' data: https://www.paypalobjects.com; frame-src https://www.paypal.com https://accounts.google.com; connect-src 'self' https://www.paypal.com https://api.paypal.com https://api.sandbox.paypal.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; report-uri /karate/portal/api/csp_report.php; report-to csp-endpoint");

// ── PIN gate ──────────────────────────────────────────────────
function get_checkin_pin(): string {
    try {
        $row = db()->query('SELECT pin FROM checkin_settings WHERE id=1')->fetch();
        return $row ? (string)$row['pin'] : '';
    } catch (Exception $e) { return ''; }
}

function pin_rate_limited(string $ip): bool {
    if ($ip === '127.0.0.1' || $ip === '::1') return false;
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE identifier LIKE ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute(['pin:' . $ip . ':%']);
        return (int)$stmt->fetchColumn() >= 5;
    } catch (Exception $e) { return false; }
}

function record_failed_pin(string $ip, string $guess): void {
    // Still tracked in login_attempts for rate-limiting purposes (see
    // pin_rate_limited() above); also logged to the activity log so admins
    // can see incorrect attempts without a dedicated UI.
    try {
        db()->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute(['pin:' . $ip . ':' . $guess]);
        db()->exec("DELETE FROM login_attempts WHERE identifier LIKE 'pin:%' AND attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    } catch (Exception $e) {}
    log_event('warning', 'checkin', "Incorrect check-in PIN entered: $guess", ['ip' => $ip]);
}

$pin_ok    = ($_SESSION['checkin_pin_date'] ?? '') === $date;
$pin_error = '';

if (!$pin_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pin') {
    $guess = trim($_POST['pin'] ?? '');
    if (pin_rate_limited($ip)) {
        $pin_error = 'Too many attempts. Try again in 15 minutes.';
    } elseif ($guess === get_checkin_pin()) {
        $_SESSION['checkin_pin_date'] = $date;
        header('Location: checkin.php');
        exit;
    } else {
        record_failed_pin($ip, $guess);
        $pin_error = 'Incorrect PIN.';
    }
}

// ── Main check-in logic (PIN-gated) ──────────────────────────
$flash = $checked_in = $already_present = $error = $student = null;
$all_students = $checkin_students = $present_students = [];
$present_ids  = [];
$session_id_now = null;

if ($pin_ok) {
    function get_or_create_session(string $date): int {
        $stmt = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $stmt->execute([$date]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        db()->prepare(
            "INSERT INTO class_sessions (session_date, class_type) VALUES (?, 'class')"
        )->execute([$date]);
        return (int)db()->lastInsertId();
    }

    $all_students = db()->query(
        "SELECT id, first_name, last_name FROM students ORDER BY last_name, first_name"
    )->fetchAll();

    $flash          = $_SESSION['checkin_flash'] ?? null;
    unset($_SESSION['checkin_flash']);

    $checked_in      = ($flash['status'] ?? '') === 'ok';
    $already_present = ($flash['status'] ?? '') === 'already';
    $error           = $flash['error'] ?? '';
    $student         = $flash['student'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'pin') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $action     = $_POST['action'] ?? 'checkin';

        if (!$student_id) {
            $_SESSION['checkin_flash'] = ['error' => 'Please select your name.'];
            header('Location: checkin.php'); exit;
        }

        $stu = db()->prepare('SELECT id, first_name, last_name FROM students WHERE id = ?');
        $stu->execute([$student_id]);
        $student = $stu->fetch();
        if (!$student) {
            $_SESSION['checkin_flash'] = ['error' => 'Student not found.'];
            header('Location: checkin.php'); exit;
        }

        if ($action === 'unmark') {
            $sess = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
            $sess->execute([$date]);
            $sid = $sess->fetchColumn();
            if ($sid) {
                db()->prepare('UPDATE attendance SET present=0 WHERE student_id=? AND session_id=?')
                    ->execute([$student_id, $sid]);
                // An empty class (nobody present) isn't worth keeping a record of
                $remaining = db()->prepare('SELECT COUNT(*) FROM attendance WHERE session_id=? AND present=1');
                $remaining->execute([$sid]);
                if ((int)$remaining->fetchColumn() === 0) {
                    db()->prepare('DELETE FROM class_sessions WHERE id=?')->execute([$sid]);
                }
            }
            header('Location: checkin.php'); exit;
        }

        $session_id = get_or_create_session($date);
        $chk = db()->prepare('SELECT present FROM attendance WHERE student_id = ? AND session_id = ?');
        $chk->execute([$student_id, $session_id]);
        $att = $chk->fetch();
        if ($att && $att['present']) {
            $_SESSION['checkin_flash'] = ['status' => 'already', 'student' => $student];
            header('Location: checkin.php'); exit;
        }
        db()->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by)
             VALUES (?,?,1,NULL)
             ON DUPLICATE KEY UPDATE present=1, recorded_by=NULL'
        )->execute([$student_id, $session_id]);
        $_SESSION['checkin_flash'] = ['status' => 'ok', 'student' => $student];
        header('Location: checkin.php'); exit;
    }

    $session_id_now = (function() use ($date) {
        $s = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $s->execute([$date]);
        return $s->fetchColumn() ?: null;
    })();

    if ($session_id_now) {
        $pa = db()->prepare('SELECT student_id FROM attendance WHERE session_id = ? AND present = 1');
        $pa->execute([$session_id_now]);
        $present_ids = array_flip($pa->fetchAll(PDO::FETCH_COLUMN));
    }

    $checkin_students = array_values(array_filter($all_students, fn($s) => !isset($present_ids[$s['id']])));
    $present_students = array_values(array_filter($all_students, fn($s) =>  isset($present_ids[$s['id']])));
    usort($present_students, fn($a, $b) => strcasecmp($a['last_name'], $b['last_name']));
}

function fmt_name(array $s): string {
    return htmlspecialchars(ucwords(strtolower($s['first_name'] . ' ' . $s['last_name'])));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check In — <?= date('j M Y') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
      integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
      crossorigin="anonymous">
<style nonce="<?= csp_nonce() ?>">
body { background: #f5f5f5; }
.checkin-wrap { max-width: 480px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
.big-check { font-size: 5rem; line-height: 1; }
.student-list .list-group-item { display: flex; align-items: center; gap: .6rem; }
.student-list .badge-present { font-size: .75rem; margin-left: auto; }
.pin-digit { font-size: 2rem; letter-spacing: .4em; text-align: center; }
</style>
</head>
<body>
<div class="checkin-wrap">

    <div class="text-center text-muted small mb-3">
        Shotokan Karate &amp; Self-defense &mdash; <?= date('l, j F Y') ?>
    </div>

    <?php if (!$pin_ok): ?>
    <!-- ── PIN entry ── -->
    <div class="card border-0 shadow p-4 mb-4">
        <h5 class="fw-bold text-center mb-1">Class Check-in</h5>
        <p class="text-center text-muted small mb-3">Enter today's PIN to continue</p>
        <?php if ($pin_error): ?>
        <div class="alert alert-danger text-center py-2"><?= htmlspecialchars($pin_error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="pin">
            <input type="tel" name="pin" id="pinInput"
                   class="form-control form-control-lg pin-digit mb-3"
                   placeholder="••••" maxlength="20" autofocus required>
            <button class="btn btn-primary btn-lg w-100 fw-semibold">Enter</button>
        </form>
    </div>

    <?php else: ?>
    <!-- ── Check-in UI ── -->

    <?php if ($checked_in): ?>
    <div class="card border-0 shadow text-center p-4 mb-4">
        <div class="big-check mb-2">✅</div>
        <h4 class="fw-bold"><?= fmt_name($student) ?></h4>
        <p class="text-success fw-semibold mb-3">Checked in successfully!</p>
        <a href="checkin.php" class="btn btn-outline-secondary btn-sm">Check in another student</a>
    </div>
    <?php elseif ($already_present): ?>
    <div class="card border-0 shadow text-center p-4 mb-4">
        <div class="big-check mb-2">✅</div>
        <h4 class="fw-bold"><?= fmt_name($student) ?></h4>
        <p class="text-muted mb-3">Already marked present.</p>
        <a href="checkin.php" class="btn btn-outline-secondary btn-sm">Check in another student</a>
    </div>
    <?php elseif ($error): ?>
    <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$checked_in && !$already_present): ?>
    <div class="card border-0 shadow p-4 mb-4">
        <h5 class="fw-bold text-center mb-3">Find your name to check in</h5>
        <input type="text" id="nameFilter" class="form-control form-control-lg mb-2"
               placeholder="Type your name…" autocomplete="off" autocorrect="off"
               autocapitalize="off" spellcheck="false">
        <div id="nameList" class="list-group"
             <?= count($checkin_students) > 10 ? 'style="max-height:340px;overflow-y:auto"' : '' ?>>
            <?php foreach ($checkin_students as $s): ?>
            <button type="button" class="list-group-item list-group-item-action name-btn"
                    data-name="<?= htmlspecialchars(strtolower($s['first_name'] . ' ' . $s['last_name'])) ?>"
                    data-id="<?= (int)$s['id'] ?>"
                    style="display:none">
                <?= fmt_name($s) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <div id="noMatch" class="text-center text-muted small mt-3" style="display:none">
            No match — ask an instructor to add you.
        </div>
    </div>
    <form method="post" id="checkinForm">
        <input type="hidden" name="student_id" id="checkinStudentId" value="">
    </form>
    <?php endif; ?>

    <?php if (!empty($present_ids)): ?>
    <div class="card border-0 shadow">
        <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
            <span>Today's Attendance</span>
            <span id="present-count" class="badge bg-success"><?= count($present_ids) ?> present</span>
        </div>
        <div id="live-list" class="list-group list-group-flush student-list"
             <?= count($present_ids) > 10 ? 'style="max-height:420px;overflow-y:auto"' : '' ?>>
            <?php foreach ($present_students as $s): ?>
            <div class="list-group-item list-group-item-success d-flex align-items-center" data-sid="<?= $s['id'] ?>">
                <span class="status-icon me-2">✅</span>
                <span class="flex-grow-1"><?= fmt_name($s) ?></span>
                <form method="post" class="ms-2 unmark-form">
                    <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="action" value="unmark">
                    <button type="submit" class="btn btn-sm btn-outline-danger py-0">Unmark</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div id="live-list-empty" class="card border-0 shadow" style="display:none">
        <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
            <span>Today's Attendance</span>
            <span id="present-count" class="badge bg-success">0 present</span>
        </div>
        <div id="live-list" class="list-group list-group-flush student-list"></div>
    </div>
    <?php endif; ?>

    <?php endif; // pin_ok ?>

</div>

<script nonce="<?= csp_nonce() ?>">
<?php if ($pin_ok): ?>
var filterInput = document.getElementById('nameFilter');
if (filterInput) {
    filterInput.focus();
    filterInput.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        var btns = document.querySelectorAll('.name-btn');
        var any = false;
        btns.forEach(function(btn) {
            var show = q.length > 0 && btn.dataset.name.indexOf(q) !== -1;
            btn.style.display = show ? '' : 'none';
            if (show) any = true;
        });
        document.getElementById('noMatch').style.display = (q.length > 0 && !any) ? '' : 'none';
    });
}

function submitCheckin(id) {
    document.getElementById('checkinStudentId').value = id;
    document.getElementById('checkinForm').submit();
}

document.querySelectorAll('.name-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        submitCheckin(parseInt(btn.dataset.id, 10));
    });
});

document.querySelectorAll('.unmark-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var name = form.closest('[data-sid]').querySelector('.flex-grow-1').textContent.trim();
        if (!confirm('Remove ' + name + ' from today\'s attendance?')) e.preventDefault();
    });
});
<?php endif; ?>
</script>
</body>
</html>
