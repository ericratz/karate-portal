<?php
// /api/v1/admin/waivers.php — payment exemptions (fee waivers).
// GET: filtered list + the student picker + year options — same WHERE
//      clauses as the old admin/waivers.php.
// POST {action:"grant"|"delete", ...}: same validation and audit entries.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

$VALID_TYPES = ['monthly_tuition', 'registration', 'belt_test', 'slc_training', 'seminar', 'all'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');

    if ($action === 'delete') {
        $del_id = api_int($input, 'id');
        db()->prepare('DELETE FROM payment_waivers WHERE id=?')->execute([$del_id]);
        audit('delete_waiver', 'waiver', $del_id);
        api_respond(['deleted' => true]);
    }

    if ($action === 'grant') {
        $sid    = api_int($input, 'student_id');
        $type   = api_str($input, 'waiver_type');
        $reason = trim(api_str($input, 'reason'));
        $date   = api_str($input, 'granted_date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

        if (!$sid || !in_array($type, $VALID_TYPES, true)) {
            api_error('Select a student and waiver type.', 422);
        }
        db()->prepare(
            'INSERT INTO payment_waivers
             (student_id, waiver_type, reason, granted_by, granted_date)
             VALUES (?,?,?,?,?)'
        )->execute([$sid, $type, $reason ?: null, current_user_id(), $date]);
        audit('grant_waiver', 'waiver', null, "student_id=$sid type=$type");
        api_respond(['granted' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$f_student = get_int('student_id');
$f_type    = get_str('type');
$f_year    = get_int('year');

$where  = ['1=1'];
$params = [];
if ($f_student) { $where[] = 'pw.student_id = ?';         $params[] = $f_student; }
if ($f_type)    { $where[] = 'pw.waiver_type = ?';        $params[] = $f_type; }
if ($f_year)    { $where[] = 'YEAR(pw.granted_date) = ?'; $params[] = $f_year; }

$waiver_years = db()->query('SELECT DISTINCT YEAR(granted_date) AS y FROM payment_waivers ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$waiver_years = array_map('intval', $waiver_years);
if (!in_array((int)date('Y'), $waiver_years, true)) {
    array_unshift($waiver_years, (int)date('Y'));
}

$stmt = db()->prepare(
    'SELECT pw.id, pw.student_id, pw.waiver_type, pw.reason, pw.granted_date,
            s.first_name, s.last_name, u.username AS granted_by_name
     FROM payment_waivers pw
     JOIN students s ON s.id = pw.student_id
     LEFT JOIN users u ON u.id = pw.granted_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY pw.granted_date DESC'
);
$stmt->execute($params);
$waivers = $stmt->fetchAll();

$students = db()->query(
    'SELECT id, first_name, last_name FROM students ORDER BY first_name, last_name'
)->fetchAll();

api_respond([
    'waivers' => array_map(fn($w) => [
        'id'              => (int)$w['id'],
        'student_id'      => (int)$w['student_id'],
        'student_name'    => trim($w['first_name'] . ' ' . $w['last_name']),
        'waiver_type'     => (string)$w['waiver_type'],
        'reason'          => $w['reason'] ?? null,
        'granted_date'    => (string)$w['granted_date'],
        'granted_by_name' => $w['granted_by_name'] ?? null,
    ], $waivers),
    'students' => array_map(fn($s) => [
        'id'         => (int)$s['id'],
        'first_name' => (string)$s['first_name'],
        'last_name'  => (string)$s['last_name'],
    ], $students),
    'years' => $waiver_years,
]);
