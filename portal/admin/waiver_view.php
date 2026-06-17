<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('instructor', 'admin');
function fmt_phone(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) === 10 ? substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6) : $p; }

$student_id = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
if (!$student_id) { header('Location: students.php'); exit; }

$student = db()->prepare('SELECT * FROM students WHERE id = ?');
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: students.php'); exit; }

$msg   = '';
$error = '';

// Load existing digital submission
$submission = null;
$stmt = db()->prepare('SELECT * FROM injury_waiver_submissions WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 1');
$stmt->execute([$student_id]);
$submission = $stmt->fetch();

// Handle admin submission (admin only)
if (has_role('admin') && !$submission && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $print_name    = trim($_POST['print_name']    ?? '');
    $signature     = trim($_POST['signature']     ?? '');
    $signed_date   = trim($_POST['signed_date']   ?? date('Y-m-d'));
    $guardian_sig  = trim($_POST['guardian_signature']   ?? '');
    $guardian_date = trim($_POST['guardian_signed_date'] ?? '') ?: null;
    $dob           = trim($_POST['date_of_birth'] ?? '');
    $cell          = trim($_POST['cell_phone']    ?? '');
    $home          = trim($_POST['home_phone']    ?? '');
    $email         = trim($_POST['email']         ?? '');
    $street        = trim($_POST['street_address'] ?? '');
    $csz           = trim($_POST['city_state_zip'] ?? '');
    $mail_addr     = trim($_POST['mailing_address']     ?? '');
    $mail_csz      = trim($_POST['mailing_city_state_zip'] ?? '');

    if (!$print_name || !$signed_date) {
        $error = 'Printed name and date are required.';
    } else {
        db()->prepare(
            'INSERT INTO injury_waiver_submissions
             (student_id, print_name, signature, signed_date,
              guardian_signature, guardian_signed_date,
              date_of_birth, cell_phone, home_phone, email,
              street_address, city_state_zip,
              mailing_address, mailing_city_state_zip, ip_address)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $student_id, $print_name, $signature ?: $print_name, $signed_date,
            $guardian_sig ?: null, $guardian_date,
            $dob ?: null, $cell ?: null, $home ?: null, $email ?: null,
            $street ?: null, $csz ?: null,
            $mail_addr ?: null, $mail_csz ?: null,
            'admin-entry',
        ]);

        db()->prepare('UPDATE students SET injury_waiver = 1, injury_waiver_date = ? WHERE id = ?')
           ->execute([$signed_date, $student_id]);

        audit('update_student', 'student', $student_id, 'admin digitized paper waiver');

        // Reload
        header('Location: waiver_view.php?student_id=' . $student_id . '&saved=1');
        exit;
    }
}

$saved = isset($_GET['saved']);

// Re-fetch submission after possible save
$stmt->execute([$student_id]);
$submission = $stmt->fetch();

// Re-fetch student (injury_waiver may have just been updated)
$st2 = db()->prepare('SELECT * FROM students WHERE id = ?');
$st2->execute([$student_id]);
$student = $st2->fetch();

$d = $submission ?: [];
$s = $student;
function wv(array $sub, string $key, string $fb = ''): string {
    return htmlspecialchars($sub[$key] ?? $fb);
}

$has_submission = !empty($submission);
$is_admin = has_role('admin');

$page_title = 'Waiver — ' . $student['first_name'] . ' ' . $student['last_name'];
include __DIR__ . '/../includes/header.php';
?>

<style>
.waiver-wrap { max-width: 760px; }
.waiver-doc  { font-size: .92rem; line-height: 1.8; }
.waiver-doc h2 { font-size: 1.15rem; font-weight: 700; text-align: center; margin-bottom: 0; }
.waiver-doc h3 { font-size: 1rem;    font-weight: 700; text-align: center; margin-bottom: 1.2rem; }
.w-label { display: block; font-size: .9rem; color: #444; margin-bottom: 2px; margin-top: 1.4rem; }
[data-bs-theme="dark"] .w-label { color: #ccc; }
.w-input {
    display: block; width: 100%;
    border: none; border-bottom: 1.5px solid #444;
    border-radius: 0; background: transparent;
    padding: 3px 2px; font-size: .95rem;
    outline: none; color: inherit;
}
.w-input:focus     { border-bottom-color: #6f42c1; }
.w-input[readonly] { cursor: default; }
.w-sig             { font-family: cursive; font-size: 1.15rem; }
.w-static          { border-bottom: 1.5px solid #444; padding: 3px 2px; font-size: .95rem; min-height: 1.8rem; }
</style>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Waiver — <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h4>
    <?php if ($student['injury_waiver']): ?>
        <span class="badge bg-success">Signed <?= date('d M Y', strtotime($student['injury_waiver_date'])) ?></span>
    <?php endif; ?>
    <?php if ($has_submission && $submission['ip_address'] === 'admin-entry'): ?>
        <span class="badge bg-secondary">Admin entry</span>
    <?php elseif ($has_submission): ?>
        <span class="badge bg-info text-dark">Digital submission</span>
    <?php endif; ?>
</div>

<?php if ($saved): ?>
    <div class="alert alert-success mb-3">Waiver digitized and saved successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!$has_submission && !$is_admin): ?>
    <div class="alert alert-warning">No digital waiver submission on file for this student.</div>
<?php endif; ?>

<div class="waiver-wrap">

<?php if (!$has_submission && $is_admin): ?>
<div class="alert alert-info mb-3">
    No digital submission on file. Fill in the fields below from the physical waiver and click <strong>Save</strong>.
    Only <strong>Printed Name</strong> and <strong>Date</strong> are required — fill in as much as the paper copy shows.
</div>
<?php endif; ?>

<?php if ($has_submission || $is_admin): ?>

<?php if (!$has_submission): ?><form method="post">
<?= csrf_input() ?>
<input type="hidden" name="student_id" value="<?= $student_id ?>">
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body px-5 py-4 waiver-doc">

        <h2>Shotokan Karate Training Program</h2>
        <h3>Waiver of Legal Rights and Indemnification Agreement</h3>

        <p>The undersigned applicant for participation in the Shotokan Karate Training Program taught by Noji Ratzlaff and hosted by Center Stage at 575 South 1200 West, Orem, Utah (<strong><em>Karate Training</em></strong> herein refers collectively to karate training/practice, related physical conditioning, and self-defense instruction/activities or exercises as taught by Noji Ratzlaff and hosted by Center Stage at 575 South 1200 West, Orem, Utah.) agrees, represents, and warrants as follows:</p>

        <p><strong>1.</strong>&nbsp; Undersigned has prepared himself/herself mentally and physically for this Karate Training, is in adequate physical health, and has no material injuries, mental or physical conditions or impairments whatsoever that would prohibit, impair, or make difficult, inadvisable, dangerous, or physically harmful undersigned's participation in the Karate Training.</p>

        <p><strong>2.</strong>&nbsp; Undersigned has fully familiarized himself/herself with the curriculum and is fully aware of the nature of the rigorous physical activities that occur therein, and undersigned requests to participate in such Karate Training activities. Undersigned represents that he/she is capable of participating fully in such activities, and acknowledges that there is a risk of physical injury and possibility of death in participation in such activities. Undersigned acknowledges that he/she must judge his/her own mental and physical capabilities with respect to the Karate Training activities and should inform instructor immediately of any accident or injury or of undersigned's inability or unwillingness to participate fully in any such activity.</p>

        <p><strong>3.</strong>&nbsp; In consideration for the acceptance by Noji Ratzlaff and Center Stage of undersigned as participant in said Karate Training,</p>
        <p class="ms-4">(a) Undersigned fully assumes any and all risk of injury, accident, harm, death, or damage of any nature whatsoever that may accrue to or befall undersigned arising out of attendance at or participation in the Karate Training;</p>
        <p class="ms-4">(b) Undersigned waives any claim, demand, action, or cause of action that undersigned now has or may hereafter acquire against Noji Ratzlaff, Center Stage, or other Karate Training participants and their schools and instructors, arising out of attendance at or participation in the Karate Training. (Noji Ratzlaff, Center Stage, and other Karate Training participants and their schools and instructors are hereafter referred to as <em>said persons</em>.);</p>
        <p class="ms-4">(c) Undersigned releases the same said persons from any liability for such claim, demand, action, or cause of action, if brought by undersigned;</p>
        <p class="ms-4">(d) Undersigned covenants that he/she will not sue or commence any legal action proceedings whatsoever against any of the same said persons over any such claim, demand, action, or cause of action;</p>
        <p class="ms-4">(e) Undersigned agrees to indemnify and hold harmless Noji Ratzlaff, Center Stage, their assigns, family members, relatives, successors, interests, and contractors from any and all claims, demands, actions, liability, loss, expense, and/or attorneys' fees that may arise from or be incurred as a result of:</p>
        <p class="ms-5">(i) injury, damage, or death to undersigned's person or property during this Karate Training;</p>
        <p class="ms-5">(ii) injury, damage, or death to any other Karate Training participant's person or property arising or resulting from the acts or omissions of undersigned.</p>

        <p>Undersigned acknowledges that Noji Ratzlaff, Center Stage, other Karate Training participants, and others may not be insured (wholly or in part) against any claims or actions by undersigned (or others) arising out of undersigned's participation in Karate Training activities, and that Noji Ratzlaff and Center Stage could not and would not accept undersigned as a participant in this Karate Training were it not for the full assumption by undersigned of all risk of injury pertaining thereto and for undersigned's waiver, release, covenant not to sue, and indemnity and other agreements as set forth herein.</p>

        <p><strong>4.</strong>&nbsp; Undersigned acknowledges that it is undersigned's responsibility to provide full medical insurance for any injury that may befall undersigned, and undersigned represents that undersigned has obtained such medical insurance, which will cover 100% of any and all medical expenses, loss, damage, death, and/or disability resulting from any injury to undersigned in or at any Karate Training, or the undersigned will fully assume the risk of failing to procure such insurance.</p>
        <p>Undersigned acknowledges that his/her sole remedy in the event of injury, loss, or damage arising from any Karate Training shall be said medical insurance obtained by undersigned. Undersigned acknowledges that neither Noji Ratzlaff, Center Stage, nor their contractors, provide medical care, and that this Karate Training is or may be held in a vicinity where hospital or other medical services or facilities are not readily available. Undersigned will be responsible for ensuring that undersigned has a means of transportation, if necessary, to convey undersigned to a hospital or medical facility.</p>

        <p><strong>5.</strong>&nbsp; Although Noji Ratzlaff and Center Stage will or may request undersigned to execute other consent forms for other Karate Training programs as Noji Ratzlaff and Center Stage deem necessary, the representations, agreements, covenants, and indemnities herein of undersigned shall apply to all Karate Training programs attended by undersigned.</p>

        <p><strong>6.</strong>&nbsp; Undersigned agrees that the various provisions of this agreement are severable, and the invalidity or inapplicability of any provision hereof in this agreement shall be governed by the laws of the state in which this agreement is fully performed in said state. If, under the laws of said state, consents, waivers, releases, and/or agreements as set forth herein are required, as a condition of their enforceability, to be in a certain form or to contain special language, such special form or language is deemed incorporated by reference herein, and undersigned covenants that he/she would have executed, and will upon request of Noji Ratzlaff and Center Stage execute (with retroactive effect to the date hereof) an agreement pertaining to the subject matter hereof that contains such special form or language.</p>

        <p><strong>7.</strong>&nbsp; This agreement represents the complete embodiment of the understanding and agreements between Noji Ratzlaff, Center Stage, and undersigned, regarding the subject matter except in writing executed by undersigned and an authorized representative of Noji Ratzlaff and Center Stage.</p>

        <p><strong>8.</strong>&nbsp; Undersigned represents that he/she is not a minor or, if a minor, that undersigned has had undersigned's parent or legal guardian sign the parental consent and indemnity agreement of Noji Ratzlaff and Center Stage.</p>

        <p><strong>9. NOJI RATZLAFF AND CENTER STAGE SUGGEST THAT, IF UNDERSIGNED HAS ANY QUESTIONS OR RESERVATIONS ABOUT ANY OF THE FOREGOING, UNDERSIGNED SHOULD NOT EXECUTE THIS AGREEMENT UNTIL AFTER CONSULTING WITH AN ATTORNEY. UNDERSIGNED HAS EITHER CONSULTED AN ATTORNEY REGARDING THE CONTENTS OF THIS AGREEMENT OR DEEMS IT UNNECESSARY TO CONSULT SUCH ATTORNEY.</strong></p>

        <p><strong>10. UNDERSIGNED UNDERSTANDS THAT BY SIGNING THIS AGREEMENT, UNDERSIGNED IS GIVING UP HIS OR HER LEGAL RIGHTS AND LEGAL RIGHTS OF UNDERSIGNED'S HEIRS IN CASE OF INJURY, LOSS, DAMAGE, OR DEATH.</strong></p>

        <p>Undersigned represents that he/she has carefully read each and every one of the provisions hereof, fully understands each provision, and consents to be bound thereby.</p>
        <p>Undersigned acknowledges receipt of a copy of this agreement.</p>

        <hr class="my-4">

        <!-- Print Name -->
        <span class="w-label">Print your name (undersigned)</span>
        <input type="text" name="print_name" class="w-input"
               value="<?= $has_submission ? wv($d,'print_name') : htmlspecialchars(trim(($s['first_name']??'').' '.($s['last_name']??''))) ?>"
               <?= $has_submission ? 'readonly' : 'required' ?>>

        <!-- Signature + Date -->
        <div class="row g-4 align-items-end mt-1">
            <div class="col">
                <span class="w-label">X &nbsp; Your signature</span>
                <input type="text" name="signature" class="w-input w-sig"
                       value="<?= wv($d,'signature') ?>"
                       <?= $has_submission ? 'readonly' : 'placeholder="As written on the physical form"' ?>>
            </div>
            <div class="col-auto" style="min-width:180px">
                <span class="w-label">Date<?= !$has_submission ? ' <span style="color:#dc3545">*</span>' : '' ?></span>
                <?php if ($has_submission): ?>
                    <div class="w-static"><?= !empty($d['signed_date']) ? date('d M Y', strtotime($d['signed_date'])) : ($student['injury_waiver_date'] ? date('d M Y', strtotime($student['injury_waiver_date'])) : '') ?></div>
                <?php else: ?>
                    <input type="date" name="signed_date" class="w-input"
                           value="<?= htmlspecialchars($student['injury_waiver_date'] ?? date('Y-m-d')) ?>" required>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guardian Signature + Date -->
        <div class="row g-4 align-items-end mt-1">
            <div class="col">
                <span class="w-label">X &nbsp; Signature of parent or guardian (if you are under 21 years of age)</span>
                <input type="text" name="guardian_signature" class="w-input w-sig"
                       value="<?= wv($d,'guardian_signature') ?>"
                       <?= $has_submission ? 'readonly' : 'placeholder="Leave blank if not on physical form"' ?>>
            </div>
            <div class="col-auto" style="min-width:180px">
                <span class="w-label">Date</span>
                <?php if ($has_submission): ?>
                    <div class="w-static"><?= !empty($d['guardian_signed_date']) ? date('d M Y', strtotime($d['guardian_signed_date'])) : '' ?></div>
                <?php else: ?>
                    <input type="date" name="guardian_signed_date" class="w-input">
                <?php endif; ?>
            </div>
        </div>

        <!-- Date of Birth -->
        <span class="w-label">Date of Birth</span>
        <?php if ($has_submission): ?>
            <div class="w-static" style="max-width:220px"><?= !empty($d['date_of_birth']) ? date('d M Y', strtotime($d['date_of_birth'])) : '' ?></div>
        <?php else: ?>
            <input type="date" name="date_of_birth" class="w-input" style="max-width:220px"
                   value="<?= htmlspecialchars($s['date_of_birth'] ?? '') ?>">
        <?php endif; ?>

        <!-- Cell Phone -->
        <span class="w-label">Cell Phone Number</span>
        <input type="tel" name="cell_phone" class="w-input" style="max-width:280px"
               value="<?= $has_submission ? htmlspecialchars(fmt_phone($d['cell_phone'] ?? '')) : htmlspecialchars($s['phone'] ?? '') ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <!-- Home Phone -->
        <span class="w-label">Home Phone Number (if different)</span>
        <input type="tel" name="home_phone" class="w-input" style="max-width:280px"
               value="<?= $has_submission ? htmlspecialchars(fmt_phone($d['home_phone'] ?? '')) : '' ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <!-- Email -->
        <span class="w-label">Email Address</span>
        <input type="email" name="email" class="w-input"
               value="<?= $has_submission ? wv($d,'email') : htmlspecialchars($s['email'] ?? '') ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <!-- Street Address -->
        <span class="w-label">Local Street Address</span>
        <input type="text" name="street_address" class="w-input"
               value="<?= wv($d,'street_address') ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <!-- City State ZIP -->
        <span class="w-label">City, State, ZIP</span>
        <input type="text" name="city_state_zip" class="w-input" style="max-width:420px"
               value="<?= wv($d,'city_state_zip') ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <!-- Mailing Address -->
        <span class="w-label">Mailing Address (if different)</span>
        <input type="text" name="mailing_address" class="w-input"
               value="<?= wv($d,'mailing_address') ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <!-- Mailing City State ZIP -->
        <span class="w-label">City, State, ZIP</span>
        <input type="text" name="mailing_city_state_zip" class="w-input" style="max-width:420px"
               value="<?= wv($d,'mailing_city_state_zip') ?>"
               <?= $has_submission ? 'readonly' : '' ?>>

        <?php if (!$has_submission && $is_admin): ?>
        <hr class="mt-4 mb-3">
        <button type="submit" class="btn btn-primary px-4 mb-2">Save Waiver</button>
        <span class="text-muted small ms-2">Marked as admin entry in the audit log</span>
        <?php endif; ?>

    </div>
</div>

<?php if (!$has_submission): ?></form><?php endif; ?>

<?php if ($has_submission): ?>
<p class="text-muted small mt-2">
    Submitted <?= htmlspecialchars($submission['submitted_at']) ?>
    <?= $submission['ip_address'] === 'admin-entry' ? '· entered by admin' : '· IP: ' . htmlspecialchars($submission['ip_address'] ?? '—') ?>
</p>
<?php endif; ?>

<?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
