<?php
// /api/v1/instructor/belt_test_edit.php — the grading-chart editor.
//
// GET [?id=N] → form context: the existing test (admin-only), every student
//   with their current/next rank + full test history (drives the type-to-
//   filter picker, chart preselection, and the history panel — batched
//   queries, mirroring the old page's N+1 fix), and the rank list.
// POST {action:"save", id?, student_id, test_date, rank_id, fee_paid, notes,
//       chart_type, sub-scores…, score_manual, ref_pid?}
//   → computes the score server-side exactly like the old page (clamped
//     sub-scores, manual fallback), derives pass/fail/pending, awards the
//     rank on a pass, warns (never blocks) on same student+date+rank dupes.
// Deletes go through /instructor/belt_tests.php {action:"delete"}.

require_once __DIR__ . '/../../../includes/api.php';

api_require_role('instructor', 'admin');

$is_admin = ($_SESSION['role'] ?? '') === 'admin';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_verify_csrf();
    $input = api_read_json();
    if (api_str($input, 'action') !== 'save') {
        api_error('Unknown action', 400);
    }

    $test_id = api_int($input, 'id');
    if ($test_id && !$is_admin) {
        // Full grading chart for existing tests is admin-only
        api_error('Editing existing belt tests requires admin access.', 403);
    }

    $sid        = api_int($input, 'student_id');
    $date       = api_str($input, 'test_date');
    $rank_id    = api_int($input, 'rank_id');
    $fee        = api_bool($input, 'fee_paid') ? 1 : 0;
    $notes      = trim(api_str($input, 'notes'));
    $chart_type = api_str($input, 'chart_type');

    // Compute score from sub-scores based on which chart was used
    $score = null;
    if ($chart_type === 'lower') {
        $bf = max(0, min(50, api_int($input, 'l_basics_form')));
        $be = max(0, min(30, api_int($input, 'l_basics_eff')));
        $kf = max(0, min(5,  api_int($input, 'l_kumite_form')));
        $ke = max(0, min(15, api_int($input, 'l_kumite_eff')));
        if ($bf || $be || $kf || $ke) $score = $bf + $be + $kf + $ke;
    } elseif ($chart_type === 'regular') {
        $kataf = max(0, min(15, api_int($input, 'r_kata_form')));
        $katae = max(0, min(20, api_int($input, 'r_kata_eff')));
        $basf  = max(0, min(15, api_int($input, 'r_basics_form')));
        $base  = max(0, min(20, api_int($input, 'r_basics_eff')));
        $kumf  = max(0, min(10, api_int($input, 'r_kumite_form')));
        $kume  = max(0, min(20, api_int($input, 'r_kumite_eff')));
        if ($kataf || $katae || $basf || $base || $kumf || $kume)
            $score = $kataf + $katae + $basf + $base + $kumf + $kume;
    }
    // Fallback: manual score (for edits where the chart wasn't re-filled)
    if ($score === null && api_str($input, 'score_manual') !== '') {
        $m = api_int($input, 'score_manual');
        if ($m >= 0 && $m <= 100) $score = $m;
    }

    if ($score === null)  $result = 'pending';
    elseif ($score >= 80) $result = 'pass';
    else                  $result = 'fail';
    $awarded = ($result === 'pass') ? 1 : 0;

    if (!$sid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$rank_id) {
        api_error('Student, date, and rank are required.', 422);
    }

    $is_dup = false;
    if ($test_id) {
        db()->prepare(
            'UPDATE belt_tests
             SET student_id=?, test_date=?, rank_testing_for=?,
                 result=?, score=?, fee_paid=?, belt_awarded=?, notes=?
             WHERE id=?'
        )->execute([$sid, $date, $rank_id, $result, $score, $fee, $awarded, $notes ?: null, $test_id]);
    } else {
        // Duplicate check (warning only, never blocks): same student, date,
        // and rank already recorded — likely entered twice
        $dup_q = db()->prepare(
            'SELECT COUNT(*) FROM belt_tests WHERE student_id=? AND test_date=? AND rank_testing_for=?'
        );
        $dup_q->execute([$sid, $date, $rank_id]);
        $is_dup = (int)$dup_q->fetchColumn() > 0;

        db()->prepare(
            'INSERT INTO belt_tests
             (student_id, test_date, rank_testing_for, result, score, fee_paid, belt_awarded, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$sid, $date, $rank_id, $result, $score, $fee, $awarded, $notes ?: null, current_user_id()]);
        $test_id = (int)db()->lastInsertId();
    }

    if ($awarded) {
        db()->prepare(
            'INSERT INTO student_ranks (student_id, rank_id, achieved_date)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE achieved_date = VALUES(achieved_date)'
        )->execute([$sid, $rank_id, $date]);
        audit('belt_awarded', 'student', $sid, "rank_id=$rank_id date=$date");
    }

    api_respond([
        'test_id'   => $test_id,
        'duplicate' => $is_dup,
        'result'    => $result,
        'score'     => $score,
    ]);
}

api_require_method('GET');

$test_id = get_int('id');
if ($test_id && !$is_admin) {
    api_error('Editing existing belt tests requires admin access.', 403);
}

$test = null;
if ($test_id) {
    $stmt = db()->prepare('SELECT * FROM belt_tests WHERE id=?');
    $stmt->execute([$test_id]);
    $row = $stmt->fetch();
    if (!$row) {
        api_error('Belt test not found', 404);
    }
    $test = [
        'id'         => (int)$row['id'],
        'student_id' => (int)$row['student_id'],
        'test_date'  => $row['test_date'],
        'rank_id'    => (int)$row['rank_testing_for'],
        'score'      => $row['score'] !== null ? (int)$row['score'] : null,
        'notes'      => $row['notes'] ?? '',
        'fee_paid'   => (bool)$row['fee_paid'],
    ];
}

$all_students = db()->query(
    'SELECT id, first_name, last_name, date_of_birth FROM students ORDER BY last_name, first_name'
)->fetchAll();

$all_ranks = db()->query(
    'SELECT id, kyu_dan, name, rank_order FROM ranks ORDER BY rank_order'
)->fetchAll();

$rank_by_order = [];
foreach ($all_ranks as $r) {
    $rank_by_order[(int)$r['rank_order']] = $r;
}

// Highest rank per student in one query (first row per student = highest)
$cur_rank_by_student = [];
$rank_rows = db()->query(
    'SELECT sr.student_id, r.id, r.name, r.kyu_dan, r.rank_order
     FROM student_ranks sr
     JOIN ranks r ON r.id = sr.rank_id
     ORDER BY sr.student_id, r.rank_order DESC'
)->fetchAll();
foreach ($rank_rows as $rr) {
    $rsid = (int)$rr['student_id'];
    if (!isset($cur_rank_by_student[$rsid])) $cur_rank_by_student[$rsid] = $rr;
}

// All belt-test history in one query, grouped per student
$history_by_student = [];
$hist_rows = db()->query(
    'SELECT bt.student_id, bt.test_date, r.kyu_dan, r.name AS rank_name,
            bt.result, bt.score, bt.fee_paid
     FROM belt_tests bt
     JOIN ranks r ON r.id = bt.rank_testing_for
     ORDER BY bt.test_date DESC'
)->fetchAll();
foreach ($hist_rows as $hr) {
    $history_by_student[(int)$hr['student_id']][] = [
        'test_date' => $hr['test_date'],
        'kyu_dan'   => (string)$hr['kyu_dan'],
        'rank_name' => (string)$hr['rank_name'],
        'result'    => (string)$hr['result'],
        'score'     => $hr['score'] !== null ? (int)$hr['score'] : null,
        'fee_paid'  => (bool)$hr['fee_paid'],
    ];
}

$students = [];
foreach ($all_students as $s) {
    $sid = (int)$s['id'];

    $is_adult = true;
    if (!empty($s['date_of_birth'])) {
        $is_adult = (new DateTime($s['date_of_birth']))->diff(new DateTime())->y >= 16;
    }

    $cur = $cur_rank_by_student[$sid] ?? null;
    if ($cur) {
        $next_rank = $rank_by_order[(int)$cur['rank_order'] + 1] ?? null;
    } else {
        $start = $is_adult ? 3 : 1; // adults → 8th Kyu; youth → 10th Kyu
        $next_rank = $rank_by_order[$start] ?? null;
    }

    $students[] = [
        'id'                 => $sid,
        'name'               => trim($s['first_name'] . ' ' . $s['last_name']),
        'name_lf'            => $s['last_name'] . ', ' . $s['first_name'],
        'current_rank_label' => $cur ? ($cur['kyu_dan'] . ' — ' . $cur['name']) : 'Unranked',
        'next_rank_id'       => $next_rank ? (int)$next_rank['id'] : null,
        'history'            => $history_by_student[$sid] ?? [],
    ];
}

api_respond([
    'test'     => $test,
    'students' => $students,
    'ranks'    => array_map(fn($r) => [
        'id'         => (int)$r['id'],
        'kyu_dan'    => (string)$r['kyu_dan'],
        'name'       => (string)$r['name'],
        'rank_order' => (int)$r['rank_order'],
    ], $all_ranks),
    'is_admin' => $is_admin,
]);
