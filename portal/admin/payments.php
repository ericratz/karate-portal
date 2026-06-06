<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

$msg   = '';
$error = '';

// ── Delete a payment ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = (int)$_POST['id'];
    db()->prepare('DELETE FROM payments WHERE id=?')->execute([$del_id]);
    audit('delete_payment', 'payment', $del_id);
    header('Location: payments.php?' . http_build_query(array_diff_key($_GET, [])));
    exit;
}

// ── Record a manual payment ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    verify_csrf();
    $sid    = (int)($_POST['student_id']     ?? 0);
    $amount = (float)($_POST['amount']       ?? 0);
    $type   = $_POST['payment_type']         ?? '';
    $method = $_POST['payment_method']       ?? '';
    $date   = $_POST['payment_date']         ?? date('Y-m-d H:i:s');
    $month  = $_POST['month_covered']        ?? null;
    $txn    = trim($_POST['transaction_id']  ?? '');
    $notes  = trim($_POST['notes']           ?? '');

    $valid_types   = ['monthly_tuition','registration','belt_test','slc_training','seminar','other'];
    $valid_methods = ['paypal','venmo','cash','check','mail'];

    $payer_name = trim($_POST['payer_name'] ?? '');
    $payer_note = trim($_POST['payer_note'] ?? '');

    if (!$sid || $amount <= 0 || !in_array($type, $valid_types) || !in_array($method, $valid_methods)) {
        $error = 'Please fill in all required fields with valid values.';
    } else {
        db()->prepare(
            'INSERT INTO payments
             (student_id, amount, payment_type, payment_method, transaction_id,
              payment_date, month_covered, notes, payer_name, payer_note, recorded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $sid, $amount, $type, $method,
            $txn ?: null,
            $date,
            ($type === 'monthly_tuition' && $month) ? $month . '-01' : null,
            $notes ?: null,
            $payer_name ?: null,
            $payer_note ?: null,
            current_user_id(),
        ]);

        // Auto-promote guest to student when registration fee is paid
        if ($type === 'registration') {
            db()->prepare("UPDATE students SET student_type='student' WHERE id=?")
                 ->execute([$sid]);
        }
        $msg = 'Payment recorded.';
    }
}

// ── Filters ───────────────────────────────────────────────────
$f_student = (int)($_GET['student_id'] ?? 0);
$f_type    = $_GET['type']   ?? '';
$f_method  = $_GET['method'] ?? '';
$f_from    = $_GET['from']   ?? '';
$f_to      = $_GET['to']     ?? '';

$where  = ['1=1'];
$params = [];

if ($f_student) { $where[] = 'p.student_id = ?'; $params[] = $f_student; }
if ($f_type)    { $where[] = 'p.payment_type = ?'; $params[] = $f_type; }
if ($f_method)  { $where[] = 'p.payment_method = ?'; $params[] = $f_method; }
if ($f_from)    { $where[] = 'DATE(p.payment_date) >= ?'; $params[] = $f_from; }
if ($f_to)      { $where[] = 'DATE(p.payment_date) <= ?'; $params[] = $f_to; }

$sql = 'SELECT p.*, s.first_name, s.last_name, u.username AS recorded_by_name
        FROM payments p
        JOIN students s ON s.id = p.student_id
        LEFT JOIN users u ON u.id = p.recorded_by
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.payment_date DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$total_shown = array_sum(array_column($payments, 'amount'));

$all_students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY last_name, first_name'
)->fetchAll();

// Pre-fill student_id from admin dashboard link
$prefill_student = (int)($_GET['student_id'] ?? 0);
$prefill_type    = $_GET['type'] ?? '';
$action          = $_GET['action'] ?? '';

$page_title = 'Payments';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Payments</h3>
    <button class="btn btn-success btn-sm" data-bs-toggle="collapse" data-bs-target="#addPaymentForm">
        + Record Payment
    </button>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ── Add payment form (collapsible) ── -->
<div class="collapse <?= $action === 'add' ? 'show' : '' ?> mb-4" id="addPaymentForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Record Manual Payment</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>

                <div class="col-md-4">
                    <label class="form-label">Student *</label>
                    <select name="student_id" class="form-select" required>
                        <option value="">— select —</option>
                        <?php foreach ($all_students as $s): ?>
                            <option value="<?= $s['id'] ?>"
                                <?= $s['id'] === $prefill_student ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Amount *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" class="form-control"
                               step="0.01" min="0.01" required
                               value="<?= MONTHLY_FEE ?>">
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Type *</label>
                    <select name="payment_type" class="form-select" required>
                        <?php
                        $types = ['monthly_tuition','registration','belt_test','slc_training','seminar','other'];
                        foreach ($types as $t):
                        ?>
                        <option value="<?= $t ?>" <?= $t === $prefill_type ? 'selected' : '' ?>>
                            <?= ucwords(str_replace('_',' ',$t)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Method *</label>
                    <select name="payment_method" class="form-select" required>
                        <option value="cash">Cash</option>
                        <option value="check">Check</option>
                        <option value="paypal">PayPal</option>
                        <option value="venmo">Venmo</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Month Covered</label>
                    <input type="month" name="month_covered" class="form-control"
                           value="<?= date('Y-m') ?>">
                    <small class="text-muted">Tuition only</small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Transaction ID</label>
                    <input type="text" name="transaction_id" class="form-control"
                           placeholder="PayPal / Venmo ID (optional)">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Payer Name <small class="text-muted">(if different from student)</small></label>
                    <input type="text" name="payer_name" class="form-control"
                           placeholder="e.g. Parent / guardian name">
                </div>

                <div class="col-md-6">
                    <label class="form-label">On-behalf-of Note</label>
                    <input type="text" name="payer_note" class="form-control"
                           placeholder="e.g. Paying for John + Jane, covering 2 months">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Student</label>
                <select name="student_id" class="form-select form-select-sm">
                    <option value="">All Students</option>
                    <?php foreach ($all_students as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $s['id'] === $f_student ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach (['monthly_tuition','registration','belt_test','slc_training','seminar','other'] as $t): ?>
                        <option value="<?= $t ?>" <?= $t === $f_type ? 'selected' : '' ?>>
                            <?= ucwords(str_replace('_',' ',$t)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Method</label>
                <select name="method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach (['cash'=>'Cash','check'=>'Check','paypal'=>'PayPal','venmo'=>'Venmo'] as $mv=>$ml): ?>
                        <option value="<?= $mv ?>" <?= $mv === $f_method ? 'selected' : '' ?>><?= $ml ?></option>
                    <?php endforeach; ?>
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
            <div class="col-auto">
                <button class="btn btn-filter btn-sm">Filter</button>
            </div>
            <div class="col-auto">
                <a href="payments.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>"
                   class="btn btn-filter btn-sm <?= ($f_from === date('Y-m-01') && $f_to === date('Y-m-d') && !$f_student && !$f_type && !$f_method) ? 'active' : '' ?>">This Month</a>
            </div>
            <div class="col-auto">
                <a href="payments.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>"
                   class="btn btn-outline-secondary btn-sm <?= ($f_from === date('Y-01-01') && $f_to === date('Y-m-d') && !$f_student && !$f_type && !$f_method) ? 'active' : '' ?>">This Year</a>
            </div>
            <?php if ($f_from || $f_to || $f_student || $f_type || $f_method): ?>
            <div class="col-auto">
                <a href="payments.php" class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Results ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= count($payments) ?> payment<?= count($payments)!==1?'s':'' ?></span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-success fw-semibold">Total: $<?= number_format($total_shown, 2) ?></span>
            <?php if (!empty($payments)): ?>
            <button id="editToggle" class="btn btn-sm btn-outline-secondary" onclick="toggleEdit()">Edit</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <p class="p-3 text-muted">No payments match the filter.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table id="paymentsTable" class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Month</th>
                    <th>Method</th>
                    <th>Transaction ID</th>
                    <th>Notes</th>
                    <th>By</th>
                    <th class="text-end">Amount</th>
                    <th class="delete-col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="text-nowrap"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                    <td>
                        <a href="../instructor/student_profile.php?id=<?= $p['student_id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($p['last_name'].', '.$p['first_name']) ?>
                        </a>
                        <?php if ($p['payer_name']): ?>
                            <div>paid by <?= htmlspecialchars($p['payer_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= ucwords(str_replace('_',' ',$p['payment_type'])) ?></td>
                    <td class="text-nowrap">
                        <?= $p['month_covered'] ? date('M Y', strtotime($p['month_covered'])) : '—' ?>
                    </td>
                    <td><?= ['paypal'=>'PayPal','venmo'=>'Venmo','cash'=>'Cash','check'=>'Check'][$p['payment_method']] ?? ucfirst($p['payment_method']) ?></td>
                    <td><?= htmlspecialchars($p['transaction_id'] ?? '—') ?></td>
                    <td>
                        <?= htmlspecialchars($p['notes'] ?? '') ?>
                        <?php if ($p['payer_note']): ?>
                            <div class="fst-italic"><?= htmlspecialchars($p['payer_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['recorded_by_name'] ?? '—') ?></td>
                    <td class="text-end fw-semibold">$<?= number_format($p['amount'],2) ?></td>
                    <td class="delete-col">
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete this payment? This cannot be undone.')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
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
    const table = document.querySelector('#paymentsTable');
    const btn   = document.getElementById('editToggle');
    const on    = table.classList.toggle('editing');
    btn.textContent   = on ? 'Done' : 'Edit';
    btn.className     = on ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
