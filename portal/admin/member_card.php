<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('student', 'parent', 'instructor', 'admin');

$student_id = (int)($_GET['student_id'] ?? 0);
if (!$student_id) { header('Location: ../instructor/students.php'); exit; }

// Students/parents may only view their own card or a linked child's — instructors/admins may view any.
$role = $_SESSION['role'] ?? '';
if (in_array($role, ['student', 'parent'], true)) {
    $allowed_ids = [];
    $own_stmt = db()->prepare('SELECT id FROM students WHERE user_id = ?');
    $own_stmt->execute([current_user_id()]);
    if ($own_row = $own_stmt->fetch()) {
        $own_sid = (int)$own_row['id'];
        $allowed_ids[] = $own_sid;
        $ch_stmt = db()->prepare('SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?');
        $ch_stmt->execute([$own_sid]);
        foreach ($ch_stmt->fetchAll() as $r) $allowed_ids[] = (int)$r['child_student_id'];
    }
    if (!in_array($student_id, $allowed_ids, true)) {
        header('Location: ' . dashboard_url($role)); exit;
    }
}

$sq = db()->prepare('SELECT first_name, last_name, registration_date, student_type FROM students WHERE id = ?');
$sq->execute([$student_id]);
$student = $sq->fetch();
if (!$student) { header('Location: ../instructor/students.php'); exit; }

$rq = db()->prepare(
    'SELECT r.kyu_dan, r.name AS rank_name
     FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ?
     ORDER BY r.rank_order DESC LIMIT 1'
);
$rq->execute([$student_id]);
$rank = $rq->fetch();

// [$bg, $fg, $border, $stripe|null, $mask_stripe — cover stripe behind text?]
$belt_colors = [
    '10th Kyu' => ['#fff',    '#000', '#000',    '#000', true],   // white + black stripe (mask under text)
    '9th Kyu'  => ['#ffe066', '#000', '#ccaa00', '#fff', false],  // yellow + white stripe (passes through text)
    '8th Kyu'  => ['#ffe066', '#000', '#ccaa00', null, false],  // yellow
    '7th Kyu'  => ['#ff9800', '#000', '#cc7000', null, false],  // orange
    '6th Kyu'  => ['#9c27b0', '#fff', '#6a1b9a', null, false],  // purple
    '5th Kyu'  => ['#1565c0', '#fff', '#0d47a1', null, false],  // blue
    '4th Kyu'  => ['#388e3c', '#fff', '#2e7d32', null, false],  // green
    '3rd Kyu'  => ['#5d4037', '#fff', '#4e342e', null, false],  // brown
    '2nd Kyu'  => ['#5d4037', '#fff', '#4e342e', null, false],  // brown
    '1st Kyu'  => ['#5d4037', '#fff', '#4e342e', null, false],  // brown
    '1st Dan'  => ['#111',    '#fff', '#000',    null, false],  // black
    '2nd Dan'  => ['#111',    '#fff', '#000',    null, false],  // black
    '3rd Dan'  => ['#111',    '#fff', '#000',    null, false],  // black
];
$kyu = $rank['kyu_dan'] ?? '';
[$bg, $fg, $border, $stripe, $mask] = $belt_colors[$kyu] ?? ['#eee', '#000', '#999', null, true];

// --belt-bg: belt color to mask stripe under text; transparent lets stripe show through
$text_bg = ($stripe !== null && $mask) ? $bg : 'transparent';
$badge_style = "--belt-bg:$text_bg;"
             . ($stripe !== null ? "--stripe-color:$stripe;" : '')
             . "background:$bg;color:$fg;border:3px solid $border;";

$member_since = $student['registration_date']
    ? date('M Y', strtotime($student['registration_date']))
    : 'N/A';

$role_label = in_array($student['student_type'], ['instructor', 'admin']) ? 'Instructor' : 'Student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Member Card — <?= hn($student['first_name'] . ' ' . $student['last_name']) ?></title>
<style nonce="<?= csp_nonce() ?>">
@font-face {
    font-family: 'Albertus Extra Bold';
    src: url('assets/albertusextrabold_regular.ttf') format('truetype');
    font-weight: normal;
    font-style: normal;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: #888;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 30px 1rem;
    font-family: 'Times New Roman', Times, serif;
    color: #000;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}

.toolbar { margin-bottom: 20px; }
.toolbar button {
    padding: 8px 22px; border-radius: 4px; font-size: .9rem; cursor: pointer;
    border: none; background: #7a1a1a; color: #fff; font-weight: 600;
}
.toolbar button:hover { background: #5c1010; }

/*
 * Outer card — white with no visible border; padding is the white gap before the inner line.
 * At screen scale (3×), 0.375in = 3 × 0.125in so the gap is proportionally 1/8 in at print.
 */
.member-card {
    width: calc(100% - 2rem);
    max-width: 9.9in;
    height: 6.23in;
    background: #fff;
    border: 2px solid #000;
    user-select: none;
    -webkit-user-select: none;
    border-radius: 0.3in;
    padding: 0.375in;
    display: flex;
    flex-direction: column;
    box-shadow: 0 4px 24px rgba(0,0,0,.55);
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}

/* Inner area — the black line border is here, contents live inside */
.card-content {
    flex: 1;
    border: 2px solid #000;
    border-radius: 0;
    padding: 0.32in 0.42in 0.28in;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

/* ── Top: flag + title ── */
.card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.card-flag svg {
    display: block;
    width: 1.90in;
    height: 1.90in;
    flex-shrink: 0;
}
/* All three title lines: Albertus Extra Bold, centered */
.card-title {
    font-family: 'Albertus Extra Bold', 'Albertus MT', 'Albertus', serif;
    color: #000;
    line-height: 1.1;
    text-align: center;
}
.card-shotokan {
    font-size: 2.42rem;
    letter-spacing: 0.06em;
}
.card-karate {
    font-size: 3.92rem;
    letter-spacing: 0.03em;
    line-height: 0.95;
}
.card-subtitle {
    font-size: 2.42rem;
    margin-top: 4px;
}

/* ── Name + role (no italics) ── */
.card-name-block {
    text-align: center;
    color: #000;
}
.card-name {
    font-size: 3.6rem;
    font-weight: bold;
    line-height: 1.1;
}
.card-role {
    font-size: 2.11rem;
    font-weight: bold;
}

/* ── Bottom: signature + rank ── */
.card-bottom {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}
.sig-block {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-width: 2.4in;
}
.sig-img {
    display: block;
    height: 1.0in;
    width: auto;
    margin-bottom: 2px;
}
.sig-line {
    border-top: 2px solid #000;
    margin-bottom: 6px;
}
.sig-label {
    font-size: 1.73rem;
    font-weight: bold;
    color: #000;
}
.card-right {
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.rank-badge {
    font-size: 2.4rem;
    font-weight: bold;
    padding: 5px 28px;
    border-radius: 60px;
    display: inline-block;
    white-space: nowrap;
    text-align: center;
    position: relative;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}
/* Full-width stripe line; text span masks it with the belt color */
.rank-badge::before {
    content: '';
    position: absolute;
    left: 0; right: 0;
    top: 50%;
    transform: translateY(-50%);
    height: 11px;
    background: var(--stripe-color, transparent);
}
.badge-text {
    position: relative;
    background: var(--belt-bg);
    padding: 0;
}
.card-since {
    font-size: 1.73rem;
    font-weight: bold;
    color: #000;
    white-space: nowrap;
}
.card-location {
    font-size: 1.73rem;
    font-weight: bold;
    color: #000;
    white-space: nowrap;
}

/* ── Print: scale the screen card down to credit-card size (3.375 × 2.125 in) ── */
@media print {
    body { background: none; padding: 0; margin: 0; }
    .toolbar { display: none; }
    .member-card {
        width: 9.9in;
        max-width: 9.9in;
        box-shadow: none;
        zoom: 0.341;
    }
}
</style>
</head>
<body>

<div class="toolbar">
    <button id="printCardBtn">Print Card</button>
</div>
<script nonce="<?= csp_nonce() ?>">
document.getElementById('printCardBtn').addEventListener('click', function() { window.print(); });
</script>

<div class="member-card">
<div class="card-content">

    <div class="card-top">
        <div class="card-flag">
            <!-- r_red = 2/3 × r_black (24) = 16; cy = top_of_black (2) + r_red (16) = 18 -->
            <svg viewBox="0 0 52 52" xmlns="http://www.w3.org/2000/svg">
                <circle cx="26" cy="26" r="24" fill="#fff" stroke="#222" stroke-width="1.5"/>
                <circle cx="26" cy="18" r="16" fill="#FF0000"/>
            </svg>
        </div>
        <div class="card-title">
            <div class="card-shotokan">Shotokan</div>
            <div class="card-karate">KARATE</div>
            <div class="card-subtitle">and Self-defense</div>
        </div>
    </div>

    <div class="card-name-block">
        <div class="card-name"><?= hn($student['first_name'] . ' ' . $student['last_name']) ?></div>
        <div class="card-role"><?= $role_label ?></div>
    </div>

    <div class="card-bottom">
        <div class="sig-block">
            <?php if (file_exists(__DIR__ . '/assets/signature.png')): ?>
                <img src="assets/signature.png" class="sig-img" alt="Signature" draggable="false">
            <?php endif; ?>
            <div class="sig-line"></div>
            <div class="sig-label">Instructor Signature</div>
        </div>
        <div class="card-right">
            <?php if ($rank): ?>
            <div class="rank-badge" style="<?= htmlspecialchars($badge_style) ?>"><span class="badge-text"><?= htmlspecialchars($rank['kyu_dan']) ?></span></div>
            <?php endif; ?>
            <div class="card-since">Member since <?= htmlspecialchars($member_since) ?></div>
            <div class="card-location">Orem, Utah USA</div>
        </div>
    </div>

</div>
</div>

</body>
</html>
