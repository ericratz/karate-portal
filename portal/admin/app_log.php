<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$f_level   = get_str('level');
$f_channel = get_str('channel');
$f_from    = get_str('from');
$f_to      = get_str('to');

$where  = [];
$params = [];
if ($f_level)   { $where[] = 'level = ?';              $params[] = $f_level; }
if ($f_channel) { $where[] = 'channel = ?';            $params[] = $f_channel; }
if ($f_from)    { $where[] = 'DATE(logged_at) >= ?';   $params[] = $f_from; }
if ($f_to)      { $where[] = 'DATE(logged_at) <= ?';   $params[] = $f_to; }

$sql = 'SELECT * FROM error_log'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY logged_at DESC LIMIT 200';

$logs = db()->prepare($sql);
$logs->execute($params);
$logs = $logs->fetchAll();

$levels   = db()->query("SELECT DISTINCT level   FROM error_log ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);
$channels = db()->query("SELECT DISTINCT channel FROM error_log ORDER BY channel")->fetchAll(PDO::FETCH_COLUMN);

$level_classes = [
    'debug'    => 'secondary',
    'info'     => 'primary',
    'warning'  => 'warning',
    'error'    => 'danger',
    'critical' => 'danger',
];

$page_title = 'Error Log';
include __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-3">Error Log</h4>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Level</label>
                <select name="level" class="form-select form-select-sm">
                    <option value="">All Levels</option>
                    <?php foreach ($levels as $l): ?>
                    <option value="<?= htmlspecialchars($l) ?>" <?= $f_level === $l ? 'selected' : '' ?>>
                        <?= ucfirst($l) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Channel</label>
                <select name="channel" class="form-select form-select-sm">
                    <option value="">All Channels</option>
                    <?php foreach ($channels as $ch): ?>
                    <option value="<?= htmlspecialchars($ch) ?>" <?= $f_channel === $ch ? 'selected' : '' ?>>
                        <?= ucfirst($ch) ?>
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
                <a href="app_log.php" class="btn btn-filter btn-sm w-100">Clear</a>
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
        <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th style="width:140px">Date / Time</th>
                    <th style="width:80px">Level</th>
                    <th style="width:80px">Channel</th>
                    <th>Message</th>
                    <th style="width:60px">User</th>
                    <th>Context</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $row): ?>
            <tr>
                <td class="text-nowrap small text-muted"><?= date('d M Y g:i a', strtotime($row['logged_at'])) ?></td>
                <td>
                    <span class="badge bg-<?= $level_classes[$row['level']] ?? 'secondary' ?><?= $row['level'] === 'warning' ? ' text-dark' : '' ?>">
                        <?= htmlspecialchars($row['level']) ?>
                    </span>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($row['channel']) ?></td>
                <td class="small"><?= htmlspecialchars($row['message']) ?></td>
                <td class="small text-muted"><?= $row['user_id'] ? '#' . $row['user_id'] : '—' ?></td>
                <td class="small">
                    <?php if ($row['context']): ?>
                    <?php $ctx = json_decode($row['context'], true) ?? []; ?>
                    <?php foreach ($ctx as $k => $v): ?>
                    <span class="text-muted"><?= htmlspecialchars($k) ?>:</span>
                    <code><?= htmlspecialchars((string)$v) ?></code>&nbsp;
                    <?php endforeach; ?>
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
