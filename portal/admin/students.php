<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auto_inactive.php';
require_role('admin');
apply_auto_inactive();

$all = db()->query(
    'SELECT s.id, s.first_name, s.last_name, s.student_type, s.active,
            s.active_override, s.injury_waiver, s.registration_date,
            r.kyu_dan, u.role AS user_role,
            (SELECT MAX(cs.session_date)
             FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
             WHERE a.student_id = s.id AND a.present = 1) AS last_attended
     FROM students s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN student_ranks sr ON sr.student_id = s.id
     LEFT JOIN ranks r ON r.id = sr.rank_id
     WHERE (sr.id IS NULL OR sr.rank_id = (
         SELECT sr2.rank_id FROM student_ranks sr2
         JOIN ranks r2 ON r2.id = sr2.rank_id
         WHERE sr2.student_id = s.id
         ORDER BY r2.rank_order DESC LIMIT 1
     ))
     ORDER BY s.last_name, s.first_name'
)->fetchAll();

$instructors = array_filter($all, function($s) {
    return in_array($s['student_type'], ['instructor', 'admin']);
});
$students = array_filter($all, function($s) {
    return $s['student_type'] === 'student';
});
$guests = array_filter($all, function($s) {
    return $s['student_type'] === 'guest';
});
$parents = array_filter($all, function($s) {
    return $s['student_type'] === 'parent';
});

$all_ranks = db()->query('SELECT kyu_dan FROM ranks ORDER BY rank_order')->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Roster';
include __DIR__ . '/../includes/header.php';

function student_row($s, $id_col = true) {
    $att_txt     = $s['last_attended'] ? date('M j, Y', strtotime($s['last_attended'])) : 'Never';
    $search_name = strtolower($s['last_name'] . ' ' . $s['first_name'] . ' ' . $s['first_name'] . ' ' . $s['last_name']);
    $status = $s['active'] ? 'active' : 'inactive';
    $login  = $s['user_role'] ? 'yes' : 'no';
    echo '<tr'
       . ' data-name="'          . htmlspecialchars($search_name)               . '"'
       . ' data-status="'        . $status                                       . '"'
       . ' data-login="'         . $login                                        . '"'
       . ' data-rank="'          . htmlspecialchars($s['kyu_dan'] ?? '')         . '"'
       . ' data-waiver="'        . ($s['injury_waiver'] ? 'yes' : 'no')         . '"'
       . ' data-last-attended="' . htmlspecialchars($s['last_attended'] ?? '')   . '"'
       . '>';
    echo '<td class="fw-semibold"><a href="../instructor/student_profile.php?id=' . $s['id'] . '" class="text-decoration-none">' . htmlspecialchars($s['last_name'].', '.$s['first_name']) . '</a></td>';
    echo '<td>' . htmlspecialchars($s['kyu_dan'] ?? '—') . '</td>';
    echo '<td>' . ($s['injury_waiver']
        ? '<span class="text-success">✓</span>'
        : '<span class="text-danger">✗</span>') . '</td>';
    echo '<td>' . $att_txt . '</td>';
    echo '<td>';
    if ($s['active'])
        echo '<span class="badge bg-success" data-bs-toggle="tooltip"'
           . ' title="Active: attended class in the last 3 months">Active</span>';
    else
        echo '<span class="badge bg-secondary" data-bs-toggle="tooltip"'
           . ' title="Inactive: no attendance in the last 3 months">Inactive</span>';
    if ($s['active_override'] !== null)
        echo ' <span class="badge bg-warning text-dark" data-bs-toggle="tooltip"'
           . ' title="Override: active/inactive status manually set by admin">Override</span>';
    echo '</td>';
    echo '</tr>';
}

function student_table($rows, $empty_msg) {
    if (empty($rows)) {
        echo '<p class="p-3 text-muted mb-0">' . $empty_msg . '</p>';
        return;
    }
    echo '<table class="table table-sm table-hover mb-0" style="table-layout:fixed;width:100%">';
    echo '<colgroup>
            <col style="width:28%">
            <col style="width:20%">
            <col style="width:14%">
            <col style="width:22%">
            <col style="width:16%">
          </colgroup>';
    echo '<thead class="table-light"><tr>
            <th>Name</th><th>Rank</th><th>Injury Waiver</th>
            <th>Last Attended</th><th>Status</th>
          </tr></thead><tbody>';
    foreach ($rows as $s) student_row($s);
    echo '</tbody></table>';
}
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Roster</h3>
    <a href="student_edit.php" class="btn btn-success btn-sm">+ New Student</a>
</div>
<div class="d-flex gap-2 align-items-center mb-4 flex-wrap">
    <input type="text" id="rosterSearch" class="form-control form-control-sm"
           placeholder="Search name…" style="width:180px" oninput="filterRoster()">
    <select id="filterStatus" class="form-select form-select-sm" style="width:130px" onchange="filterRoster()">
        <option value="">All Statuses</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
    <select id="filterLogin" class="form-select form-select-sm" style="width:140px" onchange="filterRoster()">
        <option value="">All Accounts</option>
        <option value="yes">Has Login</option>
        <option value="no">No Login</option>
    </select>
    <select id="filterRank" class="form-select form-select-sm" style="width:160px" onchange="filterRoster()">
        <option value="">All Ranks</option>
        <?php foreach ($all_ranks as $rk): ?>
            <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rk) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="filterWaiver" class="form-select form-select-sm" style="width:150px" onchange="filterRoster()">
        <option value="">All Waivers</option>
        <option value="yes">Waiver Signed</option>
        <option value="no">No Waiver</option>
    </select>
    <select id="filterAttendance" class="form-select form-select-sm" style="width:160px" onchange="filterRoster()">
        <option value="">Any Attendance</option>
        <option value="30">Last 30 Days</option>
        <option value="90">Last 90 Days</option>
        <option value="year">This Year</option>
        <option value="never">Never Attended</option>
    </select>
</div>

<!-- Instructors -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Instructors
        <span class="badge bg-primary ms-2" id="count-instructors"><?= count($instructors) ?></span>
    </div>
    <div class="card-body p-0">
        <?php student_table($instructors, 'No instructors on roster.'); ?>
    </div>
</div>

<!-- Parents -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Parents
        <span class="badge bg-primary ms-2" id="count-parents"><?= count($parents) ?></span>
    </div>
    <div class="card-body p-0">
        <?php student_table($parents, 'No parents on roster.'); ?>
    </div>
</div>

<!-- Students -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Students
        <span class="badge bg-primary ms-2" id="count-students"><?= count($students) ?></span>
    </div>
    <div class="card-body p-0">
        <?php student_table($students, 'No students on roster.'); ?>
    </div>
</div>

<!-- Guests -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Guests
        <span class="badge bg-primary ms-2" id="count-guests"><?= count($guests) ?></span>
    </div>
    <div class="card-body p-0">
        <?php student_table($guests, 'No guests on roster.'); ?>
    </div>
</div>

<script>
function filterRoster() {
    var q      = document.getElementById('rosterSearch').value.toLowerCase().trim();
    var status = document.getElementById('filterStatus').value;
    var login  = document.getElementById('filterLogin').value;
    var rank   = document.getElementById('filterRank').value;
    var waiver = document.getElementById('filterWaiver').value;
    var att    = document.getElementById('filterAttendance').value;
    var now    = new Date();

    document.querySelectorAll('tbody tr[data-name]').forEach(function(row) {
        var attMatch = true;
        if (att) {
            var lastAtt = row.dataset.lastAttended;
            if (att === 'never') {
                attMatch = !lastAtt;
            } else if (!lastAtt) {
                attMatch = false;
            } else {
                var d = new Date(lastAtt);
                if (att === '30')  { var c = new Date(); c.setDate(c.getDate() - 30);  attMatch = d >= c; }
                if (att === '90')  { var c = new Date(); c.setDate(c.getDate() - 90);  attMatch = d >= c; }
                if (att === 'year') { attMatch = d.getFullYear() === now.getFullYear(); }
            }
        }
        var match = (!q      || row.dataset.name.includes(q))
                 && (!status || row.dataset.status === status)
                 && (!login  || row.dataset.login  === login)
                 && (!rank   || row.dataset.rank   === rank)
                 && (!waiver || row.dataset.waiver  === waiver)
                 && attMatch;
        row.style.display = match ? '' : 'none';
    });
    ['instructors','parents','students','guests'].forEach(function(key) {
        var badge = document.getElementById('count-' + key);
        if (!badge) return;
        var count = 0;
        badge.closest('.card').querySelectorAll('tbody tr[data-name]').forEach(function(r) {
            if (r.style.display !== 'none') count++;
        });
        badge.textContent = count;
    });
}
</script>

<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
