<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$student_id = get_int('student_id');

// No student selected — combined Class Notes page:
// roster of students with notes + the general class-notes log
if (!$student_id) {
    $msg = '';

    // Class notes — delete entry
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        verify_csrf();
        db()->prepare('DELETE FROM general_notes WHERE id = ?')->execute([post_int('id')]);
        if (empty($_SERVER['HTTP_HX_REQUEST'])) {
            header('Location: student_notes.php');
            exit;
        }
        // For htmx requests, fall through so hx-select can pull the live count.
    }

    // Class notes — edit entry
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'edit') {
        verify_csrf();
        $note_id = post_int('id');
        $content = trim(post_str('content'));
        if ($note_id && $content !== '') {
            db()->prepare('UPDATE general_notes SET content=?, updated_at=NOW() WHERE id=?')
                 ->execute([$content, $note_id]);
            if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
                $n = db()->prepare('SELECT gn.id, gn.content, gn.created_at, u.username
                                    FROM general_notes gn LEFT JOIN users u ON u.id = gn.created_by
                                    WHERE gn.id = ?');
                $n->execute([$note_id]);
                $n = $n->fetch();
                if ($n) { ?>
<div class="note-entry border-bottom p-3" data-id="<?= $n['id'] ?>">
    <div class="d-flex justify-content-between align-items-start mb-1">
        <span>
            <strong><?= date('D d M Y', strtotime($n['created_at'])) ?></strong>
            <?= date('g:i a', strtotime($n['created_at'])) ?>
            — <?= htmlspecialchars($n['username'] ?? 'unknown') ?>
        </span>
        <div class="d-flex gap-1 flex-shrink-0 ms-2">
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 note-edit-btn">Edit</button>
            <form method="post" class="d-inline delete-btn"
                  hx-post="student_notes.php" hx-target="#general-notes-card" hx-select="#general-notes-card"
                  hx-swap="outerHTML swap:300ms" hx-confirm="Delete this entry?">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $n['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
            </form>
        </div>
    </div>
    <div class="note-content">
        <?= nl2br(htmlspecialchars($n['content'])) ?>
    </div>
    <form method="post" class="note-edit-form mt-2" style="display:none"
          hx-post="student_notes.php" hx-target="closest .note-entry" hx-swap="outerHTML">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $n['id'] ?>">
        <textarea name="content" class="form-control form-control-sm mb-2"
                  rows="4" required><?= htmlspecialchars($n['content']) ?></textarea>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary">Save</button>
            <button type="button" class="btn btn-sm btn-secondary note-cancel-btn">Cancel</button>
        </div>
    </form>
</div>
<?php           }
                exit;
            }
        }
        header('Location: student_notes.php');
        exit;
    }

    // Class notes — add entry
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add') {
        verify_csrf();
        $content = trim(post_str('content'));
        if ($content !== '') {
            db()->prepare(
                'INSERT INTO general_notes (content, created_by) VALUES (?,?)'
            )->execute([$content, current_user_id()]);
            if (empty($_SERVER['HTTP_HX_REQUEST'])) {
                header('Location: student_notes.php?saved=1');
                exit;
            }
            // htmx fall-through re-renders the page; hx-select pulls the log.
        }
    }
    if (isset($_GET['saved'])) $msg = 'Note saved.';

    // Class notes log — oldest first
    $class_notes = db()->query(
        'SELECT gn.id, gn.content, gn.created_at, gn.updated_at, u.username
         FROM general_notes gn
         LEFT JOIN users u ON u.id = gn.created_by
         ORDER BY gn.created_at ASC'
    )->fetchAll();

    $all = db()->query(
        'SELECT s.id, s.first_name, s.last_name, s.student_type, s.active, s.active_override,
                (SELECT COUNT(*) FROM student_notes sn WHERE sn.student_id = s.id) AS note_count,
                (SELECT MAX(cs.session_date)
                 FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
                 WHERE a.student_id = s.id AND a.present = 1) AS last_attended
         FROM students s
         ORDER BY s.first_name, s.last_name'
    )->fetchAll();

    // Only students who actually have notes appear in the tables
    $all = array_filter($all, fn($s) => (int)$s['note_count'] > 0);

    $instructors = array_filter($all, fn($s) => in_array($s['student_type'], ['instructor','admin']));
    $students    = array_filter($all, fn($s) => $s['student_type'] === 'student');
    $guests      = array_filter($all, fn($s) => $s['student_type'] === 'guest');
    $parents     = array_filter($all, fn($s) => $s['student_type'] === 'parent');

    $page_title = 'Class Notes';
    include __DIR__ . '/../includes/header.php';

    function notes_table(array $rows): void {
        if (empty($rows)) return;
        // min-width keeps the fixed percentage columns readable on phones —
        // the .table-responsive wrapper scrolls instead of squeezing headers
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-hover mb-0" style="table-layout:fixed;width:100%;min-width:560px">';
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
            $att = $s['last_attended'] ? date('d M Y', strtotime($s['last_attended'])) : 'Never';
            $search_name = strtolower($s['last_name'].' '.$s['first_name'].' '.$s['first_name'].' '.$s['last_name']);
            echo '<tr data-name="' . htmlspecialchars($search_name) . '">';
            echo '<td class="fw-semibold"><a href="student_notes.php?student_id=' . $s['id'] . '" class="text-decoration-none">' . hn($s['first_name'].' '.$s['last_name']) . '</a></td>';
            echo '<td>' . $att . '</td>';
            echo '<td>';
            echo $s['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
            if ($s['active_override'] !== null) echo ' <span class="badge bg-warning text-dark">Override</span>';
            echo '</td>';
            echo '<td>' . (int)$s['note_count'] . '</td>';
            echo '<td>';
            if ((int)$s['note_count'] > 0) {
                echo '<a href="student_notes.php?student_id=' . $s['id'] . '" class="btn btn-sm btn-outline-primary">View</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
    ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <h3 class="mb-0">Class Notes</h3>
        <input type="text" id="rosterSearch" class="form-control form-control-sm"
               placeholder="Search name…" style="width:200px">
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <?php if (!empty($instructors)): ?>
    <div class="card border-0 shadow-sm mb-4" id="card-instructors">
        <div class="card-header bg-white fw-semibold">
            Instructors <span class="badge bg-primary ms-2" id="count-instructors"><?= count($instructors) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($instructors); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($parents)): ?>
    <div class="card border-0 shadow-sm mb-4" id="card-parents">
        <div class="card-header bg-white fw-semibold">
            Parents <span class="badge bg-primary ms-2" id="count-parents"><?= count($parents) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($parents); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($students)): ?>
    <div class="card border-0 shadow-sm mb-4" id="card-students">
        <div class="card-header bg-white fw-semibold">
            Students <span class="badge bg-primary ms-2" id="count-students"><?= count($students) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($students); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($guests)): ?>
    <div class="card border-0 shadow-sm mb-4" id="card-guests">
        <div class="card-header bg-white fw-semibold">
            Guests <span class="badge bg-primary ms-2" id="count-guests"><?= count($guests) ?></span>
        </div>
        <div class="card-body p-0"><?php notes_table($guests); ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($all)): ?>
    <p class="text-muted">No student notes yet.</p>
    <?php endif; ?>

    <!-- ── General class notes ── -->
    <div class="d-flex align-items-center justify-content-between mb-3 mt-5">
        <h5 class="mb-0">General Class Notes</h5>
        <button class="btn btn-success btn-sm" data-bs-toggle="collapse" data-bs-target="#addEntryBox">
            + Add Entry
        </button>
    </div>

    <div class="collapse mb-4" id="addEntryBox">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post"
                      hx-post="student_notes.php"
                      hx-target="#general-notes-card"
                      hx-select="#general-notes-card"
                      hx-swap="outerHTML"
                      hx-on::after-request="if(event.detail.successful)this.reset()"
                      hx-on::after-settle="const c=document.getElementById('notesContainer');if(c)c.scrollTop=c.scrollHeight">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <textarea name="content" class="form-control" rows="4"
                                  placeholder="Class notes, reminders, announcements…"
                                  required></textarea>
                    </div>
                    <input type="hidden" name="action" value="add">
                    <button class="btn btn-primary">Save Entry</button>
                </form>
            </div>
        </div>
    </div>

    <div id="general-notes-card" class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Notes Log (<?= count($class_notes) ?> entries)</span>
            <div class="d-flex gap-2 align-items-center">
                <input type="text" id="noteSearch" class="form-control form-control-sm"
                       style="width:200px" placeholder="Search notes… (Ctrl+F)">
                <span id="matchCount" class="text-muted small"></span>
                <?php if (!empty($class_notes)): ?>
                <button id="editToggle" class="btn btn-sm btn-success">Edit</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Scrollable notes window -->
        <div id="notesContainer" class="card-body p-0" style="max-height:60vh; overflow-y:auto">
            <?php if (empty($class_notes)): ?>
                <p class="p-3 text-muted">No notes yet.</p>
            <?php else: ?>
            <?php foreach ($class_notes as $n): ?>
            <div class="note-entry border-bottom p-3" data-id="<?= $n['id'] ?>">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <span>
                        <strong><?= date('D d M Y', strtotime($n['created_at'])) ?></strong>
                        <?= date('g:i a', strtotime($n['created_at'])) ?>
                        — <?= htmlspecialchars($n['username'] ?? 'unknown') ?>
                    </span>
                    <div class="d-flex gap-1 flex-shrink-0 ms-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 note-edit-btn">Edit</button>
                        <form method="post" class="d-inline delete-btn"
                              hx-post="student_notes.php" hx-target="#general-notes-card" hx-select="#general-notes-card"
                              hx-swap="outerHTML swap:300ms" hx-confirm="Delete this entry?">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
                        </form>
                    </div>
                </div>
                <div class="note-content">
                    <?= nl2br(htmlspecialchars($n['content'])) ?>
                </div>
                <form method="post" class="note-edit-form mt-2" style="display:none"
                      hx-post="student_notes.php" hx-target="closest .note-entry" hx-swap="outerHTML">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                    <textarea name="content" class="form-control form-control-sm mb-2"
                              rows="4" required><?= htmlspecialchars($n['content']) ?></textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        <button type="button" class="btn btn-sm btn-secondary note-cancel-btn">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <style nonce="<?= csp_nonce() ?>">
        .delete-btn { display: none !important; }
        #notesContainer.editing .delete-btn { display: inline-block !important; }
    </style>

    <script nonce="<?= csp_nonce() ?>">
    function filterRoster(q) {
        q = q.toLowerCase().trim();
        document.querySelectorAll('tbody tr[data-name]').forEach(function(row) {
            row.style.display = (!q || row.dataset.name.includes(q)) ? '' : 'none';
        });
        ['instructors','parents','students','guests'].forEach(function(key) {
            var card = document.getElementById('card-' + key);
            if (!card) return;
            var count = 0;
            card.querySelectorAll('tbody tr[data-name]').forEach(function(r) {
                if (r.style.display !== 'none') count++;
            });
            var badge = document.getElementById('count-' + key);
            if (badge) badge.textContent = count;
            card.style.display = count === 0 ? 'none' : '';
        });
    }
    var rosterSearch = document.getElementById('rosterSearch');
    if (rosterSearch) {
        rosterSearch.addEventListener('input', function() { filterRoster(this.value); });
    }

    // Class notes log — delegated from document since note-entry markup can
    // arrive from the initial render or from htmx swaps (add/edit/delete).
    document.addEventListener('click', function(e) {
        var btn;
        if ((btn = e.target.closest('.note-edit-btn'))) {
            btn.closest('.note-entry').querySelector('.note-edit-form').style.display = 'block';
            return;
        }
        if ((btn = e.target.closest('.note-cancel-btn'))) {
            btn.closest('.note-entry').querySelector('.note-edit-form').style.display = 'none';
            return;
        }
        if ((btn = e.target.closest('#editToggle'))) {
            var c = document.getElementById('notesContainer');
            var editing = c.classList.toggle('editing');
            btn.textContent = editing ? 'Done' : 'Edit';
            btn.className   = editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-success';
            return;
        }
    });

    // Intercept Ctrl+F and redirect to the notes search field
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            const searchEl = document.getElementById('noteSearch');
            if (searchEl) {
                e.preventDefault();
                searchEl.focus();
                searchEl.select();
            }
        }
    });

    // Live search with highlight and scroll-to-first-match
    document.getElementById('noteSearch').addEventListener('input', function () {
        const q       = this.value.trim().toLowerCase();
        const entries = document.querySelectorAll('.note-entry');
        let matches   = 0;
        let firstMatch = null;

        entries.forEach(entry => {
            const content = entry.querySelector('.note-content');
            content.innerHTML = content.textContent
                .replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');

            if (q === '') {
                entry.style.display = '';
                return;
            }

            const text = content.textContent.toLowerCase();
            if (text.includes(q)) {
                entry.style.display = '';
                matches++;
                if (!firstMatch) firstMatch = entry;

                const regex = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                content.innerHTML = content.innerHTML.replace(regex,
                    '<mark style="background:#fff3cd;padding:0 2px">$1</mark>');
            } else {
                entry.style.display = 'none';
            }
        });

        document.getElementById('matchCount').textContent =
            q ? matches + ' match' + (matches !== 1 ? 'es' : '') : '';

        if (firstMatch) {
            const container = document.getElementById('notesContainer');
            container.scrollTop = firstMatch.offsetTop - container.offsetTop - 10;
        }
    });
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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db()->prepare('DELETE FROM student_notes WHERE id = ? AND student_id = ?')
         ->execute([post_int('id'), $student_id]);
    if (empty($_SERVER['HTTP_HX_REQUEST'])) {
        header("Location: student_notes.php?student_id=$student_id");
        exit;
    }
    // For htmx requests, fall through so hx-select can pull the live count.
}

// Add note
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['content'])) {
    verify_csrf();
    $content = trim(post_str('content'));
    if ($content !== '') {
        db()->prepare(
            'INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)'
        )->execute([$student_id, $content, current_user_id()]);
        header("Location: student_notes.php?student_id=$student_id&added=1");
        exit;
    }
}

if (isset($_GET['added'])) $msg = 'Note added.';


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
    <a href="student_notes.php" class="btn btn-outline-secondary btn-sm">← Class Notes</a>
    <h4 class="mb-0">Notes — <?= hn($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="row g-4">

    <!-- Add note -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Add Note</div>
            <div class="card-body">
                <form method="post"
                      hx-post="student_notes.php?student_id=<?= $student_id ?>"
                      hx-target="#student-notes-card"
                      hx-select="#student-notes-card"
                      hx-swap="outerHTML"
                      hx-on::after-request="if(event.detail.successful)this.reset()">
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
        <div id="student-notes-card" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>All Notes (<?= count($notes) ?>)</span>
                <?php if (!empty($notes)): ?>
                <button id="editToggle" class="btn btn-sm btn-outline-secondary">Edit</button>
                <?php endif; ?>
            </div>
            <div id="notesContainer" class="card-body p-0" style="max-height:500px;overflow-y:auto">
                <?php if (empty($notes)): ?>
                    <p class="p-3 text-muted">No notes yet.</p>
                <?php else: ?>
                <?php foreach ($notes as $n): ?>
                <div class="note-entry border-bottom p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <span>
                            <?= date('d M Y g:i a', strtotime($n['created_at'])) ?>
                            by <strong><?= htmlspecialchars($n['username'] ?? 'unknown') ?></strong>
                        </span>
                        <form method="post" class="d-inline delete-btn"
                              hx-post="student_notes.php?student_id=<?= $student_id ?>"
                              hx-target="#student-notes-card" hx-select="#student-notes-card"
                              hx-swap="outerHTML swap:300ms"
                              hx-confirm="Delete this note?">
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

<style nonce="<?= csp_nonce() ?>">
    .delete-btn { display: none !important; }
    #notesContainer.editing .delete-btn { display: inline-block !important; }
</style>

<script nonce="<?= csp_nonce() ?>">
// #student-notes-card gets replaced wholesale by htmx on delete, so delegate
// from document to survive swaps.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('#editToggle');
    if (!btn) return;
    var c = document.getElementById('notesContainer');
    var editing = c.classList.toggle('editing');
    btn.textContent = editing ? 'Done' : 'Edit';
    btn.className   = editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

