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

// Children linked to this parent (via student_guardians — no user account required)
$children = [];
if ($own_student) {
    $children_stmt = db()->prepare(
        'SELECT s.id, s.first_name, s.last_name, s.student_type, s.injury_waiver
         FROM student_guardians sg
         JOIN students s ON s.id = sg.child_student_id
         WHERE sg.parent_student_id = ?
         ORDER BY s.first_name, s.last_name'
    );
    $children_stmt->execute([$own_student['id']]);
    $children = $children_stmt->fetchAll();
}

// Per-child summary data for aggregate table
$children_summary = [];
foreach ($children as $ch) {
    $cid = (int)$ch['id'];
    $ca = db()->prepare(
        'SELECT cs.session_date FROM attendance a
         JOIN class_sessions cs ON cs.id = a.session_id
         WHERE a.student_id = ? AND a.present = 1
         ORDER BY cs.session_date DESC LIMIT 1'
    );
    $ca->execute([$cid]);
    $cp = db()->prepare(
        "SELECT payment_date, payment_type FROM payments
         WHERE student_id = ?
         ORDER BY payment_date DESC LIMIT 1"
    );
    $cp->execute([$cid]);
    $children_summary[$cid] = [
        'last_attendance' => $ca->fetchColumn() ?: null,
        'last_payment'    => $cp->fetch() ?: null,
    ];
}

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
            'SELECT bt.test_date, r.kyu_dan, bt.result, bt.score, bt.fee_paid, bt.belt_awarded
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

function fmt_date(string $d): string { return date('d M Y', strtotime($d)); }
function fmt_phone(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) === 10 ? substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6) : $p; }
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

    <?php if (!$student['injury_waiver']): ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <div class="display-6 fw-bold text-danger">✗</div>
                <div class="text-muted small mb-2">Waiver</div>
                <a href="waiver.php?student_id=<?= $tab_id ?>" class="btn btn-sm btn-warning mt-1">Complete Waiver</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
            <div class="card-body py-2 px-3">
                <?php
                $type_colors = ['student' => 'bg-primary', 'parent' => 'bg-info text-dark', 'guest' => 'bg-secondary'];
                $type_tips   = ['student' => 'Registration fee paid', 'parent' => 'Family account', 'guest' => 'Non-paying participant (registration fee not yet paid)'];
                $_stype      = $student['student_type'] ?? '';
                $_stip       = isset($type_tips[$_stype]) ? ' data-bs-toggle="tooltip" title="' . $type_tips[$_stype] . '"' : '';
                $type_badge  = '<span class="badge ' . ($type_colors[$_stype] ?? 'bg-secondary') . '"' . $_stip . '>'
                             . htmlspecialchars(ucfirst($_stype)) . '</span>';
                $addr_parts = array_filter([
                    htmlspecialchars($student['street_address'] ?? ''),
                    htmlspecialchars($student['city_state_zip'] ?? ''),
                ]);
                if ($student['injury_waiver']) {
                    $waiver_row = '<span class="text-success">✓</span>'
                        . (!empty($student['injury_waiver_date']) ? ' ' . fmt_date($student['injury_waiver_date']) : '')
                        . ' <a href="waiver.php?student_id=' . $tab_id . '" class="btn btn-sm btn-outline-secondary ms-2">View</a>';
                } else {
                    $waiver_row = '—';
                }
                $pv = [
                    'First Name'        => htmlspecialchars($student['first_name'] ?? '') ?: '—',
                    'Last Name'         => htmlspecialchars($student['last_name']  ?? '') ?: '—',
                    'Account Type'      => $type_badge,
                    'Date of Birth'     => $student['date_of_birth'] ? fmt_date($student['date_of_birth']) : '—',
                    'Phone'             => ($student['phone'] ?? '') ? fmt_phone($student['phone']) : '—',
                    'Email'             => htmlspecialchars($student['email'] ?? '') ?: '—',
                    'Emergency Contact' => htmlspecialchars($student['emergency_contact_name']  ?? '') ?: '—',
                    'Emergency Phone'   => ($student['emergency_contact_phone'] ?? '') ? fmt_phone($student['emergency_contact_phone']) : '—',
                    'Address'           => $addr_parts ? implode('<br>', $addr_parts) : '—',
                    'Member Since'      => $student['registration_date'] ? fmt_date($student['registration_date']) : '—',
                    'Waiver'  => $waiver_row,
                    'Medical Note'      => !empty($student['medical_note']) ? nl2br(htmlspecialchars($student['medical_note'])) : '—',
                ];
                $keys = array_keys($pv);
                $last = end($keys);
                foreach ($pv as $lbl => $val): ?>
                <div class="d-flex py-1 <?= $lbl !== $last ? 'border-bottom' : '' ?>">
                    <div class="text-muted small" style="min-width:160px"><?= $lbl ?></div>
                    <div><?= $val ?></div>
                </div>
                <?php endforeach; ?>
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
                        <tr><th>Date</th><th>Testing For</th><th>Score</th><th>Fee</th><th>Awarded</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($belt_tests as $t): ?>
                        <tr>
                            <td><?= fmt_date($t['test_date']) ?></td>
                            <td><?= htmlspecialchars($t['kyu_dan']) ?></td>
                            <td><?= score_badge($t['result'], isset($t['score']) ? (int)$t['score'] : null) ?></td>
                            <td><?= $t['fee_paid'] ? '<span class="text-success">✓</span>' : '' ?></td>
                            <td><?= $t['result'] === 'pass' ? '<span class="text-success">✓</span>' : ($t['result'] === 'fail' ? '<span class="text-danger">✗</span>' : '<span class="text-muted">—</span>') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($children) && $own_student && $tab_id === (int)$own_student['id']): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Child Summary</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Last Attendance</th>
                            <th>Last Payment</th>
                            <th>Waiver</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($children as $ch):
                        $cs = $children_summary[(int)$ch['id']];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ch['first_name'] . ' ' . $ch['last_name']) ?></td>
                        <td><?= $cs['last_attendance'] ? fmt_date($cs['last_attendance']) : '' ?></td>
                        <td>
                            <?php if ($cs['last_payment']): ?>
                                <?= fmt_date($cs['last_payment']['payment_date']) ?>
                                <span class="text-muted small ms-1"><?= fmt_type($cs['last_payment']['payment_type']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $ch['injury_waiver'] ? '<span class="text-success">✓</span>' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div><!-- /row -->

<?php endif; // $student ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

