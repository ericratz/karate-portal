<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role('parent');

$user_id = current_user_id();

// Parent's own student record (they may also participate)
$own_student = db()->prepare(
    'SELECT s.*, u.username FROM students s
     JOIN users u ON u.id = s.user_id
     WHERE s.user_id = ?'
);
$own_student->execute([$user_id]);
$own_student = $own_student->fetch();

// Children linked to this parent
$children_stmt = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name, s.student_type, s.injury_waiver
     FROM parent_students ps
     JOIN students s ON s.id = ps.student_id
     WHERE ps.parent_user_id = ?
     ORDER BY s.first_name, s.last_name'
);
$children_stmt->execute([$user_id]);
$children = $children_stmt->fetchAll();

// Determine which student to display — from ?student_id= tab param
$tab_id = (int)($_GET['student_id'] ?? 0);
if (!$tab_id) {
    $tab_id = $own_student ? (int)$own_student['id'] : ($children[0]['id'] ?? 0);
}

// Validate tab_id — must be own student or a linked child
$allowed_ids = $children ? array_column($children, 'id') : [];
if ($own_student) $allowed_ids[] = (int)$own_student['id'];
if (!in_array($tab_id, $allowed_ids)) {
    $tab_id = $allowed_ids[0] ?? 0;
}

// Load the selected student's full data
$student        = null;
$attendance     = [];
$payments       = [];
$belt_tests     = [];
$rank           = null;
$active_waivers = [];
$has_autopay    = false;
$att_summary    = ['attended' => 0, 'total' => 0];

if ($tab_id) {
    $student = db()->prepare('SELECT * FROM students WHERE id = ?');
    $student->execute([$tab_id]);
    $student = $student->fetch();

    if ($student) {
        $rank_q = db()->prepare(
            'SELECT r.name, r.kyu_dan FROM student_ranks sr
             JOIN ranks r ON r.id = sr.rank_id
             WHERE sr.student_id = ? ORDER BY r.rank_order DESC LIMIT 1'
        );
        $rank_q->execute([$tab_id]);
        $rank = $rank_q->fetch();

        // All-time attendance summary
        $as_q = db()->prepare(
            'SELECT COUNT(*) AS total, SUM(a.present) AS attended
             FROM class_sessions cs
             JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?'
        );
        $as_q->execute([$tab_id]);
        $att_summary = $as_q->fetch();

        $att_q = db()->prepare(
            'SELECT cs.session_date, cs.class_type FROM attendance a
             JOIN class_sessions cs ON cs.id = a.session_id
             WHERE a.student_id = ? AND a.present = 1
             ORDER BY cs.session_date DESC LIMIT 10'
        );
        $att_q->execute([$tab_id]);
        $attendance = $att_q->fetchAll();

        $pay_q = db()->prepare(
            'SELECT payment_date, payment_type, payment_method, amount, month_covered
             FROM payments WHERE student_id = ?
             ORDER BY payment_date DESC LIMIT 10'
        );
        $pay_q->execute([$tab_id]);
        $payments = $pay_q->fetchAll();

        $bt_q = db()->prepare(
            'SELECT bt.test_date, r.kyu_dan, bt.result, bt.score, bt.fee_paid
             FROM belt_tests bt JOIN ranks r ON r.id = bt.rank_testing_for
             WHERE bt.student_id = ? ORDER BY bt.test_date DESC LIMIT 10'
        );
        $bt_q->execute([$tab_id]);
        $belt_tests = $bt_q->fetchAll();

        $wv_q = db()->prepare(
            'SELECT waiver_type FROM payment_waivers WHERE student_id = ? ORDER BY granted_date DESC'
        );
        $wv_q->execute([$tab_id]);
        $active_waivers = $wv_q->fetchAll();

        $sub_q = db()->prepare(
            "SELECT id FROM subscriptions WHERE student_id=? AND status='active' LIMIT 1"
        );
        $sub_q->execute([$tab_id]);
        $has_autopay = (bool)$sub_q->fetchColumn();
    }
}

$page_title = 'My Dashboard';
include __DIR__ . '/../includes/header.php';

function fmt_date(string $d): string { return date('M j, Y', strtotime($d)); }
function fmt_type(string $t): string { return ucwords(str_replace('_', ' ', $t)); }
function score_badge(string $result, ?int $score): string {
    if ($score === null) return '<span class="badge bg-secondary">Pending</span>';
    $label = $score . '%';
    return $result === 'pass'
        ? '<span class="badge bg-success">' . $label . '</span>'
        : '<span class="badge bg-danger">'  . $label . '</span>';
}
?>

<!-- ── Page heading ── -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <?php if ($student): ?>
        <h3 class="mb-0">Welcome, <?= htmlspecialchars($student['first_name']) ?>!</h3>
        <small class="text-muted">Member since <?= $student['registration_date'] ? fmt_date($student['registration_date']) : '—' ?></small>
        <?php else: ?>
        <h3 class="mb-0">My Dashboard</h3>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <?php if ($tab_id): ?>
        <a href="profile_edit.php?student_id=<?= $tab_id ?>" class="btn btn-outline-secondary btn-sm">Edit Profile</a>
        <a href="pay.php?student_id=<?= $tab_id ?>" class="btn btn-success btn-sm">Make a Payment</a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Family tabs ── -->
<?php if (!empty($children) || $own_student): ?>
<ul class="nav nav-tabs mb-4">
    <?php if ($own_student): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab_id === (int)$own_student['id'] ? 'active' : '' ?>"
           href="?student_id=<?= $own_student['id'] ?>">
            <?= htmlspecialchars($own_student['first_name'] . ' ' . $own_student['last_name']) ?>
        </a>
    </li>
    <?php endif; ?>
    <?php foreach ($children as $child): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab_id === (int)$child['id'] ? 'active' : '' ?>"
           href="?student_id=<?= $child['id'] ?>">
            <?= htmlspecialchars($child['first_name'] . ' ' . $child['last_name']) ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if (!$student): ?>
    <div class="alert alert-info">No student profile linked to this account yet. Please contact Noji.</div>
<?php else: ?>

<!-- ── Summary stat cards ── -->
<div class="row g-3 mb-4">

    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="display-6 fw-bold text-primary">
                    <?= (int)($att_summary['attended'] ?? 0) ?>
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

    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <div class="display-6 fw-bold <?= $student['injury_waiver'] ? 'text-success' : 'text-danger' ?>">
                    <?= $student['injury_waiver'] ? '✓' : '✗' ?>
                </div>
                <div class="text-muted small mb-2">Liability Waiver</div>
                <?php if (!$student['injury_waiver']): ?>
                <a href="waiver.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-warning mt-1">Complete Waiver</a>
                <?php else: ?>
                <a href="waiver.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-success mt-1">View Waiver</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($has_autopay): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="display-6 fw-bold text-success">✓</div>
                <div class="text-muted small">Auto-Pay Active</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if (!empty($active_waivers)): ?>
<div class="alert alert-success d-flex align-items-start gap-2 mb-4">
    <span class="fw-semibold text-nowrap">Active Waivers:</span>
    <span>
        <?php foreach ($active_waivers as $i => $w): ?>
            <?= $i > 0 ? ' &nbsp;·&nbsp; ' : '' ?>
            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $w['waiver_type']))) ?>
        <?php endforeach; ?>
    </span>
</div>
<?php endif; ?>

<!-- ── Two-column layout ── -->
<div class="row g-4">

    <!-- Left: Profile + Attendance -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <!-- Profile Info -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Profile Info</span>
                <a href="profile_edit.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-muted small">First Name</div>
                        <div><?= htmlspecialchars($student['first_name'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Last Name</div>
                        <div><?= htmlspecialchars($student['last_name'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Date of Birth</div>
                        <div><?= $student['date_of_birth'] ? fmt_date($student['date_of_birth']) : '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Phone</div>
                        <div><?= htmlspecialchars($student['phone'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Email</div>
                        <div><?= htmlspecialchars($student['email'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Emergency Contact</div>
                        <div><?= htmlspecialchars($student['emergency_contact_name'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Emergency Phone</div>
                        <div><?= htmlspecialchars($student['emergency_contact_phone'] ?? '') ?: '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Member Since</div>
                        <div><?= $student['registration_date'] ? fmt_date($student['registration_date']) : '—' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                <span>Recent Attendance</span>
                <?php if (count($attendance) === 10): ?>
                <a href="attendance.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-outline-secondary">Show All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($attendance)): ?>
                    <p class="p-3 text-muted">No attendance recorded yet.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Date Attended</th><th>Type</th></tr></thead>
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

    <!-- Right: Payments + Belt Tests -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <!-- Payments -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                <span>Recent Payments</span>
                <?php if (count($payments) === 10): ?>
                <a href="payment_history.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-outline-secondary">Show All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($payments)): ?>
                    <p class="p-3 text-muted">No payments on record.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Type</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><?= fmt_date($p['payment_date']) ?></td>
                            <td><?= fmt_type($p['payment_type']) ?></td>
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
                <?php if (count($belt_tests) === 10): ?>
                <a href="belt_tests.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-outline-secondary">Show All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($belt_tests)): ?>
                    <p class="p-3 text-muted">No belt tests on record.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th><th>Testing For</th><th>Score</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($belt_tests as $t): ?>
                        <tr>
                            <td><?= fmt_date($t['test_date']) ?></td>
                            <td><?= htmlspecialchars($t['kyu_dan']) ?></td>
                            <td><?= score_badge($t['result'], isset($t['score']) ? (int)$t['score'] : null) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div><!-- /row -->

<?php endif; // $student ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
