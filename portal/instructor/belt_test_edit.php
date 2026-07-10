<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

$test_id = (int)($_GET['id'] ?? 0);
$ref_pid = (int)($_GET['ref_pid'] ?? 0);
$msg = $error = '';

// Instructors cannot view or edit existing belt tests (full grading chart is admin-only)
if ($test_id && !has_role('admin')) {
    header('Location: belt_tests_all.php');
    exit;
}

// ── Delete ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && $test_id) {
    verify_csrf();
    db()->prepare('DELETE FROM belt_tests WHERE id=?')->execute([$test_id]);
    audit('delete_belt_test', 'belt_test', $test_id);
    $dest = $ref_pid ? "student_profile.php?id=$ref_pid" : 'belt_tests_all.php';
    header("Location: $dest");
    exit;
}

// ── Save ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
    verify_csrf();
    $sid        = (int)($_POST['student_id']   ?? 0);
    $date       = $_POST['test_date']          ?? '';
    $rank_id    = (int)($_POST['rank_id']      ?? 0);
    $fee        = isset($_POST['fee_paid'])    ? 1 : 0;
    $notes      = trim($_POST['notes']         ?? '');
    $rpid       = (int)($_POST['ref_pid']      ?? 0);
    $chart_type = $_POST['chart_type']         ?? '';

    // Compute score from sub-scores based on which chart was used
    $score = null;
    if ($chart_type === 'lower') {
        $bf = max(0, min(50, (int)($_POST['l_basics_form'] ?? 0)));
        $be = max(0, min(30, (int)($_POST['l_basics_eff']  ?? 0)));
        $kf = max(0, min(5,  (int)($_POST['l_kumite_form'] ?? 0)));
        $ke = max(0, min(15, (int)($_POST['l_kumite_eff']  ?? 0)));
        if ($bf || $be || $kf || $ke) $score = $bf + $be + $kf + $ke;
    } elseif ($chart_type === 'regular') {
        $kataf = max(0, min(15, (int)($_POST['r_kata_form']   ?? 0)));
        $katae = max(0, min(20, (int)($_POST['r_kata_eff']    ?? 0)));
        $basf  = max(0, min(15, (int)($_POST['r_basics_form'] ?? 0)));
        $base  = max(0, min(20, (int)($_POST['r_basics_eff']  ?? 0)));
        $kumf  = max(0, min(10, (int)($_POST['r_kumite_form'] ?? 0)));
        $kume  = max(0, min(20, (int)($_POST['r_kumite_eff']  ?? 0)));
        if ($kataf || $katae || $basf || $base || $kumf || $kume)
            $score = $kataf + $katae + $basf + $base + $kumf + $kume;
    }
    // Fallback: manual score (for edits where chart wasn't re-filled)
    if ($score === null && isset($_POST['score_manual']) && $_POST['score_manual'] !== '') {
        $m = (int)$_POST['score_manual'];
        if ($m >= 0 && $m <= 100) $score = $m;
    }

    if ($score === null)      $result = 'pending';
    elseif ($score >= 80)     $result = 'pass';
    else                      $result = 'fail';
    $awarded = ($result === 'pass') ? 1 : 0;

    if (!$sid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$rank_id) {
        $error = 'Student, date, and rank are required.';
    } else {
        if ($test_id) {
            db()->prepare(
                'UPDATE belt_tests
                 SET student_id=?, test_date=?, rank_testing_for=?,
                     result=?, score=?, fee_paid=?, belt_awarded=?, notes=?
                 WHERE id=?'
            )->execute([$sid, $date, $rank_id, $result, $score, $fee, $awarded, $notes ?: null, $test_id]);
        } else {
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

        $dest = $rpid
            ? "student_profile.php?id=$rpid"
            : "belt_test_edit.php?id=$test_id&saved=1";
        header("Location: $dest");
        exit;
    }
}

// ── Load existing ────────────────────────────────────────────
$test = null;
if ($test_id) {
    $stmt = db()->prepare('SELECT * FROM belt_tests WHERE id=?');
    $stmt->execute([$test_id]);
    $test = $stmt->fetch();
    if (!$test) { header('Location: belt_tests_all.php'); exit; }
}

$prefill_student = $test ? (int)$test['student_id'] : (int)($_GET['student_id'] ?? 0);

$all_students = db()->query(
    'SELECT id, first_name, last_name, date_of_birth, phone
     FROM students ORDER BY last_name, first_name'
)->fetchAll();

$all_ranks = db()->query(
    'SELECT id, kyu_dan, name, rank_order FROM ranks ORDER BY rank_order'
)->fetchAll();

$rank_by_id    = [];
$rank_by_order = [];
$rank_order_map = [];
$rank_kyu_dan_map = [];
foreach ($all_ranks as $r) {
    $rank_by_id[(int)$r['id']]            = $r;
    $rank_by_order[(int)$r['rank_order']] = $r;
    $rank_order_map[(int)$r['id']]        = (int)$r['rank_order'];
    $rank_kyu_dan_map[(int)$r['id']]      = $r['kyu_dan'];
}

// Build per-student JS data
$student_info = [];
foreach ($all_students as $s) {
    $sid = (int)$s['id'];

    $age = null;
    $is_adult = true;
    if (!empty($s['date_of_birth'])) {
        $age      = (new DateTime($s['date_of_birth']))->diff(new DateTime())->y;
        $is_adult = ($age >= 16);
    }

    $rank_q = db()->prepare(
        'SELECT r.id, r.name, r.kyu_dan, r.rank_order FROM student_ranks sr
         JOIN ranks r ON r.id = sr.rank_id
         WHERE sr.student_id = ? ORDER BY r.rank_order DESC LIMIT 1'
    );
    $rank_q->execute([$sid]);
    $cur = $rank_q->fetch();

    if ($cur) {
        $next_order = (int)$cur['rank_order'] + 1;
        $next_rank  = $rank_by_order[$next_order] ?? null;
    } else {
        $start = $is_adult ? 3 : 1; // adults → 8th Kyu; youth → 10th Kyu
        $next_rank = $rank_by_order[$start] ?? null;
    }

    $hist_q = db()->prepare(
        'SELECT bt.test_date, r.kyu_dan, r.name AS rank_name,
                bt.result, bt.score, bt.belt_awarded, bt.fee_paid
         FROM belt_tests bt
         JOIN ranks r ON r.id = bt.rank_testing_for
         WHERE bt.student_id = ?
         ORDER BY bt.test_date DESC'
    );
    $hist_q->execute([$sid]);
    $history = $hist_q->fetchAll(PDO::FETCH_ASSOC);

    $student_info[$sid] = [
        'name'               => trim($s['first_name'] . ' ' . $s['last_name']),
        'current_rank_label' => $cur ? ($cur['kyu_dan'] . ' — ' . $cur['name']) : 'Unranked',
        'next_rank_id'       => $next_rank ? (int)$next_rank['id']        : null,
        'next_rank_label'    => $next_rank ? ($next_rank['kyu_dan'] . ' — ' . $next_rank['name']) : null,
        'next_rank_order'    => $next_rank ? (int)$next_rank['rank_order'] : null,
        'use_lower_chart'    => $next_rank ? ((int)$next_rank['rank_order'] <= 2) : false,
        'phone'              => $s['phone'] ?? '',
        'history'            => $history,
    ];
}

if (isset($_GET['saved'])) $msg = 'Saved.';

// For JS: pass existing test values so edit mode can pre-fill the chart
$js_existing = $test ? [
    'test_date' => $test['test_date'],
    'rank_id'   => (int)$test['rank_testing_for'],
    'score'     => $test['score'] !== null ? (int)$test['score'] : null,
    'notes'     => $test['notes'] ?? '',
    'fee_paid'  => (bool)$test['fee_paid'],
] : null;

$page_title = $test_id ? 'Edit Belt Test' : 'New Belt Test';
include __DIR__ . '/../includes/header.php';

?>

<style nonce="<?= csp_nonce() ?>">
.chart-doc {
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 6px;
    max-width: 760px;
}
[data-bs-theme="dark"] .chart-doc {
    background: #1e1e1e;
    border-color: #444;
}
.chart-section-header {
    background: #343a40;
    color: #fff;
    font-weight: 700;
    padding: 6px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 4px;
    margin-bottom: 10px;
}
.chart-criteria {
    font-size: .72rem;
    color: #666;
    line-height: 1.5;
    margin-top: 2px;
}
[data-bs-theme="dark"] .chart-criteria { color: #aaa; }
.chart-score-input {
    width: 64px !important;
    text-align: center;
}
.chart-subtotal {
    text-align: right;
    font-size: .82rem;
    color: #888;
    padding: 2px 2px 6px;
    border-bottom: 1px solid #ddd;
    margin-bottom: 12px;
}
[data-bs-theme="dark"] .chart-subtotal { border-color: #444; }
.chart-total-row {
    background: #212529;
    color: #fff;
    border-radius: 6px;
    padding: 12px 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
[data-bs-theme="dark"] .chart-total-row { background: #000; }
</style>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <h4 class="mb-0"><?= $test_id ? 'Edit Belt Test' : 'New Belt Test' ?></h4>
    <a href="https://noji.com/karate/testing/Grading-Guidelines.pdf" target="_blank"
       class="btn btn-sm ms-auto"
       style="background-color:#0052cc;border-color:#0052cc;color:#fff;">
        Grading Guidelines <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg>
    </a>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post" id="mainForm">
    <?= csrf_input() ?>
    <input type="hidden" name="ref_pid" value="<?= $ref_pid ?>">
    <input type="hidden" name="chart_type" id="chartTypeInput" value="regular">

    <!-- Student selector -->
    <div class="mb-3">
        <input type="hidden" name="student_id" id="studentSelect" value="<?= $prefill_student ?>">
        <?php if ($prefill_student):
            $pname = '';
            foreach ($all_students as $s) {
                if ((int)$s['id'] === $prefill_student) { $pname = $s['last_name'] . ', ' . $s['first_name']; break; }
            }
        ?>
        <div class="form-control" style="background:transparent;border-style:dashed;max-width:420px">
            <?= hn($pname) ?>
        </div>
        <?php else: ?>
        <div id="studentSelected" class="d-none justify-content-between align-items-center mb-1" style="max-width:760px">
            <span class="fw-semibold" id="studentSelectedName"></span>
            <button type="button" id="clearStudentFilterBtn" class="btn btn-link btn-sm p-0 text-muted">change</button>
        </div>
        <input type="text" id="studentFilter" class="form-control" placeholder="Type student name…"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
               style="max-width:420px">
        <div id="studentList" class="list-group mt-1"
             <?= count($all_students) > 10 ? 'style="max-width:420px;max-height:260px;overflow-y:auto"' : 'style="max-width:420px"' ?>>
            <?php foreach ($all_students as $s): ?>
            <button type="button" class="list-group-item list-group-item-action student-btn"
                    data-id="<?= (int)$s['id'] ?>"
                    data-name="<?= htmlspecialchars(strtolower($s['first_name'].' '.$s['last_name'].' '.$s['last_name'].' '.$s['first_name'])) ?>"
                    style="display:none">
                <?= hn($s['last_name'] . ', ' . $s['first_name']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Belt test history panel -->
    <div id="historyPanel" class="mb-4" style="display:none;max-width:760px">
        <details class="border rounded shadow-sm">
            <summary style="cursor:pointer;padding:10px 14px;font-weight:600;font-size:.9rem;
                            list-style:none;display:flex;align-items:center;gap:8px;
                            background:var(--bs-secondary-bg, #f8f9fa);border-radius:inherit;
                            user-select:none;">
                <svg id="historyChevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                     fill="currentColor" viewBox="0 0 16 16" style="transition:transform .2s;flex-shrink:0">
                    <path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
                </svg>
                Belt Test History
            </summary>
            <div id="historyContent" class="p-3 border-top"></div>
        </details>
    </div>
<script nonce="<?= csp_nonce() ?>">
(function() {
    var det = document.querySelector('#historyPanel details');
    if (!det) return;
    det.addEventListener('toggle', function() {
        var chevron = document.getElementById('historyChevron');
        if (chevron) chevron.style.transform = det.open ? 'rotate(90deg)' : '';
    });
})();
</script>

    <!-- Prompt to select student -->
    <div id="selectPrompt" class="text-muted small mb-4"
         style="<?= $prefill_student ? 'display:none' : '' ?>">
        Select a student above to open the grading chart.
    </div>

    <!-- ═══════════════════ GRADING CHART ═══════════════════ -->
    <div id="chartSection" style="display:none">

        <div class="chart-doc shadow-sm mb-3">

            <!-- Chart header -->
            <div class="text-center py-3 px-3" style="border-bottom:2px solid #6f42c1">
                <div class="text-muted small mb-1" style="letter-spacing:.08em;text-transform:uppercase;font-size:.7rem">
                    JKA Shotokan Karate
                </div>
                <h5 id="chartTitle" class="mb-0 fw-bold"></h5>
                <div class="mt-2">
                    <a id="testPdfBtn" href="#" target="_blank"
                       class="btn btn-sm"
                       style="display:none;background-color:#0052cc;border-color:#0052cc;color:#fff;">
                        <span id="testPdfLabel"></span> <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg>
                    </a>
                </div>
            </div>

            <!-- Header fields -->
            <div class="p-3 pb-1" style="border-bottom:1px solid #e0e0e0">
                <div class="row g-2 mb-2">
                    <div class="col-md-9">
                        <label class="form-label small fw-semibold mb-1">Student Name</label>
                        <input type="text" id="chartStudentName" class="form-control form-control-sm"
                               readonly style="background:transparent;border-style:dashed">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Test Date *</label>
                        <input type="date" name="test_date" id="chartTestDate"
                               class="form-control form-control-sm" required
                               value="<?= htmlspecialchars($test['test_date'] ?? date('Y-m-d')) ?>">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold mb-1">Current Rank</label>
                        <input type="text" id="chartCurrentRank" class="form-control form-control-sm"
                               readonly style="background:transparent;border-style:dashed">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold mb-1">Testing For *</label>
                        <select name="rank_id" id="chartRankSelect" class="form-select form-select-sm" required>
                            <option value="">— select rank —</option>
                            <?php foreach ($all_ranks as $r): ?>
                            <?php if ($r['kyu_dan'] === '3rd Dan') continue; ?>
                            <option value="<?= $r['id'] ?>"
                                <?= ($test && $test['rank_testing_for'] == $r['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['kyu_dan'] . ' — ' . $r['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ─── LOWER CHART (10th / 9th Kyu) ─── -->
            <div id="lowerChart" class="p-3" style="display:none">

                <div class="chart-section-header">
                    <span>BASICS</span><span class="fw-normal small opacity-75">Possible 80 points</span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Form</div>
                                <div class="chart-criteria">
                                    Correct technique · Well-formed stances · Good posture · Firm heel placement<br>
                                    Definite movements · Focused attention · Technique accuracy · Coverage
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="l_basics_form" id="l_basics_form"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="50" value="" placeholder="0">
                                <span class="text-muted small">/ 50</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-1">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Effectiveness</div>
                                <div class="chart-criteria">
                                    Powerful movements · Correct hip movements · Forceful kiai · Good balance
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="l_basics_eff" id="l_basics_eff"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="30" value="" placeholder="0">
                                <span class="text-muted small">/ 30</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-subtotal">
                    Basics subtotal: <strong id="l_basics_total">—</strong> / 80
                </div>

                <div class="chart-section-header">
                    <span>KUMITE</span><span class="fw-normal small opacity-75">Possible 20 points</span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Form</div>
                                <div class="chart-criteria">
                                    Proper stance · Technique form · Partner engagement
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="l_kumite_form" id="l_kumite_form"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="5" value="" placeholder="0">
                                <span class="text-muted small">/ 5</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-1">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Effectiveness</div>
                                <div class="chart-criteria">
                                    Controlled power · Timing · Composure
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="l_kumite_eff" id="l_kumite_eff"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="15" value="" placeholder="0">
                                <span class="text-muted small">/ 15</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-subtotal">
                    Kumite subtotal: <strong id="l_kumite_total">—</strong> / 20
                </div>

            </div><!-- /lowerChart -->

            <!-- ─── REGULAR CHART (8th Kyu – 1st Dan) ─── -->
            <div id="regularChart" class="p-3" style="display:none">

                <div class="chart-section-header">
                    <span>KATA</span><span class="fw-normal small opacity-75">Possible 35 points</span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Form</div>
                                <div class="chart-criteria">
                                    Correct kata or technique · Correct kata sequence · Well-formed stances<br>
                                    Good posture · Firm heel placement · Definite movements<br>
                                    Focused attention · Technique accuracy · Technique coverage
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="r_kata_form" id="r_kata_form"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="15" value="" placeholder="0">
                                <span class="text-muted small">/ 15</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-1">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Effectiveness</div>
                                <div class="chart-criteria">
                                    Powerful movements · Correct energy generation · Forceful kiai<br>
                                    Proper breathing · Whole body action · Good balance
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="r_kata_eff" id="r_kata_eff"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="20" value="" placeholder="0">
                                <span class="text-muted small">/ 20</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-subtotal">
                    Kata subtotal: <strong id="r_kata_total">—</strong> / 35
                </div>

                <div class="chart-section-header">
                    <span>BASICS</span><span class="fw-normal small opacity-75">Possible 35 points</span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Form</div>
                                <div class="chart-criteria">
                                    Correct technique · Well-formed stances · Good posture · Firm heel placement<br>
                                    Definite movements · Focused attention · Technique accuracy · Coverage
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="r_basics_form" id="r_basics_form"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="15" value="" placeholder="0">
                                <span class="text-muted small">/ 15</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-1">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Effectiveness</div>
                                <div class="chart-criteria">
                                    Powerful movements · Correct energy generation · Forceful kiai<br>
                                    Proper breathing · Whole body action · Good balance
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="r_basics_eff" id="r_basics_eff"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="20" value="" placeholder="0">
                                <span class="text-muted small">/ 20</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-subtotal">
                    Basics subtotal: <strong id="r_basics_total">—</strong> / 35
                </div>

                <div class="chart-section-header">
                    <span>KUMITE</span><span class="fw-normal small opacity-75">Possible 30 points</span>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Form</div>
                                <div class="chart-criteria">
                                    Proper stance · Technique form · Partner engagement · Control
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="r_kumite_form" id="r_kumite_form"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="10" value="" placeholder="0">
                                <span class="text-muted small">/ 10</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-1">
                    <div class="col">
                        <div class="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold small">Effectiveness</div>
                                <div class="chart-criteria">
                                    Controlled power · Timing · Composure · Decisiveness
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <input type="number" name="r_kumite_eff" id="r_kumite_eff"
                                       class="form-control form-control-sm chart-score-input"
                                       min="0" max="20" value="" placeholder="0">
                                <span class="text-muted small">/ 20</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="chart-subtotal">
                    Kumite subtotal: <strong id="r_kumite_total">—</strong> / 30
                </div>

            </div><!-- /regularChart -->

            <!-- ─── Total / Result ─── -->
            <div class="px-3 pb-3">
                <div class="chart-total-row">
                    <div class="fs-5">
                        Total: <strong id="totalScore">—</strong>
                        <span class="opacity-50 small fw-normal">/ 100</span>
                    </div>
                    <div id="resultBadge"></div>
                </div>
                <div class="text-muted small mt-2 fst-italic" id="resultText"></div>

                <?php if ($test_id && $test['score'] !== null): ?>
                <div class="mt-2 small text-muted border rounded p-2">
                    <strong>Recorded score:</strong> <?= (int)$test['score'] ?>%
                    — re-fill the chart above to update, or enter directly:
                    <input type="number" name="score_manual" id="scoreManual"
                           class="form-control form-control-sm d-inline-block mt-1"
                           style="width:80px"
                           min="0" max="100"
                           value="<?= (int)$test['score'] ?>">
                </div>
                <?php else: ?>
                <input type="hidden" name="score_manual" value="">
                <?php endif; ?>
            </div>

            <!-- ─── Comments ─── -->
            <div class="px-3 pb-3" style="border-top:1px solid #e0e0e0;padding-top:14px !important">
                <label class="form-label small fw-semibold mb-1">Comments</label>
                <textarea name="notes" class="form-control form-control-sm" rows="3"
                          id="chartNotes"><?= htmlspecialchars($test['notes'] ?? '') ?></textarea>
            </div>

            <!-- ─── Fee + Save ─── -->
            <div class="d-flex justify-content-between align-items-center px-3 py-3"
                 style="border-top:1px solid #e0e0e0">
                <div class="form-check mb-0">
                    <input type="checkbox" class="form-check-input" name="fee_paid" id="feePaid" value="1"
                           <?= (isset($test) && $test['fee_paid']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="feePaid">Belt Test Fee Paid</label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= $test_id ? 'Save Changes' : 'Record Test' ?>
                </button>
            </div>

        </div><!-- /chart-doc -->
    </div><!-- /chartSection -->

</form>

<?php if ($test_id): ?>
<div class="d-flex justify-content-end mt-2">
    <form method="post" class="d-inline" id="deleteBeltTestForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
    </form>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
const STUDENT_INFO    = <?= json_encode($student_info) ?>;
const RANK_ORDER_MAP  = <?= json_encode($rank_order_map) ?>;
const RANK_KYU_DAN    = <?= json_encode($rank_kyu_dan_map) ?>;
const EXISTING_TEST   = <?= json_encode($js_existing) ?>;

function testPdfUrl(rankId) {
    var kd = RANK_KYU_DAN[rankId];
    if (!kd) return null;
    var m = kd.match(/^(\d+)(?:st|nd|rd|th)\s+(Kyu|Dan)$/i);
    if (!m) return null;
    var num  = String(parseInt(m[1])).padStart(2, '0');
    var type = m[2].charAt(0).toUpperCase() + m[2].slice(1).toLowerCase();
    return 'https://noji.com/karate/testing/Test-' + type + '-' + num + '.pdf';
}

function updateTestPdfLink(rankId) {
    var btn = document.getElementById('testPdfBtn');
    var url = rankId ? testPdfUrl(rankId) : null;
    btn.href          = url || '#';
    btn.style.display = url ? '' : 'none';
    if (url) {
        document.getElementById('testPdfLabel').textContent =
            (RANK_KYU_DAN[rankId] || '') + ' Test';
    }
}

// Student type-to-filter
(function() {
    var f = document.getElementById('studentFilter');
    if (!f) return;
    f.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        var any = false;
        document.querySelectorAll('.student-btn').forEach(function(btn) {
            var show = q.length > 0 && btn.dataset.name.indexOf(q) !== -1;
            btn.style.display = show ? '' : 'none';
            if (show) any = true;
        });
    });
})();

function selectStudent(id, label) {
    document.getElementById('studentSelect').value = id;
    document.getElementById('studentSelectedName').textContent = label;
    var sel = document.getElementById('studentSelected');
    sel.classList.remove('d-none');
    sel.classList.add('d-flex');
    document.getElementById('studentFilter').style.display = 'none';
    document.getElementById('studentList').style.display   = 'none';
    onStudentChange(id);
}

function clearStudentFilter() {
    document.getElementById('studentSelect').value = '';
    var sel = document.getElementById('studentSelected');
    sel.classList.add('d-none');
    sel.classList.remove('d-flex');
    document.getElementById('studentFilter').style.display = '';
    document.getElementById('studentFilter').value         = '';
    document.getElementById('studentList').style.display   = '';
    document.querySelectorAll('.student-btn').forEach(function(b) { b.style.display = 'none'; });
    onStudentChange('');
}

var clearStudentFilterBtn = document.getElementById('clearStudentFilterBtn');
if (clearStudentFilterBtn) clearStudentFilterBtn.addEventListener('click', clearStudentFilter);
document.querySelectorAll('.student-btn').forEach(function(b) {
    b.addEventListener('click', function() {
        selectStudent(parseInt(b.dataset.id, 10), b.textContent.trim());
    });
});

var deleteBeltTestForm = document.getElementById('deleteBeltTestForm');
if (deleteBeltTestForm) {
    deleteBeltTestForm.addEventListener('submit', function(e) {
        if (!confirm('Delete this belt test record? This cannot be undone.')) e.preventDefault();
    });
}

document.querySelectorAll('.chart-score-input').forEach(function(el) {
    el.addEventListener('input', recomputeScore);
});

function onStudentChange(sid) {
    const chartSection  = document.getElementById('chartSection');
    const selectPrompt  = document.getElementById('selectPrompt');
    const historyPanel  = document.getElementById('historyPanel');

    if (!sid || !STUDENT_INFO[sid]) {
        chartSection.style.display = 'none';
        selectPrompt.style.display = '';
        historyPanel.style.display = 'none';
        return;
    }

    const info = STUDENT_INFO[sid];
    selectPrompt.style.display = 'none';

    // History panel
    historyPanel.style.display = '';
    const histEl = document.getElementById('historyContent');
    if (!info.history || info.history.length === 0) {
        histEl.innerHTML = '<span class="text-muted small">No belt tests on record.</span>';
    } else {
        let h = '<table class="table table-sm table-bordered mb-0">'
              + '<thead class="table-light"><tr><th>Date</th><th>Testing For</th><th>Score</th><th>Result</th></tr></thead><tbody>';
        info.history.forEach(function(row) {
            const date  = (row.test_date || '').substring(0, 10);
            const rank  = row.kyu_dan + ' — ' + row.rank_name;
            const score = (row.score !== null && row.score !== '') ? row.score + '%' : '—';
            let result  = '—';
            if (row.result === 'pass')    result = '<span class="badge bg-success">Pass</span>';
            else if (row.result === 'fail')    result = '<span class="badge bg-danger">Fail</span>';
            else if (row.result === 'pending') result = '<span class="badge bg-secondary">Pending</span>';
            h += '<tr><td>' + date + '</td><td>' + rank + '</td><td>' + score + '</td><td>' + result + '</td></tr>';
        });
        h += '</tbody></table>';
        histEl.innerHTML = h;
    }

    // Fill chart header
    document.getElementById('chartStudentName').value = info.name;
    document.getElementById('chartCurrentRank').value = info.current_rank_label;

    // Determine target rank and chart type
    var targetRankId    = info.next_rank_id;
    var useExistingRank = EXISTING_TEST !== null;

    if (useExistingRank && EXISTING_TEST.rank_id) {
        targetRankId = EXISTING_TEST.rank_id;
        document.getElementById('chartTestDate').value = EXISTING_TEST.test_date || '';
        if (EXISTING_TEST.notes) document.getElementById('chartNotes').value = EXISTING_TEST.notes;
        if (EXISTING_TEST.fee_paid) document.getElementById('feePaid').checked = true;
    } else {
        document.getElementById('chartTestDate').value = '<?= date("Y-m-d") ?>';
    }

    // Set Testing For dropdown
    if (targetRankId) {
        document.getElementById('chartRankSelect').value = targetRankId;
    }

    // Determine chart type from target rank
    var targetOrder = RANK_ORDER_MAP[targetRankId] || 0;
    var isLower     = (targetOrder > 0 && targetOrder <= 2);

    applyChartType(isLower);
    updateTestPdfLink(targetRankId);
    chartSection.style.display = '';
    recomputeScore();
}

function applyChartType(isLower) {
    document.getElementById('chartTypeInput').value       = isLower ? 'lower' : 'regular';
    document.getElementById('lowerChart').style.display   = isLower ? '' : 'none';
    document.getElementById('regularChart').style.display = isLower ? 'none' : '';
    document.getElementById('chartTitle').textContent     = isLower
        ? 'Grading Chart for 10th and 9th Kyu Tests'
        : 'Grading Chart for 8th Kyu Through 1st Dan Tests';
}

// Auto-check fee paid if student already has a fee-paid test on this date
document.getElementById('chartTestDate').addEventListener('change', function() {
    var date = this.value;
    var sid  = document.getElementById('studentSelect').value
            || <?= $prefill_student ?: 'null' ?>;
    if (!date || !sid || !STUDENT_INFO[sid]) return;
    var hasFee = STUDENT_INFO[sid].history.some(function(row) {
        return (row.test_date || '').substring(0, 10) === date && row.fee_paid == 1;
    });
    if (hasFee) document.getElementById('feePaid').checked = true;
});

// Update chart type and PDF link when "Testing For" dropdown changes
document.getElementById('chartRankSelect').addEventListener('change', function() {
    var rankId = parseInt(this.value);
    var order  = RANK_ORDER_MAP[rankId] || 0;
    applyChartType(order > 0 && order <= 2);
    updateTestPdfLink(rankId);
    recomputeScore();
});

function recomputeScore() {
    const chartType = document.getElementById('chartTypeInput').value;
    var total = 0, filled = false;

    if (chartType === 'lower') {
        var bf = parseInt(document.getElementById('l_basics_form').value) || 0;
        var be = parseInt(document.getElementById('l_basics_eff').value)  || 0;
        var kf = parseInt(document.getElementById('l_kumite_form').value) || 0;
        var ke = parseInt(document.getElementById('l_kumite_eff').value)  || 0;
        filled = (bf || be || kf || ke);
        total  = bf + be + kf + ke;
        document.getElementById('l_basics_total').textContent = filled ? (bf + be) : '—';
        document.getElementById('l_kumite_total').textContent = filled ? (kf + ke) : '—';
    } else {
        var kataf = parseInt(document.getElementById('r_kata_form').value)   || 0;
        var katae = parseInt(document.getElementById('r_kata_eff').value)    || 0;
        var basf  = parseInt(document.getElementById('r_basics_form').value) || 0;
        var base  = parseInt(document.getElementById('r_basics_eff').value)  || 0;
        var kumf  = parseInt(document.getElementById('r_kumite_form').value) || 0;
        var kume  = parseInt(document.getElementById('r_kumite_eff').value)  || 0;
        filled = (kataf || katae || basf || base || kumf || kume);
        total  = kataf + katae + basf + base + kumf + kume;
        document.getElementById('r_kata_total').textContent   = filled ? (kataf + katae) : '—';
        document.getElementById('r_basics_total').textContent = filled ? (basf  + base)  : '—';
        document.getElementById('r_kumite_total').textContent = filled ? (kumf  + kume)  : '—';
    }

    const totalEl  = document.getElementById('totalScore');
    const badgeEl  = document.getElementById('resultBadge');
    const textEl   = document.getElementById('resultText');

    if (!filled) {
        totalEl.textContent = '—';
        badgeEl.innerHTML   = '';
        textEl.textContent  = '';
        return;
    }

    totalEl.textContent = total;

    if (total >= 80) {
        badgeEl.innerHTML = '<span class="badge bg-success" style="font-size:.95rem">PASS</span>';
        textEl.innerHTML  = '<strong>Pass</strong> — eligible to advance to the next rank. Belt will be awarded.';
    } else if (total >= 60) {
        badgeEl.innerHTML = '<span class="badge bg-warning text-dark" style="font-size:.95rem">RETEST</span>';
        textEl.textContent = 'Retest (60–79) — student shows potential but needs more practice. Schedule a retest.';
    } else {
        badgeEl.innerHTML = '<span class="badge bg-danger" style="font-size:.95rem">FAIL</span>';
        textEl.textContent = 'Fail (below 60) — student needs significantly more practice before retesting.';
    }
}

// Initialise on page load
(function() {
    var sel = document.getElementById('studentSelect');
    var sid = sel ? sel.value : null;
    <?php if ($prefill_student): ?>
    sid = <?= $prefill_student ?>;
    <?php endif; ?>
    if (sid) onStudentChange(sid);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
