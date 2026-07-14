<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$student_id = get_int('student_id');
$rank_id    = get_int('rank_id');
if (!$student_id || !$rank_id) { header('Location: ../student/index.php'); exit; }

// Non-instructors may only view their own or their children's certificates
if (!has_role('instructor', 'admin')) {
    $own_q = db()->prepare('SELECT id FROM students WHERE user_id = ? LIMIT 1');
    $own_q->execute([current_user_id()]);
    $own_id = (int)$own_q->fetchColumn();
    $allowed = $own_id ? [$own_id] : [];
    if ($own_id) {
        $ch_q = db()->prepare('SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?');
        $ch_q->execute([$own_id]);
        $allowed = array_merge($allowed, $ch_q->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!in_array($student_id, array_map('intval', $allowed))) {
        header('Location: ../student/index.php'); exit;
    }
}

$sq = db()->prepare('SELECT first_name, last_name FROM students WHERE id = ?');
$sq->execute([$student_id]);
$student = $sq->fetch();
if (!$student) { header('Location: ../instructor/student_profile.php'); exit; }

$rq = db()->prepare(
    'SELECT r.name, r.kyu_dan, sr.achieved_date, sr.cert_number
     FROM student_ranks sr JOIN ranks r ON r.id = sr.rank_id
     WHERE sr.student_id = ? AND sr.rank_id = ?'
);
$rq->execute([$student_id, $rank_id]);
$rank = $rq->fetch();
if (!$rank) { header('Location: ../instructor/student_profile.php'); exit; }

$student_name = ucwords(strtolower($student['first_name'] . ' ' . $student['last_name']));
$kyu_dan      = (string)$rank['kyu_dan'];
$achieved     = date('j M Y', strtotime($rank['achieved_date']));
$cert_number  = $rank['cert_number'] ?? '';
$pdf_filename = 'Certificate_' . preg_replace('/[^A-Za-z0-9_]/', '_', $student_name) . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $kyu_dan) . '.pdf';

preg_match('/^(.+?)\s+(Kyu|Dan)$/i', $kyu_dan, $m);
$rank_num    = $m[1] ?? $kyu_dan;
$rank_suffix = $m[2] ?? '';

$has_signature = file_exists(__DIR__ . '/assets/signature.png');
$bg_v = filemtime(__DIR__ . '/assets/Certificate of Rank Template.jpg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate — <?= htmlspecialchars($student_name) ?></title>
<style nonce="<?= csp_nonce() ?>">
@font-face {
    font-family: 'Albertus Extra Bold';
    src: url('assets/albertusextrabold_regular.ttf') format('truetype');
    font-weight: normal;
    font-style: normal;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: #666;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 100vh;
    padding: 20px;
    font-family: 'Times New Roman', Times, serif;
}

.toolbar { margin-bottom: 16px; }
.toolbar button {
    padding: 8px 22px;
    border-radius: 4px;
    font-family: sans-serif;
    font-size: .9rem;
    cursor: pointer;
    border: none;
    background: #7a1a1a;
    color: #fff;
    font-weight: 600;
}
.toolbar button:hover    { background: #5c1010; }
.toolbar button:disabled { background: #999; cursor: default; }

/* Certificate — matches image aspect ratio 2552:3336 */
.cert-page {
    position: relative;
    width: 8.5in;
    height: 11.05in;
    overflow: hidden;
    box-shadow: 0 6px 30px rgba(0,0,0,.6);
    font-family: 'Times New Roman', Times, serif;
    user-select: none;
    -webkit-user-select: none;
}
.cert-page img { pointer-events: none; }
.cert-bg {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    object-fit: fill;
    display: block;
}
.cert-overlay {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
}

/* Shared left/right margins for all centered fields */
.field {
    position: absolute;
    left: 13%;
    width: 74%;
    text-align: center;
    color: #111;
    line-height: 1.3;
    font-weight: bold;
}

.org-name {
    top: 10%;
    font-size: 1.52rem;
    font-family: 'Albertus Extra Bold', 'Albertus MT', 'Albertus', serif;
    letter-spacing: 0.03em;
}

.cert-title {
    top: 13%;
    font-size: 2.9rem;
    font-weight: bold;
}

.certifies-label {
    top: 20.5%;
    font-size: 1.28rem;
    font-style: italic;
}

/* Name underline — ~1in shorter on each side than the field */
.name-wrapper { top: 22.4%; }
.name-field {
    display: block;
    width: calc(100% - 2in);
    margin: 0 auto;
    font-size: 1.33rem;
    font-weight: bold;
    font-style: normal;
    border-bottom: 1.5px solid #111;
    text-align: center;
    padding-bottom: 3px;
    line-height: 1.7;
}

/* Body paragraph — 3 lines at this size + width */
.body-text {
    top: 26.3%;
    font-size: 1.28rem;
    font-style: italic;
    line-height: 1.45;
}

.as-label {
    top: 34.63%;   /* body end (26.3 + 3×2.78) + one line-height gap */
    font-size: 1.28rem;
    font-style: italic;
}

/* Rank: underlined number + Kyu/Dan */
.rank-line {
    top: 37.43%;
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 10px;
    font-size: 1.33rem;
}
.rank-num-field {
    border-bottom: 1.5px solid #111;
    padding: 0 24px 3px;
    min-width: 80px;
    text-align: center;
    font-weight: bold;
    font-style: normal;
}
.rank-suffix {
    font-style: italic;
    padding-bottom: 3px;
}

/* JKA / date line */
.jka-line {
    top: 40.13%;
    font-size: 1.28rem;
    font-style: italic;
    line-height: 1.9;
}
.date-field {
    border-bottom: 1.5px solid #111;
    padding: 0 14px 3px;
    display: inline-block;
    font-size: 1.33rem;
    font-weight: bold;
    font-style: normal;
}

/* Footer — flex row so sig and number share the same top */
.cert-footer {
    position: absolute;
    top: 43.23%;
    left: 13%;
    width: 74%;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    color: #111;
    font-family: 'Times New Roman', Times, serif;
}

.sig-block {
    width: 44%;
    text-align: left;
}
.sig-img {
    display: block;
    height: 0.55in;
    width: auto;
    margin-bottom: 2px;
}
.sig-line {
    border-top: 1.5px solid #111;
    margin-bottom: 4px;
}
.sig-name {
    font-size: 0.95rem;
    font-weight: bold;
}

.number-block {
    width: 44%;
    text-align: left;
    font-size: 1.05rem;
    font-weight: bold;
    margin-top: -8px;
}
/* Spacer pushes number value down to match where sig-line appears */
.number-spacer {
    display: block;
    height: calc(0.55in - 18px);  /* sig-img height minus 22px nudge */
}
.number-value {
    display: block;
    border-bottom: 1.5px solid #111;
    padding: 0 14px 3px;
    font-weight: bold;
    font-style: normal;
    font-size: 1.33rem;
    margin-bottom: 4px;
    text-align: center;
}
.number-label {
    font-size: 0.95rem;
    font-weight: bold;
    text-align: left;
}
</style>
</head>
<body>

<div class="toolbar">
    <button id="downloadBtn">⬇ Download PDF</button>
</div>

<div class="cert-page" id="certificate">
    <img src="assets/Certificate of Rank Template.jpg?v=<?= $bg_v ?>" class="cert-bg" alt="">

    <div class="cert-overlay">

        <div class="field org-name">Shotokan Karate and Self-defense</div>

        <div class="field cert-title">Certificate of Rank</div>

        <div class="field certifies-label">This certifies that</div>

        <div class="field name-wrapper">
            <div class="name-field"><?= htmlspecialchars($student_name) ?></div>
        </div>

        <div class="field body-text">
            has completed the requirements for, and has hereby been<br>
            registered in accordance with the international ranking<br>
            standards recognized by Shotokan Karate and Self-defense,
        </div>

        <div class="field as-label">as</div>

        <div class="field rank-line">
            <span class="rank-num-field"><?= htmlspecialchars($rank_num) ?></span>
            <span class="rank-suffix"><?= htmlspecialchars($rank_suffix) ?></span>
        </div>

        <div class="field jka-line">
            of JKA (Japan Karate Association) Shotokan on
            <span class="date-field"><?= htmlspecialchars($achieved) ?></span>
        </div>

        <div class="cert-footer">
            <div class="sig-block">
                <?php if ($has_signature): ?>
                    <img src="assets/signature.png" class="sig-img" alt="Signature">
                <?php endif; ?>
                <div class="sig-line"></div>
                <div class="sig-name">Noji Ratzlaff, Instructor</div>
            </div>
            <div class="number-block">
                <?php if ($has_signature): ?>
                    <span class="number-spacer"></span>
                <?php endif; ?>
                <div class="number-value"><?= htmlspecialchars($cert_number) ?></div>
                <div class="number-label">Certificate Number</div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"
        integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H"
        crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"
        integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk"
        crossorigin="anonymous"></script>
<script nonce="<?= csp_nonce() ?>">
document.getElementById('downloadBtn').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Generating…';

    const el = document.getElementById('certificate');
    const W = 8.5, H = 11.05;

    document.fonts.ready.then(function () {
        html2canvas(el, { scale: 2, useCORS: true, allowTaint: true, logging: false }).then(function (canvas) {
            const { jsPDF } = window.jspdf;
            const pdf = new jsPDF({ unit: 'in', format: [W, H], orientation: 'portrait' });
            pdf.addImage(canvas.toDataURL('image/jpeg', 0.98), 'JPEG', 0, 0, W, H);
            pdf.save(<?= json_encode($pdf_filename) ?>);
            btn.disabled = false;
            btn.textContent = '⬇ Download PDF';
        });
    });
});
</script>

</body>
</html>
