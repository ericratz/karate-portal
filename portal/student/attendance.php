<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$student = db()->prepare('SELECT id, first_name, last_name FROM students WHERE user_id = ?');
$student->execute([current_user_id()]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

// All sessions the student attended
$attended = db()->prepare(
    'SELECT cs.session_date
     FROM attendance a
     JOIN class_sessions cs ON cs.id = a.session_id
     WHERE a.student_id = ? AND a.present = 1
     ORDER BY cs.session_date DESC'
);
$attended->execute([$student['id']]);
$attended = $attended->fetchAll();

// Summary counts — total_sessions is all class sessions ever held,
// not just ones this student was marked for
$summary = db()->prepare(
    'SELECT
        COUNT(DISTINCT cs.id) AS total_sessions,
        COALESCE(SUM(a.present), 0) AS total_attended
     FROM class_sessions cs
     LEFT JOIN attendance a ON a.session_id = cs.id AND a.student_id = ?'
);
$summary->execute([$student['id']]);
$summary = $summary->fetch();

$page_title = 'Attendance History';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Attendance History — <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-primary"><?= (int)$summary['total_attended'] ?></div>
                <div class="text-muted small">Classes Attended</div>
            </div>
        </div>
    </div>
</div>

<!-- Full list -->
<div class="card border-0 shadow-sm" style="max-width:400px">
    <div class="card-header bg-white fw-semibold">
        All Attended Dates (<?= count($attended) ?>)
    </div>
    <div class="card-body p-0">
        <?php if (empty($attended)): ?>
            <p class="p-3 text-muted">No attendance on record yet.</p>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Date</th></tr>
            </thead>
            <tbody>
            <?php foreach ($attended as $i => $row): ?>
                <tr>
                    <td class="text-muted small"><?= count($attended) - $i ?></td>
                    <td><?= date('l, M j, Y', strtotime($row['session_date'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
