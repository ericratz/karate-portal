<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$student = db()->prepare('SELECT id, first_name, last_name FROM students WHERE user_id = ?');
$student->execute([current_user_id()]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

// Available years for filter — payments plus attributed donations
$years_stmt = db()->prepare(
    'SELECT DISTINCT yr FROM (
        SELECT YEAR(payment_date) AS yr FROM payments  WHERE student_id = ?
        UNION
        SELECT YEAR(payment_date) AS yr FROM donations WHERE student_id = ?
     ) y ORDER BY yr DESC'
);
$years_stmt->execute([$student['id'], $student['id']]);
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

$selected_year = isset($_GET['year']) && in_array((int)$_GET['year'], array_map('intval', $years))
    ? (int)$_GET['year']
    : null;

// Payments — filtered or all. Attributed donations are merged in as type 'donation'.
$history_sql =
    'SELECT payment_date, payment_type, payment_method, amount, transaction_id, notes, month_covered
     FROM payments WHERE student_id = ?
     UNION ALL
     SELECT payment_date, \'donation\', payment_method, amount, NULL, notes, NULL
     FROM donations WHERE student_id = ?';
if ($selected_year) {
    $payments_stmt = db()->prepare(
        "SELECT * FROM ($history_sql) h WHERE YEAR(payment_date) = ? ORDER BY payment_date DESC"
    );
    $payments_stmt->execute([$student['id'], $student['id'], $selected_year]);
} else {
    $payments_stmt = db()->prepare(
        "SELECT * FROM ($history_sql) h ORDER BY payment_date DESC"
    );
    $payments_stmt->execute([$student['id'], $student['id']]);
}
$payments = $payments_stmt->fetchAll();

// Summary totals always reflect the filtered set
$filtered_total = array_sum(array_column($payments, 'amount'));

// All-time total (always unfiltered)
$tp = db()->prepare(
    'SELECT (SELECT COALESCE(SUM(amount), 0) FROM payments  WHERE student_id = ?)
          + (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE student_id = ?)'
);
$tp->execute([$student['id'], $student['id']]);
$total_paid = (float)$tp->fetchColumn();

$page_title = 'Payment History';
include __DIR__ . '/../includes/header.php';

function fmt_date(string $d): string {
    return date('d M Y', strtotime($d));
}
function fmt_type(string $t): string {
    return ucwords(str_replace('_', ' ', $t));
}
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Payment History — <?= hn($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-primary"><?= count($payments) ?></div>
                <div class="text-muted small"><?= $selected_year ? "$selected_year Payments" : 'Total Payments' ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-success">$<?= number_format($filtered_total, 2) ?></div>
                <div class="text-muted small"><?= $selected_year ? "$selected_year Total" : 'All-Time Total' ?></div>
            </div>
        </div>
    </div>
    <?php if ($selected_year): ?>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-secondary">$<?= number_format($total_paid, 2) ?></div>
                <div class="text-muted small">All-Time Total</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Year filter + table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <?= $selected_year ? "$selected_year Payments" : 'All Payments' ?>
            <span class="text-muted fw-normal small ms-1">(<?= count($payments) ?>)</span>
        </span>
        <?php if (!empty($years)): ?>
        <div class="d-flex gap-2 align-items-center">
            <?php foreach ($years as $yr): ?>
                <a href="?year=<?= $yr ?>"
                   class="btn btn-sm <?= $selected_year === (int)$yr ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= $yr ?>
                </a>
            <?php endforeach; ?>
            <?php if ($selected_year): ?>
                <a href="payment_history.php" class="btn btn-sm btn-outline-secondary">All</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments)): ?>
            <p class="p-3 text-muted">No payments <?= $selected_year ? "in $selected_year" : 'on record' ?> yet.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Month</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $i => $p): ?>
                <tr>
                    <td class="text-muted small"><?= count($payments) - $i ?></td>
                    <td><?= fmt_date($p['payment_date']) ?></td>
                    <td><?= fmt_type($p['payment_type']) ?></td>
                    <td class="text-muted small">
                        <?= ($p['payment_type'] === 'monthly_tuition' && $p['month_covered'])
                            ? date('M Y', strtotime($p['month_covered'])) : '—' ?>
                    </td>
                    <td><?= ucfirst($p['payment_method']) ?></td>
                    <td class="text-end">$<?= number_format($p['amount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

