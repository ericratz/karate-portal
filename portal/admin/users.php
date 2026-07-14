<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');
header('Cache-Control: no-store');

$msg   = '';
$error = '';

// ── Toggle active status ──────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'toggle' ) {
    verify_csrf();
    $tog_id = post_int('id');
    if ($tog_id !== current_user_id()) {
        db()->prepare('UPDATE users SET active = IF(active=1,0,1) WHERE id=?')->execute([$tog_id]);
        audit('toggle_user_active', 'user', $tog_id);
    }
    header('Location: users.php?msg=updated');
    exit;
}

// ── Unlink a user from a student record ──────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'unlink') {
    verify_csrf();
    $unlink_id = post_int('id');
    db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$unlink_id]);
    header('Location: users.php?msg=unlinked');
    exit;
}

// ── Reset password ────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_password'])) {
    $uid  = post_int('user_id');
    $pass = trim(post_str('new_password'));
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['link_student'])) {
    verify_csrf();
    $uid = post_int('user_id');
    $sid = post_int('student_id');
    if ($uid && $sid) {
        // Clear any existing link for this student or user
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$uid]);
        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')->execute([$uid, $sid]);
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
            u.email, u.is_admin, u.active, u.last_login,
            s.first_name, s.last_name, s.id AS student_id, s.student_type
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     ORDER BY u.is_admin DESC, u.username'
)->fetchAll();

// Unlinked roster entries (for the link dropdown)
$unlinked_students = db()->query(
    'SELECT id, first_name, last_name, student_type, active
     FROM students
     WHERE user_id IS NULL
     ORDER BY first_name, last_name'
)->fetchAll();

$linked_users   = array_values(array_filter($users, fn($u) =>  $u['student_id']));
$unlinked_users = array_values(array_filter($users, fn($u) => !$u['student_id']));

$role_tips   = [
    'admin'      => 'Full administrative access to all portal features',
    'instructor' => 'Can take attendance and view the student roster',
    'student'    => 'Paying participant — $30/month tuition',
    'guest'      => 'Non-paying participant — registration fee not yet paid',
    'parent'     => 'Family account — manages linked children\'s profiles and payments',
];
$role_badges = [
    'admin'      => 'bg-danger',
    'instructor' => 'bg-warning text-dark',
    'student'    => 'bg-primary',
    'parent'     => 'bg-info text-dark',
    'guest'      => 'bg-secondary',
];

function user_row(array $u, bool $show_roster = true): void {
    global $role_tips, $role_badges;
    $role_display = $u['is_admin'] ? 'admin' : ($u['student_type'] ?? 'student');
    $cls = $role_badges[$role_display] ?? 'bg-secondary';
    $tip = $role_tips[$role_display]   ?? '';
    echo '<tr class="' . (!$u['active'] ? 'text-muted' : '') . '"'
       . ' data-name="'   . htmlspecialchars(strtolower($u['username'])) . '"'
       . ' data-role="'   . htmlspecialchars($role_display)              . '"'
       . ' data-status="' . ($u['active'] ? 'active' : 'inactive')      . '"'
       . '>';
    echo '<td class="fw-semibold">' . htmlspecialchars($u['username']) . '</td>';
    if ($show_roster) {
        echo '<td>';
        if ($u['student_id']) {
            echo '<a href="../instructor/student_profile.php?id=' . $u['student_id'] . '" class="text-decoration-none">'
               . hn($u['first_name'] . ' ' . $u['last_name']) . '</a>';
        }
        echo '</td>';
    }
    echo '<td>';
    echo '<span class="badge ' . $cls . '"'
       . ($tip ? ' data-bs-toggle="tooltip" title="' . htmlspecialchars($tip) . '"' : '') . '>'
       . ucfirst($role_display) . '</span>';
    if ($u['id'] === current_user_id()) {
        echo ' <span class="badge bg-secondary">you</span>';
    }
    echo '</td>';
    echo '<td>';
    echo $u['active']
        ? '<span class="badge bg-secondary" data-bs-toggle="tooltip" title="Activated: this login is enabled and can sign in">Activated</span>'
        : '<span class="badge bg-danger"    data-bs-toggle="tooltip" title="Deactivated: this login has been disabled — cannot sign in">Deactivated</span>';
    echo '</td>';
    echo '<td>' . ($u['last_login'] ? date('d M Y', strtotime($u['last_login'])) : 'Never') . '</td>';
    echo '<td><a href="user_profile.php?id=' . $u['id'] . '" class="btn btn-sm btn-outline-secondary">View</a></td>';
    echo '</tr>';
}

$page_title = 'User Accounts';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">User Accounts</h3>
</div>
<div class="d-flex gap-2 align-items-center mb-4 flex-wrap">
    <input type="text" id="userSearch" class="form-control form-control-sm"
           placeholder="Search username…" style="width:180px">
    <select id="filterRole" class="form-select form-select-sm" style="width:130px">
        <option value="">All Roles</option>
        <option value="student">Student</option>
        <option value="instructor">Instructor</option>
        <option value="admin">Admin</option>
        <option value="guest">Guest</option>
        <option value="parent">Parent</option>
    </select>
    <select id="filterStatus" class="form-select form-select-sm" style="width:130px">
        <option value="">All Statuses</option>
        <option value="active">Activated</option>
        <option value="inactive">Deactivated</option>
    </select>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Linked Accounts -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Linked Accounts</span>
        <span class="badge bg-primary"><?= count($linked_users) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" id="linkedTable">
            <thead class="table-light">
                <tr>
                    <th>Username</th>
                    <th>Roster Entry</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($linked_users as $u): user_row($u, true); endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Unlinked Accounts -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Unlinked Accounts</span>
        <span class="badge bg-primary"><?= count($unlinked_users) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle" id="unlinkedTable">
            <thead class="table-light">
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unlinked_users as $u): user_row($u, false); endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
function filterUsers() {
    var q      = document.getElementById('userSearch').value.toLowerCase().trim();
    var role   = document.getElementById('filterRole').value;
    var status = document.getElementById('filterStatus').value;
    document.querySelectorAll('#linkedTable tbody tr, #unlinkedTable tbody tr').forEach(function(row) {
        var match = (!q      || row.dataset.name.includes(q))
                 && (!role   || row.dataset.role   === role)
                 && (!status || row.dataset.status === status);
        row.style.display = match ? '' : 'none';
    });
}
document.getElementById('userSearch').addEventListener('input', filterUsers);
document.getElementById('filterRole').addEventListener('change', filterUsers);
document.getElementById('filterStatus').addEventListener('change', filterUsers);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

