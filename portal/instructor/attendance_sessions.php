<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');

$date_from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : null;
$date_to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : null;
$filtering = $date_from !== null || $date_to !== null;

if ($filtering) {
    $df = $date_from ?? '2000-01-01';
    $dt = $date_to   ?? date('Y-m-d');
    $sessions_stmt = db()->prepare(
        'SELECT cs.id, cs.session_date,
                SUM(CASE WHEN a.present=1 THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN a.present=0 THEN 1 ELSE 0 END) AS absent_count
         FROM class_sessions cs
         LEFT JOIN attendance a ON a.session_id = cs.id
         WHERE cs.session_date BETWEEN ? AND ?
         GROUP BY cs.id, cs.session_date
         ORDER BY cs.session_date DESC'
    );
    $sessions_stmt->execute([$df, $dt]);
} else {
    $sessions_stmt = db()->query(
        'SELECT cs.id, cs.session_date,
                SUM(CASE WHEN a.present=1 THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN a.present=0 THEN 1 ELSE 0 END) AS absent_count
         FROM class_sessions cs
         LEFT JOIN attendance a ON a.session_id = cs.id
         GROUP BY cs.id, cs.session_date
         ORDER BY cs.session_date DESC'
    );
}
$sessions = $sessions_stmt->fetchAll();

$att_by_session = [];
if (!empty($sessions)) {
    $ids          = array_column($sessions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_stmt     = db()->prepare(
        "SELECT a.session_id, a.present, s.first_name, s.last_name
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         WHERE a.session_id IN ($placeholders)
         ORDER BY a.present DESC, s.last_name, s.first_name"
    );
    $att_stmt->execute($ids);
    foreach ($att_stmt->fetchAll() as $r) {
        $att_by_session[$r['session_id']][] = $r;
    }
}

$page_title = 'Attendance by Session';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="index.php" class="btn btn-success btn-sm">← Back</a>
    <h4 class="mb-0">Attendance by Session</h4>
    <div class="d-flex flex-column align-items-end gap-1 ms-auto">
        <input type="date" id="newSessionDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" style="width:160px">
        <a id="newSessionBtn" href="attendance.php?date=<?= date('Y-m-d') ?>" class="btn btn-success btn-sm">+ Record New Session</a>
    </div>
    <script>
    document.getElementById('newSessionDate').addEventListener('change', function() {
        document.getElementById('newSessionBtn').href = 'attendance.php?date=' + this.value;
    });
    </script>
</div>

<!-- Filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from ?? '') ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to ?? '') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-filter btn-sm">Filter</button>
            </div>
            <div class="col-auto">
                <a href="attendance_sessions.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>"
                   class="btn btn-filter btn-sm <?= ($date_from === date('Y-m-01') && $date_to === date('Y-m-d')) ? 'active' : '' ?>">This Month</a>
            </div>
            <div class="col-auto">
                <a href="attendance_sessions.php?from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>"
                   class="btn btn-filter btn-sm <?= ($date_from === date('Y-01-01') && $date_to === date('Y-m-d')) ? 'active' : '' ?>">This Year</a>
            </div>
            <?php if ($filtering): ?>
            <div class="col-auto">
                <a href="attendance_sessions.php" class="btn btn-filter btn-sm">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (empty($sessions)): ?>
    <div class="alert alert-info">No sessions found<?= $filtering ? ' in that date range' : '' ?>.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?>
        <?php if ($filtering): ?>
        <span class="text-muted fw-normal small ms-2">
            <?= ($date_from ? date('M j, Y', strtotime($date_from)) : 'beginning') ?>
            — <?= ($date_to ? date('M j, Y', strtotime($date_to)) : 'today') ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Present</th>
                    <th>Absent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $i => $sess): ?>
                <tr style="cursor:pointer" onclick="toggleSession(<?= $i ?>)">
                    <td class="fw-medium">
                        <a href="attendance.php?date=<?= $sess['session_date'] ?>"
                           class="text-decoration-none"
                           onclick="event.stopPropagation()">
                            <?= date('D, M j, Y', strtotime($sess['session_date'])) ?>
                        </a>
                    </td>
                    <td><span class="badge bg-primary"><?= (int)$sess['present_count'] ?></span></td>
                    <td><span class="badge bg-secondary"><?= (int)$sess['absent_count'] ?></span></td>
                    <td class="text-end text-muted" id="tog-<?= $i ?>">▼</td>
                </tr>
                <tr id="det-<?= $i ?>" style="display:none">
                    <td colspan="4" class="bg-light px-4 py-3">
                        <?php $att = $att_by_session[$sess['id']] ?? []; ?>
                        <?php if (empty($att)): ?>
                            <span class="text-muted small">No attendance recorded.</span>
                        <?php else: ?>
                            <?php
                            $present = array_filter($att, fn($a) => $a['present']);
                            $absent  = array_filter($att, fn($a) => !$a['present']);
                            ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="small fw-semibold text-success mb-1">Present (<?= count($present) ?>)</div>
                                    <div class="small">
                                        <?= implode(', ', array_map(fn($a) => htmlspecialchars($a['first_name'].' '.mb_substr($a['last_name'],0,1)), $present)) ?: '—' ?>
                                    </div>
                                </div>
                                <?php if (!empty($absent)): ?>
                                <div class="col-md-6">
                                    <div class="small fw-semibold text-muted mb-1">Absent (<?= count($absent) ?>)</div>
                                    <div class="small">
                                        <?= implode(', ', array_map(fn($a) => htmlspecialchars($a['first_name'].' '.mb_substr($a['last_name'],0,1)), $absent)) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function toggleSession(i) {
    var row = document.getElementById('det-' + i);
    var tog = document.getElementById('tog-' + i);
    var open = row.style.display !== 'none';
    row.style.display = open ? 'none' : '';
    tog.textContent   = open ? '▼' : '▲';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
