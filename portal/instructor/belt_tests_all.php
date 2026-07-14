<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

// Delete
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = post_int('id');
    db()->prepare('DELETE FROM belt_tests WHERE id=?')->execute([$del_id]);
    audit('delete_belt_test', 'belt_test', $del_id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header('Location: belt_tests_all.php?' . http_build_query(array_diff_key($_GET, [])));
        exit;
    }
    // For htmx requests, fall through so hx-select can pull the live count.
}

// Filters
$f_student = get_int('student_id');
$f_result  = get_str('result');
$f_year    = get_int('year');
$filtering = $f_student || $f_result !== '' || $f_year !== 0;

$where  = ['1=1'];
$params = [];
if ($f_student) { $where[] = 'bt.student_id = ?';       $params[] = $f_student; }
if ($f_result)  { $where[] = 'bt.result = ?';            $params[] = $f_result; }
if ($f_year)    { $where[] = 'YEAR(bt.test_date) = ?';   $params[] = $f_year; }

// Years available for the dropdown — actual test years plus the current year
$belt_test_years = db()->query('SELECT DISTINCT YEAR(test_date) AS y FROM belt_tests ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $belt_test_years)) {
    array_unshift($belt_test_years, (int)date('Y'));
}

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

<div id="belt-tests-page-body">
<!-- Filter bar -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end"
              hx-get="belt_tests_all.php" hx-target="#belt-tests-page-body" hx-select="#belt-tests-page-body" hx-swap="outerHTML" hx-push-url="true"
              hx-trigger="change from:select[name='result'], change from:select[name='year'], filter-refresh from:body">
            <div class="col-md-3">
                <label class="form-label small mb-1">Student</label>
                <?php
                $f_student_name = '';
                if ($f_student) {
                    foreach ($all_students as $s) {
                        if ((int)$s['id'] === $f_student) { $f_student_name = $s['first_name'].' '.$s['last_name']; break; }
                    }
                }
                ?>
                <input type="hidden" name="student_id" id="btFilterStudentId" value="<?= $f_student ?: '' ?>">
                <div id="btFilterStudentSelected" class="<?= $f_student ? 'd-flex' : 'd-none' ?> justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold" id="btFilterStudentName"><?= hn($f_student_name) ?></span>
                    <button type="button" id="clearBtFilterStudentBtn" class="btn btn-link btn-sm p-0 text-muted">×</button>
                </div>
                <div class="stu-filter-wrap">
                <input type="text" id="btFilterStudentFilter" class="form-control form-control-sm" placeholder="Type to filter…"
                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                       <?= $f_student ? 'style="display:none"' : '' ?>>
                <div id="btFilterStudentList" class="list-group mt-1 stu-dropdown" style="display:none">
                    <?php foreach ($all_students as $s): ?>
                    <button type="button" class="list-group-item list-group-item-action bt-filter-stu-btn"
                            data-id="<?= (int)$s['id'] ?>"
                            data-name="<?= htmlspecialchars(strtolower($s['first_name'].' '.$s['last_name'].' '.$s['last_name'].' '.$s['first_name'])) ?>"
                            style="display:none">
                        <?= hn($s['first_name'].' '.$s['last_name']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                </div>
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
                <label class="form-label small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All Years</option>
                    <?php foreach ($belt_test_years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $f_year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filtering): ?>
            <div class="col-auto">
                <a href="belt_tests_all.php"
                   hx-get="belt_tests_all.php" hx-target="#belt-tests-page-body" hx-select="#belt-tests-page-body"
                   hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div id="belt-tests-results" class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><?= count($belt_tests) ?> test<?= count($belt_tests) !== 1 ? 's' : '' ?></span>
        <?php if (!empty($belt_tests)): ?>
        <button id="editToggle" class="btn btn-sm btn-outline-secondary">Edit</button>
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
                            <?= hn($t['first_name'].' '.$t['last_name']) ?>
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
                        <?php if ($t['result'] === 'pass'): ?>
                            <span class="badge bg-success">Passed</span>
                        <?php elseif ($t['result'] === 'fail'): ?>
                            <span class="text-danger">✗</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                    <td>
                        <?php if (has_role('admin')): ?>
                        <a href="belt_test_edit.php?id=<?= $t['id'] ?>&ref_pid=<?= $t['student_id'] ?>"
                           class="btn btn-sm btn-outline-primary">Edit</a>
                        <?php endif; ?>
                    </td>
                    <td class="delete-col">
                        <form method="post" class="d-inline"
                              hx-post="belt_tests_all.php" hx-target="#belt-tests-page-body" hx-select="#belt-tests-page-body"
                              hx-swap="outerHTML swap:300ms"
                              hx-confirm="Delete this belt test record? This cannot be undone.">
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
</div><!-- /belt-tests-page-body -->

<style nonce="<?= csp_nonce() ?>">
    .delete-col { display: none; }
    table.editing .delete-col { display: table-cell; }

    /* Student type-to-filter dropdown — overlays content instead of
       growing the card; scrolls once it has more than ~10 rows. */
    .stu-filter-wrap { position: relative; }
    .stu-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1050;
        background: #fff;
        border: 1px solid rgba(0,0,0,.15);
        border-radius: .375rem;
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15);
        max-height: 400px;
        overflow-y: auto;
    }
    [data-bs-theme="dark"] .stu-dropdown {
        background: #2c2f33;
        border-color: rgba(255,255,255,.15);
    }
</style>

<script nonce="<?= csp_nonce() ?>">
function selectBtFilterStudent(id, label) {
    document.getElementById('btFilterStudentId').value = id;
    document.getElementById('btFilterStudentName').textContent = label;
    var sel = document.getElementById('btFilterStudentSelected');
    sel.classList.remove('d-none'); sel.classList.add('d-flex');
    document.getElementById('btFilterStudentFilter').style.display = 'none';
    document.getElementById('btFilterStudentList').style.display = 'none';
    document.body.dispatchEvent(new Event('filter-refresh'));
}
function clearBtFilterStudent() {
    document.getElementById('btFilterStudentId').value = '';
    var sel = document.getElementById('btFilterStudentSelected');
    sel.classList.add('d-none'); sel.classList.remove('d-flex');
    var f = document.getElementById('btFilterStudentFilter');
    f.style.display = ''; f.value = '';
    document.getElementById('btFilterStudentList').style.display = 'none';
    document.querySelectorAll('.bt-filter-stu-btn').forEach(function(b) { b.style.display = 'none'; });
    document.body.dispatchEvent(new Event('filter-refresh'));
}

// #belt-tests-page-body (filter bar + editToggle + table) gets replaced
// wholesale by htmx on filter submits, so delegate from document to survive swaps.
document.addEventListener('click', function(e) {
    var btn;

    if ((btn = e.target.closest('#clearBtFilterStudentBtn'))) {
        clearBtFilterStudent();
        return;
    }
    if ((btn = e.target.closest('.bt-filter-stu-btn'))) {
        selectBtFilterStudent(parseInt(btn.dataset.id, 10), btn.textContent.trim());
        return;
    }
    if ((btn = e.target.closest('#editToggle'))) {
        var t = document.getElementById('beltTestsTable');
        var editing = t.classList.toggle('editing');
        btn.textContent = editing ? 'Done' : 'Edit';
        btn.className   = editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
    }
});
document.addEventListener('input', function(e) {
    if (e.target.id !== 'btFilterStudentFilter') return;
    var q = e.target.value.toLowerCase().trim();
    var any = false;
    document.querySelectorAll('.bt-filter-stu-btn').forEach(function(b) {
        var show = q.length > 0 && b.dataset.name.indexOf(q) !== -1;
        b.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    document.getElementById('btFilterStudentList').style.display = any ? '' : 'none';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

