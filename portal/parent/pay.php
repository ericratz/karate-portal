<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role('parent', 'instructor');

$user_id = current_user_id();

// ── Build the family list (own record + linked children) ─────────
$family   = [];
$own_id   = 0;
$child_ids = [];

$own_stmt = db()->prepare('SELECT id, first_name, last_name FROM students WHERE user_id = ?');
$own_stmt->execute([$user_id]);
if ($own = $own_stmt->fetch()) {
    $own_id = (int)$own['id'];
    $family[] = [
        'id'    => $own_id,
        'label' => hn($own['first_name'] . ' ' . $own['last_name']),
        'name'  => hn($own['first_name'] . ' ' . $own['last_name']),
    ];
}

$ch_stmt = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name
     FROM student_guardians sg
     JOIN students s ON s.id = sg.child_student_id
     WHERE sg.parent_student_id = ?
     ORDER BY s.first_name, s.last_name'
);
$ch_stmt->execute([$own_id]);
foreach ($ch_stmt->fetchAll() as $c) {
    $child_ids[] = (int)$c['id'];
    $family[] = [
        'id'    => (int)$c['id'],
        'label' => hn($c['first_name'] . ' ' . $c['last_name']),
        'name'  => hn($c['first_name'] . ' ' . $c['last_name']),
    ];
}

if (empty($family)) {
    header('Location: ' . dashboard_url($_SESSION['role'] ?? 'student'));
    exit;
}

$family_ids = array_column($family, 'id');

// Pre-selected student from query param
$pre_id = get_int('student_id');
if (!in_array($pre_id, $family_ids, true)) {
    $pre_id = $family_ids[0];
}

// ── Per-student payment status ───────────────────────────────────
$placeholders = implode(',', array_fill(0, count($family_ids), '?'));

// Which family members paid tuition this month?
$tp_stmt = db()->prepare(
    "SELECT student_id FROM payments
     WHERE student_id IN ($placeholders)
       AND payment_type = 'monthly_tuition'
       AND MONTH(payment_date) = MONTH(NOW())
       AND YEAR(payment_date)  = YEAR(NOW())"
);
$tp_stmt->execute($family_ids);
$tuition_paid_ids = array_map('intval', $tp_stmt->fetchAll(PDO::FETCH_COLUMN));

// All paid tuition months per student (for per-month notice)
$pm_stmt = db()->prepare(
    "SELECT student_id, COALESCE(DATE_FORMAT(month_covered, '%Y-%m-01'), DATE_FORMAT(payment_date, '%Y-%m-01')) AS paid_month
     FROM payments WHERE student_id IN ($placeholders) AND payment_type = 'monthly_tuition'"
);
$pm_stmt->execute($family_ids);
$paid_months_by_student = [];
foreach ($pm_stmt->fetchAll() as $row) {
    $paid_months_by_student[(int)$row['student_id']][] = $row['paid_month'];
}
foreach ($family_ids as $fid) {
    if (!isset($paid_months_by_student[$fid])) $paid_months_by_student[$fid] = [];
    else $paid_months_by_student[$fid] = array_values(array_unique($paid_months_by_student[$fid]));
}
$family_tuition_paid = !empty($tuition_paid_ids);

// Names of family members who have paid tuition (for warning message)
$tuition_paid_names = [];
foreach ($family as $f) {
    if (in_array($f['id'], $tuition_paid_ids, true)) {
        $tuition_paid_names[] = $f['label'];
    }
}

// Child IDs (not parent's own record) who have paid tuition this month — for parent-free notice
$child_tuition_paid_ids = array_values(array_intersect($tuition_paid_ids, $child_ids));
$child_paid_names = [];
foreach ($family as $f) {
    if (in_array($f['id'], $child_tuition_paid_ids, true)) {
        $child_paid_names[] = $f['label'];
    }
}

// Which family members have ever paid registration?
$rp_stmt = db()->prepare(
    "SELECT DISTINCT student_id FROM payments
     WHERE student_id IN ($placeholders)
       AND payment_type = 'registration'"
);
$rp_stmt->execute($family_ids);
$reg_paid_ids = array_map('intval', $rp_stmt->fetchAll(PDO::FETCH_COLUMN));

// Which family members have an active auto-pay subscription?
$sub_stmt = db()->prepare(
    "SELECT student_id FROM subscriptions
     WHERE student_id IN ($placeholders) AND status = 'active'"
);
$sub_stmt->execute($family_ids);
$autopay_active_ids = array_map('intval', $sub_stmt->fetchAll(PDO::FETCH_COLUMN));

// Children with active auto-pay — for the parent-attends-free notice
$child_autopay_names = [];
foreach ($family as $f) {
    if ($f['id'] !== $own_id && in_array($f['id'], $autopay_active_ids, true)) {
        $child_autopay_names[] = $f['label'];
    }
}

// Auto-pay result messages (redirect targets from the subscription endpoints)
switch ($_GET['autopay'] ?? '') {
    case 'already':    $autopay_msg = ['type' => 'info',    'text' => 'That family member already has an active monthly auto-pay set up.']; break;
    case 'error':      $autopay_msg = ['type' => 'danger',  'text' => 'Something went wrong setting up auto-pay. Please try again or contact Noji.']; break;
    case 'no_profile': $autopay_msg = ['type' => 'danger',  'text' => 'No student profile found.']; break;
    case 'cancelled':  $autopay_msg = ['type' => 'success', 'text' => 'Auto-pay cancelled.']; break;
    default:           $autopay_msg = null;
}

// ── Month picker options (previous month + current + next 3 months) ──────
$month_options = [];
for ($i = -1; $i <= 3; $i++) {
    $ts = mktime(0, 0, 0, (int)date('n') + $i, 1);
    $month_options[] = [
        'value' => date('Y-m-01', $ts),
        'label' => date('F Y', $ts),
    ];
}
$current_month_value = date('Y-m-01');
$next_month_value    = date('Y-m-01', mktime(0, 0, 0, (int)date('n') + 1, 1));

$fees = [
    'monthly_tuition' => ['label' => 'Monthly Tuition',  'amount' => MONTHLY_FEE],
    'registration'    => ['label' => 'Registration Fee', 'amount' => REG_FEE],
    'belt_test'       => ['label' => 'Belt Test Fee',    'amount' => TEST_FEE],
    'slc_training'    => ['label' => 'SLC Training',     'amount' => SLC_FEE],
    'seminar'         => ['label' => 'Seminar',          'amount' => SEMINAR_FEE],
];

$page_title = 'Make a Payment';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Make a Payment</h4>
</div>

<div class="row g-4 justify-content-center">
    <div class="col-md-8 col-lg-6">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Select Payments</div>
            <div class="card-body">

                <!-- Student selector -->
                <div class="mb-4">
                    <label class="form-label fw-semibold" for="studentSelect">Paying for</label>
                    <select id="studentSelect" class="form-select">
                        <?php foreach ($family as $f): ?>
                        <option value="<?= $f['id'] ?>"
                                <?= $f['id'] === $pre_id ? 'selected' : '' ?>>
                            <?= $f['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Shown when parent selects themselves and a child has paid tuition this month -->
                <?php if ($own_id && !empty($child_paid_names)): ?>
                <div id="tuitionFamilyWarning" class="alert alert-info d-none mb-3 small">
                    <?php
                    $n = count($child_paid_names);
                    if ($n === 1) echo htmlspecialchars($child_paid_names[0]) . ' has';
                    elseif ($n === 2) echo htmlspecialchars($child_paid_names[0]) . ' and ' . htmlspecialchars($child_paid_names[1]) . ' have';
                    else echo htmlspecialchars(implode(', ', array_slice($child_paid_names, 0, -1))) . ', and ' . htmlspecialchars(end($child_paid_names)) . ' have';
                    ?> already paid tuition this month. As a parent of a paid child, you do not need to pay tuition.
                </div>
                <?php endif; ?>

                <!-- Checkbox fee list -->
                <div class="table-responsive">
                <table class="table table-hover mb-3">
                    <tbody>
                    <?php foreach ($fees as $key => $fee): ?>
                    <tr id="row-<?= $key ?>" style="cursor:pointer">
                        <td style="width:36px">
                            <input type="checkbox" class="form-check-input fee-chk"
                                   id="chk-<?= $key ?>"
                                   data-key="<?= $key ?>"
                                   data-amount="<?= $fee['amount'] ?>">
                        </td>
                        <td><?= $fee['label'] ?></td>
                        <td class="text-end fw-semibold">$<?= number_format($fee['amount'], 2) ?></td>
                    </tr>
                    <?php if ($key === 'monthly_tuition'): ?>
                    <tr id="row-month-picker" style="display:none">
                        <td></td>
                        <td colspan="2">
                            <label class="form-label small text-muted mb-1">Which month are you paying for?</label>
                            <select id="tuitionMonth" class="form-select form-select-sm" style="max-width:180px">
                                <?php foreach ($month_options as $mo): ?>
                                <option value="<?= $mo['value'] ?>"><?= $mo['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr id="row-tuition-paid-notice" style="display:none">
                        <td></td>
                        <td colspan="2">
                            <div id="tuitionAlreadyPaid" class="alert alert-info py-2 mb-0 small"></div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($key === 'registration'): ?>
                    <tr id="row-reg-extra" style="display:none">
                        <td></td>
                        <td colspan="2">
                            <div class="alert alert-warning py-2 mb-0 small">
                                Registration fee is already on file for this student.
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Donation row -->
                    <tr id="row-donation" style="cursor:pointer">
                        <td style="width:36px">
                            <input type="checkbox" class="form-check-input fee-chk"
                                   id="chk-donation" data-key="donation" data-amount="0">
                        </td>
                        <td>Donation</td>
                        <td class="text-end fw-semibold text-muted" id="donation-amount-display">—</td>
                    </tr>
                    <tr id="row-donation-amount" style="display:none">
                        <td></td>
                        <td colspan="2">
                            <div class="input-group input-group-sm mb-1" style="max-width:160px">
                                <span class="input-group-text">$</span>
                                <input type="number" id="donationAmountInput" class="form-control"
                                       placeholder="0.00" step="0.01" min="1">
                            </div>
                            <div class="form-check mt-1">
                                <input type="checkbox" class="form-check-input" id="donationAnonymous">
                                <label class="form-check-label small text-muted" for="donationAnonymous">
                                    Donate anonymously (won't appear in your payment history)
                                </label>
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
                </div>

                <!-- Custom amount -->
                <div class="border rounded p-3 mb-3">
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" id="customCheck">
                        <label class="form-check-label fw-semibold" for="customCheck">
                            Custom / Other Amount
                        </label>
                    </div>
                    <div id="customSection" style="display:none" class="row g-2">
                        <div class="col-5">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">$</span>
                                <input type="number" class="form-control" id="customAmount"
                                       placeholder="0.00" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-7">
                            <input type="text" class="form-control form-control-sm"
                                   id="customReason" placeholder="Reason for payment">
                        </div>
                    </div>
                </div>

                <!-- Note -->
                <div class="mb-3">
                    <input type="text" class="form-control form-control-sm" id="noteInput"
                           placeholder="Note (optional)">
                </div>

                <!-- Total -->
                <div class="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
                    <span class="fw-semibold fs-5">Total</span>
                    <span class="fw-bold fs-4 text-success" id="totalDisplay">$0.00</span>
                </div>

                <!-- PayPal -->
                <div id="paypalSection" style="display:none">
                    <div id="paypal-button-container"></div>
                </div>
                <div id="noSelectionMsg" class="text-muted text-center small">
                    Select at least one payment above.
                </div>

                <!-- Success receipt -->
                <div id="successMsg" style="display:none" class="alert alert-success mt-3">
                    <strong>Payment successful!</strong>
                    <div id="receiptFor" class="small text-muted mt-1"></div>
                    <div id="receiptLines" class="mt-2 mb-1 small"></div>
                    <div class="d-flex justify-content-between fw-semibold border-top pt-1 mt-1">
                        <span>Total</span>
                        <span>$<span id="paidAmountDisplay"></span></span>
                    </div>
                    <div class="text-muted small mt-1">Transaction ID: <code id="txnIdDisplay"></code></div>
                    <a href="<?= dashboard_url($_SESSION['role'] ?? 'student') ?>" class="btn btn-sm btn-success mt-2">Back to Dashboard</a>
                </div>

                <!-- Error -->
                <div id="errorMsg" style="display:none" class="alert alert-danger mt-3">
                    <strong>Payment failed:</strong> <span id="errorText"></span>
                </div>

            </div>
        </div>

        <!-- Auto-Pay (per family member) -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Monthly Auto-Pay</div>
            <div class="card-body">
                <?php if ($autopay_msg): ?>
                    <div class="alert alert-<?= $autopay_msg['type'] ?> mb-3"><?= $autopay_msg['text'] ?></div>
                <?php endif; ?>
                <p class="text-muted small mb-3">
                    Set up a recurring monthly payment of $<?= number_format(MONTHLY_FEE, 2) ?>
                    through PayPal for any family member.
                </p>
                <?php foreach ($family as $f): ?>
                <?php if ($f['id'] === $own_id && !in_array($own_id, $autopay_active_ids, true) && !empty($child_autopay_names)): ?>
                <div class="alert alert-info small mb-2">
                    <?php
                    $n = count($child_autopay_names);
                    if ($n === 1) echo htmlspecialchars($child_autopay_names[0]) . ' has';
                    elseif ($n === 2) echo htmlspecialchars($child_autopay_names[0]) . ' and ' . htmlspecialchars($child_autopay_names[1]) . ' have';
                    else echo htmlspecialchars(implode(', ', array_slice($child_autopay_names, 0, -1))) . ', and ' . htmlspecialchars(end($child_autopay_names)) . ' have';
                    ?> auto-pay set up. As a parent of a paying child, you can attend for free —
                    you do not need your own auto-pay.
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center border-top py-2">
                    <span class="fw-semibold"><?= $f['label'] ?></span>
                    <?php if (in_array($f['id'], $autopay_active_ids, true)): ?>
                    <span class="d-flex align-items-center gap-2">
                        <span class="text-success small fw-semibold">✓ Active</span>
                        <form method="post" action="<?= SITE_URL ?>/student/subscription_cancel.php"
                              class="d-inline autopay-cancel-form">
                            <?= csrf_input() ?>
                            <input type="hidden" name="student_id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                        </form>
                    </span>
                    <?php else: ?>
                    <form method="post" action="<?= SITE_URL ?>/api/paypal_subscription_create.php" class="d-inline">
                        <?= csrf_input() ?>
                        <input type="hidden" name="student_id" value="<?= $f['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success">Set up Auto-Pay</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Other options -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Other Payment Options</div>
            <div class="card-body">
                <p class="mb-0">
                    <strong>Mail a check</strong> to:<br>
                    Shotokan Karate and Self-defense<br>
                    PO Box 1288, Orem, Utah 84059-1288
                </p>
            </div>
        </div>

    </div>
</div>

<?php if (PAYPAL_CLIENT_ID !== ''): // unconfigured dev environment — page JS falls back to a warning ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars(PAYPAL_CLIENT_ID) ?>&currency=USD"></script>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
// Confirm before cancelling a family member's auto-pay
document.querySelectorAll('.autopay-cancel-form').forEach(function(f) {
    f.addEventListener('submit', function(e) {
        if (!confirm('Cancel this monthly auto-pay? PayPal will stop charging automatically.')) e.preventDefault();
    });
});

const FEES                    = <?= json_encode($fees) ?>;
const CSRF                    = document.querySelector('meta[name="csrf-token"]').content;
const TUITION_PAID_IDS        = <?= json_encode($tuition_paid_ids) ?>;
const REG_PAID_IDS            = <?= json_encode($reg_paid_ids) ?>;
const FAMILY_PAID             = <?= json_encode($family_tuition_paid) ?>;
const MONTH_OPTIONS           = <?= json_encode($month_options) ?>;
const CURRENT_MONTH_VALUE     = <?= json_encode($current_month_value) ?>;
const NEXT_MONTH_VALUE        = <?= json_encode($next_month_value) ?>;
const OWN_ID                  = <?= json_encode($own_id) ?>;
const CHILD_TUITION_PAID_IDS  = <?= json_encode($child_tuition_paid_ids) ?>;
const STUDENT_LABELS          = <?= json_encode(array_column($family, 'label', 'id')) ?>;
const PAID_MONTHS_BY_STUDENT  = <?= json_encode($paid_months_by_student) ?>;

var total = 0;

function show(id) { document.getElementById(id).style.display = ''; }
function hide(id) { document.getElementById(id).style.display = 'none'; }

function getSelectedStudentId() {
    return parseInt(document.getElementById('studentSelect').value, 10);
}

function itemLabel(item) {
    if (item.type === 'donation') return 'Donation';
    if (item.type === 'other')    return item.reason || 'Other';
    return FEES[item.type] ? FEES[item.type].label : item.type;
}

// ── Tuition notice ───────────────────────────────────────────────
function updateTuitionWarning() {
    var el = document.getElementById('tuitionFamilyWarning');
    if (!el) return;
    var tuitionChecked = document.getElementById('chk-monthly_tuition').checked;
    var show = tuitionChecked && OWN_ID > 0 && getSelectedStudentId() === OWN_ID && CHILD_TUITION_PAID_IDS.length > 0;
    el.classList.toggle('d-none', !show);
}

// ── Recalculate total ────────────────────────────────────────────
function recalculate() {
    var t = 0;
    document.querySelectorAll('.fee-chk').forEach(function(chk) {
        if (chk.checked) t += parseFloat(chk.dataset.amount) || 0;
    });
    var custom = parseFloat(document.getElementById('customAmount').value);
    if (document.getElementById('customCheck').checked && custom > 0) t += custom;
    total = Math.round(t * 100) / 100;
    document.getElementById('totalDisplay').textContent = '$' + total.toFixed(2);
    renderButtons();
}

// ── Build items array for PayPal ─────────────────────────────────
function buildItems() {
    var items = [];
    document.querySelectorAll('.fee-chk').forEach(function(chk) {
        if (!chk.checked) return;
        if (chk.dataset.key === 'donation') {
            var dAmt = parseFloat(document.getElementById('donationAmountInput').value) || 0;
            if (dAmt > 0) items.push({
                type: 'donation',
                amount: dAmt,
                anonymous: document.getElementById('donationAnonymous').checked,
            });
            return;
        }
        var item = { type: chk.dataset.key, amount: parseFloat(chk.dataset.amount) };
        if (chk.dataset.key === 'monthly_tuition') {
            item.month_covered = document.getElementById('tuitionMonth').value;
        }
        items.push(item);
    });
    if (document.getElementById('customCheck').checked) {
        var amt = parseFloat(document.getElementById('customAmount').value);
        if (amt > 0) items.push({ type: 'other', amount: amt, reason: document.getElementById('customReason').value });
    }
    return items;
}

// ── Render PayPal buttons ────────────────────────────────────────
function renderButtons() {
    var container = document.getElementById('paypal-button-container');
    container.innerHTML = '';
    if (total <= 0) { hide('paypalSection'); show('noSelectionMsg'); return; }
    show('paypalSection'); hide('noSelectionMsg');

    // SDK failed to load (blocked, offline, or unconfigured dev environment)
    if (typeof paypal === 'undefined') {
        container.innerHTML = '<div class="alert alert-warning mb-0">' +
            'PayPal checkout could not be loaded. Please disable content blockers ' +
            'and refresh, or contact the instructor to pay another way.</div>';
        return;
    }

    var capturedItems = [];
    var capturedStudentId = 0;

    paypal.Buttons({
        style: { layout: 'vertical', shape: 'rect' },

        createOrder: function() {
            capturedItems    = buildItems();
            capturedStudentId = getSelectedStudentId();
            return fetch('<?= SITE_URL ?>/api/paypal_create.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body:    JSON.stringify({
                    items:      capturedItems,
                    total:      total,
                    note:       document.getElementById('noteInput').value,
                    student_id: capturedStudentId,
                }),
            })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.error) throw new Error(d.error); return d.id; });
        },

        onApprove: function(data) {
            return fetch('<?= SITE_URL ?>/api/paypal_capture.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body:    JSON.stringify({ orderID: data.orderID }),
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    var label = STUDENT_LABELS[capturedStudentId] || '';
                    document.getElementById('receiptFor').textContent = label ? 'Payment for: ' + label : '';
                    var lines = capturedItems.map(function(item) {
                        return '<div class="d-flex justify-content-between">'
                             + '<span>' + itemLabel(item) + '</span>'
                             + '<span>$' + parseFloat(item.amount).toFixed(2) + '</span>'
                             + '</div>';
                    }).join('');
                    document.getElementById('receiptLines').innerHTML = lines;
                    document.getElementById('paidAmountDisplay').textContent = result.amount.toFixed(2);
                    document.getElementById('txnIdDisplay').textContent      = result.transaction_id;
                    show('successMsg');
                    hide('errorMsg');
                    container.innerHTML = '';
                    hide('paypalSection');
                    hide('noSelectionMsg');
                } else {
                    document.getElementById('errorText').textContent = result.error || 'Unknown error';
                    show('errorMsg');
                }
            });
        },

        onError: function(err) {
            document.getElementById('errorText').textContent = 'PayPal encountered an error. Please try again.';
            show('errorMsg');
            console.error(err);
        },
    }).render('#paypal-button-container');
}

// ── Student selector change ──────────────────────────────────────
function onStudentChange() {
    var sid = getSelectedStudentId();

    // Reset all checkboxes
    document.querySelectorAll('.fee-chk').forEach(function(chk) {
        chk.checked = false;
        var row = document.getElementById('row-' + chk.dataset.key);
        if (row) row.classList.remove('table-primary');
    });
    hide('row-month-picker');
    hide('row-tuition-paid-notice');
    hide('row-donation-amount');
    hide('row-reg-extra');
    var twEl = document.getElementById('tuitionFamilyWarning');
    if (twEl) twEl.classList.add('d-none');
    document.getElementById('customCheck').checked = false;
    hide('customSection');
    document.getElementById('donationAmountInput').value = '';
    document.getElementById('donationAnonymous').checked = false;
    document.getElementById('chk-donation').dataset.amount = '0';
    document.getElementById('donation-amount-display').textContent = '—';

    // Set tuition month default for this student
    var alreadyPaid = TUITION_PAID_IDS.indexOf(sid) !== -1;
    document.getElementById('tuitionMonth').value =
        alreadyPaid ? NEXT_MONTH_VALUE : CURRENT_MONTH_VALUE;

    recalculate();
}

document.getElementById('studentSelect').addEventListener('change', onStudentChange);

function updateTuitionMonthNotice() {
    var month = document.getElementById('tuitionMonth').value;
    var row   = document.getElementById('row-tuition-paid-notice');
    var msg   = document.getElementById('tuitionAlreadyPaid');
    var sid   = getSelectedStudentId();
    var paid  = PAID_MONTHS_BY_STUDENT[sid] || [];
    if (paid.indexOf(month) !== -1) {
        var d     = new Date(month);
        var label = d.toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
        msg.textContent = 'You have already paid tuition for ' + label + '.';
        row.style.display = '';
    } else {
        row.style.display = 'none';
    }
}

document.getElementById('tuitionMonth').addEventListener('change', updateTuitionMonthNotice);

// ── Wire up fee checkboxes ────────────────────────────────────────
document.querySelectorAll('.fee-chk').forEach(function(chk) {
    var row = document.getElementById('row-' + chk.dataset.key);

    row.addEventListener('click', function(e) {
        if (e.target === chk) return;
        chk.checked = !chk.checked;
        updateRow(chk);
        recalculate();
    });
    chk.addEventListener('change', function() {
        updateRow(chk);
        recalculate();
    });
});

function updateRow(chk) {
    var row = document.getElementById('row-' + chk.dataset.key);
    chk.checked ? row.classList.add('table-primary') : row.classList.remove('table-primary');

    if (chk.dataset.key === 'monthly_tuition') {
        document.getElementById('row-month-picker').style.display = chk.checked ? '' : 'none';
        updateTuitionWarning();
        if (!chk.checked) document.getElementById('row-tuition-paid-notice').style.display = 'none';
        else updateTuitionMonthNotice();
    }
    if (chk.dataset.key === 'registration') {
        var regRow = document.getElementById('row-reg-extra');
        var sid    = getSelectedStudentId();
        regRow.style.display = (chk.checked && REG_PAID_IDS.indexOf(sid) !== -1) ? '' : 'none';
    }
    if (chk.dataset.key === 'donation') {
        document.getElementById('row-donation-amount').style.display = chk.checked ? '' : 'none';
        if (!chk.checked) {
            document.getElementById('donationAmountInput').value = '';
            document.getElementById('donationAnonymous').checked = false;
            chk.dataset.amount = '0';
            document.getElementById('donation-amount-display').textContent = '—';
        }
    }
}

document.getElementById('donationAmountInput').addEventListener('input', function() {
    var amt = parseFloat(this.value) || 0;
    document.getElementById('chk-donation').dataset.amount = amt;
    document.getElementById('donation-amount-display').textContent = amt > 0 ? '$' + amt.toFixed(2) : '—';
    recalculate();
});

document.getElementById('customCheck').addEventListener('change', function() {
    document.getElementById('customSection').style.display = this.checked ? '' : 'none';
    recalculate();
});

document.getElementById('customAmount').addEventListener('input', recalculate);

// ── Initialize month default + notices for pre-selected student ──
(function() {
    var sid = getSelectedStudentId();
    var alreadyPaid = TUITION_PAID_IDS.indexOf(sid) !== -1;
    document.getElementById('tuitionMonth').value =
        alreadyPaid ? NEXT_MONTH_VALUE : CURRENT_MONTH_VALUE;
    updateTuitionWarning();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

