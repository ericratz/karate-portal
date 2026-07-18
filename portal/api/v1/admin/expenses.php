<?php
// /api/v1/admin/expenses.php — expenses list + record/delete/toggle-paid.
// GET: filtered list (type/year), year options, totals — same WHERE clauses
//      as the old admin/expenses.php.
// POST {action:"record"|"delete", ...}: same validation/audits. (The old
// page's toggle_paid handler was dead UI — nothing rendered its button — so
// it was not carried over.)

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

$VALID_TYPES = ['rent', 'equipment', 'utilities', 'supplies', 'other'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input  = api_read_json();
    $action = api_str($input, 'action');

    if ($action === 'delete') {
        $del_id = api_int($input, 'id');
        db()->prepare('DELETE FROM expenses WHERE id=?')->execute([$del_id]);
        audit('delete_expense', 'expense', $del_id);
        api_respond(['deleted' => true]);
    }

    if ($action === 'record') {
        $type   = api_str($input, 'expense_type');
        $amount = (float)api_str($input, 'amount', '0');
        $date   = api_str($input, 'expense_date');
        $desc   = trim(api_str($input, 'description'));
        if (!in_array($type, $VALID_TYPES, true) || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            api_error('Please fill in all required fields.', 422);
        }
        db()->prepare(
            'INSERT INTO expenses (expense_type, amount, expense_date, description, paid, recorded_by)
             VALUES (?,?,?,?,1,?)'
        )->execute([$type, $amount, $date, $desc ?: null, current_user_id()]);
        api_respond(['recorded' => true]);
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$f_type = get_str('type');
$f_year = get_int('year');

$where  = ['1=1'];
$params = [];
if ($f_type && in_array($f_type, $VALID_TYPES, true)) { $where[] = 'e.expense_type = ?'; $params[] = $f_type; }
if ($f_year) { $where[] = 'YEAR(e.expense_date) = ?'; $params[] = $f_year; }

$expense_years = db()->query('SELECT DISTINCT YEAR(expense_date) AS y FROM expenses ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$expense_years = array_map('intval', $expense_years);
if (!in_array((int)date('Y'), $expense_years, true)) {
    array_unshift($expense_years, (int)date('Y'));
}

$stmt = db()->prepare(
    'SELECT e.*, u.username FROM expenses e
     LEFT JOIN users u ON u.id = e.recorded_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY e.expense_date DESC'
);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

api_respond([
    'expenses' => array_map(fn($e) => [
        'id'           => (int)$e['id'],
        'expense_date' => (string)$e['expense_date'],
        'expense_type' => (string)$e['expense_type'],
        'description'  => $e['description'] ?? null,
        'amount'       => (float)$e['amount'],
        'paid'         => (bool)$e['paid'],
        'recorded_by'  => $e['username'] ?? null,
    ], $expenses),
    'total'      => (float)array_sum(array_column($expenses, 'amount')),
    'total_paid' => (float)array_sum(array_map(fn($e) => $e['paid'] ? $e['amount'] : 0, $expenses)),
    'years'      => $expense_years,
]);
