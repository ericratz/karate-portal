<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auto_inactive.php';
require_role('admin');
apply_auto_inactive();

// ── Summary stats ────────────────────────────────────────────
$stats = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM students WHERE active = 1)                          AS active_students,
        (SELECT COUNT(*) FROM students WHERE active = 0)                          AS inactive_students,
        (SELECT COUNT(*) FROM users)                                              AS total_users,
        (SELECT COUNT(*) FROM class_sessions)                                     AS total_sessions,
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date)=YEAR(NOW())) AS revenue_ytd,
        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())) AS revenue_month
    '
)->fetch();

// Students who haven't paid tuition this month.
// A child whose family group has already paid is considered covered —
// one tuition payment covers the whole parent/child group.
$unpaid = db()->query(
    'SELECT s.id, s.first_name, s.last_name
     FROM students s
     WHERE s.active = 1
       -- Not paid directly
       AND s.id NOT IN (
           SELECT student_id FROM payments
           WHERE payment_type = "monthly_tuition"
             AND MONTH(payment_date) = MONTH(NOW())
             AND YEAR(payment_date)  = YEAR(NOW())
       )
       -- Not covered by a family-group payment (handles both child and parent sides)
       AND NOT EXISTS (
           SELECT 1
           FROM parent_students ps
           WHERE (
               ps.student_id = s.id
               OR (s.user_id IS NOT NULL AND ps.parent_user_id = s.user_id)
           )
           AND EXISTS (
               SELECT 1 FROM payments p
               WHERE p.payment_type = "monthly_tuition"
                 AND MONTH(p.payment_date) = MONTH(NOW())
                 AND YEAR(p.payment_date)  = YEAR(NOW())
                 AND p.student_id IN (
                     SELECT student_id FROM parent_students ps2
                      WHERE ps2.parent_user_id = ps.parent_user_id
                     UNION
                     SELECT id FROM students WHERE user_id = ps.parent_user_id
                 )
           )
       )
     ORDER BY s.last_name, s.first_name'
)->fetchAll();

// Students with no waiver
$no_waiver = db()->query(
    'SELECT id, first_name, last_name FROM students WHERE active=1 AND injury_waiver=0 ORDER BY last_name'
)->fetchAll();

// Link requests submitted by new registrations
$link_requests = [];
try {
    $link_requests = db()->query(
        'SELECT lr.id, lr.request_type, lr.notes, lr.created_at,
                u.id AS user_id, u.username, u.first_name, u.last_name, u.email
         FROM link_requests lr
         JOIN users u ON u.id = lr.user_id
         WHERE lr.resolved = 0
         ORDER BY lr.created_at DESC'
    )->fetchAll();
} catch (Exception $e) {}

// Possible account links: unlinked users whose name or email matches an unlinked student
$possible_links = db()->query(
    'SELECT u.id AS user_id, u.username, u.first_name AS u_first, u.last_name AS u_last, u.email AS u_email,
            s.id AS student_id, s.first_name AS s_first, s.last_name AS s_last, s.email AS s_email
     FROM users u
     JOIN students s ON s.user_id IS NULL
         AND s.id NOT IN (SELECT student_id FROM parent_students)
         AND (
             (LOWER(u.first_name) = LOWER(s.first_name) AND LOWER(u.last_name) = LOWER(s.last_name))
             OR
             (u.email != \'\' AND u.email IS NOT NULL AND s.email != \'\' AND s.email IS NOT NULL
              AND LOWER(u.email) = LOWER(s.email))
         )
     WHERE NOT EXISTS (SELECT 1 FROM students s2 WHERE s2.user_id = u.id)
     ORDER BY u.last_name, u.first_name'
)->fetchAll();

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

$page_title = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($linked_msg): ?>
<div class="alert alert-success"><?= htmlspecialchars($linked_msg) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0"></h3>
    <div class="d-flex gap-2 flex-wrap">
        <a href="students.php"              class="btn btn-primary btn-sm">Roster</a>
        <a href="payments.php"              class="btn btn-primary btn-sm">Payments</a>
        <a href="../instructor/index.php"   class="btn btn-primary btn-sm">Instructor Dashboard</a>
        <a href="email_students.php"        class="btn btn-primary btn-sm">Email Students</a>
        <a href="users.php"                 class="btn btn-primary btn-sm">Users</a>
    </div>
</div>

<!-- ── Stat cards ── -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Active Students',    $stats['active_students'],            'text-primary'],
        ['Total Students',     $stats['active_students'] + $stats['inactive_students'], 'text-primary'],
        ['Revenue ('.date('F').')', '$'.number_format($stats['revenue_month'],2), 'text-success'],
        ['Revenue (YTD)',      '$'.number_format($stats['revenue_ytd'],2),   'text-success'],
    ];
    foreach ($cards as [$label, $val, $cls]): ?>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body">
                <div class="display-6 fw-bold <?= $cls ?>"><?= $val ?></div>
                <div class="text-muted small"><?= $label ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
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
                                    <?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?>
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
                <span>Missing Injury Waivers</span>
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
                                    <?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Link requests from new registrations -->
        <?php if (!empty($link_requests)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Link Requests</span>
                <span class="badge bg-warning text-dark"><?= count($link_requests) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Type</th>
                            <th>Notes</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $lr_type_labels = [
                        'new_guest'        => ['New Student',       'bg-success'],
                        'existing_student' => ['Existing Student',  'bg-primary'],
                        'parent'           => ['Parent',            'bg-purple text-white'],
                    ];
                    foreach ($link_requests as $lr):
                        [$lr_label, $lr_cls] = $lr_type_labels[$lr['request_type']] ?? [ucfirst($lr['request_type']), 'bg-secondary'];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars($lr['username']) ?></div>
                                <div class="text-muted small"><?= htmlspecialchars(trim($lr['first_name'].' '.$lr['last_name'])) ?></div>
                            </td>
                            <td><span class="badge <?= $lr_cls ?>"><?= $lr_label ?></span></td>
                            <td class="small text-muted" style="max-width:160px">
                                <?= $lr['notes'] ? htmlspecialchars(mb_strimwidth($lr['notes'], 0, 60, '…')) : '—' ?>
                            </td>
                            <td class="small text-muted text-nowrap">
                                <?= date('M j', strtotime($lr['created_at'])) ?>
                            </td>
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
                            <td><?= date('M j', strtotime($p['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($p['last_name'].', '.$p['first_name']) ?></td>
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

<style>.bg-purple { background-color: #6f42c1 !important; }</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
