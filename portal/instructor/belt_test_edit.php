<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

$test_id = (int)($_GET['id'] ?? 0);
$ref_pid = (int)($_GET['ref_pid'] ?? 0); // student profile to return to
$msg = $error = '';

// ── Delete ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && $test_id) {
    verify_csrf();
    db()->prepare('DELETE FROM belt_tests WHERE id=?')->execute([$test_id]);
    audit('delete_belt_test', 'belt_test', $test_id);
    $dest = $ref_pid ? "student_profile.php?id=$ref_pid" : 'belt_tests_all.php';
    header("Location: $dest");
    exit;
}

// ── Save ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    verify_csrf();
    $sid     = (int)($_POST['student_id']      ?? 0);
    $date    = $_POST['test_date']             ?? '';
    $rank_id = (int)($_POST['rank_id']         ?? 0);
    $result  = $_POST['result']                ?? 'pending';
    $fee     = isset($_POST['fee_paid'])        ? 1 : 0;
    $awarded = isset($_POST['belt_awarded'])    ? 1 : 0;
    $notes   = trim($_POST['notes']            ?? '');
    $rpid    = (int)($_POST['ref_pid']         ?? 0);

    if (!$sid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$rank_id) {
        $error = 'Student, date, and rank are required.';
    } else {
        if ($awarded && $result !== 'pass') $awarded = 0;

        if ($test_id) {
            db()->prepare(
                'UPDATE belt_tests
                 SET student_id=?, test_date=?, rank_testing_for=?,
                     result=?, fee_paid=?, belt_awarded=?, notes=?
                 WHERE id=?'
            )->execute([$sid, $date, $rank_id, $result, $fee, $awarded, $notes ?: null, $test_id]);
        } else {
            db()->prepare(
                'INSERT INTO belt_tests
                 (student_id, test_date, rank_testing_for, result, fee_paid, belt_awarded, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$sid, $date, $rank_id, $result, $fee, $awarded, $notes ?: null, current_user_id()]);
            $test_id = (int)db()->lastInsertId();
        }

        if ($awarded) {
            db()->prepare(
                'INSERT IGNORE INTO student_ranks (student_id, rank_id, achieved_date)
                 VALUES (?,?,?)'
            )->execute([$sid, $rank_id, $date]);
            audit('belt_awarded', 'student', $sid, "rank_id=$rank_id date=$date");
        }

        $dest = $rpid ? "student_profile.php?id=$rpid" : "belt_test_edit.php?id=$test_id&saved=1" . ($rpid ? "&ref_pid=$rpid" : '');
        header("Location: $dest");
        exit;
    }
}

// ── Load existing ────────────────────────────────────────────
$test = null;
if ($test_id) {
    $stmt = db()->prepare('SELECT * FROM belt_tests WHERE id=?');
    $stmt->execute([$test_id]);
    $test = $stmt->fetch();
    if (!$test) { header('Location: belt_tests_all.php'); exit; }
    if (!$ref_pid) $ref_pid = 0;
}

$prefill_student = $test ? $test['student_id'] : (int)($_GET['student_id'] ?? 0);

$all_students = db()->query(
    'SELECT id, first_name, last_name FROM students WHERE active=1 ORDER BY last_name, first_name'
)->fetchAll();

$all_ranks = db()->query(
    'SELECT id, kyu_dan, name FROM ranks ORDER BY rank_order'
)->fetchAll();

if (isset($_GET['saved'])) $msg = 'Saved.';

$page_title = $test_id ? 'Edit Belt Test' : 'New Belt Test';
include __DIR__ . '/../includes/header.php';

$back = $ref_pid ? "student_profile.php?id=$ref_pid" : 'belt_tests_all.php';
$back_label = $ref_pid ? '← Profile' : '← All Belt Tests';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= $back ?>" class="btn btn-outline-secondary btn-sm"><?= $back_label ?></a>
    <h4 class="mb-0"><?= $test_id ? 'Edit Belt Test' : 'New Belt Test' ?></h4>
    <?php if ($test_id): ?>
    <form method="post" class="d-inline ms-auto"
          onsubmit="return confirm('Delete this belt test record? This cannot be undone.')">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="alert alert-light border mb-4 small">
    <strong>Workflow:</strong>
    Record the test → set <em>Pass / Fail</em> after evaluation →
    check <em>Belt Awarded</em> when the belt is physically given.
    Rank is only updated in the student's record when Belt Awarded is checked.
</div>

<div class="card border-0 shadow-sm" style="max-width:640px">
    <div class="card-body">
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="ref_pid" value="<?= $ref_pid ?>">

            <div class="mb-3">
                <label class="form-label">Student *</label>
                <select name="student_id" class="form-select" required
                        <?= $prefill_student ? 'disabled' : '' ?>>
                    <option value="">— select —</option>
                    <?php foreach ($all_students as $s): ?>
                    <option value="<?= $s['id'] ?>"
                        <?= $s['id'] === $prefill_student ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($prefill_student): ?>
                <input type="hidden" name="student_id" value="<?= $prefill_student ?>">
                <?php endif; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label">Test Date *</label>
                    <input type="date" name="test_date" class="form-control" required
                           value="<?= htmlspecialchars($test['test_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-7">
                    <label class="form-label">Testing For *</label>
                    <select name="rank_id" class="form-select" required>
                        <option value="">— select rank —</option>
                        <?php foreach ($all_ranks as $r): ?>
                        <option value="<?= $r['id'] ?>"
                            <?= isset($test) && $test['rank_testing_for'] == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['kyu_dan'].' — '.$r['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Evaluation</label>
                <select name="result" class="form-select" id="evalSelect"
                        onchange="syncBeltAwarded()">
                    <option value="pending" <?= (!$test || $test['result']==='pending') ? 'selected':'' ?>>— Pending —</option>
                    <option value="pass"    <?= (isset($test) && $test['result']==='pass')    ? 'selected':'' ?>>✓ Pass</option>
                    <option value="fail"    <?= (isset($test) && $test['result']==='fail')    ? 'selected':'' ?>>✗ Fail</option>
                </select>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-auto">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="fee_paid" id="feePaid" value="1"
                               <?= (isset($test) && $test['fee_paid']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="feePaid">Fee Paid</label>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="belt_awarded" id="beltAwarded" value="1"
                               <?= (isset($test) && $test['belt_awarded']) ? 'checked' : '' ?>
                               <?= (!isset($test) || $test['result'] !== 'pass') ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="beltAwarded">Belt Awarded</label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control"
                       value="<?= htmlspecialchars($test['notes'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-primary">
                <?= $test_id ? 'Save Changes' : 'Record Test' ?>
            </button>
        </form>
    </div>
</div>

<script>
function syncBeltAwarded() {
    var cb = document.getElementById('beltAwarded');
    var passing = document.getElementById('evalSelect').value === 'pass';
    cb.disabled = !passing;
    if (!passing) cb.checked = false;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
