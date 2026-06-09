<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

$user_id = current_user_id();
$error   = '';

// Load student
$student = db()->prepare('SELECT * FROM students WHERE user_id = ?');
$student->execute([$user_id]);
$student = $student->fetch();

if (!$student) {
    header('Location: index.php');
    exit;
}

// Already signed — show read-only view
$signed = (bool)$student['injury_waiver'];
$submission = null;
if ($signed) {
    $stmt = db()->prepare('SELECT * FROM injury_waiver_submissions WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 1');
    $stmt->execute([$student['id']]);
    $submission = $stmt->fetch();
}

// ── Handle submission ────────────────────────────────────────
if (!$signed && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $agreed        = isset($_POST['i_agree']);

    if (!$print_name || !$signature) {
        $error = 'Please print your name and provide your signature.';
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
            $student['id'], $print_name, $signature, $signed_date,
            $guardian_sig ?: null, $guardian_date,
            $dob ?: null, $cell, $home ?: null, $email,
            $street, $csz,
            $mail_addr ?: null, $mail_csz ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Mark waiver complete on student record
        $db->prepare('UPDATE students SET injury_waiver = 1, injury_waiver_date = ? WHERE id = ?')
           ->execute([$signed_date, $student['id']]);

        header('Location: index.php?waiver=signed');
        exit;
    }
}

$page_title = 'Liability Waiver';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="mb-0">Liability Waiver</h4>
    <?php if ($signed): ?>
        <span class="badge bg-success ms-2">Signed <?= htmlspecialchars($student['injury_waiver_date']) ?></span>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($signed): ?>
<!-- ── SIGNED CONFIRMATION ── -->
<div class="alert alert-success">
    You signed this liability waiver on <strong><?= htmlspecialchars($student['injury_waiver_date'] ?? 'file') ?></strong>.
    Your waiver is on file — no further action is needed.
</div>
<?php if ($submission): ?>
<div class="card border-0 shadow-sm mb-4" style="max-width:760px">
    <div class="card-header bg-white fw-semibold">Your Submission</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Printed Name</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['print_name']) ?></dd>
            <dt class="col-sm-4">Signature</dt>
            <dd class="col-sm-8 fst-italic"><?= htmlspecialchars($submission['signature']) ?></dd>
            <dt class="col-sm-4">Signed Date</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['signed_date']) ?></dd>
            <?php if ($submission['guardian_signature']): ?>
            <dt class="col-sm-4">Guardian Signature</dt>
            <dd class="col-sm-8 fst-italic"><?= htmlspecialchars($submission['guardian_signature']) ?></dd>
            <?php endif; ?>
            <dt class="col-sm-4">Date of Birth</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['date_of_birth'] ?? '—') ?></dd>
            <dt class="col-sm-4">Cell Phone</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['cell_phone']) ?></dd>
            <dt class="col-sm-4">Email</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['email']) ?></dd>
            <dt class="col-sm-4">Address</dt>
            <dd class="col-sm-8">
                <?= htmlspecialchars($submission['street_address']) ?><br>
                <?= htmlspecialchars($submission['city_state_zip']) ?>
            </dd>
            <dt class="col-sm-4">Submitted</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($submission['submitted_at']) ?></dd>
        </dl>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── WAIVER FORM (always visible; submit disabled when already signed) ── -->
<div style="max-width:760px">

    <!-- Waiver document -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white text-center py-3">
            <h5 class="mb-1 fw-bold">Shotokan Karate Training Program</h5>
            <div class="fw-semibold">Liability Waiver and Indemnification Agreement</div>
        </div>
        <div class="card-body" style="font-size:.92rem;line-height:1.75">

<p>The undersigned applicant for participation in the Shotokan Karate Training Program taught by Noji Ratzlaff and hosted by Center Stage at 575 South 1200 West, Orem, Utah (Karate Training herein refers collectively to karate training/practice, related physical conditioning, and self-defense instruction/activities or exercises as taught by Noji Ratzlaff and hosted by Center Stage at 575 South 1200 West, Orem, Utah.) agrees, represents, and warrants as follows:</p>

<p><strong>1.</strong> Undersigned has prepared himself/herself mentally and physically for this Karate Training, is in adequate physical health, and has no material injuries, mental or physical conditions or impairments whatsoever that would prohibit, impair, or make difficult, inadvisable, dangerous, or physically harmful undersigned's participation in the Karate Training.</p>

<p><strong>2.</strong> Undersigned has fully familiarized himself/herself with the curriculum and is fully aware of the nature of the rigorous physical activities that occur therein, and undersigned requests to participate in such Karate Training activities. Undersigned represents that he/she is capable of participating fully in such activities, and acknowledges that there is a risk of physical injury and possibility of death in participation in such activities. Undersigned acknowledges that he/she must judge his/her own mental and physical capabilities with respect to the Karate Training activities and should inform instructor immediately of any accident or injury or of undersigned's inability or unwillingness to participate fully in any such activity.</p>

<p><strong>3.</strong> In consideration for the acceptance by Noji Ratzlaff and Center Stage of undersigned as participant in said Karate Training,</p>
<p class="ms-4">(a) Undersigned fully assumes any and all risk of injury, accident, harm, death, or damage of any nature whatsoever that may accrue to or befall undersigned arising out of attendance at or participation in the Karate Training;</p>
<p class="ms-4">(b) Undersigned waives any claim, demand, action, or cause of action that undersigned now has or may hereafter acquire against Noji Ratzlaff, Center Stage, or other Karate Training participants and their schools and instructors, arising out of attendance at or participation in the Karate Training. (Noji Ratzlaff, Center Stage, and other Karate Training participants and their schools and instructors are hereafter referred to as said persons.);</p>
<p class="ms-4">(c) Undersigned releases the same said persons from any liability for such claim, demand, action, or cause of action, if brought by undersigned;</p>
<p class="ms-4">(d) Undersigned covenants that he/she will not sue or commence any legal action proceedings whatsoever against any of the same said persons over any such claim, demand, action, or cause of action;</p>
<p class="ms-4">(e) Undersigned agrees to indemnify and hold harmless Noji Ratzlaff, Center Stage, their assigns, family members, relatives, successors, interests, and contractors from any and all claims, demands, actions, liability, loss, expense, and/or attorneys' fees that may arise from or be incurred as a result of:</p>
<p class="ms-5">(i) injury, damage, or death to undersigned's person or property during this Karate Training;</p>
<p class="ms-5">(ii) injury, damage, or death to any other Karate Training participant's person or property arising or resulting from the acts or omissions of undersigned.</p>

<p>Undersigned acknowledges that Noji Ratzlaff, Center Stage, other Karate Training participants, and others may not be insured (wholly or in part) against any claims or actions by undersigned (or others) arising out of undersigned's participation in Karate Training activities, and that Noji Ratzlaff and Center Stage could not and would not accept undersigned as a participant in this Karate Training were it not for the full assumption by undersigned of all risk of injury pertaining thereto and for undersigned's waiver, release, covenant not to sue, and indemnity and other agreements as set forth herein.</p>

<p><strong>4.</strong> Undersigned acknowledges that it is undersigned's responsibility to provide full medical insurance for any injury that may befall undersigned, and undersigned represents that undersigned has obtained such medical insurance, which will cover 100% of any and all medical expenses, loss, damage, death, and/or disability resulting from any injury to undersigned in or at any Karate Training, or the undersigned will fully assume the risk of failing to procure such insurance.</p>
<p>Undersigned acknowledges that his/her sole remedy in the event of injury, loss, or damage arising from any Karate Training shall be said medical insurance obtained by undersigned. Undersigned acknowledges that neither Noji Ratzlaff, Center Stage, nor their contractors, provide medical care, and that this Karate Training is or may be held in a vicinity where hospital or other medical services or facilities are not readily available. Undersigned will be responsible for ensuring that undersigned has a means of transportation, if necessary, to convey undersigned to a hospital or medical facility.</p>

<p><strong>5.</strong> Although Noji Ratzlaff and Center Stage will or may request undersigned to execute other consent forms for other Karate Training programs as Noji Ratzlaff and Center Stage deem necessary, the representations, agreements, covenants, and indemnities herein of undersigned shall apply to all Karate Training programs attended by undersigned.</p>

<p><strong>6.</strong> Undersigned agrees that the various provisions of this agreement are severable, and the invalidity or inapplicability of any provision hereof in this agreement shall be governed by the laws of the state in which this agreement is fully performed in said state. If, under the laws of said state, consents, waivers, releases, and/or agreements as set forth herein are required, as a condition of their enforceability, to be in a certain form or to contain special language, such special form or language is deemed incorporated by reference herein, and undersigned covenants that he/she would have executed, and will upon request of Noji Ratzlaff and Center Stage execute (with retroactive effect to the date hereof) an agreement pertaining to the subject matter hereof that contains such special form or language.</p>

<p><strong>7.</strong> This agreement represents the complete embodiment of the understanding and agreements between Noji Ratzlaff, Center Stage, and undersigned, regarding the subject matter except in writing executed by undersigned and an authorized representative of Noji Ratzlaff and Center Stage.</p>

<p><strong>8.</strong> Undersigned represents that he/she is not a minor or, if a minor, that undersigned has had undersigned's parent or legal guardian sign the parental consent and indemnity agreement of Noji Ratzlaff and Center Stage.</p>

<p><strong>9. NOJI RATZLAFF AND CENTER STAGE SUGGEST THAT, IF UNDERSIGNED HAS ANY QUESTIONS OR RESERVATIONS ABOUT ANY OF THE FOREGOING, UNDERSIGNED SHOULD NOT EXECUTE THIS AGREEMENT UNTIL AFTER CONSULTING WITH AN ATTORNEY. UNDERSIGNED HAS EITHER CONSULTED AN ATTORNEY REGARDING THE CONTENTS OF THIS AGREEMENT OR DEEMS IT UNNECESSARY TO CONSULT SUCH ATTORNEY.</strong></p>

<p><strong>10. UNDERSIGNED UNDERSTANDS THAT BY SIGNING THIS AGREEMENT, UNDERSIGNED IS GIVING UP HIS OR HER LEGAL RIGHTS AND LEGAL RIGHTS OF UNDERSIGNED'S HEIRS IN CASE OF INJURY, LOSS, DAMAGE, OR DEATH.</strong></p>

<p>Undersigned represents that he/she has carefully read each and every one of the provisions hereof, fully understands each provision, and consents to be bound thereby.</p>
<p>Undersigned acknowledges receipt of a copy of this agreement.</p>

        </div>
    </div>

    <?php if (!$signed): ?>
    <!-- Signature form -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Sign the Agreement</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <?= csrf_input() ?>

                <div class="col-md-6">
                    <label class="form-label">Print Your Full Name *</label>
                    <input type="text" name="print_name" class="form-control" required
                           value="<?= htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date *</label>
                    <input type="date" name="signed_date" class="form-control" required
                           value="<?= date('Y-m-d') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">
                        Your Signature *
                        <span class="text-muted small">(type your full name — this constitutes your electronic signature)</span>
                    </label>
                    <input type="text" name="signature" class="form-control fst-italic fs-5" required
                           placeholder="Type your full name to sign"
                           style="font-family: cursive; border-bottom: 2px solid #333; border-top: none; border-left: none; border-right: none; border-radius: 0; background: transparent; padding-left: 0;">
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        Signature of Parent / Guardian
                        <span class="text-muted small">(required if under 21)</span>
                    </label>
                    <input type="text" name="guardian_signature" class="form-control fst-italic"
                           placeholder="Guardian full name"
                           style="font-family: cursive;">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Guardian Signed Date</label>
                    <input type="date" name="guardian_signed_date" class="form-control">
                </div>

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-4">
                    <label class="form-label">Date of Birth *</label>
                    <input type="date" name="date_of_birth" class="form-control"
                           value="<?= htmlspecialchars($student['date_of_birth'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cell Phone *</label>
                    <input type="tel" name="cell_phone" class="form-control" required
                           value="<?= htmlspecialchars($student['phone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Home Phone <span class="text-muted small">(if different)</span></label>
                    <input type="tel" name="home_phone" class="form-control">
                </div>

                <div class="col-12">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" required
                           value="<?= htmlspecialchars($student['email'] ?? '') ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Street Address *</label>
                    <input type="text" name="street_address" class="form-control" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">City, State, ZIP *</label>
                    <input type="text" name="city_state_zip" class="form-control" required>
                </div>

                <div class="col-md-8">
                    <label class="form-label">Mailing Address <span class="text-muted small">(if different)</span></label>
                    <input type="text" name="mailing_address" class="form-control">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Mailing City, State, ZIP</label>
                    <input type="text" name="mailing_city_state_zip" class="form-control">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="i_agree" id="i_agree" required>
                        <label class="form-check-label" for="i_agree">
                            I have read and fully understand this agreement and voluntarily agree to be bound by its terms.
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary px-4"
                            <?= $signed ? 'disabled title="Waiver already on file"' : '' ?>>
                        Submit Signed Waiver
                    </button>
                </div>

            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
