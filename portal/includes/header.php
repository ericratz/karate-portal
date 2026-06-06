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
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        .navbar { background: #6f42c1 !important; }
        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .navbar-text { color: #fff !important; }
        .navbar .nav-link:hover { opacity: .8; }
        /* prevent navbar color from bleeding into page tabs */
        .nav-tabs .nav-link { color: #495057 !important; }
        .nav-tabs .nav-link.active { color: #6f42c1 !important; font-weight: 600; }
        .nav-tabs .nav-link:hover { opacity: 1; color: #6f42c1 !important; }
        [data-bs-theme="dark"] .nav-tabs .nav-link { color: #adb5bd !important; }
        [data-bs-theme="dark"] .nav-tabs .nav-link.active { color: #c89ef2 !important; }
        [data-bs-theme="dark"] .nav-tabs .nav-link:hover { color: #c89ef2 !important; }

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
        body { background: #f8f9fa; }
        [data-bs-theme="dark"] body { background: #212529; }
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
    <script>
        (function() {
            if (localStorage.getItem('theme') === 'dark') {
                document.getElementById('html-root').setAttribute('data-bs-theme', 'dark');
            }
        })();
    </script>
</head>
<body>

<nav class="navbar navbar-expand-md">
    <div class="container-fluid">
        <?php
        if (has_role('admin'))       $dashboard_url = '/karate/portal/admin/';
        elseif (has_role('instructor')) $dashboard_url = '/karate/portal/instructor/';
        else                            $dashboard_url = '/karate/portal/student/';
        ?>
        <a class="navbar-brand fw-semibold" href="<?= $dashboard_url ?>">
            &#9675; <?php
                if (has_role('admin'))            echo 'Admin Dashboard';
                elseif (has_role('instructor'))   echo 'Instructor Dashboard';
                else                              echo 'My Dashboard';
            ?>
        </a>
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto">

                <?php if (has_role('admin')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#"
                       data-bs-toggle="dropdown" role="button">Admin</a>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">Instructor</h6></li>
                        <li><a class="dropdown-item" href="/karate/portal/instructor/">Instructor Dashboard</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/students.php">Roster</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/instructor/attendance_sessions.php">Attendance</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/instructor/belt_tests_all.php">Belt Tests</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/student_notes.php">Student Notes</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Finances</h6></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/payments.php">Payments</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/waivers.php">Payment Waivers</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/expenses.php">Expenses</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Security</h6></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/users.php">Users</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/audit_log.php">Audit Log</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Communication</h6></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/general_notes.php">Class Notes</a></li>
                        <li><a class="dropdown-item" href="/karate/portal/admin/email_students.php">Email Students</a></li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>
            <div class="d-flex align-items-center gap-3">
                <button id="dark-toggle" title="Toggle dark mode"
                        style="width:44px;height:24px;border-radius:12px;border:2px solid #000;cursor:pointer;
                               padding:2px;display:flex;align-items:center;transition:background .25s;
                               background:#ccc;flex-shrink:0">
                    <span id="dark-knob"
                          style="width:20px;height:20px;border-radius:50%;background:#fff;
                                 display:block;transition:transform .25s;transform:translateX(0);background:#fff;">
                    </span>
                </button>
                <span class="navbar-text">
                    <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
                    <span class="role-badge"><?= htmlspecialchars($_SESSION['student_type'] ?? $_SESSION['role'] ?? '') ?></span>
                    &nbsp;
                    <a href="/karate/portal/logout.php"
                       class="btn btn-sm btn-logout ms-2">Log out</a>
                </span>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-4">
