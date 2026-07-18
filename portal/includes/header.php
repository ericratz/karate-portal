<?php
// Shared nav header — included at the top of every dashboard page
// $page_title must be set before including this file
?>
<!DOCTYPE html>
<html lang="en" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title ?? 'Portal') ?> — Shotokan Karate</title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style nonce="<?= csp_nonce() ?>">
        .navbar { background: #6f42c1 !important; }
        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .navbar-text { color: #fff !important; font-size: 1rem; }
        .navbar .navbar-brand,
        .navbar .dropdown-toggle { font-size: 1.25rem; }
        .navbar .nav-link:hover { opacity: .8; }
        .navbar .nav-link-lg { font-size: 1.25rem !important; }
        /* prevent navbar color from bleeding into page tabs */
        .nav-tabs .nav-link { color: #495057 !important; }
        .nav-tabs .nav-link.active { color: #6f42c1 !important; font-weight: 600; }
        .nav-tabs .nav-link:hover { opacity: 1; color: #6f42c1 !important; }
        [data-bs-theme="dark"] .nav-tabs .nav-link { color: #adb5bd !important; }
        [data-bs-theme="dark"] .nav-tabs .nav-link.active { color: #c89ef2 !important; }
        [data-bs-theme="dark"] .nav-tabs .nav-link:hover { color: #c89ef2 !important; }

        /* Dark mode: make text-muted the same brightness as body text */
        [data-bs-theme="dark"] { --bs-secondary-color: #dee2e6; }

        /* Dark mode card fixes */
        [data-bs-theme="dark"] .card { border: 1px solid rgba(255,255,255,.15) !important; }
        [data-bs-theme="dark"] .card-header.bg-white {
            background-color: #2c2f33 !important;
            color: #dee2e6 !important;
        }
        [data-bs-theme="dark"] thead.table-light,
        [data-bs-theme="dark"] .table-light > * > tr {
            --bs-table-bg: #2c2f33;
            --bs-table-color: #dee2e6;
            background-color: #2c2f33 !important;
            color: #dee2e6 !important;
        }
        [data-bs-theme="dark"] thead.table-light th {
            background-color: #2c2f33 !important;
            color: #dee2e6 !important;
        }

        .role-badge {
            font-size: .7rem;
            background: rgba(255,255,255,.25);
            border-radius: 4px;
            padding: 2px 6px;
            margin-left: 6px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        body { background: #f8f9fa; padding-bottom: 57px; }
        [data-bs-theme="dark"] body { background: #212529; }
        #site-footer {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            z-index: 1030;
            background: #6f42c1;
            border-top: 1px solid #5a32a3;
        }
        [data-bs-theme="dark"] #site-footer {
            background: #6f42c1;
            border-top-color: #5a32a3;
        }
        .form-check-input {
            border: 2px solid #555;
            width: 1.15em;
            height: 1.15em;
        }
        .form-check-input:checked {
            background-color: #6f42c1;
            border-color: #6f42c1;
        }
        .form-check-input:focus {
            border-color: #6f42c1;
            box-shadow: 0 0 0 .25rem rgba(111,66,193,.25);
        }
        .form-check-input:disabled { border-color: #aaa; opacity: .55; }
        /* Global input background */
        .form-control, .form-select {
            background-color: #f0f0f0;
        }
        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select {
            background-color: #2a2a2a;
        }

        /* Filter buttons — neutral gray */
        .btn-filter {
            --bs-btn-bg: #e8e8e8;
            --bs-btn-color: #333;
            --bs-btn-border-color: #ccc;
            --bs-btn-hover-bg: #d5d5d5;
            --bs-btn-hover-color: #111;
            --bs-btn-hover-border-color: #bbb;
            --bs-btn-active-bg: #c8c8c8;
            --bs-btn-active-color: #111;
        }
        [data-bs-theme="dark"] .btn-filter {
            --bs-btn-bg: #3a3a3a;
            --bs-btn-color: #ddd;
            --bs-btn-border-color: #555;
            --bs-btn-hover-bg: #4a4a4a;
            --bs-btn-hover-color: #fff;
            --bs-btn-active-bg: #555;
            --bs-btn-active-color: #fff;
        }
        /* Toggleable filter buttons (This Month / This Year, etc.) —
           make the applied filter unmistakable */
        .btn-filter.active {
            background-color: #6f42c1 !important;
            border-color: #6f42c1 !important;
            color: #fff !important;
            font-weight: 600;
        }
        [data-bs-theme="dark"] .btn-filter.active {
            background-color: #8a5cd6 !important;
            border-color: #8a5cd6 !important;
            color: #fff !important;
        }

        /* Global green buttons */
        .btn-primary {
            --bs-btn-bg: #198754;
            --bs-btn-border-color: #198754;
            --bs-btn-hover-bg: #157347;
            --bs-btn-hover-border-color: #146c43;
            --bs-btn-active-bg: #146c43;
            --bs-btn-color: #fff;
            --bs-btn-hover-color: #fff;
            --bs-btn-active-color: #fff;
        }
        .navbar .dropdown-menu {
            background-color: #6f42c1;
            border-color: #5a32a3;
        }
        .navbar .dropdown-menu .dropdown-item {
            color: rgba(255,255,255,.9) !important;
        }
        .navbar .dropdown-menu .dropdown-item:hover,
        .navbar .dropdown-menu .dropdown-item:focus {
            background-color: rgba(255,255,255,.15);
            color: #fff !important;
        }
        .navbar .dropdown-menu .dropdown-header {
            color: rgba(255,255,255,.55) !important;
            font-size: .7rem;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .navbar .dropdown-menu .dropdown-divider {
            border-color: rgba(255,255,255,.15);
        }
        .btn-logout {
            color: #fff !important;
            border: 1px solid #dc3545;
            background: transparent;
            transition: background .15s, color .15s;
        }
        .btn-logout:hover, .btn-logout:focus {
            background: #dc3545;
            color: #fff !important;
            border-color: #dc3545;
        }
        a { text-decoration: none; }
        a:hover { text-decoration: none; }

        /* Mobile: bump .btn-sm in tables up to a comfortable touch target */
        @media (max-width: 767px) {
            .table .btn-sm {
                padding: .375rem .75rem;
                font-size: .875rem;
            }

            /* Footer collapses to just the chevron tab — tapping it reveals
               the whole bar (contact card + trademark/copyright text) */
            #site-footer .footer-grid { display: none !important; }
            #site-footer.footer-open .footer-grid {
                display: grid !important;
                /* !important — must beat the inline 3-column style */
                grid-template-columns: 1fr !important; /* stack: card, then texts */
                padding: 10px 12px;
                /* never grow past the viewport — keeps the chevron reachable */
                max-height: 70vh;
                overflow-y: auto;
            }
            #site-footer.footer-open .footer-grid > div { width: auto !important; }
            #site-footer .footer-side-text { display: none; }
            #site-footer.footer-open .footer-side-text {
                display: block;
                text-align: center !important;
            }
            body { padding-bottom: 34px; } /* only the chevron tab needs room */

            /* The expanded header menu itself never scrolls — only the open
               dropdown does, capped so it can't run off the screen */
            .navbar .dropdown-menu {
                max-height: 55vh !important; /* beats the inline max-height */
                overflow-y: auto;
            }
        }
        .btn-outline-secondary, .btn-outline-primary {
            --bs-btn-bg: #198754;
            --bs-btn-color: #fff;
            --bs-btn-border-color: #198754;
            --bs-btn-hover-bg: #157347;
            --bs-btn-hover-border-color: #146c43;
            --bs-btn-hover-color: #fff;
            --bs-btn-active-bg: #146c43;
            --bs-btn-active-color: #fff;
        }
    </style>
    <script nonce="<?= csp_nonce() ?>">
        (function() {
            if (localStorage.getItem('theme') === 'dark') {
                document.getElementById('html-root').setAttribute('data-bs-theme', 'dark');
            }
        })();
    </script>
</head>
<body>

<nav class="navbar navbar-expand-md sticky-top">
    <div class="container-fluid">
        <?php
        if (has_role('admin'))          $dashboard_url = SITE_URL . '/admin/';
        elseif (has_role('instructor')) $dashboard_url = SITE_URL . '/instructor/';
        elseif (has_role('parent'))     $dashboard_url = SITE_URL . '/parent/';
        else                            $dashboard_url = SITE_URL . '/student/';
        ?>
        <a class="navbar-brand fw-semibold" href="<?= $dashboard_url ?>">
            <?php
                if (has_role('admin'))          echo 'Admin Dashboard';
                elseif (has_role('instructor')) echo 'Instructor Dashboard';
                elseif (has_role('parent'))     echo 'My Dashboard';
                else                            echo 'My Dashboard';
            ?>
        </a>
        <button class="navbar-toggler" type="button" aria-label="Toggle navigation menu"
                data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto">

                <?php if (has_role('admin')): ?>
                <li class="nav-item">
                    <a class="nav-link nav-link-lg" href="<?= SITE_URL ?>/admin/students.php">Roster</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-lg" href="<?= SITE_URL ?>/instructor/attendance_sessions.php">Attendance</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-lg" href="https://ericratz.atlassian.net/jira/software/projects/SCRUM/boards/1" target="_blank" style="color:#7ab3f5 !important;">Jira <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16" style="vertical-align:middle;margin-left:2px"><path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/><path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/></svg></a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       data-bs-toggle="dropdown" role="button">Admin</a>
                    <ul class="dropdown-menu dropdown-menu-end" style="max-height:calc(100vh - 120px);overflow-y:auto;">
                        <li><h6 class="dropdown-header">Student Info</h6></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/instructor/attendance_sessions.php">Attendance</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/instructor/belt_tests_all.php">Belt Tests</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/student_notes.php">Class Notes</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/email_students.php">Email Students</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/instructor/">Instructor Dashboard</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/students.php">Roster</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Finances</h6></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/donations.php">Donations</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/waivers.php">Exemptions</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/expenses.php">Expenses</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/payments.php">Payments</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Security</h6></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/logs.php">Logs</a></li>
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/admin/users.php">Users</a></li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>
            <div class="d-flex align-items-center gap-3">
                <button id="dark-toggle" title="Toggle dark mode" aria-label="Toggle dark mode"
                        style="position:relative;width:78px;height:28px;border-radius:14px;
                               border:2px solid #888;cursor:pointer;
                               transition:background .25s,border-color .25s;background:#3d1a7a;flex-shrink:0">
                    <span id="dark-label"
                          style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
                                 font-size:.72rem;font-weight:700;color:#e0e0e0;user-select:none;
                                 pointer-events:none;letter-spacing:.05em">Dark</span>
                    <span id="dark-knob"
                          style="position:absolute;top:3px;left:3px;width:18px;height:18px;
                                 border-radius:50%;background:#fff;transition:transform .25s"></span>
                </button>
                <span class="navbar-text">
                    <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
                    <?php
                    $_rt = $_SESSION['role'] ?? '';
                    if ($_rt === 'student')     $_tip = 'Registration fee paid';
                    elseif ($_rt === 'parent')  $_tip = 'Family account';
                    elseif ($_rt === 'guest')   $_tip = 'Non-paying participant (registration fee not yet paid)';
                    else                        $_tip = '';
                    ?>
                    <span class="role-badge"<?= $_tip ? ' data-bs-toggle="tooltip" title="' . htmlspecialchars($_tip) . '"' : '' ?>><?= htmlspecialchars($_rt) ?></span>
                    &nbsp;
                    <a href="<?= SITE_URL ?>/logout.php"
                       class="btn btn-sm btn-logout ms-2">Log out</a>
                </span>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-4">

