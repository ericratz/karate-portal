<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

// Manual backup download — streams a fresh SQL export straight to the
// browser as a file download. Nothing is written to disk on the server.
if (($_GET['download_backup'] ?? '') === '1') {
    verify_csrf();
    $pdo    = db();
    $dbname = DB_NAME;

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="karate_backup_' . date('Y-m-d_His') . '.sql"');

    echo "-- ============================================================\n";
    echo "-- Database backup: {$dbname}\n";
    echo "-- Generated: " . date('D j M Y g:i a T') . "\n";
    echo "-- ============================================================\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $safe = '`' . str_replace('`', '``', (string)$table) . '`';

        $row = $pdo->query("SHOW CREATE TABLE {$safe}")->fetch(PDO::FETCH_NUM);
        echo "-- Table: {$table}\n";
        echo "DROP TABLE IF EXISTS {$safe};\n";
        echo $row[1] . ";\n\n";

        $stmt  = $pdo->query("SELECT * FROM {$safe}");
        $first = true;
        $cols  = null;
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($first) {
                $cols  = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', strval($c)) . '`', array_keys($data)));
                $first = false;
            }
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($data));
            echo "INSERT INTO {$safe} ({$cols}) VALUES (" . implode(', ', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "-- End of backup\n";

    audit('manual_backup_download', null, null, count($tables) . ' tables');
    exit;
}

$tab = get_str('tab', 'activity');
if (!in_array($tab, ['activity', 'error', 'mail'], true)) $tab = 'activity';

// ── Timeframe (shared across tabs) ──────────────────────────────
$timeframe = get_str('timeframe', 'day');
if (!in_array($timeframe, ['day', 'week', 'month', 'year', 'all'], true)) $timeframe = 'day';
$timeframe_labels = ['day' => 'This Day', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time'];
$timeframe_since = [
    'day'   => date('Y-m-d 00:00:00'),
    'week'  => date('Y-m-d 00:00:00', strtotime('monday this week')),
    'month' => date('Y-m-01 00:00:00'),
    'year'  => date('Y-01-01 00:00:00'),
    'all'   => null,
][$timeframe];

// ── Activity ──────────────────────────────────────────────────
$a_action = get_str('action');
$a_user   = trim(get_str('user'));

// ── Error ─────────────────────────────────────────────────────
$e_level   = get_str('level');
$e_channel = get_str('channel');

// ── Mail ──────────────────────────────────────────────────────
$m_status = get_str('status');
$m_type   = get_str('type');

// ── Fetch only the active tab ─────────────────────────────────
$entries = $logs = $mails = [];
$a_actions = $a_users = $e_levels = $e_channels = $m_types = [];
$LIMIT = 500;

if ($tab === 'activity') {
    $where = ['1=1']; $params = [];
    if ($a_action) { $where[] = 'action = ?';              $params[] = $a_action; }
    if ($a_user)   { $where[] = 'username = ?';            $params[] = $a_user; }
    if ($timeframe_since) { $where[] = 'created_at >= ?';  $params[] = $timeframe_since; }
    $stmt = db()->prepare(
        'SELECT * FROM activity_log WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC LIMIT ' . $LIMIT
    );
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
    $a_actions = db()->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    $a_users   = db()->query("SELECT DISTINCT username FROM activity_log WHERE username IS NOT NULL AND username <> '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

} elseif ($tab === 'error') {
    $LIMIT = 200;
    $where = []; $params = [];
    if ($e_level)   { $where[] = 'level = ?';            $params[] = $e_level; }
    if ($e_channel) { $where[] = 'channel = ?';          $params[] = $e_channel; }
    if ($timeframe_since) { $where[] = 'logged_at >= ?'; $params[] = $timeframe_since; }
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
    if ($timeframe_since) { $where[] = 'sent_at >= ?'; $params[] = $timeframe_since; }
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

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Logs</h4>
    <a href="logs.php?download_backup=1" class="btn btn-blue btn-sm">⬇ Download Backup</a>
</div>

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
        <form method="get" class="row g-2 align-items-end js-live-filter-form">
            <input type="hidden" name="tab" value="activity">
            <div class="col-md-3">
                <label class="form-label small mb-1">Timeframe</label>
                <select name="timeframe" class="form-select form-select-sm js-live-filter">
                    <?php foreach ($timeframe_labels as $tv => $tl): ?>
                    <option value="<?= $tv ?>" <?= $timeframe === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Action</label>
                <select name="action" class="form-select form-select-sm js-live-filter">
                    <option value="">All Actions</option>
                    <?php foreach ($a_actions as $a): $a = (string)$a; ?>
                    <option value="<?= htmlspecialchars($a) ?>" <?= $a_action === $a ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">User</label>
                <select name="user" class="form-select form-select-sm js-live-filter">
                    <option value="">All Users</option>
                    <?php foreach ($a_users as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $a_user === $u ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
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
        <form method="get" class="row g-2 align-items-end js-live-filter-form">
            <input type="hidden" name="tab" value="error">
            <div class="col-md-3">
                <label class="form-label small mb-1">Timeframe</label>
                <select name="timeframe" class="form-select form-select-sm js-live-filter">
                    <?php foreach ($timeframe_labels as $tv => $tl): ?>
                    <option value="<?= $tv ?>" <?= $timeframe === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Level</label>
                <select name="level" class="form-select form-select-sm js-live-filter">
                    <option value="">All Levels</option>
                    <?php foreach ($e_levels as $l): $l = (string)$l; ?>
                    <option value="<?= htmlspecialchars($l) ?>" <?= $e_level === $l ? 'selected' : '' ?>>
                        <?= ucfirst($l) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Channel</label>
                <select name="channel" class="form-select form-select-sm js-live-filter">
                    <option value="">All Channels</option>
                    <?php foreach ($e_channels as $ch): $ch = (string)$ch; ?>
                    <option value="<?= htmlspecialchars($ch) ?>" <?= $e_channel === $ch ? 'selected' : '' ?>>
                        <?= ucfirst($ch) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
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
        <form method="get" class="row g-2 align-items-end js-live-filter-form">
            <input type="hidden" name="tab" value="mail">
            <div class="col-md-3">
                <label class="form-label small mb-1">Timeframe</label>
                <select name="timeframe" class="form-select form-select-sm js-live-filter">
                    <?php foreach ($timeframe_labels as $tv => $tl): ?>
                    <option value="<?= $tv ?>" <?= $timeframe === $tv ? 'selected' : '' ?>><?= $tl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm js-live-filter">
                    <option value="">All</option>
                    <option value="sent"   <?= $m_status === 'sent'   ? 'selected' : '' ?>>Sent</option>
                    <option value="failed" <?= $m_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm js-live-filter">
                    <option value="">All Types</option>
                    <?php foreach ($m_types as $t): $t = (string)$t; ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $m_type === $t ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_', ' ', $t)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
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

<?php endif; ?>

<style nonce="<?= csp_nonce() ?>">
    /* .btn-primary is repurposed site-wide as green, so a genuinely blue
       button needs its own class. */
    .btn-blue {
        --bs-btn-bg: #0d6efd;
        --bs-btn-border-color: #0d6efd;
        --bs-btn-hover-bg: #0b5ed7;
        --bs-btn-hover-border-color: #0a58ca;
        --bs-btn-active-bg: #0a58ca;
        --bs-btn-active-border-color: #0a53be;
        --bs-btn-color: #fff;
        --bs-btn-hover-color: #fff;
        --bs-btn-active-color: #fff;
    }
</style>

<script nonce="<?= csp_nonce() ?>">
document.querySelectorAll('.js-live-filter').forEach(function (el) {
    el.addEventListener('change', function () { el.closest('form').submit(); });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
