<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$msg = $error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $new_pin = trim(post_str('pin'));
    if ($new_pin === '') {
        $error = 'PIN cannot be empty.';
    } else {
        db()->prepare('UPDATE checkin_settings SET pin=? WHERE id=1')->execute([$new_pin]);
        header('Location: checkin_pin.php?updated=1');
        exit;
    }
}

if (isset($_GET['updated'])) $msg = 'PIN updated.';

$row        = db()->query('SELECT pin, updated_at FROM checkin_settings WHERE id=1')->fetch();
$current_pin = $row['pin']        ?? '(not set)';
$updated_at  = $row['updated_at'] ?? null;

$page_title = 'Check-in PIN';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="../instructor/attendance_sessions.php" class="btn btn-sm btn-filter">← Classes</a>
    <h4 class="mb-0">Check-in PIN</h4>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-4" style="max-width:700px">

    <!-- Current PIN + change form -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="mb-3">
                    <div class="text-muted small mb-1">Current PIN</div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="fs-1 fw-bold letter-spacing-wide" id="currentPinDisplay"
                             data-pin="<?= htmlspecialchars($current_pin) ?>">
                            <?= str_repeat('•', max(4, strlen($current_pin))) ?>
                        </div>
                        <button type="button" id="togglePinBtn" class="btn btn-sm btn-outline-secondary">View</button>
                    </div>
                    <?php if ($updated_at): ?>
                    <div class="text-muted small">Last changed <?= htmlspecialchars(date('j M Y g:i a', (int) strtotime($updated_at))) ?></div>
                    <?php endif; ?>
                </div>
                <form method="post" class="d-flex gap-2 align-items-end">
                    <?= csrf_input() ?>
                    <div>
                        <label class="form-label small fw-semibold mb-1">New PIN</label>
                        <input type="text" name="pin" class="form-control" style="width:140px"
                               placeholder="e.g. 4821" maxlength="20" autocomplete="off">
                    </div>
                    <button class="btn btn-primary">Update PIN</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <p class="text-muted small">
            Incorrect PIN attempts are recorded in the <a href="logs.php?channel=checkin">activity log</a>.
        </p>
    </div>

</div>

<script nonce="<?= csp_nonce() ?>">
var pinDisplay = document.getElementById('currentPinDisplay');
var toggleBtn  = document.getElementById('togglePinBtn');
var shown      = false;
toggleBtn.addEventListener('click', function() {
    shown = !shown;
    pinDisplay.textContent = shown ? pinDisplay.dataset.pin : '•'.repeat(Math.max(4, pinDisplay.dataset.pin.length));
    toggleBtn.textContent  = shown ? 'Hide' : 'View';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
