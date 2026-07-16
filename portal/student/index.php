<?php
// The student dashboard now lives in the React SPA (app.php — same bundle as
// the parent portal, scoped to the student's own record). This stub keeps the
// old URL working, and keeps the "account pending" screen for logins that
// aren't linked to a student record yet.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/family.php';
require_login();

if (family_own_student((int)current_user_id()) !== null) {
    header('Location: app.php');
    exit;
}

// No roster entry linked yet — show a pending screen
$page_title = 'Account Pending';
include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm text-center p-4">
            <div class="fs-1 mb-3">⏳</div>
            <h5 class="mb-2">Your account is pending</h5>
            <p class="text-muted mb-3">
                Your login has been created but hasn't been linked to a student record yet.
                Contact Noji to get set up — it usually takes just a few minutes.
            </p>
            <a href="mailto:<?= htmlspecialchars(DOJO_EMAIL) ?>" class="btn btn-primary">
                Email Noji
            </a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
