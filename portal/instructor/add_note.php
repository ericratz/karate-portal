<?php
// Instructors can ADD a note for a student but cannot read existing notes.
// Admins see notes via admin/student_notes.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

$student_id = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
if (!$student_id) { header('Location: index.php'); exit; }

$student = db()->prepare('SELECT first_name, last_name FROM students WHERE id = ?');
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $content = trim($_POST['content'] ?? '');
    if ($content === '') {
        $error = 'Note cannot be empty.';
    } else {
        db()->prepare(
            'INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)'
        )->execute([$student_id, $content, current_user_id()]);
        $msg = 'Note saved.';
    }
}

$page_title = 'Add Note — ' . $student['first_name'] . ' ' . $student['last_name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Add Note — <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h4>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card border-0 shadow-sm" style="max-width:600px">
    <div class="card-body">
        <p class="text-muted small mb-3">
            Notes are private and visible to admins only.
            Your name and the current date/time are recorded automatically.
        </p>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="student_id" value="<?= $student_id ?>">
            <div class="mb-3">
                <textarea name="content" class="form-control" rows="5"
                          placeholder="Enter note…" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Save Note</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

