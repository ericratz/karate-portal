<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auto_inactive.php';
require_role('instructor', 'admin');
apply_auto_inactive();

// Recent sessions
$recent_sessions = db()->query(
    'SELECT id, session_date FROM class_sessions ORDER BY session_date DESC LIMIT 10'
)->fetchAll();

// Recent belt tests — fetch 11 to detect overflow
$belt_tests = db()->query(
    'SELECT bt.id, bt.test_date, bt.result, bt.fee_paid, bt.belt_awarded,
            s.id AS student_id, s.first_name, s.last_name,
            r.kyu_dan
     FROM belt_tests bt
     JOIN students s ON s.id = bt.student_id
     JOIN ranks r    ON r.id = bt.rank_testing_for
     ORDER BY bt.test_date DESC, s.last_name
     LIMIT 11'
)->fetchAll();
$has_more_tests = count($belt_tests) === 11;
if ($has_more_tests) array_pop($belt_tests);

$page_title = 'Instructor Dashboard';
include __DIR__ . '/../includes/header.php';
?>


<div class="row g-4">

    <!-- ── Left: Attendance ── -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Take Attendance</div>
            <div class="card-body">
                <form method="get" action="attendance.php">
                    <div class="mb-3">
                        <label class="form-label">Class Date</label>
                        <input type="date" name="date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <button class="btn btn-primary w-100">Record New Session</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Recent Sessions</span>
                <a href="attendance_sessions.php" class="btn btn-sm btn-outline-secondary">View by Session</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_sessions)): ?>
                    <p class="p-3 text-muted">No sessions recorded yet.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Date</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_sessions as $s): ?>
                        <tr>
                            <td>
                                <a href="attendance.php?date=<?= $s['session_date'] ?>" class="text-decoration-none">
                                    <?= date('D, M j, Y', strtotime($s['session_date'])) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Right: Students + Belt Tests ── -->
    <div class="col-md-6 d-flex flex-column gap-4">

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Students</div>
            <div class="card-body">
                <a href="students.php" class="btn btn-primary w-100">View Student Roster</a>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Recent Belt Tests</span>
                <a href="belt_tests_all.php" class="btn btn-sm btn-outline-secondary">View Tests</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($belt_tests)): ?>
                    <p class="p-3 text-muted">No belt tests on record.</p>
                <?php else: ?>
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Testing For</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($belt_tests as $t): ?>
                        <tr>
                            <td class="text-nowrap"><?= date('M j, Y', strtotime($t['test_date'])) ?></td>
                            <td>
                                <a href="student_profile.php?id=<?= $t['student_id'] ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($t['last_name'].', '.$t['first_name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($t['kyu_dan']) ?></td>
                            <td>
                                <?php if ($t['result']==='pass'): ?>
                                    <span class="badge bg-success">Pass</span>
                                <?php elseif ($t['result']==='fail'): ?>
                                    <span class="badge bg-danger">Fail</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
