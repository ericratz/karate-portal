<?php
// GET /api/v1/admin/logs.php — the combined log viewer's data: one tab per
// request (activity | error | mail) with the same timeframe/filter WHERE
// clauses, row limits, and distinct-value dropdown options as the old
// admin/logs.php.

require_once __DIR__ . '/../../../includes/api.php';

api_require_method('GET');
api_require_role('admin');

$tab = get_str('tab', 'activity');
if (!in_array($tab, ['activity', 'error', 'mail'], true)) $tab = 'activity';

$timeframe = get_str('timeframe', 'week');
if (!in_array($timeframe, ['day', 'week', 'month', 'year', 'all'], true)) $timeframe = 'week';
$timeframe_since = [
    'day'   => date('Y-m-d 00:00:00'),
    'week'  => date('Y-m-d 00:00:00', strtotime('monday this week')),
    'month' => date('Y-m-01 00:00:00'),
    'year'  => date('Y-01-01 00:00:00'),
    'all'   => null,
][$timeframe];

if ($tab === 'activity') {
    $a_action = get_str('action');
    $a_user   = trim(get_str('user'));
    $LIMIT = 500;

    $where = ['1=1']; $params = [];
    if ($a_action) { $where[] = 'action = ?';             $params[] = $a_action; }
    if ($a_user)   { $where[] = 'username = ?';           $params[] = $a_user; }
    if ($timeframe_since) { $where[] = 'created_at >= ?'; $params[] = $timeframe_since; }
    $stmt = db()->prepare(
        'SELECT * FROM activity_log WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC LIMIT ' . $LIMIT
    );
    $stmt->execute($params);

    api_respond([
        'limit'   => $LIMIT,
        'entries' => array_map(fn($e) => [
            'id'          => (int)$e['id'],
            'created_at'  => (string)$e['created_at'],
            'username'    => $e['username'] ?? null,
            'action'      => (string)$e['action'],
            'target_type' => $e['target_type'] ?? null,
            'target_id'   => $e['target_id'] !== null ? (int)$e['target_id'] : null,
            'detail'      => $e['detail'] ?? null,
            'ip_address'  => $e['ip_address'] ?? null,
        ], $stmt->fetchAll()),
        'actions' => db()->query('SELECT DISTINCT action FROM activity_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN),
        'users'   => db()->query("SELECT DISTINCT username FROM activity_log WHERE username IS NOT NULL AND username <> '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN),
    ]);
}

if ($tab === 'error') {
    $e_level   = get_str('level');
    $e_channel = get_str('channel');
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

    api_respond([
        'limit' => $LIMIT,
        'logs'  => array_map(fn($row) => [
            'id'        => (int)$row['id'],
            'logged_at' => (string)$row['logged_at'],
            'level'     => (string)$row['level'],
            'channel'   => (string)$row['channel'],
            'message'   => (string)$row['message'],
            'user_id'   => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'context'   => $row['context'] !== null ? (json_decode($row['context'], true) ?: null) : null,
        ], $stmt->fetchAll()),
        'levels'   => db()->query('SELECT DISTINCT level   FROM error_log ORDER BY level')->fetchAll(PDO::FETCH_COLUMN),
        'channels' => db()->query('SELECT DISTINCT channel FROM error_log ORDER BY channel')->fetchAll(PDO::FETCH_COLUMN),
    ]);
}

// mail
$m_status = get_str('status');
$m_type   = get_str('type');
$LIMIT = 500;

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

api_respond([
    'limit' => $LIMIT,
    'mails' => array_map(fn($row) => [
        'id'       => (int)$row['id'],
        'sent_at'  => (string)$row['sent_at'],
        'to_email' => (string)$row['to_email'],
        'subject'  => (string)$row['subject'],
        'type'     => $row['type'] ?? null,
        'status'   => (string)$row['status'],
    ], $stmt->fetchAll()),
    'types' => db()->query('SELECT DISTINCT type FROM email_log ORDER BY type')->fetchAll(PDO::FETCH_COLUMN),
]);
