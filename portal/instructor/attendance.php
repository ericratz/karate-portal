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

$sort = $_GET['sort'] ?? 'last_attended';
$msg  = '';

// ── Save attendance ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_date      = $_POST['session_date']      ?? '';
    $post_new_date  = trim($_POST['session_date_edit'] ?? '');
    $post_class_type = in_array($_POST['class_type'] ?? '', ['class','seminar','private'])
                       ? $_POST['class_type'] : 'class';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        $msg = 'Invalid date.';
    } else {
        $db = db();

        // Create or retrieve the session
        $db->prepare(
            'INSERT INTO class_sessions (session_date, class_type, instructor_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id), class_type = VALUES(class_type)'
        )->execute([$post_date, $post_class_type, current_user_id()]);

        $session_id = $db->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $session_id->execute([$post_date]);
        $session_id = $session_id->fetchColumn();

        // Optionally move the session to a new date
        if ($post_new_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_new_date) && $post_new_date !== $post_date) {
            $conflict = $db->prepare('SELECT id FROM class_sessions WHERE session_date = ? AND id != ?');
            $conflict->execute([$post_new_date, $session_id]);
            if ($conflict->fetchColumn()) {
                $msg = 'Cannot change date: a class already exists on ' . date('M j, Y', strtotime($post_new_date)) . '.';
            } else {
                $db->prepare('UPDATE class_sessions SET session_date = ? WHERE id = ?')
                   ->execute([$post_new_date, $session_id]);
                $post_date = $post_new_date;
                $date      = $post_new_date;
            }
        }

        // Save only present students — delete previous records then re-insert
        $present_ids = array_map('intval', $_POST['present'] ?? []);
        $db->prepare('DELETE FROM attendance WHERE session_id = ?')->execute([$session_id]);
        $ins = $db->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by) VALUES (?,?,1,?)'
        );
        foreach ($present_ids as $sid) {
            $ins->execute([$sid, $session_id, current_user_id()]);
        }

        if (!$msg) {
            $msg  = 'Attendance saved for ' . date('M j, Y', strtotime($post_date)) . '.';
            $date = $post_date;
        }
    }
}

// ── Load session ─────────────────────────────────────────────
$session = db()->prepare('SELECT id, class_type FROM class_sessions WHERE session_date = ?');
$session->execute([$date]);
$session_row = $session->fetch();
$session_id  = $session_row ? $session_row['id'] : false;
$current_class_type = $session_row['class_type'] ?? 'class';

// ── Build student query ──────────────────────────────────────
$order_clause = $sort === 'last_name'
    ? 'ORDER BY s.last_name, s.first_name'
    : 'ORDER BY last_attended ASC, s.last_name';

$base_query = "
    SELECT s.id, s.first_name, s.last_name, s.student_type, s.injury_waiver,
           COALESCE(a.present, 0) AS present,
           (SELECT MAX(cs2.session_date)
            FROM attendance a2
            JOIN class_sessions cs2 ON cs2.id = a2.session_id
            WHERE a2.student_id = s.id AND a2.present = 1) AS last_attended
    FROM students s
    LEFT JOIN attendance a ON a.session_id = ? AND a.student_id = s.id AND a.present = 1
    $order_clause
";

$stmt = db()->prepare($base_query);
$stmt->execute([$session_id]);
$all = $stmt->fetchAll();

$instructors = array_filter($all, fn($r) => in_array($r['student_type'], ['instructor', 'admin']));
$students    = array_filter($all, fn($r) => $r['student_type'] === 'student');
$guests      = array_filter($all, fn($r) => $r['student_type'] === 'guest');
$parents     = array_filter($all, fn($r) => $r['student_type'] === 'parent');

$page_title = 'Take Attendance';
include __DIR__ . '/../includes/header.php';

function row(array $s): void { ?>
    <tr id="row-<?= $s['id'] ?>"
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
        <td class="small">
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

    <!-- Class settings row -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-1">Class Date</label>
                    <input type="date" name="session_date_edit" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($date) ?>" style="width:160px">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Class Type</label>
                    <select name="class_type" class="form-select form-select-sm" style="width:140px">
                        <option value="class"   <?= $current_class_type === 'class'   ? 'selected' : '' ?>>Class</option>
                        <option value="seminar" <?= $current_class_type === 'seminar' ? 'selected' : '' ?>>Seminar</option>
                        <option value="private" <?= $current_class_type === 'private' ? 'selected' : '' ?>>Private</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

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
                        <th>Liability Waiver</th>
                    </tr>
                </thead>
                <tbody id="instructors-body">
                    <?php foreach ($instructors as $s) row($s); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── PARENTS ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Parents <span class="badge bg-primary" id="count-parents"><?= count($parents) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($parents)): ?>
                <p class="p-3 text-muted">No parents.</p>
            <?php else: ?>
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px" class="text-center">✓</th>
                        <th>Name</th>
                        <th>Last Attended</th>
                        <th>Liability Waiver</th>
                    </tr>
                </thead>
                <tbody id="parents-body">
                    <?php foreach ($parents as $s) row($s); ?>
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
                        <th>Liability Waiver</th>
                    </tr>
                </thead>
                <tbody id="students-body">
                    <?php foreach ($students as $s) row($s); ?>
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
                        <th>Liability Waiver</th>
                    </tr>
                </thead>
                <tbody id="guests-body">
                    <?php foreach ($guests as $s) row($s); ?>
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
          onsubmit="return confirm('Delete the class for <?= date('M j, Y', strtotime($date)) ?>?\n\nThis will remove all attendance records for this day and cannot be undone.')">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete_session">
        <input type="hidden" name="session_date" value="<?= htmlspecialchars($date) ?>">
        <button type="submit" class="btn btn-outline-danger">Delete This Class</button>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleRow(id) {
    const cb = document.getElementById('cb-' + id);
    cb.checked = !cb.checked;
}

document.querySelectorAll('.presence-cb').forEach(cb => {
    cb.addEventListener('change', () => {/* no absent styling needed */});
});

// Name filter
document.getElementById('nameFilter').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#instructors-body tr, #students-body tr, #guests-body tr, #parents-body tr').forEach(row => {
        const name = row.querySelector('.row-name');
        if (name) row.style.display = name.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
    [['instructors-body','count-instructors'],['students-body','count-students'],
     ['guests-body','count-guests'],['parents-body','count-parents']].forEach(function([bodyId, badgeId]) {
        const badge = document.getElementById(badgeId);
        if (!badge) return;
        let count = 0;
        document.querySelectorAll('#' + bodyId + ' tr').forEach(r => { if (r.style.display !== 'none') count++; });
        badge.textContent = count;
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
