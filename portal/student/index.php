<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_login();

$user_id = current_user_id();

// Get student profile linked to this user
$student = db()->prepare(
    'SELECT s.*, u.username, u.email AS login_email
     FROM students s
     JOIN users u ON u.id = s.user_id
     WHERE s.user_id = ?'
);
$student->execute([$user_id]);
$student = $student->fetch();

// No roster entry linked yet — show a pending screen
if (!$student) {
    $page_title = 'Account Pending';
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm text-center p-4">
                <div class="fs-1 mb-3">⏳</div>
                <h5 class="mb-2">Your account is pending</h5>
                <p class="text-muted mb-3">
                    Your login has been created but hasn't been linked to a student record yet.
                    Contact Noji to get set up — it usually takes just a few minutes.
                </p>
                <a href="mailto:<?= htmlspecialchars(DOJO_EMAIL) ?>" class="btn btn-primary">
                    Email Noji
                </a>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$student_id = $student['id'];

// Current rank (highest rank_order achieved)
$rank = db()->prepare(
    'SELECT r.name, r.kyu_dan
     FROM student_ranks sr
     JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ?
     ORDER BY r.rank_order DESC
     LIMIT 1'
);
$rank->execute([$student_id]);
$rank = $rank->fetch();

// Last 10 sessions the student actually attended
$attendance = db()->prepare(
    'SELECT cs.session_date, cs.class_type
     FROM attendance a
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE a.student_id = ? AND a.present = 1
     ORDER BY cs.session_date DESC
     LIMIT 10'
);
$attendance->execute([$student_id]);
$attendance = $attendance->fetchAll();

// Attendance summary (all time)
$att_summary = db()->prepare(
    'SELECT
        COUNT(*) AS total,
        SUM(a.present) AS attended
     FROM class_sessions cs
     JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?'
);
$att_summary->execute([$student_id]);
$att_summary = $att_summary->fetch();

// Belt test history for this student
$tests = db()->prepare(
    'SELECT bt.test_date, r.kyu_dan, bt.result, bt.fee_paid
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ?
     ORDER BY bt.test_date DESC
     LIMIT 10'
);
$tests->execute([$student_id]);
$tests = $tests->fetchAll();

// Payments — most recent 10
$payments = db()->prepare(
    'SELECT payment_date, payment_type, payment_method, amount, transaction_id, notes, month_covered
     FROM payments
     WHERE student_id = ?
     ORDER BY payment_date DESC
     LIMIT 10'
);
$payments->execute([$student_id]);
$payments = $payments->fetchAll();

// Active payment waivers for this student
$waivers_stmt = db()->prepare(
    'SELECT waiver_type FROM payment_waivers
     WHERE student_id = ?
     ORDER BY granted_date DESC'
);
$waivers_stmt->execute([$student_id]);
$active_waivers = $waivers_stmt->fetchAll();

// Auto-pay status
$sub_stmt = db()->prepare(
    "SELECT id FROM subscriptions WHERE student_id=? AND status='active' LIMIT 1"
);
$sub_stmt->execute([$student_id]);
$has_autopay = (bool)$sub_stmt->fetchColumn();

$autopay_success = ($_GET['autopay'] ?? '') === 'success';

$page_title = 'My Dashboard';
include __DIR__ . '/../includes/header.php';

function fmt_date(string $d): string {
    return date('j M Y', strtotime($d));
}
function fmt_type(string $t): string {
    return ucwords(str_replace('_', ' ', $t));
}
function badge_result(string $r): string {
    switch ($r) {
        case 'pass': return '<span class="badge bg-success">Pass</span>';
        case 'fail': return '<span class="badge bg-danger">Fail</span>';
        default:     return '<span class="badge bg-secondary">Pending</span>';
    }
}
?>

<!-- ── Page heading ── -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h3 class="mb-0">
            Welcome, <?= htmlspecialchars($student['first_name']) ?>!
        </h3>
        <small class="text-muted">
            Member since <?= fmt_date($student['registration_date']) ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="../instructor/student_profile.php?id=<?= $student_id ?>" class="btn btn-success">View Profile</a>
        <a href="pay.php" class="btn btn-success">Make a Payment</a>
    </div>

</div>

<!-- ── Summary cards ── -->
<div class="row g-3 mb-4">

    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="display-6 fw-bold text-primary">
                    <?= $att_summary['attended'] ?? 0 ?>
                </div>
                <div class="text-muted small">Classes Attended</div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="display-6 fw-bold" style="color:#6f42c1">
                    <?= $rank ? htmlspecialchars($rank['name']) : '—' ?>
                </div>
                <div class="text-muted small">Current Rank</div>
            </div>
        </div>
    </div>

    <?php if (!$student['injury_waiver']): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <div class="display-6 fw-bold text-danger">✗</div>
                <div class="text-muted small mb-2">Waiver</div>
                <a href="waiver.php" class="btn btn-sm btn-warning mt-1">Complete Waiver</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($autopay_success): ?>
<div class="alert alert-success">✓ Monthly auto-pay is set up! PayPal will charge $<?= number_format(MONTHLY_FEE, 2) ?> automatically each month.</div>
<?php endif; ?>


<!-- ── Two-column layout ── -->
<div class="row g-4">

    <!-- Attendance + Contact -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                <span>Recent Attendance</span>
                <?php if (count($attendance) === 10): ?>
                <a href="attendance.php" class="btn btn-sm btn-outline-secondary">Show All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($attendance)): ?>
                    <p class="p-3 text-muted">No attendance recorded yet.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date Attended</th><th>Type</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($attendance as $row): ?>
                        <tr>
                            <td><?= fmt_date($row['session_date']) ?></td>
                            <td><?= ucfirst($row['class_type']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Belt Tests + Payments -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <!-- Payments -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                <span>Recent Payments</span>
                <?php if (count($payments) === 10): ?>
                <a href="payment_history.php" class="btn btn-sm btn-outline-secondary">Show All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <p class="p-3 text-muted">No payments on record.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= fmt_date($p['payment_date']) ?></td>
                            <td><?= fmt_type($p['payment_type']) ?></td>
                            <td><?= ucfirst($p['payment_method']) ?></td>
                            <td class="text-end">$<?= number_format($p['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Belt Tests -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                <span>Belt Tests</span>
                <?php if (count($tests) === 10): ?>
                <a href="belt_tests.php" class="btn btn-sm btn-outline-secondary">Show All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($tests)): ?>
                    <p class="p-3 text-muted">No belt tests on record.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Testing For</th>
                            <th>Result</th>
                            <th>Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tests as $t): ?>
                        <tr>
                            <td><?= fmt_date($t['test_date']) ?></td>
                            <td><?= htmlspecialchars($t['kyu_dan']) ?></td>
                            <td><?= badge_result($t['result']) ?></td>
                            <td>
                                <?= $t['fee_paid']
                                    ? '<span class="text-success">Paid</span>'
                                    : '<span class="text-danger">Unpaid</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>


<?php include __DIR__ . '/../includes/footer.php'; ?>

