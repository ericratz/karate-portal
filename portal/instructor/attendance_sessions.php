<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

$valid_types = ['class', 'seminar', 'private'];
$type_filter = isset($_GET['type']) && in_array($_GET['type'], $valid_types, true) ? $_GET['type'] : null;
$year_filter = get_int('year');
$filtering   = $type_filter !== null || $year_filter !== 0;

// Build query dynamically based on active filters
$where  = [];
$params = [];

if ($type_filter !== null) {
    $where[]  = 'cs.class_type = ?';
    $params[] = $type_filter;
}
if ($year_filter) {
    $where[]  = 'YEAR(cs.session_date) = ?';
    $params[] = $year_filter;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Years available for the dropdown — actual class years plus the current year
$session_years = db()->query('SELECT DISTINCT YEAR(session_date) AS y FROM class_sessions ORDER BY y DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
if (!in_array((int)date('Y'), $session_years)) {
    array_unshift($session_years, (int)date('Y'));
}
$sessions_stmt = db()->prepare(
    "SELECT cs.id, cs.session_date, cs.class_type,
            SUM(CASE WHEN a.present=1 THEN 1 ELSE 0 END) AS present_count
     FROM class_sessions cs
     LEFT JOIN attendance a ON a.session_id = cs.id
     $where_sql
     GROUP BY cs.id, cs.session_date, cs.class_type
     ORDER BY cs.session_date DESC"
);
$sessions_stmt->execute($params);
$sessions = $sessions_stmt->fetchAll();

$att_by_session = [];
if (!empty($sessions)) {
    $ids          = array_column($sessions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_stmt     = db()->prepare(
        "SELECT a.session_id, s.first_name, s.last_name
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         WHERE a.session_id IN ($placeholders) AND a.present = 1
         ORDER BY s.first_name, s.last_name"
    );
    $att_stmt->execute($ids);
    foreach ($att_stmt->fetchAll() as $r) {
        $att_by_session[$r['session_id']][] = $r;
    }
}

$class_type_labels = ['class' => 'Class', 'seminar' => 'Seminar', 'private' => 'Private'];

$page_title = 'Classes';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
    <h4 class="mb-0">Classes</h4>
    <a href="../checkin.php" target="_blank" class="btn btn-sm ms-2"
       style="background-color:#0052cc;border-color:#0052cc;color:#fff;">
        QR Check-in <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg>
    </a>
    <?php if (has_role('admin')): ?>
    <a href="../admin/checkin_pin.php" class="btn btn-sm btn-outline-secondary ms-1">Check-in PIN</a>
    <?php endif; ?>
    <div class="d-flex flex-column align-items-stretch gap-1 ms-auto">
        <input type="date" id="newSessionDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
        <a id="newSessionBtn" href="attendance.php?date=<?= date('Y-m-d') ?>" class="btn btn-success btn-sm">+ Record New Class</a>
    </div>
</div>

<div id="sessions-page-body">
<!-- Filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" id="sessionsFilterForm" class="row g-2 align-items-end"
              hx-get="attendance_sessions.php" hx-target="#sessions-page-body" hx-select="#sessions-page-body"
              hx-swap="outerHTML" hx-push-url="true"
              hx-trigger="change from:select[name='type'], change from:select[name='year']">
            <div class="col-auto">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="class"   <?= $type_filter === 'class'   ? 'selected' : '' ?>>Class</option>
                    <option value="seminar" <?= $type_filter === 'seminar' ? 'selected' : '' ?>>Seminar</option>
                    <option value="private" <?= $type_filter === 'private' ? 'selected' : '' ?>>Private</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All Years</option>
                    <?php foreach ($session_years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $year_filter === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filtering): ?>
            <div class="col-auto">
                <a href="attendance_sessions.php"
                   hx-get="attendance_sessions.php" hx-target="#sessions-page-body" hx-select="#sessions-page-body"
                   hx-swap="outerHTML" hx-push-url="true"
                   class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($sessions)): ?>
    <div class="alert alert-info">No classes found<?= $filtering ? ' matching those filters' : '' ?>.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <?= count($sessions) ?> class<?= count($sessions) !== 1 ? 'es' : '' ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Present</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $i => $sess): ?>
                <tr style="cursor:pointer" class="session-row" data-idx="<?= $i ?>">
                    <td class="fw-medium">
                        <a href="attendance.php?date=<?= $sess['session_date'] ?>"
                           class="text-decoration-none session-link">
                            <?= date('D d M Y', strtotime($sess['session_date'])) ?>
                        </a>
                    </td>
                    <td class="text-muted small">
                        <?= $class_type_labels[$sess['class_type']] ?? ucfirst($sess['class_type']) ?>
                    </td>
                    <td><span class="badge bg-primary"><?= (int)$sess['present_count'] ?></span></td>
                    <td class="text-end text-muted" id="tog-<?= $i ?>">▼</td>
                </tr>
                <tr id="det-<?= $i ?>" style="display:none">
                    <td colspan="4" class="px-4 py-3">
                        <?php $att = $att_by_session[$sess['id']] ?? []; ?>
                        <?php if (empty($att)): ?>
                            <span class="text-muted small">No attendance recorded.</span>
                        <?php else: ?>
                            <div class="small fw-semibold text-success mb-1">Present (<?= count($att) ?>)</div>
                            <div class="small">
                                <?= implode(', ', array_map(fn($a) => hn($a['first_name']).' '.mb_strtoupper(mb_substr($a['last_name'],0,1)), $att)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>
</div><!-- /sessions-page-body -->

<script nonce="<?= csp_nonce() ?>">
document.getElementById('newSessionDate').addEventListener('change', function() {
    document.getElementById('newSessionBtn').href = 'attendance.php?date=' + this.value;
});

function toggleSession(i) {
    var row  = document.getElementById('det-' + i);
    var tog  = document.getElementById('tog-' + i);
    var open = row.style.display !== 'none';

    // Collapse all rows first
    document.querySelectorAll('tr[id^="det-"]').forEach(function(r) {
        r.style.display = 'none';
    });
    document.querySelectorAll('td[id^="tog-"]').forEach(function(t) {
        t.textContent = '▼';
    });

    // If it was closed, open it
    if (!open) {
        row.style.display = '';
        tog.textContent   = '▲';
    }
}

// Delegated — #sessions-page-body gets replaced wholesale by htmx on filter
// submits, so bind from document to survive swaps.
document.addEventListener('click', function(e) {
    if (e.target.closest('.session-link')) return;
    var row = e.target.closest('.session-row');
    if (row) toggleSession(row.dataset.idx);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

