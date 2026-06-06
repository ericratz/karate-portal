<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$student_id = (int)($_GET['student_id'] ?? 0);
if (!$student_id) { header('Location: students.php'); exit; }

$student = db()->prepare('SELECT * FROM students WHERE id = ?');
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: students.php'); exit; }

$submission = db()->prepare(
    'SELECT * FROM injury_waiver_submissions WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 1'
);
$submission->execute([$student_id]);
$submission = $submission->fetch();

$page_title = 'Waiver — ' . $student['first_name'] . ' ' . $student['last_name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="../instructor/student_profile.php?id=<?= $student_id ?>"
       class="btn btn-outline-secondary btn-sm">← Profile</a>
    <h4 class="mb-0">Injury Waiver — <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<?php if (!$submission && !$student['injury_waiver']): ?>
    <div class="alert alert-warning">No waiver on file for this student.</div>
<?php elseif (!$submission && $student['injury_waiver']): ?>
    <div class="alert alert-info">
        Waiver recorded manually
        <?php if ($student['injury_waiver_date']): ?>
            on <strong><?= date('M j, Y', strtotime($student['injury_waiver_date'])) ?></strong>
        <?php endif; ?>
        — no digital submission on file.
    </div>
<?php else: ?>
<div class="card border-0 shadow-sm mb-4" style="max-width:700px">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span>Signed Waiver</span>
        <span class="text-muted small">Submitted: <?= htmlspecialchars($submission['submitted_at']) ?></span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Printed Name</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['print_name']) ?></dd>

            <dt class="col-sm-4">Signature</dt>
            <dd class="col-sm-8 fst-italic fs-5" style="font-family:cursive">
                <?= htmlspecialchars($submission['signature']) ?>
            </dd>

            <dt class="col-sm-4">Signed Date</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['signed_date']) ?></dd>

            <?php if ($submission['guardian_signature']): ?>
            <dt class="col-sm-4">Guardian Signature</dt>
            <dd class="col-sm-8 fst-italic" style="font-family:cursive">
                <?= htmlspecialchars($submission['guardian_signature']) ?>
            </dd>
            <dt class="col-sm-4">Guardian Signed Date</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['guardian_signed_date'] ?? '—') ?></dd>
            <?php endif; ?>

            <dt class="col-sm-4">Date of Birth</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['date_of_birth'] ?? '—') ?></dd>

            <dt class="col-sm-4">Cell Phone</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['cell_phone']) ?></dd>

            <?php if ($submission['home_phone']): ?>
            <dt class="col-sm-4">Home Phone</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['home_phone']) ?></dd>
            <?php endif; ?>

            <dt class="col-sm-4">Email</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['email']) ?></dd>

            <dt class="col-sm-4">Street Address</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['street_address']) ?></dd>

            <dt class="col-sm-4">City, State, ZIP</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['city_state_zip']) ?></dd>

            <?php if ($submission['mailing_address']): ?>
            <dt class="col-sm-4">Mailing Address</dt>
            <dd class="col-sm-8">
                <?= htmlspecialchars($submission['mailing_address']) ?><br>
                <?= htmlspecialchars($submission['mailing_city_state_zip'] ?? '') ?>
            </dd>
            <?php endif; ?>

            <dt class="col-sm-4">IP Address</dt>
            <dd class="col-sm-8 text-muted small"><?= htmlspecialchars($submission['ip_address'] ?? '—') ?></dd>
        </dl>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
