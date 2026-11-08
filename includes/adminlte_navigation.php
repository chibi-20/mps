<?php
$navigationUser = $sess ?? current_user();
$navigationIsAdmin = ($navigationUser['role'] ?? '') === 'admin';
$navigationPage = $activeNavPage ?? 'analytics';
$navigationPanel = in_array($_GET['panel'] ?? '', ['analytics', 'submissions', 'competencies', 'assessments', 'teachers', 'settings'], true)
    ? $_GET['panel']
    : 'analytics';
$navigationDashboardUrl = BASE_URL . ($navigationIsAdmin ? 'admin-dashboard.php' : 'teacher-dashboard.php');
?>
<nav class="topnav app-header navbar navbar-expand bg-body d-print-none">
    <div class="container-fluid">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Toggle navigation"><i class="bi bi-list"></i></a></li>
        </ul>
        <div class="topnav-brand">
            <img src="<?= BASE_URL ?>assets/logo.png" alt="Jacobo Z. Gonzales Memorial National High School" class="school-logo">
            <span class="topnav-title">MPS &amp; Item Analysis System</span>
        </div>
        <div class="topnav-user">
            <?php if (!$navigationIsAdmin):
                $scannerOrigin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
                $scannerUrl = preg_match('~^https?://~i', SCANNER_PWA_URL) ? SCANNER_PWA_URL : $scannerOrigin . '/' . ltrim(SCANNER_PWA_URL, '/');
                $scannerLink = $scannerUrl . (str_contains(SCANNER_PWA_URL, '?') ? '&' : '?') . 'mps_url=' . rawurlencode($scannerOrigin . rtrim(BASE_URL, '/'));
            ?>
            <a href="<?= h($scannerLink) ?>" class="btn btn-sm btn-outline-secondary">Install MPS Scanner</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
        <a href="<?= $navigationDashboardUrl ?>" class="brand-link">
            <img src="<?= BASE_URL ?>assets/logo.png" alt="" class="school-logo">
            <span class="brand-text fw-light"><?= $navigationIsAdmin ? 'MPS Administration' : 'MPS Teacher Portal' ?></span>
        </a>
    </div>
    <div class="sidebar-wrapper">
        <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column admin-menu" role="menu">
                <li class="nav-header">Core Performance</li>
                <?php if ($navigationIsAdmin): ?>
                    <?php if ($navigationPage === 'dashboard'): ?>
                    <li class="nav-item"><button class="admin-tab nav-link <?= $navigationPage === 'dashboard' && $navigationPanel === 'analytics' ? 'active' : '' ?>" data-panel="analytics" onclick="adminTab(this,'analytics')"><i class="nav-icon bi bi-graph-up-arrow"></i><p>Analytics</p></button></li>
                    <li class="nav-item"><button class="admin-tab nav-link <?= $navigationPanel === 'submissions' ? 'active' : '' ?>" data-panel="submissions" onclick="adminTab(this,'submissions')"><i class="nav-icon bi bi-inbox"></i><p>Submissions</p></button></li>
                    <li class="nav-item"><button class="admin-tab nav-link <?= $navigationPanel === 'competencies' ? 'active' : '' ?>" data-panel="competencies" onclick="adminTab(this,'competencies')"><i class="nav-icon bi bi-journal-check"></i><p>Learning Competencies</p></button></li>
                    <li class="nav-item"><button class="admin-tab nav-link <?= $navigationPanel === 'assessments' ? 'active' : '' ?>" data-panel="assessments" onclick="adminTab(this,'assessments')"><i class="nav-icon bi bi-file-earmark-text"></i><p>Assessments</p></button></li>
                    <li class="nav-item"><button class="admin-tab nav-link <?= $navigationPanel === 'teachers' ? 'active' : '' ?>" data-panel="teachers" onclick="adminTab(this,'teachers')"><i class="nav-icon bi bi-people"></i><p>Teacher Accounts</p></button></li>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>students.php"><i class="nav-icon bi bi-person-vcard"></i><p>Students</p></a></li>
                    <?php else: ?>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>admin-dashboard.php?panel=analytics"><i class="nav-icon bi bi-graph-up-arrow"></i><p>Analytics</p></a></li>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>admin-dashboard.php?panel=submissions"><i class="nav-icon bi bi-inbox"></i><p>Submissions</p></a></li>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>admin-dashboard.php?panel=competencies"><i class="nav-icon bi bi-journal-check"></i><p>Learning Competencies</p></a></li>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>admin-dashboard.php?panel=assessments"><i class="nav-icon bi bi-file-earmark-text"></i><p>Assessments</p></a></li>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>admin-dashboard.php?panel=teachers"><i class="nav-icon bi bi-people"></i><p>Teacher Accounts</p></a></li>
                    <li class="nav-item"><a class="admin-tab nav-link <?= $navigationPage === 'students' ? 'active' : '' ?>" href="<?= BASE_URL ?>students.php"><i class="nav-icon bi bi-person-vcard"></i><p>Students</p></a></li>
                    <?php endif; ?>
                    <li class="nav-header">Administration</li>
                    <li class="nav-item"><a class="admin-tab nav-link <?= $navigationPage === 'scan-audit' ? 'active' : '' ?>" href="<?= BASE_URL ?>scanner-audit.php"><i class="nav-icon bi bi-upc-scan"></i><p>Scan Audit</p></a></li>
                    <?php if ($navigationPage === 'dashboard'): ?>
                    <li class="nav-item"><button class="admin-tab nav-link <?= $navigationPanel === 'settings' ? 'active' : '' ?>" data-panel="settings" onclick="adminTab(this,'settings')"><i class="nav-icon bi bi-gear"></i><p>Settings</p></button></li>
                    <?php else: ?>
                    <li class="nav-item"><a class="admin-tab nav-link" href="<?= BASE_URL ?>admin-dashboard.php?panel=settings"><i class="nav-icon bi bi-gear"></i><p>Settings</p></a></li>
                    <?php endif; ?>
                <?php else: ?>
                    <li class="nav-item"><a class="admin-tab nav-link <?= $navigationPage === 'dashboard' ? 'active' : '' ?>" href="<?= $navigationDashboardUrl ?>"><i class="nav-icon bi bi-journal-text"></i><p>My Assessments</p></a></li>
                    <li class="nav-item"><a class="admin-tab nav-link <?= $navigationPage === 'students' ? 'active' : '' ?>" href="<?= BASE_URL ?>students.php"><i class="nav-icon bi bi-person-vcard"></i><p>Students</p></a></li>
                    <!-- <li class="nav-item"><a class="admin-tab nav-link <?= $navigationPage === 'scanner-settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>scanner-settings.php"><i class="nav-icon bi bi-gear"></i><p>Scanner Setup</p></a></li> -->
                <?php endif; ?>
            </ul>
        </nav>
        <div class="mps-mastery-target">
            <div class="d-flex justify-content-between align-items-center"><strong>DepEd Target MPS</strong><b><?= (int)MASTERY_THRESHOLD ?>%</b></div>
            <small>Division benchmark for the mastery standard.</small>
            <div class="mps-target-track"><span style="width:<?= (int)MASTERY_THRESHOLD ?>%"></span></div>
        </div>
        <?php if (!$navigationIsAdmin && $navigationPage === 'dashboard'): require __DIR__ . '/teacher_assessment_sidebar.php'; endif; ?>
    </div>
    <div class="mps-sidebar-account">
        <a class="mps-sidebar-profile <?= $navigationPage === 'profile' ? 'active' : '' ?>" href="<?= BASE_URL ?>profile.php" aria-label="Open profile for <?= h((string)($navigationUser['display'] ?? 'user')) ?>">
            <span class="mps-sidebar-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
            <span class="mps-sidebar-account-copy">
                <strong><?= h((string)($navigationUser['display'] ?? '')) ?></strong>
                <small>View profile · <?= $navigationIsAdmin ? 'Administrator' : 'Teacher' ?></small>
            </span>
        </a>
        <a class="mps-sidebar-signout" href="<?= BASE_URL ?>api/logout.php" title="Sign out" aria-label="Sign out">
            <i class="bi bi-box-arrow-right"></i><span>Sign Out</span>
        </a>
    </div>
</aside>
