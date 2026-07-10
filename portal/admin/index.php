<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auto_inactive.php';
require_role('admin');
apply_auto_inactive();

// ── Dismiss / resolve alert (POST) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss_alert') {
    verify_csrf();
    $lr_id = (int)($_POST['lr_id'] ?? 0);
    if ($lr_id) {
        db()->prepare(
            'UPDATE link_requests SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?'
        )->execute([$_SESSION['user_id'], $lr_id]);
        audit('dismiss_alert', 'link_requests', $lr_id);
    }
    header('Location: ./');
    exit;
}

// ── Summary stats ────────────────────────────────────────────
$stats = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM students WHERE active = 1)                          AS active_students,
        (SELECT COUNT(*) FROM students WHERE active = 0)                          AS inactive_students,
        (SELECT COUNT(*) FROM users)                                              AS total_users,
        (SELECT COUNT(*) FROM class_sessions)                                     AS total_sessions,
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date)=YEAR(NOW())) AS revenue_ytd,
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())) AS revenue_month,
        (SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_type=\'rent\' AND paid=1 AND YEAR(expense_date)=YEAR(NOW())) AS rent_ytd
    '
)->fetch();

// Students who haven't paid tuition this month (active, registered students only).
$unpaid = db()->query(
    'SELECT s.id, s.first_name, s.last_name
     FROM students s
     WHERE s.active = 1
       AND s.student_type IN (\'student\',\'parent\',\'instructor\')
       AND s.id NOT IN (
           SELECT student_id FROM payments
           WHERE payment_type = "monthly_tuition"
             AND MONTH(payment_date) = MONTH(NOW())
             AND YEAR(payment_date)  = YEAR(NOW())
       )
     ORDER BY s.first_name, s.last_name'
)->fetchAll();

// Students with no waiver
$no_waiver = db()->query(
    'SELECT id, first_name, last_name FROM students WHERE active=1 AND injury_waiver=0 ORDER BY last_name'
)->fetchAll();

// ── Registration alert queries ───────────────────────────────────
// Claimed existing records (user linked themselves to a known record)
$alerts_claimed = [];
// New self-registered students (FYI)
$alerts_new = [];
// Needs manual linking (user said their record wasn't listed)
$alerts_linking = [];
try {
    $base = 'SELECT lr.id, lr.created_at, lr.student_id,
                     u.id AS user_id, u.username, u.first_name AS u_first, u.last_name AS u_last,
                     s.first_name AS s_first, s.last_name AS s_last, s.student_type
              FROM link_requests lr
              JOIN users u ON u.id = lr.user_id
              LEFT JOIN students s ON s.id = lr.student_id
              WHERE lr.resolved = 0 AND lr.request_type = ?
              ORDER BY lr.created_at DESC';
    $stmt = db()->prepare($base);
    $stmt->execute(['claimed_existing']); $alerts_claimed = $stmt->fetchAll();
    $stmt->execute(['new_student']);      $alerts_new     = $stmt->fetchAll();
    $stmt->execute(['needs_linking']);    $alerts_linking = $stmt->fetchAll();
} catch (Exception $e) {}

// Legacy link requests (old-style notify flow — kept for backward compat)
$link_requests = [];
try {
    $link_requests = db()->query(
        'SELECT lr.id, lr.request_type, lr.notes, lr.created_at,
                u.id AS user_id, u.username, u.first_name, u.last_name, u.email
         FROM link_requests lr
         JOIN users u ON u.id = lr.user_id
         WHERE lr.resolved = 0
           AND lr.request_type IN (\'new_guest\',\'existing_student\',\'parent\')
         ORDER BY lr.created_at DESC'
    )->fetchAll();
} catch (Exception $e) {}

// Possible account links: unlinked users whose name or email matches an unlinked student
$possible_links = db()->query(
    'SELECT u.id AS user_id, u.username, u.first_name AS u_first, u.last_name AS u_last, u.email AS u_email,
            s.id AS student_id, s.first_name AS s_first, s.last_name AS s_last, s.email AS s_email
     FROM users u
     JOIN students s ON s.user_id IS NULL
         AND (
             (LOWER(u.first_name) = LOWER(s.first_name) AND LOWER(u.last_name) = LOWER(s.last_name))
             OR
             (u.email != \'\' AND u.email IS NOT NULL AND s.email != \'\' AND s.email IS NOT NULL
              AND LOWER(u.email) = LOWER(s.email))
         )
     WHERE NOT EXISTS (SELECT 1 FROM students s2 WHERE s2.user_id = u.id)
     ORDER BY u.first_name, u.last_name'
)->fetchAll();

// ── Attendance alert — was last Saturday's class recorded? ──────────────────
$check_saturday = (date('N') == 6)                          // 6 = Saturday
    ? date('Y-m-d')
    : date('Y-m-d', strtotime('last saturday'));
$att_stmt = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
$att_stmt->execute([$check_saturday]);
$attendance_missing = !$att_stmt->fetch();
// Only show alert within 6 days of the expected class (Sat–Fri)
$days_since_saturday = (int)floor((time() - strtotime($check_saturday)) / 86400);
$show_attendance_alert = $attendance_missing && $days_since_saturday <= 6;

// ── Rent reminder — show all month until recorded ─────────────────────────────
$rent_stmt = db()->prepare(
    "SELECT COUNT(*) FROM expenses WHERE expense_type = 'rent' AND DATE_FORMAT(expense_date, '%Y-%m') = ?"
);
$rent_stmt->execute([date('Y-m')]);
$show_rent_alert = (int)$rent_stmt->fetchColumn() === 0;

// Recent payments (last 10)
$recent_payments = db()->query(
    'SELECT p.payment_date, p.amount, p.payment_type, p.payment_method,
            s.first_name, s.last_name
     FROM payments p JOIN students s ON s.id = p.student_id
     ORDER BY p.payment_date DESC LIMIT 11'
)->fetchAll();
$has_more_payments = count($recent_payments) === 11;
if ($has_more_payments) array_pop($recent_payments);

$linked_msg = isset($_GET['linked']) ? 'Accounts linked successfully.' : '';

// ── Revenue chart — last 12 months ───────────────────────────────────────
$rev_rows = db()->query(
    "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS month,
            payment_type,
            SUM(amount) AS total
     FROM payments
     WHERE payment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month, payment_type
     ORDER BY month"
)->fetchAll();

$exp_rows = db()->query(
    "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS month,
            SUM(amount) AS total
     FROM expenses
     WHERE expense_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month
     ORDER BY month"
)->fetchAll();

$exp_type_rows = db()->query(
    "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS month,
            expense_type,
            SUM(amount) AS total
     FROM expenses
     WHERE expense_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month, expense_type
     ORDER BY month"
)->fetchAll();

$don_rows = db()->query(
    "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS month, SUM(amount) AS total
     FROM donations
     WHERE payment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
     GROUP BY month
     ORDER BY month"
)->fetchAll();

$chart_months = [];
for ($i = 11; $i >= 0; $i--) {
    $chart_months[] = date('Y-m', strtotime("-$i months"));
}

$named_types  = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar'];
$exp_types    = ['rent', 'equipment', 'utilities', 'supplies', 'other'];
$chart_data   = array_fill_keys($named_types, []);
$chart_data['other']     = [];
$chart_data['donations'] = [];
$chart_data['revenue']   = [];
$chart_data['expenses']  = [];
$chart_data['exp_abs']   = [];
foreach ($exp_types as $t) $chart_data['exp_' . $t] = [];

$rev_map = [];
foreach ($rev_rows as $r) {
    $rev_map[$r['month']][$r['payment_type']] = (float)$r['total'];
}
$exp_map = [];
foreach ($exp_rows as $r) {
    $exp_map[$r['month']] = (float)$r['total'];
}
$exp_type_map = [];
foreach ($exp_type_rows as $r) {
    $exp_type_map[$r['month']][$r['expense_type']] = (float)$r['total'];
}
$don_map = [];
foreach ($don_rows as $r) {
    $don_map[$r['month']] = (float)$r['total'];
}

foreach ($chart_months as $m) {
    foreach ($named_types as $t) {
        $chart_data[$t][] = $rev_map[$m][$t] ?? 0;
    }
    $other = 0;
    foreach ($rev_map[$m] ?? [] as $type => $amt) {
        if (!in_array($type, $named_types)) $other += $amt;
    }
    $chart_data['other'][]     = $other;
    $chart_data['donations'][] = $don_map[$m] ?? 0;
    foreach ($exp_types as $t) {
        $chart_data['exp_' . $t][] = $exp_type_map[$m][$t] ?? 0;
    }

    $exp_total = $exp_map[$m] ?? 0;
    $chart_data['revenue'][]  = array_sum($rev_map[$m] ?? []) + ($don_map[$m] ?? 0);
    $chart_data['expenses'][] = -$exp_total;
    $chart_data['exp_abs'][]  = $exp_total;
}

$chart_labels = array_map(fn($m) => date('M Y', strtotime($m . '-01')), $chart_months);

$page_title = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($linked_msg): ?>
<div class="alert alert-success"><?= htmlspecialchars($linked_msg) ?></div>
<?php endif; ?>

<?php if ($show_attendance_alert): ?>
<div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
    <span class="fw-semibold">⚠ Attendance not recorded</span> —
    No class session found for <?= date('D d M Y', strtotime($check_saturday)) ?>.
    <a href="../instructor/attendance.php?date=<?= $check_saturday ?>" class="alert-link ms-1">Record now →</a>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($show_rent_alert): ?>
<div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
    <span class="fw-semibold">⚠ Rent not recorded</span> —
    No rent payment found for <?= date('F Y') ?>.
    <a href="expenses.php" class="alert-link ms-1">Record now →</a>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>


<!-- ── Stat cards ── -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Active Students',         $stats['active_students'],                               'text-primary'],
        ['Total Students',          $stats['active_students'] + $stats['inactive_students'], 'text-primary'],
        ['Revenue ('.date('F').')', '$'.number_format($stats['revenue_month'],2),            'text-success'],
        ['Revenue ('.date('Y').')', '$'.number_format($stats['revenue_ytd'],2),              'text-success'],
        ['Paid to Center Stage ('.date('Y').')', '$'.number_format($stats['rent_ytd'],2),      'text-danger'],
    ];
    foreach ($cards as [$label, $val, $cls]): ?>
    <div class="col-6 col-lg">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body">
                <div class="display-6 fw-bold <?= $cls ?>"><?= $val ?></div>
                <div class="text-muted small"><?= $label ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Revenue chart ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Revenue and Expenses</div>
    <div class="card-body">
        <canvas id="revenueChart" height="80"></canvas>
    </div>
</div>

<!-- ── Action items + Recent payments ── -->
<div class="row g-4">

    <!-- Attention items -->
    <div class="col-lg-5 d-flex flex-column gap-4">

        <!-- Unpaid tuition this month -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Tuition Unpaid — <?= date('F Y') ?></span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($unpaid) ?></span>
            </div>
            <div class="card-body p-0" style="max-height:220px;overflow-y:auto">
                <?php if (empty($unpaid)): ?>
                    <p class="p-3 text-success mb-0">All students paid ✓</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($unpaid as $s): ?>
                        <tr>
                            <td>
                                <a href="../instructor/student_profile.php?id=<?= $s['id'] ?>">
                                    <?= hn($s['first_name'].' '.$s['last_name']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="payments.php?action=add&student_id=<?= $s['id'] ?>&type=monthly_tuition"
                                   class="btn btn-success btn-sm py-0">Record Payment</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Missing waivers -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Missing Waivers</span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($no_waiver) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($no_waiver)): ?>
                    <p class="p-3 text-success mb-0">All waivers signed ✓</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($no_waiver as $s): ?>
                        <tr>
                            <td>
                                <a href="student_edit.php?id=<?= $s['id'] ?>">
                                    <?= hn($s['first_name'].' '.$s['last_name']) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Needs Manual Linking (action required) ── -->
        <?php if (!empty($alerts_linking)): ?>
        <div class="card border-0 shadow-sm border-start border-4 border-danger">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Needs Manual Linking</span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($alerts_linking) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>User</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts_linking as $a): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($a['username']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars(trim($a['u_first'].' '.$a['u_last'])) ?></div>
                            </td>
                            <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                            <td>
                                <a href="resolve_link.php?lr_id=<?= $a['id'] ?>"
                                   class="btn btn-sm btn-warning py-0">Resolve</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Claimed Existing Records (auto-linked, FYI) ── -->
        <?php if (!empty($alerts_claimed)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Claimed Existing Records</span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($alerts_claimed) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Login</th><th>Linked To</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts_claimed as $a): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($a['username']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars(trim($a['u_first'].' '.$a['u_last'])) ?></div>
                            </td>
                            <td class="small">
                                <?php if ($a['s_first']): ?>
                                    <a href="../instructor/student_profile.php?id=<?= $a['student_id'] ?>">
                                        <?= htmlspecialchars($a['s_first'].' '.$a['s_last']) ?>
                                    </a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="dismiss_alert">
                                    <input type="hidden" name="lr_id"  value="<?= $a['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary py-0">Dismiss</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── New Registrations (FYI) ── -->
        <?php if (!empty($alerts_new)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>New Registrations</span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($alerts_new) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>User</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($alerts_new as $a): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($a['username']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars(trim($a['u_first'].' '.$a['u_last'])) ?></div>
                            </td>
                            <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="dismiss_alert">
                                    <input type="hidden" name="lr_id"  value="<?= $a['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary py-0">Dismiss</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Legacy link requests (old notify-Noji flow) -->
        <?php if (!empty($link_requests)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Link Requests</span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($link_requests) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>User</th><th>Type</th><th>Notes</th><th>Date</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $lr_labels = [
                        'new_guest'        => ['New Student',      'bg-success'],
                        'existing_student' => ['Existing Student', 'bg-primary'],
                        'parent'           => ['Parent',           'bg-info text-dark'],
                    ];
                    foreach ($link_requests as $lr):
                        [$lbl, $cls] = $lr_labels[$lr['request_type']] ?? [ucfirst($lr['request_type']), 'bg-secondary'];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($lr['username']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars(trim($lr['first_name'].' '.$lr['last_name'])) ?></div>
                            </td>
                            <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
                            <td class="small text-muted" style="max-width:160px">
                                <?= $lr['notes'] ? htmlspecialchars(mb_strimwidth($lr['notes'], 0, 60, '…')) : '—' ?>
                            </td>
                            <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($lr['created_at'])) ?></td>
                            <td>
                                <a href="compare_account.php?user_id=<?= $lr['user_id'] ?>&link_request_id=<?= $lr['id'] ?>"
                                   class="btn btn-sm btn-outline-primary py-0">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Possible account links -->
        <?php if (!empty($possible_links)): ?>
        <div class="card border-0 shadow-sm border-start border-4 border-info">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Possible Account Links</span>
                <span class="badge" style="background-color:#fd7e14;color:#fff"><?= count($possible_links) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Login</th>
                            <th>Matches Roster</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($possible_links as $m): ?>
                        <tr>
                            <td>
                                <div><?= htmlspecialchars($m['username']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars(trim($m['u_first'].' '.$m['u_last'])) ?></small>
                            </td>
                            <td>
                                <a href="../instructor/student_profile.php?id=<?= $m['student_id'] ?>">
                                    <?= htmlspecialchars($m['s_first'].' '.$m['s_last']) ?>
                                </a>
                                <?php if ($m['u_email'] && $m['s_email'] && strtolower($m['u_email']) === strtolower($m['s_email'])): ?>
                                    <br><small class="text-muted">email match</small>
                                <?php else: ?>
                                    <br><small class="text-muted">name match</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="compare_account.php?user_id=<?= $m['user_id'] ?>&student_id=<?= $m['student_id'] ?>"
                                   class="btn btn-sm btn-primary py-0">Compare</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Recent payments -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Recent Payments</span>
                <?php if ($has_more_payments): ?>
                <a href="payments.php" class="btn btn-sm btn-outline-primary">View All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_payments)): ?>
                    <p class="p-3 text-muted">No payments yet.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Type</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_payments as $p): ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= hn($p['first_name'].' '.$p['last_name']) ?></td>
                            <td><?= ucwords(str_replace('_',' ',$p['payment_type'])) ?></td>
                            <td class="text-end">$<?= number_format($p['amount'],2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<style nonce="<?= csp_nonce() ?>">.bg-purple { background-color: #6f42c1 !important; }</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"
        integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g"
        crossorigin="anonymous"></script>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var htmlRoot = document.getElementById('html-root');
    function textColor() {
        return htmlRoot.getAttribute('data-bs-theme') === 'dark' ? '#ffffff' : '#212529';
    }

    var bd = {
        tuition:      <?= json_encode($chart_data['monthly_tuition']) ?>,
        registration: <?= json_encode($chart_data['registration']) ?>,
        belt_test:    <?= json_encode($chart_data['belt_test']) ?>,
        slc_training: <?= json_encode($chart_data['slc_training']) ?>,
        seminar:      <?= json_encode($chart_data['seminar']) ?>,
        donations:    <?= json_encode($chart_data['donations']) ?>,
        other:        <?= json_encode($chart_data['other']) ?>,
        exp_rent:      <?= json_encode($chart_data['exp_rent']) ?>,
        exp_equipment: <?= json_encode($chart_data['exp_equipment']) ?>,
        exp_utilities: <?= json_encode($chart_data['exp_utilities']) ?>,
        exp_supplies:  <?= json_encode($chart_data['exp_supplies']) ?>,
        exp_other:     <?= json_encode($chart_data['exp_other']) ?>,
        exp_abs:       <?= json_encode($chart_data['exp_abs']) ?>,
    };

    var chart = new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'Revenue',
                    data: <?= json_encode($chart_data['revenue']) ?>,
                    backgroundColor: 'rgba(25,135,84,0.75)',
                    stack: 'a',
                },
                {
                    label: 'Expenses',
                    data: <?= json_encode($chart_data['expenses']) ?>,
                    backgroundColor: 'rgba(220,53,69,0.75)',
                    stack: 'b',
                },
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top', labels: { color: textColor() } },
                tooltip: {
                    mode: 'index',
                    callbacks: {
                        label: ctx => {
                            if (ctx.raw === 0) return null;
                            var i = ctx.dataIndex;
                            var lines = [];
                            if (ctx.dataset.label === 'Revenue') {
                                if (bd.tuition[i]      > 0) lines.push(' Tuition: $'      + bd.tuition[i].toFixed(2));
                                if (bd.registration[i] > 0) lines.push(' Registration: $' + bd.registration[i].toFixed(2));
                                if (bd.belt_test[i]    > 0) lines.push(' Belt Tests: $'   + bd.belt_test[i].toFixed(2));
                                if (bd.slc_training[i] > 0) lines.push(' SLC Training: $' + bd.slc_training[i].toFixed(2));
                                if (bd.seminar[i]      > 0) lines.push(' Seminar: $'      + bd.seminar[i].toFixed(2));
                                if (bd.donations[i]    > 0) lines.push(' Donations: $'    + bd.donations[i].toFixed(2));
                                if (bd.other[i]        > 0) lines.push(' Other: $'        + bd.other[i].toFixed(2));
                            } else if (ctx.dataset.label === 'Expenses') {
                                if (bd.exp_rent[i]      > 0) lines.push(' Rent: $'      + bd.exp_rent[i].toFixed(2));
                                if (bd.exp_equipment[i] > 0) lines.push(' Equipment: $' + bd.exp_equipment[i].toFixed(2));
                                if (bd.exp_utilities[i] > 0) lines.push(' Utilities: $' + bd.exp_utilities[i].toFixed(2));
                                if (bd.exp_supplies[i]  > 0) lines.push(' Supplies: $'  + bd.exp_supplies[i].toFixed(2));
                                if (bd.exp_other[i]     > 0) lines.push(' Other: $'     + bd.exp_other[i].toFixed(2));
                            }
                            return lines.length ? lines : null;
                        },
                        footer: items => {
                            var net  = items.reduce((s, item) => s + item.raw, 0);
                            var sign = net < 0 ? '-' : '';
                            return 'Net: ' + sign + '$' + Math.abs(net).toFixed(2);
                        }
                    }
                }
            },
            scales: {
                x: { stacked: true, ticks: { color: textColor() } },
                y: {
                    stacked: true,
                    ticks: { color: textColor(), callback: v => '$' + Math.abs(v) }
                }
            }
        }
    });

    new MutationObserver(function () {
        var c = textColor();
        chart.options.plugins.legend.labels.color = c;
        chart.options.scales.x.ticks.color = c;
        chart.options.scales.y.ticks.color = c;
        chart.update();
    }).observe(htmlRoot, { attributes: true, attributeFilter: ['data-bs-theme'] });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

