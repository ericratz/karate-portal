<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_role('parent', 'instructor', 'admin');
function fmt_phone(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) === 10 ? substr($d,0,3).'-'.substr($d,3,3).'-'.substr($d,6) : $p; }

$user_id = current_user_id();
$error   = '';

// Build the list of student IDs this parent may view/sign for
$allowed_ids = [];
$own_stmt = db()->prepare('SELECT id FROM students WHERE user_id = ?');
$own_stmt->execute([$user_id]);
if ($own_row = $own_stmt->fetch()) {
    $own_sid = (int)$own_row['id'];
    $allowed_ids[] = $own_sid;
    $ch_stmt = db()->prepare('SELECT child_student_id FROM student_guardians WHERE parent_student_id = ?');
    $ch_stmt->execute([$own_sid]);
    foreach ($ch_stmt->fetchAll() as $r) $allowed_ids[] = (int)$r['child_student_id'];
}

$student_id = get_int('student_id') ?: post_int('student_id');
if (!$student_id || !in_array($student_id, $allowed_ids, true)) {
    header('Location: index.php');
    exit;
}

// Load the student record
$student = db()->prepare('SELECT * FROM students WHERE id = ?');
$student->execute([$student_id]);
$student = $student->fetch();
if (!$student) { header('Location: index.php'); exit; }

$signed = (bool)$student['injury_waiver'];
$submission = null;
if ($signed) {
    $stmt = db()->prepare(
        'SELECT * FROM injury_waiver_submissions WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 1'
    );
    $stmt->execute([$student_id]);
    $submission = $stmt->fetch();
}

// Determine minor status — computed before the POST handler below, which
// falls back to this when the submitted date_of_birth is empty.
$is_minor = false;
if (!empty($student['date_of_birth'])) {
    $is_minor = (new DateTime($student['date_of_birth']))->diff(new DateTime())->y < 18;
}

// Handle form submission
if (!$signed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $print_name    = trim(post_str('print_name'));
    $signature     = trim(post_str('signature'));
    $signed_date   = trim(post_str('signed_date', date('Y-m-d')));
    $guardian_sig  = trim(post_str('guardian_signature'));
    $guardian_date = trim(post_str('guardian_signed_date')) ?: null;
    $dob           = trim(post_str('date_of_birth'));
    $cell          = trim(post_str('cell_phone'));
    $home          = trim(post_str('home_phone'));
    $email         = trim(post_str('email'));
    $street        = trim(post_str('street_address'));
    $csz           = trim(post_str('city_state_zip'));
    $mail_addr     = trim(post_str('mailing_address'));
    $mail_csz      = trim(post_str('mailing_city_state_zip'));
    $agreed        = isset($_POST['i_agree']);

    $dob_check = !empty($dob) ? (new DateTime($dob))->diff(new DateTime())->y < 18 : $is_minor;
    if (!$print_name) {
        $error = 'Please print the student\'s name.';
    } elseif (!$dob_check && !$signature) {
        $error = 'Please provide the student\'s signature.';
    } elseif ($dob_check && !$guardian_sig) {
        $error = 'A parent or guardian signature is required for minors.';
    } elseif (!$agreed) {
        $error = 'You must check the agreement box to submit.';
    } elseif (!$cell || !$email || !$street || !$csz) {
        $error = 'Cell phone, email, street address, and city/state/ZIP are required.';
    } else {
        $db = db();
        $db->prepare(
            'INSERT INTO injury_waiver_submissions
             (student_id, print_name, signature, signed_date,
              guardian_signature, guardian_signed_date,
              date_of_birth, cell_phone, home_phone, email,
              street_address, city_state_zip,
              mailing_address, mailing_city_state_zip, ip_address)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $student_id, $print_name, $signature, $signed_date,
            $guardian_sig ?: null, $guardian_date,
            $dob ?: null, $cell, $home ?: null, $email,
            $street, $csz,
            $mail_addr ?: null, $mail_csz ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $db->prepare('UPDATE students SET injury_waiver = 1, injury_waiver_date = ? WHERE id = ?')
           ->execute([$signed_date, $student_id]);

        header('Location: index.php?student_id=' . $student_id . '&waiver=signed');
        exit;
    }
}

// Data source for pre-filling
$d = $submission ?: [];
$s = $student;
function wv(array $sub, string $key, string $fb = ''): string {
    return htmlspecialchars($sub[$key] ?? $fb);
}

$page_title = 'Waiver — ' . $student['first_name'] . ' ' . $student['last_name'];
include __DIR__ . '/../includes/header.php';
?>

<style nonce="<?= csp_nonce() ?>">
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
.w-input:focus          { border-bottom-color: #6f42c1; }
.w-input[readonly]      { cursor: default; }
.w-sig                  { font-family: cursive; font-size: 1.15rem; }
.w-static               { border-bottom: 1.5px solid #444; padding: 3px 2px; font-size: .95rem; min-height: 1.8rem; }
</style>

<div class="waiver-wrap">

<?php if ($error): ?>
    <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($signed): ?>
<div class="alert alert-success mb-3">
    This waiver for <strong><?= hn($student['first_name'] . ' ' . $student['last_name']) ?></strong>
    was signed on <strong><?= date('d M Y', strtotime($student['injury_waiver_date'])) ?></strong>.
    The waiver is on file — no further action is needed.
</div>
<?php endif; ?>

<?php if (!$signed): ?><form method="post"><?= csrf_input() ?>
<input type="hidden" name="student_id" value="<?= $student_id ?>"><?php endif; ?>

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
               value="<?= $signed ? wv($d,'print_name') : htmlspecialchars(trim(($s['first_name']??'').' '.($s['last_name']??''))) ?>"
               <?= $signed ? 'readonly' : 'required' ?>>

        <!-- Signature + Date (adults only) -->
        <?php if (!$is_minor || $signed): ?>
        <div class="row g-4 align-items-end mt-1" <?= $is_minor && $signed && empty($d['signature']) ? 'style="display:none"' : '' ?>>
            <div class="col">
                <span class="w-label">X &nbsp; Student's signature<?= !$signed ? ' <span style="font-size:.72rem;color:#999">(type full name — constitutes electronic signature)</span>' : '' ?></span>
                <input type="text" name="signature" class="w-input w-sig"
                       value="<?= wv($d,'signature') ?>"
                       <?= $signed ? 'readonly' : (!$is_minor ? 'required placeholder="Type full name to sign"' : 'placeholder="Type full name to sign"') ?>>
            </div>
            <div class="col-auto" style="min-width:160px">
                <span class="w-label">Date</span>
                <?php if ($signed): ?>
                    <div class="w-static"><?= !empty($d['signed_date']) ? date('d M Y', strtotime($d['signed_date'])) : ($student['injury_waiver_date'] ? date('d M Y', strtotime($student['injury_waiver_date'])) : '') ?></div>
                <?php else: ?>
                    <input type="date" name="signed_date" class="w-input" value="<?= date('Y-m-d') ?>" <?= !$is_minor ? 'required' : '' ?>>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Guardian Signature + Date -->
        <div class="row g-4 align-items-end mt-1">
            <div class="col">
                <span class="w-label">X &nbsp; Signature of parent or guardian<?= $is_minor && !$signed ? ' <span style="font-size:.72rem;color:#999">(required for minors)</span>' : ' (if under 21 years of age)' ?></span>
                <input type="text" name="guardian_signature" class="w-input w-sig"
                       value="<?= wv($d,'guardian_signature') ?>"
                       <?= $signed ? 'readonly' : ($is_minor ? 'required placeholder="Guardian full name"' : 'placeholder="Guardian full name (if applicable)"') ?>>
            </div>
            <div class="col-auto" style="min-width:160px">
                <span class="w-label">Date</span>
                <?php if ($signed): ?>
                    <div class="w-static"><?= !empty($d['guardian_signed_date']) ? date('d M Y', strtotime($d['guardian_signed_date'])) : '' ?></div>
                <?php else: ?>
                    <input type="date" name="guardian_signed_date" class="w-input" <?= $is_minor ? 'required' : '' ?>>
                <?php endif; ?>
            </div>
        </div>

        <!-- Date of Birth -->
        <span class="w-label">Date of Birth</span>
        <?php if ($signed): ?>
            <div class="w-static" style="max-width:220px"><?= !empty($d['date_of_birth']) ? date('d M Y', strtotime($d['date_of_birth'])) : '' ?></div>
        <?php else: ?>
            <input type="date" name="date_of_birth" class="w-input" style="max-width:220px"
                   value="<?= htmlspecialchars($s['date_of_birth'] ?? '') ?>">
        <?php endif; ?>

        <!-- Cell Phone -->
        <span class="w-label">Cell Phone Number</span>
        <input type="tel" name="cell_phone" class="w-input" style="max-width:280px"
               value="<?= $signed ? htmlspecialchars(fmt_phone($d['cell_phone'] ?? '')) : htmlspecialchars($s['phone'] ?? '') ?>"
               <?= $signed ? 'readonly' : 'required' ?>>

        <!-- Home Phone -->
        <span class="w-label">Home Phone Number (if different)</span>
        <input type="tel" name="home_phone" class="w-input" style="max-width:280px"
               value="<?= $signed ? htmlspecialchars(fmt_phone($d['home_phone'] ?? '')) : '' ?>"
               <?= $signed ? 'readonly' : '' ?>>

        <!-- Email -->
        <span class="w-label">Email Address</span>
        <input type="email" name="email" class="w-input"
               value="<?= $signed ? wv($d,'email') : htmlspecialchars($s['email'] ?? '') ?>"
               <?= $signed ? 'readonly' : 'required' ?>>

        <!-- Street Address -->
        <span class="w-label">Local Street Address</span>
        <input type="text" name="street_address" class="w-input"
               value="<?= wv($d,'street_address') ?>"
               <?= $signed ? 'readonly' : 'required' ?>>

        <!-- City State ZIP -->
        <span class="w-label">City, State, ZIP</span>
        <input type="text" name="city_state_zip" class="w-input" style="max-width:420px"
               value="<?= wv($d,'city_state_zip') ?>"
               <?= $signed ? 'readonly' : 'required' ?>>

        <!-- Mailing Address -->
        <span class="w-label">Mailing Address (if different)</span>
        <input type="text" name="mailing_address" class="w-input"
               value="<?= wv($d,'mailing_address') ?>"
               <?= $signed ? 'readonly' : '' ?>>

        <!-- Mailing City State ZIP -->
        <span class="w-label">City, State, ZIP</span>
        <input type="text" name="mailing_city_state_zip" class="w-input" style="max-width:420px"
               value="<?= wv($d,'mailing_city_state_zip') ?>"
               <?= $signed ? 'readonly' : '' ?>>

        <?php if (!$signed): ?>
        <hr class="mt-4 mb-3">
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="i_agree" id="i_agree" required>
            <label class="form-check-label" for="i_agree">
                I have read and fully understand this agreement and voluntarily agree to be bound by its terms.
            </label>
        </div>
        <button type="submit" class="btn btn-primary px-4 mb-2">Submit Signed Waiver</button>
        <?php endif; ?>

    </div>
</div>

<?php if (!$signed): ?></form><?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
