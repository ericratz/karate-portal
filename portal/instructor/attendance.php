<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

// ── Delete session ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_session') {
    verify_csrf();
    $del_date = $_POST['session_date'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $del_date)) {
        $sid_q = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $sid_q->execute([$del_date]);
        $del_sid = $sid_q->fetchColumn();
        if ($del_sid) {
            db()->prepare('DELETE FROM attendance WHERE session_id = ?')->execute([$del_sid]);
            db()->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$del_sid]);
            audit('delete_session', 'session', $del_sid, "date=$del_date");
        }
    }
    header('Location: index.php');
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$sort = $_GET['sort'] ?? 'last_attended'; // 'last_attended' or 'last_name'
$msg  = '';

// ── Save attendance ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_date = $_POST['session_date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        $msg = 'Invalid date.';
    } else {
        $db = db();
        $db->prepare(
            'INSERT INTO class_sessions (session_date, instructor_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id)'
        )->execute([$post_date, current_user_id()]);

        $session_id = $db->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $session_id->execute([$post_date]);
        $session_id = $session_id->fetchColumn();

        $all_ids     = $db->query('SELECT id FROM students')->fetchAll(PDO::FETCH_COLUMN);
        $present_ids = array_map('intval', $_POST['present'] ?? []);

        $upsert = $db->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE present = VALUES(present), recorded_by = VALUES(recorded_by)'
        );
        foreach ($all_ids as $sid) {
            $upsert->execute([$sid, $session_id, in_array($sid, $present_ids) ? 1 : 0, current_user_id()]);
        }
        $msg  = 'Attendance saved for ' . date('M j, Y', strtotime($post_date)) . '.';
        $date = $post_date;
    }
}

// ── Load session ─────────────────────────────────────────────
$session = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
$session->execute([$date]);
$session_id = $session->fetchColumn();

// ── Build student query — sort by last_attended or last_name ─
// last_attended = date of most recent attendance record where present=1
$order_clause = $sort === 'last_name'
    ? 'ORDER BY s.last_name, s.first_name'
    : 'ORDER BY last_attended ASC, s.last_name';   // nulls (never attended) sort first

$base_query = "
    SELECT s.id, s.first_name, s.last_name, s.student_type, s.injury_waiver,
           COALESCE(a.present, 0) AS present,
           (SELECT MAX(cs2.session_date)
            FROM attendance a2
            JOIN class_sessions cs2 ON cs2.id = a2.session_id
            WHERE a2.student_id = s.id AND a2.present = 1) AS last_attended
    FROM students s
    LEFT JOIN attendance a ON a.session_id = ? AND a.student_id = s.id
    $order_clause
";

$stmt = db()->prepare($base_query);
$stmt->execute([$session_id]);
$all = $stmt->fetchAll();

$instructors = array_filter($all, fn($r) => in_array($r['student_type'], ['instructor', 'admin']));
$students    = array_filter($all, fn($r) => $r['student_type'] === 'student');
$guests      = array_filter($all, fn($r) => $r['student_type'] === 'guest');

$is_past = false;

$page_title = 'Take Attendance';
include __DIR__ . '/../includes/header.php';

function row(array $s, bool $is_past): void { ?>
    <tr id="row-<?= $s['id'] ?>"
        class="<?= ($is_past && !$s['present']) ? 'table-danger' : '' ?>"
        style="cursor:pointer"
        onclick="toggleRow(<?= $s['id'] ?>)">
        <td class="text-center">
            <input type="checkbox" class="form-check-input presence-cb"
                   name="present[]" value="<?= $s['id'] ?>"
                   id="cb-<?= $s['id'] ?>"
                   <?= $s['present'] ? 'checked' : '' ?>
                   onclick="event.stopPropagation()">
        </td>
        <td class="row-name">
            <?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name']) ?>
        </td>
        <td class="small text-muted">
            <?= $s['last_attended'] ? date('M j, Y', strtotime($s['last_attended'])) : '<em>never</em>' ?>
        </td>
        <td>
            <?php if ($s['injury_waiver']): ?>
                <span class="text-success">✓</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark">⚠ No waiver</span>
            <?php endif; ?>
        </td>
    </tr>
<?php }
?>

<div class="d-flex align-items-center gap-3 mb-3">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">← Back</a>
    <h4 class="mb-0">Attendance — <?= date('l, F j, Y', strtotime($date)) ?></h4>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Sort toggle -->
<div class="d-flex gap-2 mb-3 align-items-center">
    <span class="text-muted small">Sort by:</span>
    <a href="?date=<?= $date ?>&sort=last_attended"
       class="btn btn-sm btn-filter <?= $sort === 'last_attended' ? 'active' : '' ?>">
        Last Attended
    </a>
    <a href="?date=<?= $date ?>&sort=last_name"
       class="btn btn-sm btn-filter <?= $sort === 'last_name' ? 'active' : '' ?>">
        Last Name
    </a>

    <input type="text" id="nameFilter" class="form-control form-control-sm ms-3"
           style="max-width:200px" placeholder="Filter by name…">
</div>

<form method="post" action="attendance.php" id="att-form">
    <?= csrf_input() ?>
    <input type="hidden" name="session_date" value="<?= htmlspecialchars($date) ?>">

    <!-- ── INSTRUCTORS ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Instructors <span class="badge bg-primary" id="count-instructors"><?= count($instructors) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($instructors)): ?>
                <p class="p-3 text-muted">No instructors.</p>
            <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px" class="text-center">✓</th>
                        <th>Name</th>
                        <th>Last Attended</th>
                        <th>Injury Waiver</th>
                    </tr>
                </thead>
                <tbody id="instructors-body">
                    <?php foreach ($instructors as $s) row($s, $is_past); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── STUDENTS ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Students <span class="badge bg-primary" id="count-students"><?= count($students) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($students)): ?>
                <p class="p-3 text-muted">No registered students.</p>
            <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px" class="text-center">✓</th>
                        <th>Name</th>
                        <th>Last Attended</th>
                        <th>Injury Waiver</th>
                    </tr>
                </thead>
                <tbody id="students-body">
                    <?php foreach ($students as $s) row($s, $is_past); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── GUESTS ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Guests <span class="badge bg-primary" id="count-guests"><?= count($guests) ?></span>
            <small class="text-muted fw-normal ms-2">(registration fee not yet paid)</small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($guests)): ?>
                <p class="p-3 text-muted">No guests.</p>
            <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px" class="text-center">✓</th>
                        <th>Name</th>
                        <th>Last Attended</th>
                        <th>Injury Waiver</th>
                    </tr>
                </thead>
                <tbody id="guests-body">
                    <?php foreach ($guests as $s) row($s, $is_past); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</form>

<div class="d-flex justify-content-between align-items-center mt-2">
    <button type="submit" form="att-form" class="btn btn-primary px-4">Save Attendance</button>
    <?php if ($session_id): ?>
    <form method="post" action="attendance.php"
          onsubmit="return confirm('Delete the session for <?= date('M j, Y', strtotime($date)) ?>?\n\nThis will remove all attendance records for this day and cannot be undone.')">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete_session">
        <input type="hidden" name="session_date" value="<?= htmlspecialchars($date) ?>">
        <button type="submit" class="btn btn-outline-danger">Delete This Session</button>
    </form>
    <?php endif; ?>
</div>

<script>
const IS_PAST = <?= $is_past ? 'true' : 'false' ?>;

function toggleRow(id) {
    const cb  = document.getElementById('cb-' + id);
    const row = document.getElementById('row-' + id);
    cb.checked = !cb.checked;
    row.classList.toggle('table-danger', IS_PAST && !cb.checked);
}

document.querySelectorAll('.presence-cb').forEach(cb => {
    cb.addEventListener('change', () => {
        document.getElementById('row-' + cb.value).classList.toggle('table-danger', IS_PAST && !cb.checked);
    });
});

// Name filter — searches all lists simultaneously and updates counts
document.getElementById('nameFilter').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#instructors-body tr, #students-body tr, #guests-body tr').forEach(row => {
        const name = row.querySelector('.row-name');
        if (name) row.style.display = name.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
    [['instructors-body','count-instructors'],['students-body','count-students'],['guests-body','count-guests']].forEach(function([bodyId, badgeId]) {
        const badge = document.getElementById(badgeId);
        if (!badge) return;
        let count = 0;
        document.querySelectorAll('#' + bodyId + ' tr').forEach(r => { if (r.style.display !== 'none') count++; });
        badge.textContent = count;
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
