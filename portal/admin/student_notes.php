<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$student_id = (int)($_GET['student_id'] ?? 0);

// No student selected — show roster with note counts
if (!$student_id) {
    $all = db()->query(
        'SELECT s.id, s.first_name, s.last_name, s.student_type, s.active, s.active_override,
                (SELECT COUNT(*) FROM student_notes sn WHERE sn.student_id = s.id) AS note_count,
                (SELECT MAX(cs.session_date)
                 FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
                 WHERE a.student_id = s.id AND a.present = 1) AS last_attended
         FROM students s
         ORDER BY s.last_name, s.first_name'
    )->fetchAll();

    $instructors = array_filter($all, fn($s) => in_array($s['student_type'], ['instructor','admin']));
    $students    = array_filter($all, fn($s) => $s['student_type'] === 'student');
    $guests      = array_filter($all, fn($s) => $s['student_type'] === 'guest');

    $page_title = 'Student Notes';
    include __DIR__ . '/../includes/header.php';

    function notes_table(array $rows, string $empty_msg): void {
        if (empty($rows)) {
            echo '<p class="p-3 text-muted mb-0">' . $empty_msg . '</p>';
            return;
        }
        echo '<table class="table table-sm table-hover mb-0" style="table-layout:fixed;width:100%">';
        echo '<colgroup>
                <col style="width:28%">
                <col style="width:22%">
                <col style="width:16%">
                <col style="width:14%">
                <col style="width:20%">
              </colgroup>';
        echo '<thead class="table-light"><tr>
                <th>Name</th><th>Last Attended</th><th>Status</th><th>Notes</th><th></th>
              </tr></thead><tbody>';
        foreach ($rows as $s) {
            $att = $s['last_attended'] ? date('M j, Y', strtotime($s['last_attended'])) : 'Never';
            echo '<tr>';
            echo '<td class="fw-semibold">' . htmlspecialchars($s['last_name'].', '.$s['first_name']) . '</td>';
            echo '<td>' . $att . '</td>';
            echo '<td>';
            echo $s['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
            if ($s['active_override'] !== null) echo ' <span class="badge bg-warning text-dark">Override</span>';
            echo '</td>';
            echo '<td>' . (int)$s['note_count'] . '</td>';
            echo '<td><a href="student_notes.php?student_id=' . $s['id'] . '" class="btn btn-sm btn-outline-primary">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0">Student Notes</h3>
        <input type="text" id="rosterSearch" class="form-control form-control-sm"
               placeholder="Search name…" style="width:200px" oninput="filterRoster(this.value)">
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Instructors <span class="badge bg-primary ms-2"><?= count($instructors) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($instructors, 'No instructors on roster.'); ?></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Students <span class="badge bg-primary ms-2"><?= count($students) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($students, 'No students on roster.'); ?></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Guests <span class="badge bg-primary ms-2"><?= count($guests) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($guests, 'No guests on roster.'); ?></div>
    </div>

    <script>
    function filterRoster(q) {
        q = q.toLowerCase().trim();
        document.querySelectorAll('tbody tr').forEach(function(row) {
            var name = row.querySelector('td');
            if (name) row.style.display = (!q || name.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
    }
    </script>

    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$student = db()->prepare('SELECT first_name, last_name FROM students WHERE id = ?');
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: student_notes.php'); exit; }

$msg = '';

// Delete note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db()->prepare('DELETE FROM student_notes WHERE id = ? AND student_id = ?')
         ->execute([(int)$_POST['id'], $student_id]);
    header("Location: student_notes.php?student_id=$student_id");
    exit;
}

// Add note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    verify_csrf();
    $content = trim($_POST['content'] ?? '');
    if ($content !== '') {
        db()->prepare(
            'INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)'
        )->execute([$student_id, $content, current_user_id()]);
        $msg = 'Note added.';
    }
}


// Load all notes newest first
$notes = db()->prepare(
    'SELECT sn.id, sn.content, sn.created_at, u.username
     FROM student_notes sn
     LEFT JOIN users u ON u.id = sn.created_by
     WHERE sn.student_id = ?
     ORDER BY sn.created_at DESC'
);
$notes->execute([$student_id]);
$notes = $notes->fetchAll();

$page_title = 'Notes — ' . $student['first_name'] . ' ' . $student['last_name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="student_notes.php" class="btn btn-outline-secondary btn-sm">← Student Notes</a>
    <h4 class="mb-0">Notes — <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="row g-4">

    <!-- Add note -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Add Note</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <textarea name="content" class="form-control" rows="5"
                                  placeholder="Enter note…" required></textarea>
                    </div>
                    <button class="btn btn-primary">Save Note</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Existing notes -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>All Notes (<?= count($notes) ?>)</span>
                <?php if (!empty($notes)): ?>
                <button id="editToggle" class="btn btn-sm btn-outline-secondary" onclick="toggleEdit()">Edit</button>
                <?php endif; ?>
            </div>
            <div id="notesContainer" class="card-body p-0" style="max-height:500px;overflow-y:auto">
                <?php if (empty($notes)): ?>
                    <p class="p-3 text-muted">No notes yet.</p>
                <?php else: ?>
                <?php foreach ($notes as $n): ?>
                <div class="border-bottom p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <span>
                            <?= date('M j, Y g:i a', strtotime($n['created_at'])) ?>
                            by <strong><?= htmlspecialchars($n['username'] ?? 'unknown') ?></strong>
                        </span>
                        <form method="post" class="d-inline delete-btn"
                              onsubmit="return confirm('Delete this note?')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 ms-2">✕</button>
                        </form>
                    </div>
                    <p class="mb-0 mt-1"><?= nl2br(htmlspecialchars($n['content'])) ?></p>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<style>
    .delete-btn { display: none !important; }
    #notesContainer.editing .delete-btn { display: inline-block !important; }
</style>
<script>
function toggleEdit() {
    const container = document.getElementById('notesContainer');
    const btn       = document.getElementById('editToggle');
    const on        = container.classList.toggle('editing');
    btn.textContent = on ? 'Done' : 'Edit';
    btn.className   = on ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
