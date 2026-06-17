<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$msg = '';

// Delete entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db()->prepare('DELETE FROM general_notes WHERE id = ?')->execute([(int)$_POST['id']]);
    header('Location: general_notes.php');
    exit;
}

// Edit existing note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    verify_csrf();
    $note_id = (int)$_POST['id'];
    $content = trim($_POST['content'] ?? '');
    if ($note_id && $content !== '') {
        db()->prepare('UPDATE general_notes SET content=?, updated_at=NOW() WHERE id=?')
             ->execute([$content, $note_id]);
    }
    header('Location: general_notes.php');
    exit;
}

// Add new note entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    verify_csrf();
    $content = trim($_POST['content'] ?? '');
    if ($content !== '') {
        db()->prepare(
            'INSERT INTO general_notes (content, created_by) VALUES (?,?)'
        )->execute([$content, current_user_id()]);
        $msg = 'Note saved.';
    }
}


// Load all notes oldest-first (log style)
$notes = db()->query(
    'SELECT gn.id, gn.content, gn.created_at, gn.updated_at, u.username
     FROM general_notes gn
     LEFT JOIN users u ON u.id = gn.created_by
     ORDER BY gn.created_at ASC'
)->fetchAll();

$page_title = 'General Class Notes';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">General Class Notes</h3>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Add entry -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Add Entry</div>
    <div class="card-body">
        <form method="post">
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

<!-- Notes log -->
<div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Notes Log (<?= count($notes) ?> entries)</span>
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" id="noteSearch" class="form-control form-control-sm"
                           style="width:200px" placeholder="Search notes… (Ctrl+F)">
                    <span id="matchCount" class="text-muted small"></span>
                    <?php if (!empty($notes)): ?>
                    <button id="editToggle" class="btn btn-sm btn-success" onclick="toggleEdit()">Edit</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Scrollable notes window -->
            <div id="notesContainer"
                 class="card-body p-0"
                 style="max-height:60vh; overflow-y:auto">
                <?php if (empty($notes)): ?>
                    <p class="p-3 text-muted">No notes yet.</p>
                <?php else: ?>
                <?php foreach ($notes as $n): ?>
                <div class="note-entry border-bottom p-3" data-id="<?= $n['id'] ?>">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span>
                            <strong><?= date('D j M Y', strtotime($n['created_at'])) ?></strong>
                            <?= date('g:i a', strtotime($n['created_at'])) ?>
                            — <?= htmlspecialchars($n['username'] ?? 'unknown') ?>
                        </span>
                        <div class="d-flex gap-1 flex-shrink-0 ms-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0"
                                    onclick="noteEdit(<?= $n['id'] ?>)">Edit</button>
                            <form method="post" class="d-inline delete-btn"
                                  onsubmit="return confirm('Delete this entry?')">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0">✕</button>
                            </form>
                        </div>
                    </div>
                    <!-- View -->
                    <div class="note-view-<?= $n['id'] ?> note-content">
                        <?= nl2br(htmlspecialchars($n['content'])) ?>
                    </div>
                    <!-- Edit -->
                    <form method="post" class="note-edit-<?= $n['id'] ?> mt-2" style="display:none">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= $n['id'] ?>">
                        <textarea name="content" class="form-control form-control-sm mb-2"
                                  rows="4" required><?= htmlspecialchars($n['content']) ?></textarea>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                            <button type="button" class="btn btn-sm btn-secondary"
                                    onclick="noteCancel(<?= $n['id'] ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
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
    btn.className   = on ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-success';
}
function noteEdit(id) {
    document.querySelector('.note-view-' + id).style.display = 'none';
    document.querySelector('.note-edit-' + id).style.display = 'block';
}
function noteCancel(id) {
    document.querySelector('.note-view-' + id).style.display = '';
    document.querySelector('.note-edit-' + id).style.display = 'none';
}
</script>

<script>
// Intercept Ctrl+F and redirect to our search field
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
    const q     = this.value.trim().toLowerCase();
    const entries = document.querySelectorAll('.note-entry');
    let matches   = 0;
    let firstMatch = null;

    entries.forEach(entry => {
        // Reset highlights
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

            // Highlight matches
            const regex = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            content.innerHTML = content.innerHTML.replace(regex,
                '<mark style="background:#fff3cd;padding:0 2px">$1</mark>');
        } else {
            entry.style.display = 'none';
        }
    });

    // Show match count
    document.getElementById('matchCount').textContent =
        q ? matches + ' match' + (matches !== 1 ? 'es' : '') : '';

    // Scroll container to first match
    if (firstMatch) {
        const container = document.getElementById('notesContainer');
        container.scrollTop = firstMatch.offsetTop - container.offsetTop - 10;
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

