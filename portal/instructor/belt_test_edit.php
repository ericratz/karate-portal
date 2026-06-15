<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

$test_id = (int)($_GET['id'] ?? 0);
$ref_pid = (int)($_GET['ref_pid'] ?? 0);
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
    $sid     = (int)($_POST['student_id']   ?? 0);
    $date    = $_POST['test_date']          ?? '';
    $rank_id = (int)($_POST['rank_id']      ?? 0);
    $score   = $_POST['score'] !== '' ? (int)$_POST['score'] : null;
    $fee     = isset($_POST['fee_paid'])    ? 1 : 0;
    $awarded = isset($_POST['belt_awarded']) ? 1 : 0;
    $notes   = trim($_POST['notes']         ?? '');
    $rpid    = (int)($_POST['ref_pid']      ?? 0);

    // Auto-compute result from score
    if ($score === null) {
        $result = 'pending';
    } elseif ($score >= 80) {
        $result = 'pass';
    } else {
        $result = 'fail';
        $awarded = 0;
    }
    // Auto-award belt on pass — no separate checkbox needed
    if ($result === 'pass') $awarded = 1;
    if ($awarded && $result !== 'pass') $awarded = 0;

    if (!$sid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$rank_id) {
        $error = 'Student, date, and rank are required.';
    } elseif ($score !== null && ($score < 0 || $score > 100)) {
        $error = 'Score must be between 0 and 100.';
    } else {
        if ($test_id) {
            db()->prepare(
                'UPDATE belt_tests
                 SET student_id=?, test_date=?, rank_testing_for=?,
                     result=?, score=?, fee_paid=?, belt_awarded=?, notes=?
                 WHERE id=?'
            )->execute([$sid, $date, $rank_id, $result, $score, $fee, $awarded, $notes ?: null, $test_id]);
        } else {
            db()->prepare(
                'INSERT INTO belt_tests
                 (student_id, test_date, rank_testing_for, result, score, fee_paid, belt_awarded, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$sid, $date, $rank_id, $result, $score, $fee, $awarded, $notes ?: null, current_user_id()]);
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
}

$prefill_student = $test ? $test['student_id'] : (int)($_GET['student_id'] ?? 0);

$all_students = db()->query(
    'SELECT id, first_name, last_name FROM students WHERE active=1 ORDER BY first_name, last_name'
)->fetchAll();

$all_ranks = db()->query(
    'SELECT id, kyu_dan, name FROM ranks ORDER BY rank_order'
)->fetchAll();

// Build student info map for JS (current rank + belt test history)
$student_info = [];
foreach ($all_students as $s) {
    $rank_q = db()->prepare(
        'SELECT r.name, r.kyu_dan FROM student_ranks sr
         JOIN ranks r ON r.id = sr.rank_id
         WHERE sr.student_id = ? ORDER BY r.rank_order DESC LIMIT 1'
    );
    $rank_q->execute([$s['id']]);
    $rank_row = $rank_q->fetch();

    $hist_q = db()->prepare(
        'SELECT bt.test_date, r.kyu_dan, r.name AS rank_name,
                bt.result, bt.score, bt.belt_awarded
         FROM belt_tests bt
         JOIN ranks r ON r.id = bt.rank_testing_for
         WHERE bt.student_id = ?
         ORDER BY bt.test_date DESC'
    );
    $hist_q->execute([$s['id']]);
    $history = $hist_q->fetchAll(PDO::FETCH_ASSOC);

    $student_info[$s['id']] = [
        'rank'    => $rank_row ? $rank_row['kyu_dan'] . ' — ' . $rank_row['name'] : '—',
        'history' => $history,
    ];
}

if (isset($_GET['saved'])) $msg = 'Saved.';

$page_title = $test_id ? 'Edit Belt Test' : 'New Belt Test';
include __DIR__ . '/../includes/header.php';

$back = $ref_pid ? "student_profile.php?id=$ref_pid" : 'belt_tests_all.php';
$back_label = $ref_pid ? '← Profile' : '← All Belt Tests';
?>

<div class="d-flex align-items-center gap-3 mb-4">
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
    Record the test → enter score after evaluation (80% or above = pass).
    A passing score automatically records the rank in the student's Rank History and marks the belt as awarded.
</div>

<div class="card border-0 shadow-sm" style="max-width:640px">
    <div class="card-body">
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="ref_pid" value="<?= $ref_pid ?>">

            <div class="mb-3">
                <label class="form-label">Student *</label>
                <select name="student_id" class="form-select" id="studentSelect" required
                        <?= $prefill_student ? 'disabled' : '' ?>
                        onchange="onStudentChange(this.value)">
                    <option value="">— select —</option>
                    <?php foreach ($all_students as $s): ?>
                    <option value="<?= $s['id'] ?>"
                        <?= $s['id'] === $prefill_student ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($prefill_student): ?>
                <input type="hidden" name="student_id" value="<?= $prefill_student ?>">
                <?php endif; ?>
            </div>

            <!-- Student info panel (populated via JS) -->
            <div id="studentInfoPanel" class="border rounded small mb-3 p-3"
                 style="<?= $prefill_student ? '' : 'display:none' ?>">
                <div class="mb-2"><strong>Current Rank:</strong> <span id="studentCurrentRank">—</span></div>
                <div id="studentHistoryWrap">
                    <strong class="d-block mb-1">Belt Test History</strong>
                    <div id="studentHistory"></div>
                </div>
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

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">
                        Score (%)
                        <span class="text-muted small">— leave blank if pending</span>
                    </label>
                    <div class="input-group">
                        <input type="number" name="score" class="form-control" id="scoreInput"
                               min="0" max="100" step="1"
                               value="<?= isset($test['score']) && $test['score'] !== null ? (int)$test['score'] : '' ?>"
                               oninput="updateResultPreview()">
                        <span class="input-group-text">%</span>
                    </div>
                    <div id="resultPreview" class="mt-1 small"></div>
                </div>
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
                               <?= (isset($test) && $test['belt_awarded']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="beltAwarded">Test Passed</label>
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
const STUDENT_INFO = <?= json_encode($student_info) ?>;

function onStudentChange(sid) {
    const panel   = document.getElementById('studentInfoPanel');
    const rankEl  = document.getElementById('studentCurrentRank');
    const histEl  = document.getElementById('studentHistory');
    if (!sid || !STUDENT_INFO[sid]) {
        panel.style.display = 'none';
        return;
    }
    const info = STUDENT_INFO[sid];
    rankEl.textContent = info.rank;

    // Belt test history table
    if (!info.history || info.history.length === 0) {
        histEl.innerHTML = '<span class="text-muted">No belt tests on record.</span>';
    } else {
        let html = '<table class="table table-sm table-bordered mb-0 mt-1">'
                 + '<thead class="table-light"><tr>'
                 + '<th>Date</th><th>Testing For</th><th>Score</th><th>Result</th>'
                 + '</tr></thead><tbody>';
        info.history.forEach(function(row) {
            const date   = row.test_date ? row.test_date.substring(0, 10) : '—';
            const rank   = row.kyu_dan + ' — ' + row.rank_name;
            const score  = row.score !== null && row.score !== '' ? row.score + '%' : '—';
            let result   = '—';
            if (row.result === 'pass') {
                result = '<span class="badge bg-success">Pass</span>'
                       + (row.belt_awarded == 1 ? ' <span class="badge bg-primary">Passed</span>' : '');
            } else if (row.result === 'fail') {
                result = '<span class="badge bg-danger">Fail</span>';
            } else if (row.result === 'pending') {
                result = '<span class="badge bg-secondary">Pending</span>';
            }
            html += '<tr><td>' + date + '</td><td>' + rank + '</td><td>' + score + '</td><td>' + result + '</td></tr>';
        });
        html += '</tbody></table>';
        histEl.innerHTML = html;
    }

    panel.style.display = '';
}

function updateResultPreview() {
    const val = document.getElementById('scoreInput').value;
    const el  = document.getElementById('resultPreview');
    if (val === '') { el.textContent = ''; return; }
    const score = parseInt(val, 10);
    if (score >= 80) {
        el.innerHTML = '<span class="badge bg-success">Pass</span>';
    } else {
        el.innerHTML = '<span class="badge bg-danger">Fail</span>';
    }
}

// Initialise for prefilled student
(function() {
    const sel = document.getElementById('studentSelect');
    if (sel && sel.value) onStudentChange(sel.value);
    <?php if ($prefill_student): ?>
    onStudentChange(<?= $prefill_student ?>);
    <?php endif; ?>
    updateResultPreview();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

