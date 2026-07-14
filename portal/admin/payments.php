<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

// If no registration payment remains, revert student back to guest
function sync_registration_status(int $student_id): void {
    $stmt = db()->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND payment_type='registration'");
    $stmt->execute([$student_id]);
    if (!(int)$stmt->fetchColumn()) {
        db()->prepare("UPDATE students SET student_type='guest' WHERE id=? AND student_type='student'")
             ->execute([$student_id]);
    }
}

$msg   = '';
$error = '';

// ── Delete a payment ─────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = post_int('id');
    // Fetch student_id before deleting so we can sync status after
    $del_row = db()->prepare('SELECT student_id FROM payments WHERE id=?');
    $del_row->execute([$del_id]);
    $del_sid = (int)$del_row->fetchColumn();
    db()->prepare('DELETE FROM payments WHERE id=?')->execute([$del_id]);
    audit('delete_payment', 'payment', $del_id);
    if ($del_sid) sync_registration_status($del_sid);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header('Location: payments.php?' . http_build_query($_GET));
        exit;
    }
    // For htmx requests, fall through so hx-select can pull the live count.
}

// ── Edit a payment ────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'edit_payment') {
    verify_csrf();
    $pid    = post_int('id');
    $amount = (float)post_str('amount', '0');
    $type   = post_str('payment_type');
    $method = post_str('payment_method');
    $date   = post_str('payment_date', date('Y-m-d'));
    $month  = post_str('month_covered');
    $txn    = trim(post_str('transaction_id'));
    $notes  = trim(post_str('notes'));
    $valid_types   = ['monthly_tuition','registration','belt_test','slc_training','seminar','other'];
    $valid_methods = ['paypal','cash','check','mail'];
    if ($pid && $amount > 0 && in_array($type, $valid_types) && in_array($method, $valid_methods)) {
        db()->prepare(
            'UPDATE payments SET payment_date=?, payment_type=?, payment_method=?, amount=?,
             transaction_id=?, notes=?, month_covered=? WHERE id=?'
        )->execute([
            $date, $type, $method, $amount,
            $txn ?: null,
            $notes ?: null,
            ($type === 'monthly_tuition' && $month) ? $month . '-01' : null,
            $pid,
        ]);
        // Fetch student_id for status sync
        $sid_row = db()->prepare('SELECT student_id FROM payments WHERE id=?');
        $sid_row->execute([$pid]);
        $edit_sid = (int)$sid_row->fetchColumn();
        // Promote to student if type is now registration
        if ($type === 'registration' && $edit_sid) {
            db()->prepare("UPDATE students SET student_type='student' WHERE id=? AND student_type='guest'")->execute([$edit_sid]);
        }
        // Revert to guest if registration was removed
        if ($edit_sid) sync_registration_status($edit_sid);
        audit('edit_payment', 'payment', $pid);
    }
    header('Location: payments.php?' . http_build_query($_GET));
    exit;
}

// ── Record a manual payment ───────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    verify_csrf();
    $sid    = post_int('student_id');
    $amount = (float)post_str('amount', '0');
    $type   = post_str('payment_type');
    $method = post_str('payment_method', 'paypal');
    $date   = post_str('payment_date', date('Y-m-d H:i:s'));
    $month  = post_str('month_covered') ?: null;
    $txn    = trim(post_str('transaction_id'));
    $notes  = trim(post_str('notes'));

    $valid_types   = ['monthly_tuition','registration','belt_test','slc_training','seminar','other'];
    $valid_methods = ['paypal','cash','check','mail'];

    $payer_name = trim(post_str('payer_name'));
    $payer_note = trim(post_str('payer_note'));

    if (!$sid || $amount <= 0 || !in_array($type, $valid_types) || !in_array($method, $valid_methods)) {
        $error = 'Please fill in all required fields with valid values.';
    } else {
        // Duplicate check (warning only, never blocks): tuition already
        // recorded for this student + month?
        $dup_count = 0;
        if ($type === 'monthly_tuition' && $month) {
            $dup_q = db()->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE student_id=? AND payment_type='monthly_tuition' AND month_covered=?"
            );
            $dup_q->execute([$sid, $month . '-01']);
            $dup_count = (int)$dup_q->fetchColumn();
        }

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
        // Send payment receipt email to student
        $receipt_student = db()->prepare('SELECT first_name, last_name, email FROM students WHERE id = ?');
        $receipt_student->execute([$sid]);
        $rs = $receipt_student->fetch();
        if ($rs && !empty($rs['email'])) {
            $rs_name = trim($rs['first_name'] . ' ' . $rs['last_name']);
            send_payment_receipt(
                $rs['email'],
                $rs_name,
                [['type' => $type, 'amount' => $amount]],
                $amount,
                $method,
                $txn ?: null
            );
        }

        $done_params = ['recorded' => 1];
        if ($dup_count > 0) $done_params['dup'] = $dup_count + 1;
        header('Location: payments.php?' . http_build_query($_GET + $done_params));
        exit;
    }
}

if (isset($_GET['recorded'])) $msg = 'Payment recorded.';
$dup_warning = isset($_GET['dup'])
    ? 'Heads up: this student now has ' . get_int('dup') . ' tuition payments recorded for that month. If this was accidental, delete the extra one below.'
    : '';

// ── Filters ───────────────────────────────────────────────────
$f_student = get_int('student_id');
$f_type    = get_str('type');
$f_method  = get_str('method');
$f_year    = get_int('year');

$where  = ['1=1'];
$params = [];

if ($f_student) { $where[] = 'p.student_id = ?'; $params[] = $f_student; }
if ($f_type)    { $where[] = 'p.payment_type = ?'; $params[] = $f_type; }
if ($f_method)  { $where[] = 'p.payment_method = ?'; $params[] = $f_method; }
if ($f_year)    { $where[] = 'YEAR(p.payment_date) = ?'; $params[] = $f_year; }

// Years available for the dropdown — actual payment years plus the current year
$payment_years = db()->query('SELECT DISTINCT YEAR(payment_date) AS y FROM payments ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $payment_years)) {
    array_unshift($payment_years, (int)date('Y'));
}

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
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

// Pre-fill student_id from admin dashboard link
$prefill_student = get_int('student_id');
$prefill_type    = get_str('type');
$action          = get_str('action');

$page_title = 'Payments';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Payments</h3>
    <div class="d-flex gap-2">
        <a href="student_edit.php" class="btn btn-success btn-sm">+ New Participant</a>
        <button class="btn btn-success btn-sm" data-bs-toggle="collapse" data-bs-target="#addPaymentForm">
            + Record Payment
        </button>
    </div>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= $msg ?></div><?php endif; ?>
<?php if ($dup_warning): ?><div class="alert alert-warning"><?= htmlspecialchars($dup_warning) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ── Add payment form (collapsible) ── -->
<div class="collapse <?= $action === 'add' ? 'show' : '' ?> mb-4" id="addPaymentForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Record Manual Payment</div>
        <div class="card-body">
            <form method="post" class="row g-3" id="addPayForm">
                <?= csrf_input() ?>

                <div class="col-md-4">
                    <label class="form-label">Student *</label>
                    <?php
                    $prefill_student_name = '';
                    if ($prefill_student) {
                        foreach ($all_students as $s) {
                            if ((int)$s['id'] === $prefill_student) { $prefill_student_name = $s['first_name'].' '.$s['last_name']; break; }
                        }
                    }
                    ?>
                    <input type="hidden" name="student_id" id="payGrantStudentId" value="<?= $prefill_student ?: '' ?>">
                    <div id="payGrantStudentSelected" class="<?= $prefill_student ? 'd-flex' : 'd-none' ?> justify-content-between align-items-center mb-1">
                        <span class="fw-semibold" id="payGrantStudentName"><?= hn($prefill_student_name) ?></span>
                        <button type="button" id="clearPayGrantStudentBtn" class="btn btn-link btn-sm p-0 text-muted">change</button>
                    </div>
                    <input type="text" id="payGrantStudentFilter" class="form-control" placeholder="Type student name…"
                           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                           <?= $prefill_student ? 'style="display:none"' : 'required' ?>>
                    <div id="payGrantStudentList" class="list-group mt-1"
                         style="<?= count($all_students) > 8 ? 'max-height:200px;overflow-y:auto;' : '' ?><?= $prefill_student ? 'display:none' : '' ?>">
                        <?php foreach ($all_students as $s): ?>
                        <button type="button" class="list-group-item list-group-item-action pay-grant-stu-btn"
                                data-id="<?= (int)$s['id'] ?>"
                                data-name="<?= htmlspecialchars(strtolower($s['first_name'].' '.$s['last_name'].' '.$s['last_name'].' '.$s['first_name'])) ?>"
                                style="display:none">
                            <?= hn($s['first_name'].' '.$s['last_name']) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Amount *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" id="paymentAmountInput" class="form-control"
                               step="0.01" min="0.01" required
                               value="<?= MONTHLY_FEE ?>">
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Type *</label>
                    <select name="payment_type" id="paymentTypeSelect" class="form-select" required>
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
                        <option value="paypal" selected>PayPal</option>
                        <option value="mail">Mail</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Transaction ID</label>
                    <input type="text" name="transaction_id" class="form-control"
                           placeholder="PayPal transaction ID (optional)">
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

<div id="payments-page-body">
<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end"
              hx-get="payments.php" hx-target="#payments-page-body" hx-select="#payments-page-body" hx-swap="outerHTML" hx-push-url="true"
              hx-trigger="change from:select[name='type'], change from:select[name='method'], change from:select[name='year'], filter-refresh from:body">
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
                <input type="hidden" name="student_id" id="payFilterStudentId" value="<?= $f_student ?: '' ?>">
                <div id="payFilterStudentSelected" class="<?= $f_student ? 'd-flex' : 'd-none' ?> justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold" id="payFilterStudentName"><?= hn($f_student_name) ?></span>
                    <button type="button" id="clearPayFilterStudentBtn" class="btn btn-link btn-sm p-0 text-muted">×</button>
                </div>
                <input type="text" id="payFilterStudentFilter" class="form-control form-control-sm" placeholder="Type to filter…"
                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                       <?= $f_student ? 'style="display:none"' : '' ?>>
                <div id="payFilterStudentList" class="list-group mt-1"
                     style="<?= count($all_students) > 8 ? 'max-height:200px;overflow-y:auto;' : '' ?><?= $f_student ? 'display:none' : '' ?>">
                    <?php foreach ($all_students as $s): ?>
                    <button type="button" class="list-group-item list-group-item-action pay-filter-stu-btn"
                            data-id="<?= (int)$s['id'] ?>"
                            data-name="<?= htmlspecialchars(strtolower($s['first_name'].' '.$s['last_name'].' '.$s['last_name'].' '.$s['first_name'])) ?>"
                            style="display:none">
                        <?= hn($s['first_name'].' '.$s['last_name']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
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
                    <?php foreach (['cash'=>'Cash','check'=>'Check','paypal'=>'PayPal','mail'=>'Mail'] as $mv=>$ml): ?>
                        <option value="<?= $mv ?>" <?= $mv === $f_method ? 'selected' : '' ?>><?= $ml ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All Years</option>
                    <?php foreach ($payment_years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $f_year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($f_student || $f_type || $f_method || $f_year): ?>
            <div class="col-auto">
                <a href="payments.php"
                   hx-get="payments.php" hx-target="#payments-page-body" hx-select="#payments-page-body"
                   hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Results ── -->
<div id="payments-results" class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= count($payments) ?> payment<?= count($payments)!==1?'s':'' ?></span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-success fw-semibold">Total: $<?= number_format($total_shown, 2) ?></span>
            <?php if (!empty($payments)): ?>
            <button id="editToggle" class="btn btn-sm btn-outline-secondary">Edit</button>
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
                    <th></th>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Transaction ID</th>
                    <th>Notes</th>
                    <th>By</th>
                    <th class="text-end">Amount</th>
                    <th class="edit-col"></th>
                    <th class="delete-col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-success py-0 prefill-payment-btn"
                                data-student-id="<?= $p['student_id'] ?>"
                                data-student-name="<?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?>"
                                title="Add payment for this student">+</button>
                    </td>
                    <td class="text-nowrap"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                    <td>
                        <a href="../instructor/student_profile.php?id=<?= $p['student_id'] ?>" class="text-decoration-none">
                            <?= hn($p['first_name'].' '.$p['last_name']) ?>
                        </a>
                        <?php if ($p['payer_name']): ?>
                            <div class="text-muted small">paid by <?= htmlspecialchars($p['payer_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= ucwords(str_replace('_',' ',(string)$p['payment_type'])) ?></td>
                    <td><?= ['paypal'=>'PayPal','cash'=>'Cash','check'=>'Check','mail'=>'Mail'][$p['payment_method']] ?? ucfirst($p['payment_method']) ?></td>
                    <td><?= htmlspecialchars($p['transaction_id'] ?? '—') ?></td>
                    <td>
                        <?= htmlspecialchars($p['notes'] ?? '') ?>
                        <?php if ($p['payer_note']): ?>
                            <div class="fst-italic text-muted small"><?= htmlspecialchars($p['payer_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['recorded_by_name'] ?? '—') ?></td>
                    <td class="text-end fw-semibold">$<?= number_format($p['amount'],2) ?></td>
                    <td class="edit-col">
                        <button type="button" class="btn btn-sm btn-outline-primary py-0 toggle-edit-row-btn"
                                data-id="<?= $p['id'] ?>">Edit</button>
                    </td>
                    <td class="delete-col">
                        <form method="post" class="d-inline"
                              hx-post="payments.php" hx-target="#payments-page-body" hx-select="#payments-page-body"
                              hx-swap="outerHTML swap:300ms"
                              hx-confirm="Delete this payment? This cannot be undone.">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
                        </form>
                    </td>
                </tr>
                <tr id="edit-row-<?= $p['id'] ?>" style="display:none">
                    <td colspan="11">
                        <form method="post" class="row g-2 align-items-end py-1">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="edit_payment">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <div class="col-auto">
                                <label class="form-label small mb-1">Date</label>
                                <input type="date" name="payment_date" class="form-control form-control-sm"
                                       value="<?= date('Y-m-d', strtotime($p['payment_date'])) ?>" required>
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-1">Type</label>
                                <select name="payment_type" class="form-select form-select-sm">
                                    <?php foreach (['monthly_tuition'=>'Monthly Tuition','registration'=>'Registration Fee','belt_test'=>'Belt Test Fee','slc_training'=>'SLC Training','seminar'=>'Seminar','other'=>'Other'] as $tv=>$tl): ?>
                                    <option value="<?= $tv ?>" <?= $p['payment_type']===$tv?'selected':'' ?>><?= $tl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-1">Method</label>
                                <select name="payment_method" class="form-select form-select-sm">
                                    <?php foreach (['cash'=>'Cash','check'=>'Check','paypal'=>'PayPal','mail'=>'Mail'] as $mv=>$ml): ?>
                                    <option value="<?= $mv ?>" <?= $p['payment_method']===$mv?'selected':'' ?>><?= $ml ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="month_covered"
                                   value="<?= $p['month_covered'] ? date('Y-m', strtotime($p['month_covered'])) : '' ?>">
                            <div class="col-auto" style="width:110px">
                                <label class="form-label small mb-1">Amount</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="amount" class="form-control"
                                           step="0.01" min="0.01" value="<?= $p['amount'] ?>" required>
                                </div>
                            </div>
                            <div class="col-auto" style="width:160px">
                                <label class="form-label small mb-1">Transaction ID</label>
                                <input type="text" name="transaction_id" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($p['transaction_id'] ?? '') ?>">
                            </div>
                            <div class="col-auto" style="width:160px">
                                <label class="form-label small mb-1">Notes</label>
                                <input type="text" name="notes" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($p['notes'] ?? '') ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-success">Save</button>
                                <button type="button" class="btn btn-sm btn-secondary toggle-edit-row-btn"
                                        data-id="<?= $p['id'] ?>">Cancel</button>
                            </div>
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
</div><!-- /payments-page-body -->

<style nonce="<?= csp_nonce() ?>">
    .delete-col { display: none; }
    .edit-col   { display: none; }
    table.editing .delete-col { display: table-cell; }
    table.editing .edit-col   { display: table-cell; }
</style>
<script nonce="<?= csp_nonce() ?>">
const TYPE_FEES = {
    monthly_tuition: <?= (float)MONTHLY_FEE ?>,
    registration:    <?= (float)REG_FEE ?>,
    belt_test:       <?= (float)TEST_FEE ?>,
    slc_training:    <?= (float)SLC_FEE ?>,
    seminar:         <?= (float)SEMINAR_FEE ?>
};
(function() {
    var typeSel = document.getElementById('paymentTypeSelect');
    var amountInput = document.getElementById('paymentAmountInput');
    if (!typeSel || !amountInput) return;
    var lastAutoValue = amountInput.value;
    function updateAmount() {
        var current = parseFloat(amountInput.value);
        var isBlankOrZero = !amountInput.value || isNaN(current) || current === 0;
        var isUntouched   = amountInput.value === lastAutoValue;
        if ((isBlankOrZero || isUntouched) && TYPE_FEES.hasOwnProperty(typeSel.value)) {
            amountInput.value = TYPE_FEES[typeSel.value];
            lastAutoValue = amountInput.value;
        }
    }
    typeSel.addEventListener('change', updateAmount);
    updateAmount();
})();

function closeEditRows() {
    document.querySelectorAll('#paymentsTable tr[id^="edit-row-"]').forEach(function(r) { r.style.display = 'none'; });
    if (typeof setFormClean === 'function') setFormClean();
}
function toggleEditRow(pid) {
    var row = document.getElementById('edit-row-' + pid);
    if (!row) return;
    var closing = row.style.display !== 'none';
    row.style.display = closing ? 'none' : '';
    if (closing && typeof setFormClean === 'function') setFormClean();
}

// Delegated — #payments-page-body gets replaced wholesale by htmx on
// filter/delete, so bind from document to survive swaps.
document.addEventListener('click', function(e) {
    var btn;
    if ((btn = e.target.closest('#clearPayFilterStudentBtn'))) {
        clearPayFilterStudent();
        return;
    }
    if ((btn = e.target.closest('.pay-filter-stu-btn'))) {
        selectPayFilterStudent(parseInt(btn.dataset.id, 10), btn.textContent.trim());
        return;
    }
    if ((btn = e.target.closest('#editToggle'))) {
        var t = document.getElementById('paymentsTable');
        var editing = t.classList.toggle('editing');
        btn.textContent = editing ? 'Done' : 'Edit';
        btn.className   = editing ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-outline-secondary';
        if (!editing) closeEditRows();
        return;
    }
    if ((btn = e.target.closest('.toggle-edit-row-btn'))) {
        toggleEditRow(btn.dataset.id);
        return;
    }
});

function prefillPayment(studentId, studentName) {
    const collapse = document.getElementById('addPaymentForm');
    const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse);
    bsCollapse.show();
    selectPayGrantStudent(studentId, studentName);
    collapse.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Delegated — the "+" prefill button lives inside #payments-page-body, which
// htmx replaces wholesale on filter/delete, so bind from document.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.prefill-payment-btn');
    if (!btn) return;
    prefillPayment(parseInt(btn.dataset.studentId, 10), btn.dataset.studentName);
});

// Grant form student filter
function selectPayGrantStudent(id, label) {
    document.getElementById('payGrantStudentId').value = id;
    document.getElementById('payGrantStudentName').textContent = label;
    var sel = document.getElementById('payGrantStudentSelected');
    sel.classList.remove('d-none'); sel.classList.add('d-flex');
    var filt = document.getElementById('payGrantStudentFilter');
    filt.style.display = 'none';
    filt.required = false;
    filt.setCustomValidity('');
    document.getElementById('payGrantStudentList').style.display = 'none';
}
function clearPayGrantStudent() {
    document.getElementById('payGrantStudentId').value = '';
    var sel = document.getElementById('payGrantStudentSelected');
    sel.classList.add('d-none'); sel.classList.remove('d-flex');
    var f = document.getElementById('payGrantStudentFilter');
    f.style.display = ''; f.value = '';
    f.required = true;
    document.getElementById('payGrantStudentList').style.display = '';
    document.querySelectorAll('.pay-grant-stu-btn').forEach(function(b) { b.style.display = 'none'; });
}
document.getElementById('clearPayGrantStudentBtn').addEventListener('click', clearPayGrantStudent);
document.querySelectorAll('.pay-grant-stu-btn').forEach(function(b) {
    b.addEventListener('click', function() {
        selectPayGrantStudent(parseInt(b.dataset.id, 10), b.textContent.trim());
    });
});
// Block submit when no student is selected — the student_id input is hidden,
// so native `required` validation can't cover it. Show the browser's own
// validation bubble on the visible filter box instead.
document.getElementById('addPayForm').addEventListener('submit', function(e) {
    if (!document.getElementById('payGrantStudentId').value) {
        e.preventDefault();
        var f = document.getElementById('payGrantStudentFilter');
        f.setCustomValidity('Please select a student.');
        f.reportValidity();
    }
});
document.getElementById('payGrantStudentFilter').addEventListener('input', function() {
    this.setCustomValidity('');
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('.pay-grant-stu-btn').forEach(function(b) {
        b.style.display = (q.length > 0 && b.dataset.name.indexOf(q) !== -1) ? '' : 'none';
    });
});

// Filter bar student filter
function selectPayFilterStudent(id, label) {
    document.getElementById('payFilterStudentId').value = id;
    document.getElementById('payFilterStudentName').textContent = label;
    var sel = document.getElementById('payFilterStudentSelected');
    sel.classList.remove('d-none'); sel.classList.add('d-flex');
    document.getElementById('payFilterStudentFilter').style.display = 'none';
    document.getElementById('payFilterStudentList').style.display = 'none';
    document.body.dispatchEvent(new Event('filter-refresh'));
}
function clearPayFilterStudent() {
    document.getElementById('payFilterStudentId').value = '';
    var sel = document.getElementById('payFilterStudentSelected');
    sel.classList.add('d-none'); sel.classList.remove('d-flex');
    var f = document.getElementById('payFilterStudentFilter');
    f.style.display = ''; f.value = '';
    document.getElementById('payFilterStudentList').style.display = '';
    document.querySelectorAll('.pay-filter-stu-btn').forEach(function(b) { b.style.display = 'none'; });
    document.body.dispatchEvent(new Event('filter-refresh'));
}
document.getElementById('payFilterStudentFilter').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('.pay-filter-stu-btn').forEach(function(b) {
        b.style.display = (q.length > 0 && b.dataset.name.indexOf(q) !== -1) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

