<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$student = db()->prepare('SELECT s.id, s.first_name, s.last_name FROM students s WHERE s.user_id = ?');
$student->execute([current_user_id()]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

$paid_this_month = db()->prepare(
    'SELECT COUNT(*) FROM payments
     WHERE student_id = ?
       AND payment_type = "monthly_tuition"
       AND MONTH(payment_date) = MONTH(NOW())
       AND YEAR(payment_date)  = YEAR(NOW())'
);
$paid_this_month->execute([$student['id']]);
$already_paid = (int)$paid_this_month->fetchColumn() > 0;

$paid_reg = db()->prepare(
    'SELECT COUNT(*) FROM payments WHERE student_id = ? AND payment_type = "registration"'
);
$paid_reg->execute([$student['id']]);
$already_paid_reg = (int)$paid_reg->fetchColumn() > 0;

// Month options for tuition picker (current + next 3 months)
$month_options = [];
for ($i = 0; $i <= 3; $i++) {
    $ts = mktime(0, 0, 0, date('n') + $i, 1);
    $month_options[] = [
        'value' => date('Y-m-01', $ts),
        'label' => date('F Y', $ts),
    ];
}
$default_month = $already_paid ? $month_options[1]['value'] : $month_options[0]['value'];

$fees = [
    'monthly_tuition' => ['label' => 'Monthly Tuition',  'amount' => MONTHLY_FEE],
    'registration'    => ['label' => 'Registration Fee', 'amount' => REG_FEE],
    'belt_test'       => ['label' => 'Belt Test Fee',    'amount' => TEST_FEE],
    'slc_training'    => ['label' => 'SLC Training',     'amount' => SLC_FEE],
    'seminar'         => ['label' => 'Seminar',          'amount' => SEMINAR_FEE],
];

// Check for active subscription
$sub_stmt = db()->prepare(
    "SELECT paypal_subscription_id FROM subscriptions WHERE student_id=? AND status='active' LIMIT 1"
);
$sub_stmt->execute([$student['id']]);
$active_subscription = $sub_stmt->fetchColumn();

$autopay_key = $_GET['autopay'] ?? '';
switch ($autopay_key) {
    case 'already':    $autopay_msg = ['type' => 'info',   'text' => 'You already have an active monthly auto-pay set up.']; break;
    case 'error':      $autopay_msg = ['type' => 'danger', 'text' => 'Something went wrong setting up auto-pay. Please try again or contact Noji.']; break;
    case 'no_profile': $autopay_msg = ['type' => 'danger', 'text' => 'No student profile found.']; break;
    default:           $autopay_msg = null;
}

$page_title = 'Make a Payment';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Make a Payment</h4>
</div>

<?php if ($already_paid): ?>
<div class="alert alert-success">
    ✓ Tuition for <?= date('F Y') ?> has already been paid.
    You can still make other payments below.
</div>
<?php endif; ?>

<div class="row g-4 justify-content-center">
    <div class="col-md-8 col-lg-6">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Select Payments</div>
            <div class="card-body">

                <!-- Checkbox fee list -->
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
                    <?php if ($key === 'registration' && $already_paid_reg): ?>
                    <tr id="row-reg-extra" style="display:none">
                        <td></td>
                        <td colspan="2">
                            <div class="alert alert-warning py-2 mb-0 small">
                                Your registration fee is already on file. If you're paying for someone else, enter their name in the Note field below.
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($key === 'monthly_tuition'): ?>
                    <tr id="row-month-picker" style="display:none">
                        <td></td>
                        <td colspan="2">
                            <label class="form-label small text-muted mb-1">Which month are you paying for?</label>
                            <select id="tuitionMonth" class="form-select form-select-sm" style="max-width:180px">
                                <?php foreach ($month_options as $mo): ?>
                                <option value="<?= $mo['value'] ?>"
                                    <?= $mo['value'] === $default_month ? 'selected' : '' ?>>
                                    <?= $mo['label'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
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
                            <div class="form-text">Donations support the dojo and are recorded separately.</div>
                        </td>
                    </tr>

                    </tbody>
                </table>

                <!-- Custom amount row -->
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

                <!-- Note field -->
                <div class="mb-3">
                    <input type="text" class="form-control form-control-sm" id="noteInput"
                           placeholder="Note (optional — e.g. paying for Jan + Feb, paying on behalf of…)">
                </div>

                <!-- Total -->
                <div class="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
                    <span class="fw-semibold fs-5">Total</span>
                    <span class="fw-bold fs-4 text-success" id="totalDisplay">$0.00</span>
                </div>

                <!-- PayPal buttons -->
                <div id="paypalSection" style="display:none">
                    <div id="paypal-button-container"></div>
                </div>
                <div id="noSelectionMsg" class="text-muted text-center small">
                    Select at least one payment above.
                </div>

                <!-- Success -->
                <div id="successMsg" style="display:none" class="alert alert-success mt-3">
                    <strong>Payment successful!</strong>
                    <div id="receiptLines" class="mt-2 mb-1 small"></div>
                    <div class="d-flex justify-content-between fw-semibold border-top pt-1 mt-1">
                        <span>Total</span>
                        <span>$<span id="paidAmountDisplay"></span></span>
                    </div>
                    <div class="text-muted small mt-1">Transaction ID: <code id="txnIdDisplay"></code></div>
                    <a href="index.php" class="btn btn-sm btn-success mt-2">Back to Dashboard</a>
                </div>

                <!-- Error -->
                <div id="errorMsg" style="display:none" class="alert alert-danger mt-3">
                    <strong>Payment failed:</strong> <span id="errorText"></span>
                </div>

            </div>
        </div>

        <!-- Auto-Pay -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Monthly Auto-Pay</div>
            <div class="card-body">
                <?php if ($autopay_msg): ?>
                    <div class="alert alert-<?= $autopay_msg['type'] ?> mb-3"><?= $autopay_msg['text'] ?></div>
                <?php endif; ?>
                <?php if ($active_subscription): ?>
                    <p class="mb-2 text-success fw-semibold">✓ Auto-Pay is active</p>
                    <p class="text-muted small mb-0">
                        PayPal will charge $<?= number_format(MONTHLY_FEE, 2) ?> automatically each month.
                        You can cancel anytime from your <a href="profile_edit.php">profile page</a>.
                    </p>
                <?php else: ?>
                    <p class="text-muted small mb-3">
                        Set up a recurring monthly payment of $<?= number_format(MONTHLY_FEE, 2) ?> through PayPal.
                        You can cancel anytime from your profile page.
                    </p>
                    <form method="post" action="../paypal_subscription_create.php">
                        <?= csrf_input() ?>
                        <button type="submit" class="btn btn-success">Set up Monthly Auto-Pay</button>
                    </form>
                <?php endif; ?>
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

<script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars(PAYPAL_CLIENT_ID) ?>&currency=USD&enable-funding=venmo"></script>

<script>
const FEES = <?= json_encode($fees) ?>;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

var total = 0;

// ── Helpers ───────────────────────────────────────────────────
function show(id) { document.getElementById(id).style.display = ''; }
function hide(id) { document.getElementById(id).style.display = 'none'; }

function itemLabel(item) {
    if (item.type === 'donation') return 'Donation';
    if (item.type === 'other')    return item.reason || 'Other';
    return FEES[item.type] ? FEES[item.type].label : item.type;
}

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

function buildItems() {
    var items = [];
    document.querySelectorAll('.fee-chk').forEach(function(chk) {
        if (!chk.checked) return;
        if (chk.dataset.key === 'donation') {
            var dAmt = parseFloat(document.getElementById('donationAmountInput').value) || 0;
            if (dAmt > 0) items.push({ type: 'donation', amount: dAmt });
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

function renderButtons() {
    var container = document.getElementById('paypal-button-container');
    container.innerHTML = '';

    if (total <= 0) {
        hide('paypalSection');
        show('noSelectionMsg');
        return;
    }
    show('paypalSection');
    hide('noSelectionMsg');

    var capturedItems = [];

    paypal.Buttons({
        style: { layout: 'vertical', shape: 'rect' },

        createOrder: function() {
            capturedItems = buildItems();
            return fetch('/karate/portal/paypal_create.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body:    JSON.stringify({
                    items: capturedItems,
                    total: total,
                    note:  document.getElementById('noteInput').value,
                }),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) throw new Error(data.error);
                return data.id;
            });
        },

        onApprove: function(data) {
            return fetch('/karate/portal/paypal_capture.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body:    JSON.stringify({ orderID: data.orderID }),
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    // Build receipt lines from captured items
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

// ── Wire up events ────────────────────────────────────────────

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
    if (chk.checked) row.classList.add('table-primary');
    else             row.classList.remove('table-primary');
    if (chk.dataset.key === 'monthly_tuition') {
        document.getElementById('row-month-picker').style.display = chk.checked ? '' : 'none';
    }
    if (chk.dataset.key === 'registration') {
        var regRow = document.getElementById('row-reg-extra');
        if (regRow) regRow.style.display = chk.checked ? '' : 'none';
    }
    if (chk.dataset.key === 'donation') {
        document.getElementById('row-donation-amount').style.display = chk.checked ? '' : 'none';
        if (!chk.checked) {
            document.getElementById('donationAmountInput').value = '';
            chk.dataset.amount = '0';
            document.getElementById('donation-amount-display').textContent = '—';
        }
    }
}

// Donation amount input updates data-amount on the checkbox so recalculate() picks it up
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

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

