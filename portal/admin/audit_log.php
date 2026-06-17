<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

// Filters
$f_action = $_GET['action'] ?? '';
$f_from   = $_GET['from']   ?? '';
$f_to     = $_GET['to']     ?? '';
$f_user   = trim($_GET['user'] ?? '');

$where  = ['1=1'];
$params = [];
if ($f_action) { $where[] = 'al.action = ?';            $params[] = $f_action; }
if ($f_from)   { $where[] = 'DATE(al.created_at) >= ?'; $params[] = $f_from; }
if ($f_to)     { $where[] = 'DATE(al.created_at) <= ?'; $params[] = $f_to; }
if ($f_user)   { $where[] = 'al.username LIKE ?';        $params[] = '%' . $f_user . '%'; }

$stmt = db()->prepare(
    'SELECT al.*
     FROM audit_log al
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY al.created_at DESC
     LIMIT 500'
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Distinct actions for filter dropdown
$actions = db()->query(
    'SELECT DISTINCT action FROM audit_log ORDER BY action'
)->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Audit Log';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h3 class="mb-0">Audit Log</h3>
    <div class="d-flex align-items-center gap-3">
        <small class="text-muted">Last 500 matching entries</small>
        <a href="db_backup.php" class="btn btn-sm btn-outline-secondary">
            ⬇ Download Database Backup
        </a>
    </div>
</div>

<!-- Filter bar -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $a === $f_action ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">User</label>
                <input type="text" name="user" class="form-control form-control-sm"
                       placeholder="Username…" value="<?= htmlspecialchars($f_user) ?>">
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
                <a href="audit_log.php" class="btn btn-filter btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <?= count($entries) ?> entr<?= count($entries) !== 1 ? 'ies' : 'y' ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($entries)): ?>
            <p class="p-3 text-muted">No entries match the filter.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date / Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Detail</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e): ?>
                <tr>
                    <td class="text-nowrap small text-muted">
                        <?= date('d M Y g:i a', strtotime($e['created_at'])) ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($e['username'] ?? '—') ?></td>
                    <td>
                        <?php
                        $a = $e['action'];
                        if (strpos($a, 'delete') !== false)      $badge = 'bg-danger';
                        elseif (strpos($a, 'fail') !== false)    $badge = 'bg-warning text-dark';
                        elseif (strpos($a, 'login') !== false)   $badge = 'bg-secondary';
                        elseif (strpos($a, 'award') !== false)   $badge = 'bg-success';
                        else                                     $badge = 'bg-primary';
                        ?>
                        <span class="badge <?= $badge ?>"><?= htmlspecialchars($e['action']) ?></span>
                    </td>
                    <td class="small text-muted">
                        <?php if ($e['target_type'] && $e['target_id']): ?>
                            <?= htmlspecialchars($e['target_type']) ?> #<?= (int)$e['target_id'] ?>
                        <?php elseif ($e['target_type']): ?>
                            <?= htmlspecialchars($e['target_type']) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($e['detail'] ?? '') ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($e['ip_address'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

