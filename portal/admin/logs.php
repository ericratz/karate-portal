<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$tab = $_GET['tab'] ?? 'activity';
if (!in_array($tab, ['activity', 'error', 'mail'], true)) $tab = 'activity';

// ── Activity ──────────────────────────────────────────────────
$a_action = $_GET['action'] ?? '';
$a_user   = trim($_GET['user'] ?? '');
$a_from   = $_GET['from']   ?? '';
$a_to     = $_GET['to']     ?? '';

// ── Error ─────────────────────────────────────────────────────
$e_level   = $_GET['level']   ?? '';
$e_channel = $_GET['channel'] ?? '';
$e_from    = $_GET['from']    ?? '';
$e_to      = $_GET['to']      ?? '';

// ── Mail ──────────────────────────────────────────────────────
$m_status = $_GET['status'] ?? '';
$m_type   = $_GET['type']   ?? '';
$m_from   = $_GET['from']   ?? '';
$m_to     = $_GET['to']     ?? '';

// ── Fetch only the active tab ─────────────────────────────────
$entries = $logs = $mails = [];
$LIMIT = 500;

if ($tab === 'activity') {
    $where = ['1=1']; $params = [];
    if ($a_action) { $where[] = 'action = ?';              $params[] = $a_action; }
    if ($a_user)   { $where[] = 'username LIKE ?';         $params[] = '%' . $a_user . '%'; }
    if ($a_from)   { $where[] = 'DATE(created_at) >= ?';   $params[] = $a_from; }
    if ($a_to)     { $where[] = 'DATE(created_at) <= ?';   $params[] = $a_to; }
    $stmt = db()->prepare(
        'SELECT * FROM activity_log WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC LIMIT ' . $LIMIT
    );
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
    $a_actions = db()->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

} elseif ($tab === 'error') {
    $LIMIT = 200;
    $where = []; $params = [];
    if ($e_level)   { $where[] = 'level = ?';            $params[] = $e_level; }
    if ($e_channel) { $where[] = 'channel = ?';          $params[] = $e_channel; }
    if ($e_from)    { $where[] = 'DATE(logged_at) >= ?'; $params[] = $e_from; }
    if ($e_to)      { $where[] = 'DATE(logged_at) <= ?'; $params[] = $e_to; }
    $stmt = db()->prepare(
        'SELECT * FROM error_log'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY logged_at DESC LIMIT ' . $LIMIT
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    $e_levels   = db()->query('SELECT DISTINCT level   FROM error_log ORDER BY level')->fetchAll(PDO::FETCH_COLUMN);
    $e_channels = db()->query('SELECT DISTINCT channel FROM error_log ORDER BY channel')->fetchAll(PDO::FETCH_COLUMN);

} elseif ($tab === 'mail') {
    $where = []; $params = [];
    if ($m_status) { $where[] = 'status = ?';          $params[] = $m_status; }
    if ($m_type)   { $where[] = 'type = ?';            $params[] = $m_type; }
    if ($m_from)   { $where[] = 'DATE(sent_at) >= ?';  $params[] = $m_from; }
    if ($m_to)     { $where[] = 'DATE(sent_at) <= ?';  $params[] = $m_to; }
    $stmt = db()->prepare(
        'SELECT * FROM email_log'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY sent_at DESC LIMIT ' . $LIMIT
    );
    $stmt->execute($params);
    $mails = $stmt->fetchAll();
    $m_types = db()->query('SELECT DISTINCT type FROM email_log ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);
}

$level_classes = [
    'debug'    => 'secondary',
    'info'     => 'primary',
    'warning'  => 'warning text-dark',
    'error'    => 'danger',
    'critical' => 'danger',
];

$page_title = 'Logs';
include __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-3">Logs</h4>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>"
           href="logs.php?tab=activity">Activity</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'error' ? 'active' : '' ?>"
           href="logs.php?tab=error">Errors</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'mail' ? 'active' : '' ?>"
           href="logs.php?tab=mail">Mail</a>
    </li>
</ul>

<?php // ════════════════════ ACTIVITY TAB ════════════════════ ?>
<?php if ($tab === 'activity'): ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="activity">
            <div class="col-md-2">
                <label class="form-label small mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($a_actions as $a): ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $a_action === $a ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">User</label>
                <input type="text" name="user" class="form-control form-control-sm"
                       placeholder="Username…" value="<?= htmlspecialchars($a_user) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($a_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($a_to) ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-filter btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="logs.php?tab=activity" class="btn btn-filter btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><?= count($entries) ?> entr<?= count($entries) !== 1 ? 'ies' : 'y' ?></span>
        <?php if (count($entries) === $LIMIT): ?>
        <span class="text-warning small fw-normal">Result limit reached — use filters to narrow your search</span>
        <?php endif; ?>
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
                <td class="text-nowrap small text-muted"><?= date('d M Y g:i a', strtotime($e['created_at'])) ?></td>
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

<?php // ════════════════════ ERROR TAB ════════════════════ ?>
<?php elseif ($tab === 'error'): ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="error">
            <div class="col-md-2">
                <label class="form-label small mb-1">Level</label>
                <select name="level" class="form-select form-select-sm">
                    <option value="">All Levels</option>
                    <?php foreach ($e_levels as $l): ?>
                    <option value="<?= htmlspecialchars($l) ?>" <?= $e_level === $l ? 'selected' : '' ?>>
                        <?= ucfirst($l) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Channel</label>
                <select name="channel" class="form-select form-select-sm">
                    <option value="">All Channels</option>
                    <?php foreach ($e_channels as $ch): ?>
                    <option value="<?= htmlspecialchars($ch) ?>" <?= $e_channel === $ch ? 'selected' : '' ?>>
                        <?= ucfirst($ch) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($e_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($e_to) ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-filter btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="logs.php?tab=error" class="btn btn-filter btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><?= count($logs) ?> entr<?= count($logs) !== 1 ? 'ies' : 'y' ?></span>
        <?php if (count($logs) === $LIMIT): ?>
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
                    <th>Date / Time</th>
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
                    <span class="badge bg-<?= $level_classes[$row['level']] ?? 'secondary' ?>">
                        <?= htmlspecialchars($row['level']) ?>
                    </span>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($row['channel']) ?></td>
                <td class="small"><?= htmlspecialchars($row['message']) ?></td>
                <td class="small text-muted"><?= $row['user_id'] ? '#' . $row['user_id'] : '—' ?></td>
                <td class="small">
                    <?php if ($row['context']): ?>
                    <?php foreach (json_decode($row['context'], true) ?? [] as $k => $v): ?>
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

<?php // ════════════════════ MAIL TAB ════════════════════ ?>
<?php elseif ($tab === 'mail'): ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="mail">
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="sent"   <?= $m_status === 'sent'   ? 'selected' : '' ?>>Sent</option>
                    <option value="failed" <?= $m_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($m_types as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $m_type === $t ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_', ' ', $t)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($m_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($m_to) ?>">
            </div>
            <div class="col-md-1">
                <button class="btn btn-filter btn-sm w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="logs.php?tab=mail" class="btn btn-filter btn-sm w-100">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><?= count($mails) ?> entr<?= count($mails) !== 1 ? 'ies' : 'y' ?></span>
        <?php if (count($mails) === $LIMIT): ?>
        <span class="text-warning small fw-normal">Result limit reached — use filters to narrow your search</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($mails)): ?>
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
            <?php foreach ($mails as $row): ?>
            <tr>
                <td class="text-nowrap small text-muted"><?= date('d M Y g:i a', strtotime($row['sent_at'])) ?></td>
                <td class="small"><?= htmlspecialchars($row['to_email']) ?></td>
                <td class="small"><?= htmlspecialchars($row['subject']) ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['type']))) ?></span></td>
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

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
