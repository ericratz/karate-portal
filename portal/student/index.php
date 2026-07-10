<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/belt_helpers.php';
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
    'SELECT r.name, r.kyu_dan, sr.rank_id
     FROM student_ranks sr
     JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ?
     ORDER BY r.rank_order DESC
     LIMIT 1'
);
$rank->execute([$student_id]);
$rank = $rank->fetch();

$next_rank = belt_next_rank($rank['kyu_dan'] ?? null, $student['date_of_birth'] ?? null);

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

// Attendance counts per month — last 12 months
$att_chart_stmt = db()->prepare(
    "SELECT DATE_FORMAT(cs.session_date, '%Y-%m') AS month, COUNT(*) AS count
     FROM attendance a
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE a.student_id = ? AND a.present = 1
       AND cs.session_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY month
     ORDER BY month ASC"
);
$att_chart_stmt->execute([$student_id]);
$att_chart_by_month = $att_chart_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Rank advancement months — last 12 months (with rank name)
$rank_months_stmt = db()->prepare(
    "SELECT DATE_FORMAT(sr.achieved_date, '%Y-%m') AS month, r.name AS rank_name
     FROM student_ranks sr
     JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ?
       AND sr.achieved_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     ORDER BY sr.achieved_date"
);
$rank_months_stmt->execute([$student_id]);
$rank_months = [];
foreach ($rank_months_stmt->fetchAll() as $row) {
    $rank_months[$row['month']][] = $row['rank_name'];
}

$chart_labels = [];
$chart_data   = [];
$chart_colors = [];
$chart_ranks  = [];
for ($i = 11; $i >= 0; $i--) {
    $key            = date('Y-m', strtotime("-$i months"));
    $chart_labels[] = date('M Y', strtotime("-$i months"));
    $chart_data[]   = (int)($att_chart_by_month[$key] ?? 0);
    $chart_colors[] = isset($rank_months[$key]) ? '#6f42c1' : '#198754';
    $chart_ranks[]  = isset($rank_months[$key]) ? implode(', ', $rank_months[$key]) : null;
}

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
    return date('d M Y', strtotime($d));
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
            Welcome, <?= hn($student['first_name']) ?>!
        </h3>
        <small class="text-muted">
            Member since <?= fmt_date($student['registration_date']) ?>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= hw_index_url($student['date_of_birth'] ?? null) ?>" target="_blank" class="btn" style="background-color:#0052cc;border-color:#0052cc;color:#fff;">All Homework <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg></a>
        <a href="https://noji.com/karate/testing/testing.php" target="_blank" class="btn" style="background-color:#0052cc;border-color:#0052cc;color:#fff;">Test Info <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg></a>
        <a href="../admin/member_card.php?student_id=<?= $student_id ?>" target="_blank" class="btn" style="background-color:#0052cc;border-color:#0052cc;color:#fff;">Member Card <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg></a>
        <a href="../instructor/student_profile.php?id=<?= $student_id ?>" class="btn btn-success">View Profile</a>
        <a href="pay.php" class="btn btn-success">Make a Payment</a>
    </div>

</div>

<?php
$ext_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:3px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg>';
?>
<!-- ── Summary cards ── -->
<div class="row g-3 mb-4">

    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center gap-1">
                <div class="fs-3 fw-bold text-primary"><?= $att_summary['attended'] ?? 0 ?></div>
                <div class="text-muted small">Classes Attended</div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center gap-1">
                <?php if ($rank && $rank['rank_id']): ?>
                <a href="../admin/certificate.php?student_id=<?= $student_id ?>&rank_id=<?= $rank['rank_id'] ?>"
                   target="_blank" class="fw-bold fs-3 text-decoration-none" style="color:#6f42c1">
                    <?= htmlspecialchars($rank['name']) ?><?= $ext_icon ?>
                </a>
                <?php else: ?>
                <div class="fw-bold fs-3" style="color:#6f42c1">—</div>
                <?php endif; ?>
                <div class="text-muted small">Current Rank</div>
            </div>
        </div>
    </div>

    <?php if ($next_rank && $next_rank['hw_url']): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center gap-1">
                <a href="<?= $next_rank['hw_url'] ?>" target="_blank" class="fw-bold fs-3 text-decoration-none">
                    <?= htmlspecialchars($next_rank['name']) ?><?= $ext_icon ?>
                </a>
                <div class="text-muted small">Next Belt Homework</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($next_rank && $next_rank['test_url']): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center gap-1">
                <a href="<?= $next_rank['test_url'] ?>" target="_blank" class="fw-bold fs-3 text-decoration-none">
                    <?= htmlspecialchars($next_rank['name']) ?><?= $ext_icon ?>
                </a>
                <div class="text-muted small">Next Belt Test</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$student['injury_waiver']): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <a href="waiver.php" class="card-body d-flex flex-column align-items-center justify-content-center gap-1 text-decoration-none">
                <span class="fs-3 fw-bold text-danger">✗</span>
                <span class="text-muted small">Complete Waiver</span>
            </a>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($autopay_success): ?>
<div class="alert alert-success">✓ Monthly auto-pay is set up! PayPal will charge $<?= number_format(MONTHLY_FEE, 2) ?> automatically each month.</div>
<?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?>
<div class="alert alert-success">✓ Profile saved successfully.</div>
<?php endif; ?>


<!-- ── Attendance bar graph ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold border-bottom">Attendance — Last 12 Months</div>
    <div class="card-body" style="height:220px;">
        <canvas id="attChart"></canvas>
    </div>
</div>

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
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
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
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
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

    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
        integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
        crossorigin="anonymous"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var chartInst = null;
    var ranks     = <?= json_encode($chart_ranks) ?>;

    function colors() {
        var dark = document.getElementById('html-root').getAttribute('data-bs-theme') === 'dark';
        return {
            grid:  dark ? 'rgba(255,255,255,.12)' : 'rgba(0,0,0,.2)',
            label: dark ? '#dee2e6' : '#000'
        };
    }

    function buildChart() {
        if (chartInst) chartInst.destroy();
        var c = colors();
        chartInst = new Chart(document.getElementById('attChart'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    data: <?= json_encode($chart_data) ?>,
                    backgroundColor: <?= json_encode($chart_colors) ?>,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return 'Classes: ' + ctx.parsed.y; },
                            afterLabel: function(ctx) { return ranks[ctx.dataIndex] ? 'Belt: ' + ranks[ctx.dataIndex] : ''; }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: c.label }, grid: { color: c.grid } },
                    y: { beginAtZero: true, ticks: { stepSize: 1, color: c.label }, grid: { color: c.grid } }
                }
            }
        });
    }

    buildChart();
    new MutationObserver(buildChart).observe(
        document.getElementById('html-root'),
        { attributes: true, attributeFilter: ['data-bs-theme'] }
    );
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

