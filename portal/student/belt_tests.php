<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$student = db()->prepare('SELECT id, first_name, last_name FROM students WHERE user_id = ?');
$student->execute([current_user_id()]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

// All belt tests
$tests = db()->prepare(
    'SELECT bt.test_date, r.name AS rank_name, r.kyu_dan, bt.result, bt.score, bt.fee_paid, bt.belt_awarded
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     WHERE bt.student_id = ?
     ORDER BY bt.test_date DESC'
);
$tests->execute([$student['id']]);
$tests = $tests->fetchAll();

$passed  = count(array_filter($tests, fn($t) => $t['result'] === 'pass'));
$pending = count(array_filter($tests, fn($t) => $t['result'] === 'pending'));

$page_title = 'Belt Test History';
include __DIR__ . '/../includes/header.php';

function fmt_date(string $d): string {
    return date('d M Y', strtotime($d));
}
function badge_result(string $r, ?int $score): string {
    if ($score === null) return '<span class="badge bg-secondary">Pending</span>';
    $label = $score . '%';
    if ($r === 'pass') return '<span class="badge bg-success">' . $label . '</span>';
    if ($r === 'fail') return '<span class="badge bg-danger">'  . $label . '</span>';
    return '<span class="badge bg-secondary">' . $label . '</span>';
}
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Belt Test History — <?= hn($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-primary"><?= count($tests) ?></div>
                <div class="text-muted small">Total Tests</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-success"><?= $passed ?></div>
                <div class="text-muted small">Passed</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="display-6 fw-bold text-secondary"><?= $pending ?></div>
                <div class="text-muted small">Pending</div>
            </div>
        </div>
    </div>
</div>

<!-- Full list -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        All Belt Tests (<?= count($tests) ?>)
    </div>
    <div class="card-body p-0">
        <?php if (empty($tests)): ?>
            <p class="p-3 text-muted">No belt tests on record yet.</p>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Testing For</th>
                    <th>Score</th>
                    <th>Fee</th>
                    <th>Test Passed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tests as $i => $t): ?>
                <tr>
                    <td class="text-muted small"><?= count($tests) - $i ?></td>
                    <td><?= fmt_date($t['test_date']) ?></td>
                    <td><?= htmlspecialchars($t['kyu_dan']) ?></td>
                    <td><?= badge_result($t['result'], isset($t['score']) ? (int)$t['score'] : null) ?></td>
                    <td>
                        <?= $t['fee_paid']
                            ? '<span class="text-success">Paid</span>'
                            : '<span class="text-danger">Unpaid</span>' ?>
                    </td>
                    <td>
                        <?php if ($t['result'] === 'pass'): ?>
                            <span class="badge bg-success">Passed</span>
                        <?php elseif ($t['result'] === 'fail'): ?>
                            <span class="badge bg-danger">Failed</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

