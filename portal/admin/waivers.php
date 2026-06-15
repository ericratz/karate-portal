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
    header('Location: waivers.php?' . http_build_query(array_diff_key($_GET, [])));
    exit;
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
        $msg = 'Waiver granted.';
    }
}


$students = db()->query(
    'SELECT id, first_name, last_name FROM students WHERE active=1 ORDER BY first_name, last_name'
)->fetchAll();

// Filters
$f_student = (int)($_GET['student_id'] ?? 0);
$f_type    = $_GET['type'] ?? '';

$where  = ['1=1'];
$params = [];
if ($f_student) { $where[] = 'pw.student_id = ?'; $params[] = $f_student; }
if ($f_type)    { $where[] = 'pw.waiver_type = ?'; $params[] = $f_type; }

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
            <div class="card-header bg-white fw-semibold">Grant Waiver</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label class="form-label">Student *</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">— select —</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>">
                                <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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
                    <button name="grant" class="btn btn-success w-100">Grant Waiver</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Existing waivers -->
    <div class="col-md-8">

        <!-- Filter bar -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Student</label>
                        <select name="student_id" class="form-select form-select-sm">
                            <option value="">All Students</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $s['id'] === $f_student ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                    <div class="col-md-2">
                        <button class="btn btn-filter btn-sm w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>All Waivers (<?= count($waivers) ?>)</span>
                <?php if (!empty($waivers)): ?>
                <button id="editToggle" class="btn btn-sm btn-outline-secondary" onclick="toggleEdit()">Edit</button>
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
                                    <?= htmlspecialchars($w['first_name'].' '.$w['last_name']) ?>
                                </a>
                            </td>
                            <td><?= ucwords(str_replace('_',' ',$w['waiver_type'])) ?></td>
                            <td><?= htmlspecialchars($w['reason'] ?? '—') ?></td>
                            <td><?= date('j M Y', strtotime($w['granted_date'])) ?></td>
                            <td class="delete-col">
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('Permanently delete this waiver? This cannot be undone.')">
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
    </div>
</div>

<style>
    .delete-col { display: none; }
    table.editing .delete-col { display: table-cell; }
</style>
<script>
function toggleEdit() {
    const table = document.getElementById('waiversTable');
    const btn   = document.getElementById('editToggle');
    const on    = table.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

