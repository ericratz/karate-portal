<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$lr_id = (int)($_GET['lr_id'] ?? $_POST['lr_id'] ?? 0);
if (!$lr_id) {
    header('Location: ./');
    exit;
}

// Load the link request (must be needs_linking and unresolved)
$lr_stmt = db()->prepare(
    'SELECT lr.id, lr.created_at, lr.student_id,
            u.id AS user_id, u.username, u.is_admin,
            u.first_name AS u_first, u.last_name AS u_last,
            u.email AS u_email, u.date_of_birth AS u_dob,
            s.id AS dup_id, s.first_name AS s_first, s.last_name AS s_last,
            s.date_of_birth AS s_dob, s.email AS s_email, s.student_type AS s_type
     FROM link_requests lr
     JOIN users u ON u.id = lr.user_id
     LEFT JOIN students s ON s.id = lr.student_id
     WHERE lr.id = ? AND lr.request_type = \'needs_linking\' AND lr.resolved = 0
     LIMIT 1'
);
$lr_stmt->execute([$lr_id]);
$lr = $lr_stmt->fetch();

if (!$lr) {
    header('Location: ./?error=not_found');
    exit;
}

$error = '';

// ── POST: link user to selected real student ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $real_student_id = (int)($_POST['real_student_id'] ?? 0);

    if (!$real_student_id) {
        $error = 'Please select a student record to link to.';
    } else {
        try {
            db()->beginTransaction();

            // Verify real student record exists and is still unlinked (or already linked to this user)
            $rs_stmt = db()->prepare(
                'SELECT id, student_type FROM students WHERE id = ? AND (user_id IS NULL OR user_id = ?)'
            );
            $rs_stmt->execute([$real_student_id, $lr['user_id']]);
            $real = $rs_stmt->fetch();
            if (!$real) {
                throw new Exception('That student record is no longer available — it may have already been linked.');
            }

            // Link user to the real student
            db()->prepare('UPDATE students SET user_id = ? WHERE id = ?')
                 ->execute([$lr['user_id'], $real_student_id]);

            // Delete the auto-created duplicate guest record (if different from real)
            if ($lr['dup_id'] && $lr['dup_id'] !== $real_student_id) {
                db()->prepare('DELETE FROM students WHERE id = ? AND student_type = \'guest\'')
                     ->execute([$lr['dup_id']]);
            }

            // Mark alert resolved
            db()->prepare(
                'UPDATE link_requests SET resolved = 1, resolved_at = NOW(), resolved_by = ? WHERE id = ?'
            )->execute([$_SESSION['user_id'], $lr_id]);

            db()->commit();
            audit('resolve_link', 'link_requests', $lr_id,
                  "user={$lr['user_id']} linked_to=student:$real_student_id dup_deleted={$lr['dup_id']}");

            header('Location: ./?linked=1');
            exit;

        } catch (Exception $e) {
            db()->rollBack();
            log_event('error', 'system', 'Link resolution failed', ['message' => $e->getMessage()]);
            $error = $e->getMessage();
        }
    }
}

// Load candidate student records (unlinked, excluding the duplicate)
$candidates_stmt = db()->prepare(
    'SELECT s.id, s.first_name, s.last_name, s.date_of_birth, s.email, s.city_state_zip, s.student_type,
            (SELECT r.name FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
             WHERE sr.student_id = s.id ORDER BY r.rank_order DESC LIMIT 1) AS rank_name
     FROM students s
     WHERE s.user_id IS NULL AND s.id != ?
     ORDER BY s.first_name, s.last_name'
);
$candidates_stmt->execute([$lr['dup_id'] ?? 0]);
$candidates = $candidates_stmt->fetchAll();

$page_title = 'Resolve Linking';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="./" class="btn btn-sm btn-outline-secondary">&larr; Dashboard</a>
    <h4 class="mb-0">Resolve Linking</h4>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- Left: user & auto-created record info -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">New Account</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Username</dt>
                    <dd class="col-7"><?= htmlspecialchars($lr['username']) ?></dd>
                    <dt class="col-5 text-muted">Name</dt>
                    <dd class="col-7"><?= htmlspecialchars(trim($lr['u_first'].' '.$lr['u_last'])) ?></dd>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7"><?= htmlspecialchars($lr['u_email']) ?></dd>
                    <?php if ($lr['u_dob']): ?>
                    <dt class="col-5 text-muted">Date of Birth</dt>
                    <dd class="col-7"><?= date('d M Y', strtotime($lr['u_dob'])) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Registered</dt>
                    <dd class="col-7"><?= date('d M Y', strtotime($lr['created_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($lr['dup_id']): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                Auto-Created Record
                <span class="badge bg-secondary">Will be deleted</span>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Name</dt>
                    <dd class="col-7"><?= htmlspecialchars(trim($lr['s_first'].' '.$lr['s_last'])) ?></dd>
                    <?php if ($lr['s_dob']): ?>
                    <dt class="col-5 text-muted">Date of Birth</dt>
                    <dd class="col-7"><?= date('d M Y', strtotime($lr['s_dob'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($lr['s_email']): ?>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7"><?= htmlspecialchars($lr['s_email']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7"><span class="badge bg-secondary"><?= htmlspecialchars($lr['s_type'] ?? '—') ?></span></dd>
                </dl>
                <div class="alert alert-warning py-1 small mt-2 mb-0">
                    This temporary record will be deleted once you link the user to their real record.
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="alert alert-info small mt-3">
            <strong>What happens:</strong> The user's login gets linked to the selected real student record.
            Their temporary auto-created record is deleted. Their account type is determined by the linked student record.
        </div>
    </div>

    <!-- Right: student picker -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Select the Real Student Record</span>
                <span class="text-muted small"><?= count($candidates) ?> unlinked record<?= count($candidates) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($candidates)): ?>
                    <p class="text-muted mb-0">No unlinked student records found.</p>
                <?php else: ?>

                    <input type="text" id="candidateSearch" class="form-control form-control-sm mb-3"
                           placeholder="Search by name or email…" oninput="filterCandidates(this.value)">

                    <form method="post" id="resolveForm">
                        <?= csrf_input() ?>
                        <input type="hidden" name="lr_id"           value="<?= $lr_id ?>">
                        <input type="hidden" name="real_student_id" id="realStudentId" value="">

                        <div class="table-responsive" style="max-height:420px;overflow-y:auto">
                        <table class="table table-sm table-hover mb-0" id="candidateTable">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th></th>
                                    <th>Name</th>
                                    <th>DOB</th>
                                    <th>Belt</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($candidates as $c): ?>
                            <tr class="candidate-row" data-search="<?= strtolower(htmlspecialchars($c['first_name'].' '.$c['last_name'].' '.($c['email'] ?? ''))) ?>">
                                <td>
                                    <input type="radio" name="radio_pick" value="<?= $c['id'] ?>"
                                           onchange="document.getElementById('realStudentId').value=this.value;
                                                     document.getElementById('confirmBtn').disabled=false;">
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></div>
                                    <?php if ($c['email']): ?><div class="text-muted small"><?= htmlspecialchars($c['email']) ?></div><?php endif; ?>
                                </td>
                                <td class="small text-nowrap">
                                    <?= $c['date_of_birth'] ? date('d M Y', strtotime($c['date_of_birth'])) : '—' ?>
                                </td>
                                <td class="small"><?= $c['rank_name'] ? htmlspecialchars($c['rank_name']) : '—' ?></td>
                                <td><span class="badge bg-secondary" style="font-size:.7rem"><?= htmlspecialchars($c['student_type']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <button type="submit" id="confirmBtn" class="btn btn-warning w-100 mt-3" disabled>
                            Link Account to Selected Record
                        </button>
                    </form>

                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
function filterCandidates(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.candidate-row').forEach(row => {
        row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
