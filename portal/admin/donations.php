<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

$msg = $error = '';

// ── Delete ───────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $del_id = post_int('id');
    db()->prepare('DELETE FROM donations WHERE id=?')->execute([$del_id]);
    audit('delete_donation', 'donation', $del_id);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header('Location: donations.php?' . http_build_query(array_diff_key($_GET, [])));
        exit;
    }
    // For htmx requests, fall through to render the full page — htmx's
    // hx-select pulls out just the results section, so the count reflects
    // the deletion live.
}

// ── Record donation ──────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    verify_csrf();
    $amount     = (float)post_str('amount', '0');
    $method     = post_str('payment_method');
    $donor      = trim(post_str('donor_name'));
    $notes      = trim(post_str('notes'));
    $date       = post_str('payment_date', date('Y-m-d'));
    $don_sid    = post_int('student_id');

    $valid_methods = ['paypal','cash','check','mail'];

    // Optional student link — must be a real student id
    if ($don_sid) {
        $chk = db()->prepare('SELECT COUNT(*) FROM students WHERE id = ?');
        $chk->execute([$don_sid]);
        if (!$chk->fetchColumn()) $don_sid = 0;
    }

    if ($amount <= 0 || !in_array($method, $valid_methods)) {
        $error = 'Amount and payment method are required.';
    } else {
        // Linked donations take their name from the student record;
        // free-typed names are stored as unlinked (anonymous) donor_name.
        db()->prepare(
            'INSERT INTO donations (student_id, amount, payment_method, donor_name, notes, payment_date, recorded_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $don_sid ?: null,
            $amount, $method,
            ($don_sid || $donor === '') ? null : $donor,
            $notes  ?: null,
            $date,
            current_user_id(),
        ]);
        audit('record_donation', 'donation', (int)db()->lastInsertId(), "amount=$amount");
        header('Location: donations.php?recorded=1');
        exit;
    }
}

if (isset($_GET['recorded'])) $msg = 'Donation recorded.';

// ── Filters ───────────────────────────────────────────────────
$f_from   = get_str('from');
$f_to     = get_str('to');
$f_method = get_str('method');
$f_year   = get_int('year');

$where  = ['1=1'];
$params = [];
if ($f_from)   { $where[] = 'payment_date >= ?'; $params[] = $f_from; }
if ($f_to)     { $where[] = 'payment_date <= ?'; $params[] = $f_to; }
if ($f_method) { $where[] = 'payment_method = ?'; $params[] = $f_method; }
if ($f_year)   { $where[] = 'YEAR(payment_date) = ?'; $params[] = $f_year; }

// Years available for the dropdown — actual donation years plus the current year
$donation_years = db()->query('SELECT DISTINCT YEAR(payment_date) AS y FROM donations ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $donation_years)) {
    array_unshift($donation_years, (int)date('Y'));
}

$donations = db()->prepare(
    'SELECT d.*, u.username AS recorded_by_name,
            s.first_name AS student_first, s.last_name AS student_last
     FROM donations d
     LEFT JOIN users u ON u.id = d.recorded_by
     LEFT JOIN students s ON s.id = d.student_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY d.payment_date DESC, d.created_at DESC'
);
$donations->execute($params);
$donations = $donations->fetchAll();

$total_shown = array_sum(array_column($donations, 'amount'));

// Students for the optional donor picker
$all_students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

$page_title = 'Donations';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Donations</h3>
    <button class="btn btn-success btn-sm" data-bs-toggle="collapse" data-bs-target="#addDonationForm">
        + Record Donation
    </button>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ── Add donation form ── -->
<div class="collapse mb-4" id="addDonationForm">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Record Donation</div>
        <div class="card-body">
            <form method="post" class="row g-3" id="addDonForm">
                <?= csrf_input() ?>

                <div class="col-md-2">
                    <label class="form-label">Amount *</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" name="amount" id="donAmountInput" class="form-control"
                               step="0.01" min="0.01" required placeholder="0.00">
                    </div>
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
                    <label class="form-label">Donor <small class="text-muted">(optional — anonymous if blank; pick a student to link, or type any name)</small></label>
                    <input type="hidden" name="student_id" id="donStudentId" value="">
                    <div id="donStudentSelected" class="d-none justify-content-between align-items-center mb-1">
                        <span class="fw-semibold" id="donStudentName"></span>
                        <button type="button" id="clearDonStudentBtn" class="btn btn-link btn-sm p-0 text-muted">change</button>
                    </div>
                    <input type="text" name="donor_name" id="donStudentFilter" class="form-control" placeholder="Type donor name…"
                           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                    <div id="donStudentList" class="list-group mt-1"
                         style="<?= count($all_students) > 8 ? 'max-height:200px;overflow-y:auto;' : '' ?>">
                        <?php foreach ($all_students as $s): ?>
                        <button type="button" class="list-group-item list-group-item-action don-stu-btn"
                                data-id="<?= (int)$s['id'] ?>"
                                data-name="<?= htmlspecialchars(strtolower($s['first_name'].' '.$s['last_name'].' '.$s['last_name'].' '.$s['first_name'])) ?>"
                                style="display:none">
                            <?= hn($s['first_name'].' '.$s['last_name']) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-8">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Optional">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-success">Save Donation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="donations-page-body">
<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end"
              hx-get="donations.php" hx-target="#donations-page-body" hx-select="#donations-page-body" hx-swap="outerHTML" hx-push-url="true"
              hx-trigger="change from:select[name='method'], change from:select[name='year']">
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
                    <?php foreach ($donation_years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $f_year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($f_method || $f_year): ?>
            <div class="col-auto">
                <a href="donations.php"
                   hx-get="donations.php" hx-target="#donations-page-body" hx-select="#donations-page-body"
                   hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Results ── -->
<div id="donations-results" class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= count($donations) ?> donation<?= count($donations)!==1?'s':'' ?></span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-success fw-semibold">Total: $<?= number_format($total_shown, 2) ?></span>
            <?php if (!empty($donations)): ?>
            <button id="editToggle" class="btn btn-sm btn-outline-secondary">Edit</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($donations)): ?>
            <p class="p-3 text-muted">No donations match the filter.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table id="donationsTable" class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Donor</th>
                    <th>Method</th>
                    <th>Notes</th>
                    <th>By</th>
                    <th class="text-end">Amount</th>
                    <th class="delete-col"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($donations as $d): ?>
                <tr>
                    <td class="text-nowrap"><?= date('d M Y', strtotime($d['payment_date'])) ?></td>
                    <td>
                        <?php if ($d['student_id']): ?>
                            <a href="student_edit.php?id=<?= (int)$d['student_id'] ?>" class="text-decoration-none">
                                <?= hn($d['donor_name'] ?: $d['student_first'].' '.$d['student_last']) ?>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($d['donor_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= ['paypal'=>'PayPal','cash'=>'Cash','check'=>'Check','mail'=>'Mail'][$d['payment_method']] ?? ucfirst($d['payment_method']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($d['notes'] ?? '') ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($d['recorded_by_name'] ?? '—') ?></td>
                    <td class="text-end fw-semibold">$<?= number_format($d['amount'], 2) ?></td>
                    <td class="delete-col">
                        <form method="post" class="d-inline"
                              hx-post="donations.php" hx-target="#donations-page-body" hx-select="#donations-page-body"
                              hx-swap="outerHTML swap:300ms"
                              hx-confirm="Delete this donation record? This cannot be undone.">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
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
</div><!-- /donations-page-body -->

<style nonce="<?= csp_nonce() ?>">
    .delete-col { display: none; }
    table.editing .delete-col { display: table-cell; }
</style>

<script nonce="<?= csp_nonce() ?>">
// #donations-results (editToggle + table) gets replaced wholesale by htmx on
// filter submits, so delegate from document to survive swaps.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('#editToggle');
    if (!btn) return;
    var t = document.getElementById('donationsTable');
    var editing = t.classList.toggle('editing');
    btn.textContent = editing ? 'Done' : 'Edit';
    btn.className   = editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
});

// Popup on the amount box when it's empty or not a positive value — backstop
// in case native `required` validation doesn't fire.
document.getElementById('addDonForm').addEventListener('submit', function(e) {
    var amt = document.getElementById('donAmountInput');
    if (!(parseFloat(amt.value) > 0)) {
        e.preventDefault();
        amt.setCustomValidity('Please enter a donation amount.');
        amt.reportValidity();
    }
});
document.getElementById('donAmountInput').addEventListener('input', function() {
    this.setCustomValidity('');
});

// Optional student picker on the Record Donation form
function selectDonStudent(id, label) {
    document.getElementById('donStudentId').value = id;
    document.getElementById('donStudentName').textContent = label;
    var sel = document.getElementById('donStudentSelected');
    sel.classList.remove('d-none'); sel.classList.add('d-flex');
    var f = document.getElementById('donStudentFilter');
    // Clear the typed text so it doesn't post as a free-text donor name too
    f.value = '';
    f.style.display = 'none';
    document.getElementById('donStudentList').style.display = 'none';
}
function clearDonStudent() {
    document.getElementById('donStudentId').value = '';
    var sel = document.getElementById('donStudentSelected');
    sel.classList.add('d-none'); sel.classList.remove('d-flex');
    var f = document.getElementById('donStudentFilter');
    f.style.display = ''; f.value = '';
    document.getElementById('donStudentList').style.display = '';
    document.querySelectorAll('.don-stu-btn').forEach(function(b) { b.style.display = 'none'; });
}
document.getElementById('clearDonStudentBtn').addEventListener('click', clearDonStudent);
document.querySelectorAll('.don-stu-btn').forEach(function(b) {
    b.addEventListener('click', function() {
        selectDonStudent(parseInt(b.dataset.id, 10), b.textContent.trim());
    });
});
document.getElementById('donStudentFilter').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('.don-stu-btn').forEach(function(b) {
        b.style.display = (q.length > 0 && b.dataset.name.indexOf(q) !== -1) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

