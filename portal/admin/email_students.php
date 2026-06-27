<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_role('admin');

$msg = $error = '';

$all_students = db()->query(
    'SELECT s.id, s.first_name, s.last_name, s.email, s.student_type, s.active,
            u.email AS login_email
     FROM students s
     LEFT JOIN users u ON u.id = s.user_id
     ORDER BY s.first_name, s.last_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body']    ?? '');
    $send_to = array_map('intval', $_POST['send_to'] ?? []);
    $send_to = array_filter($send_to); // remove 0s

    if (!$subject || !$body) {
        $error = 'Subject and message body are required.';
    } elseif (empty($send_to)) {
        $error = 'Please select at least one recipient.';
    } else {
        $by_id   = array_column($all_students, null, 'id');
        $sent    = 0;
        $failed  = 0;
        foreach ($send_to as $sid) {
            $t = $by_id[$sid] ?? null;
            if (!$t) continue;
            $to = $t['email'] ?: $t['login_email'];
            if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }
            $name          = $t['first_name'] . ' ' . $t['last_name'];
            $personal_body = "Dear $name,\n\n$body\n\n— " . SITE_NAME;
            $headers       = "From: " . DOJO_EMAIL . "\r\n"
                           . "Reply-To: " . ADMIN_EMAIL . "\r\n"
                           . "Content-Type: text/plain; charset=UTF-8\r\n";
            log_email($to, $subject, $personal_body, $headers, 'bulk') ? $sent++ : $failed++;
        }
        $msg = "Sent to $sent recipient" . ($sent !== 1 ? 's' : '') . '.';
        if ($failed) $msg .= " $failed skipped (missing or invalid email).";
    }
}

$page_title = 'Email Students';
include __DIR__ . '/../includes/header.php';

$js_students = [];
foreach ($all_students as $s) {
    $email = $s['email'] ?: $s['login_email'];
    $js_students[] = [
        'id'    => $s['id'],
        'name'  => $s['first_name'] . ' ' . $s['last_name'],
        'email' => $email ?: '',
        'type'  => $s['student_type'],
    ];
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Email Students</h3>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post" id="emailForm">
<?= csrf_input() ?>

<!-- Compose -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Compose</div>
    <div class="card-body">

        <div class="mb-3">
            <label class="form-label">Subject *</label>
            <input type="text" name="subject" class="form-control" required
                   placeholder="e.g. Class cancelled this Saturday">
        </div>

        <div class="mb-3">
            <label class="form-label">Message *</label>
            <textarea name="body" class="form-control" rows="7" required
                      placeholder="Your message here…&#10;&#10;Each email will be addressed to the recipient by name automatically."></textarea>
        </div>

        <!-- Group select (client-side only — not posted) -->
        <div class="mb-3">
            <div class="d-flex gap-3 flex-wrap align-items-center">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="chk_all"
                           onchange="toggleAll(this)">
                    <label class="form-check-label fw-semibold" for="chk_all">All</label>
                </div>
                <div class="vr mx-1"></div>
                <?php foreach (['instructors' => 'Instructors', 'parents' => 'Parents', 'students' => 'Students', 'guests' => 'Guests'] as $val => $label): ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input group-chk"
                           id="chk_<?= $val ?>" data-group="<?= $val ?>"
                           onchange="onGroupChange(this)">
                    <label class="form-check-label" for="chk_<?= $val ?>"><?= $label ?></label>
                </div>
                <?php endforeach; ?>
                <span class="text-muted small ms-2">— or select individuals in the list below</span>
            </div>
        </div>

        <button type="submit" class="btn btn-primary px-4" id="sendBtn"
                onclick="return confirmSend()">
            Send Email
        </button>

    </div>
</div>

<!-- Recipient list — individual checkboxes post with the form -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Recipients</span>
        <span id="recipientCount" class="badge bg-secondary">0 selected</span>
    </div>
    <div class="card-body p-0" style="max-height:420px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0" id="recipientTable">
            <thead class="table-light">
                <tr>
                    <th style="width:36px"></th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody id="recipientBody">
                <?php foreach ($all_students as $s):
                    $email = $s['email'] ?: $s['login_email'];
                    $hasEmail = $email && filter_var($email, FILTER_VALIDATE_EMAIL);
                    if (!$hasEmail) continue;
                ?>
                <tr class="recipient-row" data-type="<?= $s['student_type'] ?>"
                    data-group="<?= in_array($s['student_type'], ['instructor','admin']) ? 'instructors' : ($s['student_type'] === 'student' ? 'students' : ($s['student_type'] === 'parent' ? 'parents' : 'guests')) ?>">
                    <td>
                        <input type="checkbox" class="form-check-input recipient-chk"
                               name="send_to[]" value="<?= $s['id'] ?>"
                               id="r<?= $s['id'] ?>"
                               onchange="updateCount()"
>
                    </td>
                    <td class="small">
                        <label for="r<?= $s['id'] ?>" class="mb-0 cursor-pointer">
                            <?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?>
                        </label>
                    </td>
                    <td>
                        <?php
                        $type_cls = ['student' => 'bg-primary', 'instructor' => 'bg-warning text-dark', 'parent' => 'bg-info text-dark', 'guest' => 'bg-secondary'];
                        ?>
                        <span class="badge <?= $type_cls[$s['student_type']] ?? 'bg-secondary' ?>"><?= ucfirst($s['student_type']) ?></span>
                    </td>
                    <td class="small"><?= htmlspecialchars($email) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</form>

<script>
function toggleAll(chk) {
    // Sync group checkboxes
    document.querySelectorAll('.group-chk').forEach(function(c) {
        c.checked       = chk.checked;
        c.indeterminate = false;
    });
    // Check/uncheck all individual rows
    document.querySelectorAll('.recipient-chk:not(:disabled)').forEach(function(c) {
        c.checked = chk.checked;
    });
    updateCount();
}

function onGroupChange(groupChk) {
    const group    = groupChk.dataset.group;
    const checked  = groupChk.checked;
    // Toggle all rows in this group
    document.querySelectorAll('.recipient-row[data-group="' + group + '"] .recipient-chk:not(:disabled)')
            .forEach(function(c) { c.checked = checked; });
    syncAllCheckbox();
    updateCount();
}

function syncAllCheckbox() {
    const groups = document.querySelectorAll('.group-chk');
    const allChk = document.getElementById('chk_all');

    // Sync group checkboxes first so their state reflects actual row state
    groups.forEach(function(gc) {
        const rows = document.querySelectorAll('.recipient-row[data-group="' + gc.dataset.group + '"] .recipient-chk:not(:disabled)');
        const on   = Array.from(rows).filter(function(r) { return r.checked; }).length;
        gc.checked       = on === rows.length && rows.length > 0;
        gc.indeterminate = on > 0 && on < rows.length;
    });

    // Then compute All checkbox from individual rows (not group checkboxes)
    const allRows = document.querySelectorAll('.recipient-chk:not(:disabled)');
    const allOn   = Array.from(allRows).filter(function(r) { return r.checked; }).length;
    allChk.checked       = allOn === allRows.length && allRows.length > 0;
    allChk.indeterminate = allOn > 0 && allOn < allRows.length;
}

function updateCount() {
    const n = document.querySelectorAll('.recipient-chk:checked').length;
    document.getElementById('recipientCount').textContent = n + ' selected';
    syncAllCheckbox();
}

function confirmSend() {
    const n = document.querySelectorAll('.recipient-chk:checked').length;
    if (n === 0) { alert('Please select at least one recipient.'); return false; }
    return confirm('Send this email to ' + n + ' recipient' + (n !== 1 ? 's' : '') + '?');
}

// Click anywhere on a row toggles its checkbox
document.querySelectorAll('.recipient-row').forEach(function(row) {
    row.addEventListener('click', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'LABEL') return;
        const chk = row.querySelector('.recipient-chk');
        if (chk && !chk.disabled) { chk.checked = !chk.checked; updateCount(); }
    });
    row.style.cursor = 'pointer';
});

updateCount();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

