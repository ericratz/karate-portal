<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$f_status = get_str('status');
$f_type   = get_str('type');
$f_from   = get_str('from');
$f_to     = get_str('to');

$where  = [];
$params = [];
if ($f_status) { $where[] = 'status = ?';           $params[] = $f_status; }
if ($f_type)   { $where[] = 'type = ?';             $params[] = $f_type; }
if ($f_from)   { $where[] = 'DATE(sent_at) >= ?';   $params[] = $f_from; }
if ($f_to)     { $where[] = 'DATE(sent_at) <= ?';   $params[] = $f_to; }

$sql = 'SELECT * FROM email_log'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY sent_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$types = db()->query("SELECT DISTINCT type FROM email_log ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Mail Log';
include __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-3">Mail Log</h4>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="sent"   <?= $f_status === 'sent'   ? 'selected' : '' ?>>Sent</option>
                    <option value="failed" <?= $f_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $f_type === $t ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_', ' ', $t)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($f_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($f_to) ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-filter btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="email_log.php" class="btn btn-filter btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><?= count($logs) ?> entr<?= count($logs) !== 1 ? 'ies' : 'y' ?></span>
        <?php if (count($logs) === 200): ?>
        <span class="text-warning small fw-normal">Result limit reached — use filters to narrow your search</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <p class="p-3 text-muted">No entries match the filter.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date / Time</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Type</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $row): ?>
            <tr>
                <td class="text-nowrap small text-muted"><?= date('d M Y g:i a', strtotime($row['sent_at'])) ?></td>
                <td class="small"><?= htmlspecialchars($row['to_email']) ?></td>
                <td class="small"><?= htmlspecialchars($row['subject']) ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$row['type']))) ?></span></td>
                <td>
                    <?php if ($row['status'] === 'sent'): ?>
                        <span class="text-success fw-semibold small">✓ sent</span>
                    <?php else: ?>
                        <span class="text-danger fw-semibold small">✗ failed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
