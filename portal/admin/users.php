<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$msg   = '';
$error = '';

// ── Toggle active status ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle' ) {
    verify_csrf();
    $tog_id = (int)$_POST['id'];
    if ($tog_id !== current_user_id()) {
        db()->prepare('UPDATE users SET active = IF(active=1,0,1) WHERE id=?')->execute([$tog_id]);
        audit('toggle_user_active', 'user', $tog_id);
    }
    header('Location: users.php?msg=updated');
    exit;
}

// ── Unlink a user from a student record ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink') {
    verify_csrf();
    $unlink_id = (int)$_POST['id'];
    db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$unlink_id]);
    header('Location: users.php?msg=unlinked');
    exit;
}

// ── Reset password ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $uid  = (int)$_POST['user_id'];
    $pass = trim($_POST['new_password'] ?? '');
    verify_csrf();
    if (!$uid || strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
             ->execute([password_hash($pass, PASSWORD_BCRYPT), $uid]);
        audit('reset_password', 'user', $uid);
        $msg = 'Password updated.';
    }
}

// ── Link a user to a student record ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_student'])) {
    verify_csrf();
    $uid = (int)$_POST['user_id'];
    $sid = (int)$_POST['student_id'];
    if ($uid && $sid) {
        // Clear any existing link for this student or user
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$uid]);
        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')->execute([$uid, $sid]);
        // Sync users.role from student_type
        $stype_stmt = db()->prepare('SELECT student_type FROM students WHERE id = ?');
        $stype_stmt->execute([$sid]);
        $stype = $stype_stmt->fetchColumn();
        $role  = in_array($stype, ['instructor', 'admin']) ? $stype : 'student';
        db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $uid]);
        $msg = 'Account linked to roster entry.';
    }
}

if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'updated':  $msg = 'User updated.'; break;
        case 'unlinked': $msg = 'Account unlinked from roster.'; break;
        default:         $msg = 'Done.';
    }
}

// ── Load all users with linked student if any ─────────────────
$users = db()->query(
    'SELECT u.id, u.username, u.first_name AS u_first, u.last_name AS u_last,
            u.email, u.role, u.active, u.last_login,
            s.first_name, s.last_name, s.id AS student_id, s.student_type
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     ORDER BY u.role, u.username'
)->fetchAll();

// Unlinked roster entries (for the link dropdown) — exclude children already linked to a parent
$unlinked_students = db()->query(
    'SELECT id, first_name, last_name, student_type, active
     FROM students
     WHERE user_id IS NULL
       AND id NOT IN (SELECT student_id FROM parent_students)
     ORDER BY last_name, first_name'
)->fetchAll();

$page_title = 'User Accounts';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">User Accounts</h3>
</div>
<div class="d-flex gap-2 align-items-center mb-4 flex-wrap">
    <input type="text" id="userSearch" class="form-control form-control-sm"
           placeholder="Search username…" style="width:180px" oninput="filterUsers()">
    <select id="filterRole" class="form-select form-select-sm" style="width:130px" onchange="filterUsers()">
        <option value="">All Roles</option>
        <option value="student">Student</option>
        <option value="instructor">Instructor</option>
        <option value="admin">Admin</option>
        <option value="guest">Guest</option>
        <option value="parent">Parent</option>
    </select>
    <select id="filterStatus" class="form-select form-select-sm" style="width:130px" onchange="filterUsers()">
        <option value="">All Statuses</option>
        <option value="active">Activated</option>
        <option value="inactive">Deactivated</option>
    </select>
    <select id="filterLinked" class="form-select form-select-sm" style="width:150px" onchange="filterUsers()">
        <option value="">All Accounts</option>
        <option value="yes">Linked</option>
        <option value="no">Unlinked</option>
    </select>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>


<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Username</th>
                    <th>Linked Roster Entry</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= !$u['active'] ? 'text-muted' : '' ?>"
                    data-role="<?= htmlspecialchars($u['student_type'] ?? $u['role']) ?>"
                    data-status="<?= $u['active'] ? 'active' : 'inactive' ?>"
                    data-linked="<?= $u['student_id'] ? 'yes' : 'no' ?>">

                    <!-- Username -->
                    <td><?= htmlspecialchars($u['username']) ?></td>

                    <!-- Linked roster entry -->
                    <td>
                        <?php if ($u['student_id']): ?>
                            <a href="../instructor/student_profile.php?id=<?= $u['student_id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?>
                            </a>
                        <?php else: ?>
                            <span>Not linked</span>
                        <?php endif; ?>
                    </td>

                    <!-- Role -->
                    <td>
                        <?php
                        $role_display = $u['student_type'] ?? $u['role'];
                        $role_tips = [
                            'admin'      => 'Full administrative access to all portal features',
                            'instructor' => 'Can take attendance and view the student roster',
                            'student'    => 'Paying participant — $30/month tuition',
                            'guest'      => 'Non-paying participant — registration fee not yet paid',
                            'parent'     => 'Family account — manages linked children\'s profiles and payments',
                        ];
                        $role_badges  = [
                            'admin'      => 'bg-danger',
                            'instructor' => 'bg-warning text-dark',
                            'student'    => 'bg-primary',
                            'guest'      => 'bg-secondary',
                        ];
                        $cls = $role_badges[$role_display] ?? 'bg-secondary';
                        $tip = $role_tips[$role_display] ?? '';
                        ?>
                        <span class="badge <?= $cls ?>"
                              <?= $tip ? 'data-bs-toggle="tooltip" title="' . htmlspecialchars($tip) . '"' : '' ?>>
                            <?= ucfirst($role_display) ?>
                        </span>
                        <?php if ($u['id'] === current_user_id()): ?>
                            <span class="badge bg-secondary ms-1">you</span>
                        <?php endif; ?>
                    </td>

                    <!-- Status -->
                    <td>
                        <?= $u['active']
                            ? '<span class="badge bg-secondary" data-bs-toggle="tooltip" title="Activated: this login is enabled and can sign in">Activated</span>'
                            : '<span class="badge bg-danger" data-bs-toggle="tooltip" title="Deactivated: this login has been disabled — cannot sign in">Deactivated</span>' ?>
                    </td>

                    <!-- Last login -->
                    <td>
                        <?= $u['last_login'] ? date('M j, Y', strtotime($u['last_login'])) : 'Never' ?>
                    </td>

                    <!-- Actions -->
                    <td>
                        <a href="user_profile.php?id=<?= $u['id'] ?>"
                           class="btn btn-sm btn-outline-secondary">View</a>
                    </td>

                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterUsers() {
    var q      = document.getElementById('userSearch').value.toLowerCase().trim();
    var role   = document.getElementById('filterRole').value;
    var status = document.getElementById('filterStatus').value;
    var linked = document.getElementById('filterLinked').value;
    document.querySelectorAll('tbody tr[data-role]').forEach(function(row) {
        var name = row.querySelector('td').textContent.toLowerCase();
        var match = (!q      || name.includes(q))
                 && (!role   || row.dataset.role   === role)
                 && (!status || row.dataset.status === status)
                 && (!linked || row.dataset.linked === linked);
        row.style.display = match ? '' : 'none';
    });
}
</script>

<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
