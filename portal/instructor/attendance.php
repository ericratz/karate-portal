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

$date      = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$highlight = (int)($_GET['highlight'] ?? 0);

$sort = in_array($_GET['sort'] ?? '', ['last_name','last_attended']) ? $_GET['sort'] : 'first_name';
$msg  = '';

// ── Save attendance ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_date       = $_POST['session_date'] ?? '';
    $post_class_type = in_array($_POST['class_type'] ?? '', ['class','seminar','private'])
                       ? $_POST['class_type'] : 'class';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post_date)) {
        $msg = 'Invalid date.';
    } else {
        $db = db();

        // Create or retrieve the session for this date
        $db->prepare(
            'INSERT INTO class_sessions (session_date, class_type, instructor_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE instructor_id = VALUES(instructor_id), class_type = VALUES(class_type)'
        )->execute([$post_date, $post_class_type, current_user_id()]);

        $session_id = $db->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
        $session_id->execute([$post_date]);
        $session_id = $session_id->fetchColumn();

        // Save only present students — delete previous records then re-insert
        $present_ids = array_map('intval', $_POST['present'] ?? []);
        $db->prepare('DELETE FROM attendance WHERE session_id = ?')->execute([$session_id]);
        $ins = $db->prepare(
            'INSERT INTO attendance (student_id, session_id, present, recorded_by) VALUES (?,?,1,?)'
        );
        foreach ($present_ids as $sid) {
            $ins->execute([$sid, $session_id, current_user_id()]);
        }

        if (empty($present_ids)) {
            // No one present — an empty class isn't worth keeping a record of
            $db->prepare('DELETE FROM class_sessions WHERE id = ?')->execute([$session_id]);
            header('Location: attendance.php?' . http_build_query(['date' => $post_date, 'sort' => $sort, 'removed' => 1]));
            exit;
        }

        header('Location: attendance.php?' . http_build_query(['date' => $post_date, 'sort' => $sort, 'saved' => count($present_ids)]));
        exit;
    }
}

if (isset($_GET['saved'])) {
    $msg = 'Attendance saved for ' . date('d M Y', strtotime($date)) . ' — ' . (int)$_GET['saved'] . ' present.';
} elseif (isset($_GET['removed'])) {
    $msg = 'No students were marked present, so the class for ' . date('d M Y', strtotime($date)) . ' was removed.';
}

// ── Load session ─────────────────────────────────────────────
$session = db()->prepare('SELECT id, class_type FROM class_sessions WHERE session_date = ?');
$session->execute([$date]);
$session_row = $session->fetch();
$session_id  = $session_row ? $session_row['id'] : false;
$current_class_type = $session_row['class_type'] ?? 'class';

// ── Build student query ──────────────────────────────────────
if ($sort === 'last_name') {
    $order_clause = 'ORDER BY s.last_name, s.first_name';
} elseif ($sort === 'last_attended') {
    $order_clause = 'ORDER BY last_attended ASC, s.first_name, s.last_name';
} else {
    $order_clause = 'ORDER BY s.first_name, s.last_name';
}

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

function row(array $s): void {
    global $sort;
    ?><tr id="row-<?= $s['id'] ?>" class="att-row" data-id="<?= $s['id'] ?>"
        style="cursor:pointer">
        <td class="text-center">
            <input type="checkbox" class="form-check-input presence-cb"
                   name="present[]" value="<?= $s['id'] ?>"
                   id="cb-<?= $s['id'] ?>"
                   <?= $s['present'] ? 'checked' : '' ?>>
        </td>
        <td class="row-name">
            <?= $sort === 'last_name'
                ? hn($s['last_name']) . ', ' . hn($s['first_name'])
                : hn($s['first_name'] . ' ' . $s['last_name']) ?>
        </td>
        <td class="small">
            <?= $s['last_attended'] ? date('d M Y', strtotime($s['last_attended'])) : '<em>never</em>' ?>
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
    <h4 class="mb-0" id="att-heading">Attendance — <?= date('l, j F Y', strtotime($date)) ?></h4>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Sort toggle -->
<div class="d-flex gap-2 mb-3 align-items-center">
    <span class="text-muted small">Sort by:</span>
    <a href="?date=<?= $date ?>&sort=first_name"
       class="btn btn-sm btn-filter <?= $sort === 'first_name' ? 'active' : '' ?>">
        First Name
    </a>
    <a href="?date=<?= $date ?>&sort=last_name"
       class="btn btn-sm btn-filter <?= $sort === 'last_name' ? 'active' : '' ?>">
        Last Name
    </a>
    <a href="?date=<?= $date ?>&sort=last_attended"
       class="btn btn-sm btn-filter <?= $sort === 'last_attended' ? 'active' : '' ?>">
        Last Attended
    </a>

    <input type="text" id="nameFilter" class="form-control form-control-sm ms-3"
           style="max-width:200px" placeholder="Filter by name…">
</div>

<form method="post" action="attendance.php?date=<?= htmlspecialchars($date) ?>" id="att-form">
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
    <div class="card border-0 shadow-sm mb-4" id="card-instructors">
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
                        <th>Waiver</th>
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
    <div class="card border-0 shadow-sm mb-4" id="card-parents">
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
                        <th>Waiver</th>
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
    <div class="card border-0 shadow-sm mb-4" id="card-students">
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
                        <th>Waiver</th>
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
    <div class="card border-0 shadow-sm mb-4" id="card-guests">
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
                        <th>Waiver</th>
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
    <form method="post" action="attendance.php" id="deleteSessionForm"
          data-confirm="Delete the class for <?= date('d M Y', strtotime($date)) ?>?&#10;&#10;This will remove all attendance records for this day and cannot be undone.">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete_session">
        <input type="hidden" name="session_date" value="<?= htmlspecialchars($date) ?>">
        <button type="submit" class="btn btn-outline-danger">Delete This Class</button>
    </form>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
function toggleRow(id) {
    const cb = document.getElementById('cb-' + id);
    cb.checked = !cb.checked;
}

document.querySelectorAll('.presence-cb').forEach(cb => {
    cb.addEventListener('change', () => {/* no absent styling needed */});
});

document.querySelectorAll('.att-row').forEach(function(row) {
    row.addEventListener('click', function(e) {
        if (e.target.closest('.presence-cb')) return;
        toggleRow(row.dataset.id);
    });
});

var deleteSessionForm = document.getElementById('deleteSessionForm');
if (deleteSessionForm) {
    deleteSessionForm.addEventListener('submit', function(e) {
        if (!confirm(deleteSessionForm.dataset.confirm)) e.preventDefault();
    });
}

// Navigate to selected date when date field changes
(function () {
    const input = document.querySelector('input[name="session_date_edit"]');
    if (input) {
        input.addEventListener('change', function () {
            if (this.value) {
                const params = new URLSearchParams(window.location.search);
                params.set('date', this.value);
                params.delete('highlight');
                window.location.href = 'attendance.php?' + params.toString();
            }
        });
    }
})();

// Auto-highlight a student arriving from their profile page
(function () {
    const hlId = <?= $highlight ?: 'null' ?>;
    if (!hlId) return;
    const cb  = document.getElementById('cb-'  + hlId);
    const row = document.getElementById('row-' + hlId);
    if (!cb || !row) return;
    cb.checked = true;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.style.transition = 'background 0.3s';
    row.style.background = 'var(--bs-warning-bg-subtle, #fff3cd)';
    setTimeout(() => row.style.background = '', 2500);
})();

// Name filter
document.getElementById('nameFilter').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#instructors-body tr, #students-body tr, #guests-body tr, #parents-body tr').forEach(row => {
        const name = row.querySelector('.row-name');
        if (name) row.style.display = name.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
    [['instructors-body','count-instructors','card-instructors'],
     ['students-body','count-students','card-students'],
     ['guests-body','count-guests','card-guests'],
     ['parents-body','count-parents','card-parents']].forEach(function([bodyId, badgeId, cardId]) {
        const body  = document.getElementById(bodyId);
        if (!body) return; // section had no members at all — leave its "No X." message alone
        const badge = document.getElementById(badgeId);
        const card  = document.getElementById(cardId);
        let count = 0;
        body.querySelectorAll('tr').forEach(r => { if (r.style.display !== 'none') count++; });
        if (badge) badge.textContent = count;
        if (card)  card.style.display = count === 0 ? 'none' : '';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

