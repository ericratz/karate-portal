<?php
// /api/v1/admin/student_notes.php — Class Notes.
// GET (no student_id): the students-with-notes roster + the general
//     class-notes log (oldest first), same queries as admin/student_notes.php.
// GET ?student_id=N: that student's notes, newest first.
// POST {action, ...}: general-notes add/edit/delete (no student_id) and
//     per-student note add/delete (student_id set).

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input      = api_read_json();
    $action     = api_str($input, 'action');
    $student_id = api_int($input, 'student_id');

    if ($student_id) {
        // Per-student notes
        if ($action === 'add') {
            $content = trim(api_str($input, 'content'));
            if ($content !== '') {
                db()->prepare(
                    'INSERT INTO student_notes (student_id, content, created_by) VALUES (?,?,?)'
                )->execute([$student_id, $content, current_user_id()]);
            }
            api_respond(['saved' => true]);
        }
        if ($action === 'delete') {
            db()->prepare('DELETE FROM student_notes WHERE id = ? AND student_id = ?')
                 ->execute([api_int($input, 'id'), $student_id]);
            api_respond(['saved' => true]);
        }
    } else {
        // General class notes
        if ($action === 'add') {
            $content = trim(api_str($input, 'content'));
            if ($content !== '') {
                db()->prepare('INSERT INTO general_notes (content, created_by) VALUES (?,?)')
                     ->execute([$content, current_user_id()]);
            }
            api_respond(['saved' => true]);
        }
        if ($action === 'edit') {
            $note_id = api_int($input, 'id');
            $content = trim(api_str($input, 'content'));
            if ($note_id && $content !== '') {
                db()->prepare('UPDATE general_notes SET content=?, updated_at=NOW() WHERE id=?')
                     ->execute([$content, $note_id]);
            }
            api_respond(['saved' => true]);
        }
        if ($action === 'delete') {
            db()->prepare('DELETE FROM general_notes WHERE id = ?')->execute([api_int($input, 'id')]);
            api_respond(['saved' => true]);
        }
    }

    api_error('Unknown action', 400);
}

api_require_method('GET');

$student_id = get_int('student_id');

if ($student_id) {
    $student = db()->prepare('SELECT id, first_name, last_name FROM students WHERE id = ?');
    $student->execute([$student_id]);
    $student = $student->fetch();
    if (!$student) api_error('Student not found', 404);

    $notes = db()->prepare(
        'SELECT sn.id, sn.content, sn.created_at, u.username
         FROM student_notes sn
         LEFT JOIN users u ON u.id = sn.created_by
         WHERE sn.student_id = ?
         ORDER BY sn.created_at DESC'
    );
    $notes->execute([$student_id]);

    api_respond([
        'student' => [
            'id'   => (int)$student['id'],
            'name' => trim($student['first_name'] . ' ' . $student['last_name']),
        ],
        'notes' => array_map(fn($n) => [
            'id'         => (int)$n['id'],
            'content'    => (string)$n['content'],
            'created_at' => (string)$n['created_at'],
            'username'   => $n['username'] ?? null,
        ], $notes->fetchAll()),
    ]);
}

// Class notes log — oldest first
$class_notes = db()->query(
    'SELECT gn.id, gn.content, gn.created_at, gn.updated_at, u.username
     FROM general_notes gn
     LEFT JOIN users u ON u.id = gn.created_by
     ORDER BY gn.created_at ASC'
)->fetchAll();

$all = db()->query(
    'SELECT s.id, s.first_name, s.last_name, s.student_type, s.active, s.active_override,
            (SELECT COUNT(*) FROM student_notes sn WHERE sn.student_id = s.id) AS note_count,
            (SELECT MAX(cs.session_date)
             FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
             WHERE a.student_id = s.id AND a.present = 1) AS last_attended
     FROM students s
     ORDER BY s.first_name, s.last_name'
)->fetchAll();

// Only students who actually have notes appear in the tables
$all = array_values(array_filter($all, fn($s) => (int)$s['note_count'] > 0));

api_respond([
    'students' => array_map(fn($s) => [
        'id'              => (int)$s['id'],
        'first_name'      => (string)$s['first_name'],
        'last_name'       => (string)$s['last_name'],
        'student_type'    => (string)$s['student_type'],
        'active'          => (bool)$s['active'],
        'active_override' => $s['active_override'] !== null,
        'note_count'      => (int)$s['note_count'],
        'last_attended'   => $s['last_attended'] ?? null,
    ], $all),
    'class_notes' => array_map(fn($n) => [
        'id'         => (int)$n['id'],
        'content'    => (string)$n['content'],
        'created_at' => (string)$n['created_at'],
        'username'   => $n['username'] ?? null,
    ], $class_notes),
]);
