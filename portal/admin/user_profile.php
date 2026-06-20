<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$id    = (int)($_GET['id'] ?? 0);
$msg   = '';
$error = '';

if (!$id) { header('Location: users.php'); exit; }

// ── Update account details ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account') {
    verify_csrf();
    $username   = trim($_POST['username']   ?? '');
    $email      = trim($_POST['email']      ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $dob        = trim($_POST['date_of_birth'] ?? '');
    $role       = in_array($_POST['role'] ?? '', ['student','instructor','admin','parent']) ? $_POST['role'] : 'student';
    if (!$username) {
        $error = 'Username is required.';
    } else {
        // Check username uniqueness
        $chk = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $chk->execute([$username, $id]);
        if ($chk->fetch()) {
            $error = 'That username is already taken.';
        } else {
            // Account details live entirely on users — student record is managed separately via student_edit
            db()->prepare('UPDATE users SET username=?, email=?, role=?, first_name=?, last_name=?, date_of_birth=? WHERE id=?')
                 ->execute([$username, $email ?: null, $role, $first_name ?: null, $last_name ?: null, $dob ?: null, $id]);
            audit('update_user', 'user', $id);
            header("Location: user_profile.php?id=$id&msg=saved");
            exit;
        }
    }
}

// ── Reset password ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    verify_csrf();
    $pass = trim($_POST['new_password'] ?? '');
    if (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
             ->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
        audit('reset_password', 'user', $id);
        header("Location: user_profile.php?id=$id&msg=password");
        exit;
    }
}

// ── Toggle active ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    verify_csrf();
    if ($id !== current_user_id()) {
        db()->prepare('UPDATE users SET active = IF(active=1,0,1) WHERE id=?')->execute([$id]);
        audit('toggle_user_active', 'user', $id);
    }
    header("Location: user_profile.php?id=$id&msg=saved");
    exit;
}

// ── Unlink from roster ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlink') {
    verify_csrf();
    db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$id]);
    audit('unlink_user', 'user', $id);
    header("Location: user_profile.php?id=$id&msg=unlinked");
    exit;
}

// ── Link to roster entry ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'link_student') {
    verify_csrf();
    $sid = (int)($_POST['student_id'] ?? 0);
    if ($sid) {
        db()->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')->execute([$id, $sid]);
        $stype = db()->prepare('SELECT student_type FROM students WHERE id = ?');
        $stype->execute([$sid]);
        $role = $stype->fetchColumn();
        $role = in_array($role, ['instructor','admin']) ? $role : 'student';
        db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $id]);
        audit('link_user', 'user', $id, "student_id=$sid");
        header("Location: user_profile.php?id=$id&msg=linked");
        exit;
    }
}

// ── Delete user account (preserves student record) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    verify_csrf();
    if ($id === current_user_id()) {
        $error = 'You cannot delete your own account.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE students SET user_id = NULL WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
            audit('delete_user', 'user', $id);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $error = 'Delete failed — no changes were made.';
        }
        if (!$error) {
            header('Location: users.php?msg=deleted');
            exit;
        }
    }
}

// ── Flash messages ────────────────────────────────────────────
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'saved':         $msg = 'Changes saved.'; break;
        case 'password':      $msg = 'Password updated.'; break;
        case 'linked':        $msg = 'Account linked to roster entry.'; break;
        case 'unlinked':      $msg = 'Account unlinked from roster.'; break;
        case 'deleted':       $msg = 'User account deleted.'; break;
    }
}

// ── Load user ─────────────────────────────────────────────────
$user = db()->prepare(
    'SELECT u.*, s.id AS student_id,
            s.first_name AS student_first_name, s.last_name AS student_last_name,
            s.student_type
     FROM users u
     LEFT JOIN students s ON s.user_id = u.id
     WHERE u.id = ?'
);
$user->execute([$id]);
$user = $user->fetch();
if (!$user) { header('Location: users.php'); exit; }

// Unlinked roster entries for link dropdown
$unlinked = db()->query(
    'SELECT id, first_name, last_name, student_type
     FROM students
     WHERE user_id IS NULL
     ORDER BY first_name, last_name'
)->fetchAll();

$role_badges = [
    'admin'      => 'bg-danger',
    'instructor' => 'bg-warning text-dark',
    'student'    => 'bg-primary',
    'parent'     => 'bg-info text-dark',
    'guest'      => 'bg-secondary',
];

$page_title = 'User Account — ' . htmlspecialchars($user['username']);
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">
        <?= htmlspecialchars($user['username']) ?>
        <span class="badge <?= $role_badges[$user['role']] ?? 'bg-secondary' ?> ms-1">
            <?= ucfirst($user['role']) ?>
        </span>
        <?= !$user['active'] ? '<span class="badge bg-danger ms-1">Deactivated</span>' : '' ?>
    </h4>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-4" style="max-width:700px">

    <!-- Account Details -->
    <div class="col-12">
        <form id="account-form" method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_account">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Account Details</span>
                    <div class="d-flex gap-2">
                        <button type="button" id="accountCancelBtn" class="btn btn-sm btn-secondary" style="display:none"
                                onclick="cardCancel('account')">Cancel</button>
                        <button type="button" id="accountEditBtn" class="btn btn-sm btn-success"
                                onclick="cardToggle('account')">Edit</button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- View -->
                    <div id="account-view" class="row g-2">
                        <?php
                        $av = [
                            'First Name'      => htmlspecialchars($user['first_name'] ?? '') ?: '—',
                            'Last Name'       => htmlspecialchars($user['last_name']  ?? '') ?: '—',
                            'Date of Birth'   => !empty($user['date_of_birth']) ? date('d M Y', strtotime($user['date_of_birth'])) : '—',
                            'Username'        => htmlspecialchars($user['username']),
                            'Email'           => htmlspecialchars($user['email'] ?? '') ?: '—',
                            'Role'            => ucfirst($user['role']),
                            'Account Created' => date('d M Y', strtotime($user['created_at'])),
                            'Last Login'      => $user['last_login'] ? date('d M Y g:i a', strtotime($user['last_login'])) : 'Never',
                        ];
                        foreach ($av as $lbl => $val): ?>
                        <div class="col-6">
                            <div class="text-muted small"><?= $lbl ?></div>
                            <div><?= $val ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- Edit -->
                    <div id="account-edit" style="display:none" class="row g-3">
                        <div class="col-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?= htmlspecialchars($user['first_name'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control"
                                   value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required
                                   value="<?= htmlspecialchars($user['username']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <option value="student"    <?= $user['role']==='student'    ? 'selected':'' ?>>Student</option>
                                <option value="parent"     <?= $user['role']==='parent'     ? 'selected':'' ?>>Parent</option>
                                <option value="instructor" <?= $user['role']==='instructor' ? 'selected':'' ?>>Instructor</option>
                                <option value="admin"      <?= $user['role']==='admin'      ? 'selected':'' ?>>Admin</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Linked Roster Entry -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Linked Roster Entry</div>
            <div class="card-body">
                <?php if ($user['student_id']): ?>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <div><?= htmlspecialchars($user['student_first_name'] . ' ' . $user['student_last_name']) ?></div>
                            <div class="text-muted small"><?= ucfirst($user['student_type']) ?></div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="student_edit.php?id=<?= $user['student_id'] ?>"
                               class="btn btn-sm btn-outline-primary">Edit Roster Entry</a>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Unlink this account from the roster entry?')">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="unlink">
                                <button class="btn btn-sm btn-outline-danger">Unlink</button>
                            </form>
                        </div>
                    </div>
                <?php elseif (!empty($unlinked)): ?>
                    <p class="text-muted small mb-2">This account is not linked to any roster entry.</p>
                    <form method="get" action="compare_account.php" class="d-flex gap-2 align-items-center flex-wrap">
                        <input type="hidden" name="user_id" value="<?= $id ?>">
                        <select name="student_id" class="form-select form-select-sm" style="max-width:240px" required>
                            <option value="">— select roster entry —</option>
                            <?php foreach ($unlinked as $s): ?>
                            <option value="<?= $s['id'] ?>">
                                <?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>
                                (<?= ucfirst($s['student_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Compare</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">No unlinked roster entries available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Password Reset -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Password</span>
                <button class="btn btn-sm btn-success" type="button"
                        data-bs-toggle="collapse" data-bs-target="#pwResetBox">Change</button>
            </div>
            <div class="collapse" id="pwResetBox">
                <div class="card-body border-top">
                    <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="text" name="new_password" class="form-control form-control-sm"
                               placeholder="New password (min 8 chars)" style="max-width:240px">
                        <button type="submit" class="btn btn-sm btn-warning">Set Password</button>
                        <button type="button" class="btn btn-sm btn-secondary"
                                data-bs-toggle="collapse" data-bs-target="#pwResetBox">Cancel</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Activate / Deactivate -->
    <?php if ($id !== current_user_id()): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Account Status</div>
            <div class="card-body d-flex align-items-center justify-content-between gap-3">
                <div>
                    <?= $user['active']
                        ? '<span class="badge bg-secondary me-2">Activated</span> This account can log in.'
                        : '<span class="badge bg-danger me-2">Deactivated</span> This account cannot log in.' ?>
                </div>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('<?= $user['active'] ? 'Deactivate' : 'Activate' ?> this account?')">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <button class="btn btn-sm <?= $user['active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                        <?= $user['active'] ? 'Deactivate' : 'Activate' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Account -->
    <?php if ($id !== current_user_id()): ?>
    <div class="col-12">
        <form method="post"
              onsubmit="return confirm('Delete this login account?\n\nThe student roster entry and all their history (attendance, belt tests, payments) will be kept. Only the login account will be removed.')">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete_user">
            <button class="btn btn-outline-danger">Delete Login Account</button>
        </form>
    </div>
    <?php endif; ?>

</div>

<script>
function cardToggle(cardId) {
    var btn    = document.getElementById(cardId + 'EditBtn');
    var cancel = document.getElementById(cardId + 'CancelBtn');
    var view   = document.getElementById(cardId + '-view');
    var edit   = document.getElementById(cardId + '-edit');
    if (btn.dataset.editing !== 'true') {
        btn.dataset.editing = 'true';
        btn.textContent = 'Confirm';
        btn.classList.replace('btn-success', 'btn-warning');
        if (cancel) cancel.style.display = '';
        if (view) view.style.display = 'none';
        if (edit) edit.style.display = '';
    } else {
        if (typeof setFormClean === 'function') setFormClean();
        document.getElementById(cardId + '-form').submit();
    }
}
function cardCancel(cardId) {
    var btn    = document.getElementById(cardId + 'EditBtn');
    var cancel = document.getElementById(cardId + 'CancelBtn');
    var view   = document.getElementById(cardId + '-view');
    var edit   = document.getElementById(cardId + '-edit');
    btn.dataset.editing = 'false';
    btn.textContent = 'Edit';
    btn.classList.replace('btn-warning', 'btn-success');
    if (cancel) cancel.style.display = 'none';
    if (view) view.style.display = '';
    if (edit) edit.style.display = 'none';
    var form = document.getElementById(cardId + '-form');
    if (form) form.reset();
    if (typeof setFormClean === 'function') setFormClean();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

