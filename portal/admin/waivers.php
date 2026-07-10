<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$msg = $error = '';

// Delete waiver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = (int)$_POST['id'];
    db()->prepare('DELETE FROM payment_waivers WHERE id=?')->execute([$del_id]);
    audit('delete_waiver', 'waiver', $del_id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header('Location: waivers.php?' . http_build_query(array_diff_key($_GET, [])));
        exit;
    }
    // For htmx requests, fall through to render the full page — htmx's
    // hx-select="#waivers-page-body" pulls out the filter bar + results
    // together, so the count and filter-bar state (Clear button, selects)
    // all stay in sync live.
}

// Grant waiver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant'])) {
    verify_csrf();
    $sid    = (int)$_POST['student_id'];
    $type   = $_POST['waiver_type'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $date   = $_POST['granted_date'] ?? date('Y-m-d');

    $valid = ['monthly_tuition','registration','belt_test','slc_training','seminar','all'];
    if (!$sid || !in_array($type, $valid)) {
        $error = 'Select a student and waiver type.';
    } else {
        db()->prepare(
            'INSERT INTO payment_waivers
             (student_id, waiver_type, reason, granted_by, granted_date)
             VALUES (?,?,?,?,?)'
        )->execute([$sid, $type, $reason ?: null, current_user_id(), $date]);
        audit('grant_waiver', 'waiver', null, "student_id=$sid type=$type");
        header('Location: waivers.php?granted=1');
        exit;
    }
}

if (isset($_GET['granted'])) $msg = 'Exemption granted.';


$students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

// Filters
$f_student = (int)($_GET['student_id'] ?? 0);
$f_type    = $_GET['type'] ?? '';
$f_year    = (int)($_GET['year'] ?? 0);

$where  = ['1=1'];
$params = [];
if ($f_student) { $where[] = 'pw.student_id = ?'; $params[] = $f_student; }
if ($f_type)    { $where[] = 'pw.waiver_type = ?'; $params[] = $f_type; }
if ($f_year)    { $where[] = 'YEAR(pw.granted_date) = ?'; $params[] = $f_year; }

// Years available for the dropdown — actual waiver years plus the current year
$waiver_years = db()->query('SELECT DISTINCT YEAR(granted_date) AS y FROM payment_waivers ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $waiver_years)) {
    array_unshift($waiver_years, (int)date('Y'));
}

$stmt = db()->prepare(
    'SELECT pw.*, s.first_name, s.last_name, u.username AS granted_by_name
     FROM payment_waivers pw
     JOIN students s ON s.id = pw.student_id
     LEFT JOIN users u ON u.id = pw.granted_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY pw.granted_date DESC'
);
$stmt->execute($params);
$waivers = $stmt->fetchAll();

$page_title = 'Exempt';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Exempt</h3>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-4">

    <!-- Grant form -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Grant Exemption</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label class="form-label">Student *</label>
                        <input type="hidden" name="student_id" id="grantStudentId" value="">
                        <div id="grantStudentSelected" class="d-none justify-content-between align-items-center mb-1">
                            <span class="fw-semibold" id="grantStudentName"></span>
                            <button type="button" id="clearGrantStudentBtn" class="btn btn-link btn-sm p-0 text-muted">change</button>
                        </div>
                        <div class="stu-filter-wrap">
                        <input type="text" id="grantStudentFilter" class="form-control" placeholder="Type student name…"
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                        <div id="grantStudentList" class="list-group mt-1 stu-dropdown" style="display:none">
                            <?php foreach ($students as $s):
                                $fn = (string)($s['first_name'] ?? '');
                                $ln = (string)($s['last_name'] ?? '');
                            ?>
                            <button type="button" class="list-group-item list-group-item-action grant-stu-btn"
                                    data-id="<?= (int)$s['id'] ?>"
                                    data-name="<?= htmlspecialchars(strtolower($fn.' '.$ln.' '.$ln.' '.$fn)) ?>"
                                    style="display:none">
                                <?= hn($fn.' '.$ln) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Waiver Type *</label>
                        <select name="waiver_type" class="form-select" required>
                            <option value="monthly_tuition">Monthly Tuition</option>
                            <option value="registration">Registration Fee</option>
                            <option value="belt_test">Belt Test Fee</option>
                            <option value="slc_training">SLC Training</option>
                            <option value="seminar">Seminar</option>
                            <option value="all">All Fees</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="3"
                                  placeholder="Financial hardship, scholarship, etc."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Granted Date</label>
                        <input type="date" name="granted_date" class="form-control"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <button name="grant" class="btn btn-success w-100">Grant Exemption</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Existing waivers -->
    <div class="col-md-8">
    <div id="waivers-page-body">

        <!-- Filter bar -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="get" id="filterForm" class="row g-2 align-items-end"
                      hx-get="waivers.php" hx-target="#waivers-page-body" hx-select="#waivers-page-body" hx-swap="outerHTML" hx-push-url="true"
                      hx-trigger="change from:select[name='type'], change from:select[name='year'], filter-refresh from:body">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Student</label>
                        <?php
                        $f_student_name = '';
                        if ($f_student) {
                            foreach ($students as $s) {
                                if ((int)$s['id'] === $f_student) { $f_student_name = $s['first_name'].' '.$s['last_name']; break; }
                            }
                        }
                        ?>
                        <input type="hidden" name="student_id" id="filterStudentId" value="<?= $f_student ?: '' ?>">
                        <div id="filterStudentSelected" class="<?= $f_student ? 'd-flex' : 'd-none' ?> justify-content-between align-items-center mb-1">
                            <span class="small fw-semibold" id="filterStudentName"><?= hn($f_student_name) ?></span>
                            <button type="button" id="clearFilterStudentBtn" class="btn btn-link btn-sm p-0 text-muted">×</button>
                        </div>
                        <div class="stu-filter-wrap">
                        <input type="text" id="filterStudentFilter" class="form-control form-control-sm" placeholder="Type to filter…"
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                               <?= $f_student ? 'style="display:none"' : '' ?>>
                        <div id="filterStudentList" class="list-group mt-1 stu-dropdown" style="display:none">
                            <?php foreach ($students as $s):
                                $fn = (string)($s['first_name'] ?? '');
                                $ln = (string)($s['last_name'] ?? '');
                            ?>
                            <button type="button" class="list-group-item list-group-item-action filter-stu-btn"
                                    data-id="<?= (int)$s['id'] ?>"
                                    data-name="<?= htmlspecialchars(strtolower($fn.' '.$ln.' '.$ln.' '.$fn)) ?>"
                                    style="display:none">
                                <?= hn($fn.' '.$ln) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Type</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <?php foreach (['monthly_tuition'=>'Monthly Tuition','registration'=>'Registration Fee','belt_test'=>'Belt Test Fee','slc_training'=>'SLC Training','seminar'=>'Seminar','all'=>'All Fees'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $v === $f_type ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <option value="">All Years</option>
                            <?php foreach ($waiver_years as $y): ?>
                                <option value="<?= (int)$y ?>" <?= $f_year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($f_student || $f_type || $f_year): ?>
                    <div class="col-auto">
                        <a href="waivers.php"
                           hx-get="waivers.php" hx-target="#waivers-page-body" hx-select="#waivers-page-body"
                           hx-swap="outerHTML" hx-push-url="true"
                           class="btn btn-filter btn-sm">Clear</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div id="waivers-results" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>All Exemptions (<?= count($waivers) ?>)</span>
                <?php if (!empty($waivers)): ?>
                <button id="editToggle" class="btn btn-sm btn-outline-secondary">Edit</button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($waivers)): ?>
                    <p class="p-3 text-muted">No waivers match the filter.</p>
                <?php else: ?>
                <div style="overflow-x:auto">
                <table id="waiversTable" class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Granted</th>
                            <th class="delete-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($waivers as $w): ?>
                        <tr>
                            <td>
                                <a href="student_edit.php?id=<?= $w['student_id'] ?>" class="text-decoration-none">
                                    <?= hn($w['first_name'].' '.$w['last_name']) ?>
                                </a>
                            </td>
                            <td><?= ucwords(str_replace('_',' ',$w['waiver_type'])) ?></td>
                            <td><?= htmlspecialchars($w['reason'] ?? '—') ?></td>
                            <td><?= date('d M Y', strtotime($w['granted_date'])) ?></td>
                            <td class="delete-col">
                                <form method="post" class="d-inline"
                                      hx-post="waivers.php" hx-target="#waivers-page-body" hx-select="#waivers-page-body"
                                      hx-swap="outerHTML swap:300ms"
                                      hx-confirm="Permanently delete this exemption? This cannot be undone.">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $w['id'] ?>">
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
    </div><!-- /waivers-page-body -->
    </div>
</div>

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
// Grant form student filter
function selectGrantStudent(id, label) {
    document.getElementById('grantStudentId').value = id;
    document.getElementById('grantStudentName').textContent = label;
    var sel = document.getElementById('grantStudentSelected');
    sel.classList.remove('d-none'); sel.classList.add('d-flex');
    document.getElementById('grantStudentFilter').style.display = 'none';
    document.getElementById('grantStudentList').style.display = 'none';
}
function clearGrantStudent() {
    document.getElementById('grantStudentId').value = '';
    var sel = document.getElementById('grantStudentSelected');
    sel.classList.add('d-none'); sel.classList.remove('d-flex');
    var f = document.getElementById('grantStudentFilter');
    f.style.display = ''; f.value = '';
    document.getElementById('grantStudentList').style.display = 'none';
    document.querySelectorAll('.grant-stu-btn').forEach(function(b) { b.style.display = 'none'; });
}
document.getElementById('grantStudentFilter').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    var any = false;
    document.querySelectorAll('.grant-stu-btn').forEach(function(b) {
        var show = q.length > 0 && b.dataset.name.indexOf(q) !== -1;
        b.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    document.getElementById('grantStudentList').style.display = any ? '' : 'none';
});

// Filter bar student filter
function selectFilterStudent(id, label) {
    document.getElementById('filterStudentId').value = id;
    document.getElementById('filterStudentName').textContent = label;
    var sel = document.getElementById('filterStudentSelected');
    sel.classList.remove('d-none'); sel.classList.add('d-flex');
    document.getElementById('filterStudentFilter').style.display = 'none';
    document.getElementById('filterStudentList').style.display = 'none';
    document.body.dispatchEvent(new Event('filter-refresh'));
}
function clearFilterStudent() {
    document.getElementById('filterStudentId').value = '';
    var sel = document.getElementById('filterStudentSelected');
    sel.classList.add('d-none'); sel.classList.remove('d-flex');
    var f = document.getElementById('filterStudentFilter');
    f.style.display = ''; f.value = '';
    document.getElementById('filterStudentList').style.display = 'none';
    document.querySelectorAll('.filter-stu-btn').forEach(function(b) { b.style.display = 'none'; });
    document.body.dispatchEvent(new Event('filter-refresh'));
}
document.getElementById('filterStudentFilter').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    var any = false;
    document.querySelectorAll('.filter-stu-btn').forEach(function(b) {
        var show = q.length > 0 && b.dataset.name.indexOf(q) !== -1;
        b.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    document.getElementById('filterStudentList').style.display = any ? '' : 'none';
});

// Delegated click handling — #waivers-results (editToggle + table) gets
// replaced wholesale by htmx on filter submits, so bindings on those specific
// elements wouldn't survive a swap. Delegating from document does.
document.addEventListener('click', function(e) {
    var btn;

    if ((btn = e.target.closest('#clearGrantStudentBtn'))) {
        clearGrantStudent();
        return;
    }
    if ((btn = e.target.closest('.grant-stu-btn'))) {
        selectGrantStudent(parseInt(btn.dataset.id, 10), btn.textContent.trim());
        return;
    }
    if ((btn = e.target.closest('#clearFilterStudentBtn'))) {
        clearFilterStudent();
        return;
    }
    if ((btn = e.target.closest('.filter-stu-btn'))) {
        selectFilterStudent(parseInt(btn.dataset.id, 10), btn.textContent.trim());
        return;
    }
    if ((btn = e.target.closest('#editToggle'))) {
        var t = document.getElementById('waiversTable');
        var editing = t.classList.toggle('editing');
        btn.textContent = editing ? 'Done' : 'Edit';
        btn.className   = editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
        return;
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

