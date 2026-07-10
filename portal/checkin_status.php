<?php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: application/json');

$date = date('Y-m-d');
$sess = db()->prepare('SELECT id FROM class_sessions WHERE session_date = ?');
$sess->execute([$date]);
$session_id = $sess->fetchColumn();

if (!$session_id) {
    echo '[]';
    exit;
}

$stmt = db()->prepare('SELECT student_id FROM attendance WHERE session_id = ? AND present = 1');
$stmt->execute([$session_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
