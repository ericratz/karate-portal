<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = (int)$_POST['id'];
    db()->prepare('DELETE FROM belt_tests WHERE id=?')->execute([$del_id]);
    audit('delete_belt_test', 'belt_test', $del_id);
    header('Location: belt_tests_all.php?' . http_build_query(array_diff_key($_GET, [])));
    exit;
}

// Filters
$f_student = (int)($_GET['student_id'] ?? 0);
$f_result  = $_GET['result'] ?? '';
$f_from    = $_GET['from']   ?? '';
$f_to      = $_GET['to']     ?? '';

$where  = ['1=1'];
$params = [];
if ($f_student) { $where[] = 'bt.student_id = ?'; $params[] = $f_student; }
if ($f_result)  { $where[] = 'bt.result = ?';      $params[] = $f_result; }
if ($f_from)    { $where[] = 'bt.test_date >= ?';  $params[] = $f_from; }
if ($f_to)      { $where[] = 'bt.test_date <= ?';  $params[] = $f_to; }

$stmt = db()->prepare(
    'SELECT bt.id, bt.test_date, bt.result, bt.score, bt.fee_paid, bt.belt_awarded, bt.notes,
            s.id AS student_id, s.first_name, s.last_name,
            r.kyu_dan, r.name AS rank_name
     FROM belt_tests bt
     JOIN students s ON s.id = bt.student_id
     JOIN ranks r    ON r.id = bt.rank_testing_for
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY bt.test_date DESC, s.first_name, s.last_name'
);
$stmt->execute($params);
$belt_tests = $stmt->fetchAll();

$all_students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

$page_title = 'All Belt Tests';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">All Belt Tests</h4>
    <a href="belt_test_edit.php" class="btn btn-success btn-sm ms-auto">+ New Test</a>
</div>

<!-- Filter bar -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Student</label>
                <select name="student_id" class="form-select form-select-sm">
                    <option value="">All Students</option>
                    <?php foreach ($all_students as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] === $f_student ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Result</label>
                <select name="result" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending" <?= $f_result==='pending' ? 'selected':'' ?>>Pending</option>
                    <option value="pass"    <?= $f_result==='pass'    ? 'selected':'' ?>>Pass</option>
                    <option value="fail"    <?= $f_result==='fail'    ? 'selected':'' ?>>Fail</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($f_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($f_to) ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-filter btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="belt_tests_all.php" class="btn btn-filter btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><?= count($belt_tests) ?> test<?= count($belt_tests) !== 1 ? 's' : '' ?></span>
        <?php if (!empty($belt_tests)): ?>
        <button id="editToggle" class="btn btn-sm btn-outline-secondary" onclick="toggleEdit()">Edit</button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($belt_tests)): ?>
            <p class="p-3 text-muted">No belt tests match the filter.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table id="beltTestsTable" class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Testing For</th>
                    <th>Score</th>
                    <th class="text-center">Fee Paid</th>
                    <th class="text-center">Test Passed</th>
                    <th>Notes</th>
                    <th></th>
                    <th class="delete-col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($belt_tests as $t): ?>
                <tr>
                    <td class="text-nowrap"><?= date('d M Y', strtotime($t['test_date'])) ?></td>
                    <td>
                        <a href="student_profile.php?id=<?= $t['student_id'] ?>">
                            <?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($t['kyu_dan']) ?></td>
                    <td>
                        <?php if (isset($t['score']) && $t['score'] !== null): ?>
                            <?php if ($t['result'] === 'pass'): ?>
                                <span class="badge bg-success"><?= (int)$t['score'] ?>%</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= (int)$t['score'] ?>%</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $t['fee_paid'] ? '<span class="text-success">✓</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <?= $t['belt_awarded'] ? '<span class="badge bg-success">Passed</span>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                    <td>
                        <a href="belt_test_edit.php?id=<?= $t['id'] ?>&ref_pid=<?= $t['student_id'] ?>"
                           class="btn btn-sm btn-outline-primary">Edit</a>
                    </td>
                    <td class="delete-col">
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete this belt test record? This cannot be undone.')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .delete-col { display: none; }
    table.editing .delete-col { display: table-cell; }
</style>
<script>
function toggleEdit() {
    var table = document.getElementById('beltTestsTable');
    var btn   = document.getElementById('editToggle');
    var on    = table.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

