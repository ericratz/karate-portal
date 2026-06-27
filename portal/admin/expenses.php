<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$msg = $error = '';

$f_type = $_GET['type'] ?? '';
$f_paid = $_GET['paid'] ?? '';
$f_from = $_GET['from'] ?? '';
$f_to   = $_GET['to']   ?? '';
$filtering = $f_type || $f_paid !== '' || $f_from || $f_to;
$filter_qs = http_build_query(array_filter(['type'=>$f_type,'paid'=>$f_paid,'from'=>$f_from,'to'=>$f_to], fn($v) => $v !== ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['delete','toggle_paid'])) {
    verify_csrf();
    $type   = $_POST['expense_type'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $date   = $_POST['expense_date'] ?? '';
    $desc   = trim($_POST['description'] ?? '');
    $paid   = isset($_POST['paid']) ? 1 : 0;

    $valid_types = ['rent','equipment','utilities','supplies','other'];
    if (!in_array($type, $valid_types) || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Please fill in all required fields.';
    } else {
        db()->prepare(
            'INSERT INTO expenses (expense_type, amount, expense_date, description, paid, recorded_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$type, $amount, $date, $desc ?: null, $paid, current_user_id()]);
        $msg = 'Expense recorded.';
    }
}

// Delete expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = (int)$_POST['id'];
    db()->prepare('DELETE FROM expenses WHERE id=?')->execute([$del_id]);
    audit('delete_expense', 'expense', $del_id);
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) { exit; }
    header('Location: expenses.php?' . $filter_qs);
    exit;
}

// Toggle paid status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_paid') {
    verify_csrf();
    $tog_id = (int)$_POST['id'];
    db()->prepare('UPDATE expenses SET paid = IF(paid=1,0,1) WHERE id=?')->execute([$tog_id]);
    audit('toggle_expense_paid', 'expense', $tog_id);
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
        $row = db()->prepare('SELECT paid FROM expenses WHERE id=?');
        $row->execute([$tog_id]);
        $new_paid = (bool)$row->fetchColumn();
        ?><form method="post" class="d-inline" hx-post="expenses.php" hx-target="this" hx-swap="outerHTML">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="toggle_paid">
            <input type="hidden" name="id" value="<?= $tog_id ?>">
            <button type="submit" class="btn btn-sm <?= $new_paid ? 'btn-success' : 'btn-outline-secondary' ?>">
                <?= $new_paid ? '✓ Paid' : 'Unpaid' ?>
            </button>
        </form><?php
        exit;
    }
    header('Location: expenses.php?' . $filter_qs);
    exit;
}

// Build filter query
$valid_types = ['rent','equipment','utilities','supplies','other'];
$where  = ['1=1'];
$params = [];
if ($f_type && in_array($f_type, $valid_types)) { $where[] = 'e.expense_type = ?'; $params[] = $f_type; }
if ($f_paid === '1') { $where[] = 'e.paid = 1'; }
if ($f_paid === '0') { $where[] = 'e.paid = 0'; }
if ($f_from) { $where[] = 'e.expense_date >= ?'; $params[] = $f_from; }
if ($f_to)   { $where[] = 'e.expense_date <= ?'; $params[] = $f_to; }

$exp_stmt = db()->prepare(
    'SELECT e.*, u.username FROM expenses e
     LEFT JOIN users u ON u.id = e.recorded_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY e.expense_date DESC'
);
$exp_stmt->execute($params);
$expenses = $exp_stmt->fetchAll();

$total      = array_sum(array_column($expenses, 'amount'));
$total_paid = array_sum(array_map(fn($e) => $e['paid'] ? $e['amount'] : 0, $expenses));

$page_title = 'Expenses';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Expenses</h3>
    <button class="btn btn-success btn-sm" data-bs-toggle="collapse" data-bs-target="#addForm">
        + Record Expense
    </button>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Add expense form -->
<div class="collapse mb-4" id="addForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Record Expense</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>
                <div class="col-md-3">
                    <label class="form-label">Type *</label>
                    <select name="expense_type" class="form-select" required>
                        <option value="rent">Rent</option>
                        <option value="equipment">Equipment</option>
                        <option value="utilities">Utilities</option>
                        <option value="supplies">Supplies</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Amount *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" class="form-control"
                               step="0.01" min="0.01" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date *</label>
                    <input type="date" name="expense_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control"
                           placeholder="e.g. Monthly studio rent — July">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="paid" id="paid" value="1">
                        <label class="form-check-label" for="paid">Already paid</label>
                    </div>
                </div>
                <div class="col-12">
                    <button class="btn btn-success">Save Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end"
              hx-get="expenses.php" hx-target="#expenses-results" hx-select="#expenses-results" hx-swap="outerHTML" hx-push-url="true">
            <div class="col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach (['rent','equipment','utilities','supplies','other'] as $t): ?>
                        <option value="<?= $t ?>" <?= $t === $f_type ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="paid" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="1" <?= $f_paid === '1' ? 'selected' : '' ?>>Paid</option>
                    <option value="0" <?= $f_paid === '0' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($f_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($f_to) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-filter btn-sm">Filter</button>
            </div>
            <div class="col-auto">
                <a href="expenses.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>"
                   hx-get="expenses.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>"
                   hx-target="#expenses-results" hx-select="#expenses-results" hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm <?= ($f_from === date('Y-m-01') && $f_to === date('Y-m-d') && !$f_type && $f_paid === '') ? 'active' : '' ?>">This Month</a>
            </div>
            <div class="col-auto">
                <a href="expenses.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>"
                   hx-get="expenses.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>"
                   hx-target="#expenses-results" hx-select="#expenses-results" hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm <?= ($f_from === date('Y-01-01') && $f_to === date('Y-m-d') && !$f_type && $f_paid === '') ? 'active' : '' ?>">This Year</a>
            </div>
            <?php if ($filtering): ?>
            <div class="col-auto">
                <a href="expenses.php"
                   hx-get="expenses.php" hx-target="#expenses-results" hx-select="#expenses-results"
                   hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div id="expenses-results">
<div class="d-flex align-items-center gap-3 mb-3">
    <span class="ms-auto">
        Total: <strong>$<?= number_format($total, 2) ?></strong>
        &nbsp;|&nbsp;
        Paid: <strong class="text-success">$<?= number_format($total_paid, 2) ?></strong>
        &nbsp;|&nbsp;
        Unpaid: <strong class="text-danger">$<?= number_format($total - $total_paid, 2) ?></strong>
    </span>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= count($expenses) ?> expense<?= count($expenses)!==1?'s':'' ?></span>
        <?php if (!empty($expenses)): ?>
        <button id="editToggle" class="btn btn-sm btn-outline-secondary"
                onclick="(function(btn){var t=document.getElementById('expensesTable');var e=t.classList.toggle('editing');btn.textContent=e?'Done':'Edit';btn.className=e?'btn btn-sm btn-danger':'btn btn-sm btn-outline-secondary';})(this)">Edit</button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($expenses)): ?>
            <p class="p-3 text-muted">No expenses match the filter.</p>
        <?php else: ?>
        <table id="expensesTable" class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Paid</th>
                    <th>Recorded By</th>
                    <th class="delete-col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($expenses as $e): ?>
                <tr class="<?= !$e['paid'] ? 'table-warning' : '' ?>">
                    <td><?= date('d M Y', strtotime($e['expense_date'])) ?></td>
                    <td><?= ucfirst($e['expense_type']) ?></td>
                    <td><?= htmlspecialchars($e['description'] ?? '—') ?></td>
                    <td class="text-end">$<?= number_format($e['amount'], 2) ?></td>
                    <td class="text-center">
                        <form method="post" class="d-inline"
                              hx-post="expenses.php" hx-target="this" hx-swap="outerHTML">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="toggle_paid">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <button type="submit"
                                    class="btn btn-sm <?= $e['paid'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                <?= $e['paid'] ? '✓ Paid' : 'Unpaid' ?>
                            </button>
                        </form>
                    </td>
                    <td><?= htmlspecialchars($e['username'] ?? '—') ?></td>
                    <td class="delete-col">
                        <form method="post" class="d-inline"
                              hx-post="expenses.php" hx-target="closest tr"
                              hx-swap="delete swap:300ms"
                              hx-confirm="Delete this expense? This cannot be undone.">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</div>

<style>
    .delete-col { display: none; }
    table.editing .delete-col { display: table-cell; }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>

