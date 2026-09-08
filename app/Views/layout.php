<?php
/**
 * Dashboard shell (the ONLY dashboard layout, shared by every staff role).
 *
 * Rendered by App\Libraries\DashboardPageBuilder, which passes $role,
 * $activePage, $bodyView, $bodyData, plus whatever the body view needs. The
 * sidebar and page title come from Config\Navigation so a new page is one
 * manifest entry rather than an edit here. The formatDate/formatTime/
 * formatAuditMember/formatAuditUser helpers are provided by the builder (do
 * not redefine them here).
 */

use Config\Navigation;

// Defensive defaults so the layout still renders if a value is ever missing.
$user = $user ?? [];
$username = $user['username'] ?? 'Admin';
$activePage = $activePage ?? 'dashboard';
$role = $role ?? '';
$pageTitle = Navigation::titleFor($activePage);
$idleTimeoutSeconds = $idleTimeoutSeconds ?? 900;
// Only DashboardPageBuilder-driven pages supply this (SessionAccount::levelLabel());
// a caller like createFamily()/profile() that renders 'layout' directly leaves it
// unset, and sidebar-account-menu.php's own `?? 'Account'` fallback takes over.
$accountLevelLabel = $accountLevelLabel ?? null;
// A caller that omits the body view still gets a page rather than a TypeError from
// view(null): the dashboard body is the safe landing content for any manifest page.
$bodyView = ($bodyView ?? '') !== '' ? $bodyView : 'Pages/dashboard';
?>
<?php
/*
 * SB Admin-style shell: a Bootstrap 5-safe responsive frame around the topnav,
 * sidebar, and a single body view chosen by the caller ($bodyView/$bodyData).
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle) ?> - Binan Access Card MIS</title>
    <link rel="icon" type="image/png" href="<?= asset_url('assets/image/binan.png') ?>">
    <?php
    // Ensure design tokens are loaded first
    ?>
    <link rel="stylesheet" href="<?= esc(asset_url('css/design-tokens.css'), 'attr') ?>">
    <?php
    // The dashboard's distribution analytics reuse the scanner reports styles
    // (KPI tiles, chart cards, barangay chart) from the scanner asset group.
    $layoutStyles = array_merge(asset_styles('head'), asset_styles('admin'));
    if (($activePage ?? '') === 'dashboard') {
        $layoutStyles = array_merge($layoutStyles, asset_styles('scanner'));
    }
    ?>
    <?php foreach ($layoutStyles as $stylePath): ?>
    <link rel="stylesheet" href="<?= esc(asset_url($stylePath), 'attr') ?>">
    <?php endforeach; ?>
</head>
<body class="app-body bg-white">
<div id="layoutSidenav" class="d-flex vh-100 overflow-hidden">
    <div id="layoutSidenav_nav" class="app-sidebar bg-light border-end d-flex flex-column flex-shrink-0 offcanvas-lg offcanvas-start" tabindex="-1">
        <?= view('components/dashboard_sidebar', [
            'role' => $role,
            'activePage' => $activePage,
            'user' => $user,
            'username' => $username,
            'accountLevelLabel' => $accountLevelLabel,
            'accountSettingsUrl' => site_url('account/profile'),
            'accountSettingsMode' => 'modal'
        ]) ?>
    </div>
    <div id="layoutSidenav_content" class="app-content flex-grow-1 d-flex flex-column">
            <!-- Mobile Topbar -->
            <nav class="d-lg-none navbar navbar-light border-bottom px-3 py-2 mobile-topbar">
                <a class="navbar-brand d-flex align-items-center" href="<?= esc(site_url('dashboard'), 'attr') ?>">
                    <img src="<?= esc(asset_url('assets/image/binan.png'), 'attr') ?>" alt="City of Binan Logo" height="32" class="me-2">
                    <span class="fw-normal mobile-brand-title">Bi&ntilde;an Access Card MIS</span>
                </a>
                <button class="btn btn-sm btn-light border d-flex align-items-center justify-content-center mobile-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#layoutSidenav_nav" aria-controls="layoutSidenav_nav" aria-label="Toggle sidebar">
                    <i class="bi bi-list fs-5" aria-hidden="true"></i>
                </button>
            </nav>
            
            <?php /* Desktop topbar: the sidebar toggle and the breadcrumb trail on
                     one line, closed by a border that meets the sidebar brand
                     header's own. Both are sized by --app-topbar-height so the two
                     borders line up as a single rule across the shell. */ ?>
            <header class="d-none d-lg-flex align-items-center gap-3 border-bottom px-4 app-topbar">
                <?php /* A disclosure button, so it states what it controls and
                         whether that thing is open; an icon alone says neither.
                         view-interactions.js keeps aria-expanded and the label in
                         step with the sidebar. No tooltip: it would be anchored
                         to a control that then slides 225px away underneath it. */ ?>
                <button class="btn btn-link p-0 text-muted text-decoration-none border-0 lh-1"
                        id="sidebarToggle" type="button"
                        aria-controls="layoutSidenav_nav" aria-expanded="true"
                        aria-label="Collapse sidebar">
                    <i class="bi bi-layout-sidebar fs-5" aria-hidden="true"></i>
                </button>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <?php if (($breadcrumbParent = Navigation::parentFor($activePage)) !== null): ?>
                        <li class="breadcrumb-item">
                            <a href="<?= esc(site_url(Navigation::routeFor($breadcrumbParent)), 'attr') ?>"><?= esc(Navigation::titleFor($breadcrumbParent)) ?></a>
                        </li>
                        <?php endif; ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= esc($pageTitle) ?></li>
                    </ol>
                </nav>
            </header>

            <?php /* Bottom padding comes from theme.css, not a pb-* utility: it
                     has to carry the safe-area inset, which no Bootstrap
                     spacing class can express. */ ?>
            <main class="container-fluid px-4 pt-3 dashboard-content flex-grow-1">

            <?php /* The breadcrumb in the topbar shows the page name, so printing
                     it again here was the same string twice on one screen. The
                     heading stays for screen readers and for the document
                     outline: a page with no h1 gives assistive tech nothing to
                     jump to, and the breadcrumb cannot serve as one because it
                     sits inside a nav landmark. */ ?>
            <h1 class="visually-hidden" id="dashboard-page-title"><?= esc($pageTitle) ?></h1>
            <?php if (session()->getFlashdata('success')): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert" data-auto-dismiss-alert>
                    <?= esc(session()->getFlashdata('success')) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($resetInfo = session()->getFlashdata('reset_password')): ?>
                <div class="reset-password-callout" role="alert">
                    <div class="reset-password-callout__head">
                        <i class="bi bi-key-fill" aria-hidden="true"></i>
                        <span>New password for <strong><?= esc((string) ($resetInfo['username'] ?? '')) ?></strong></span>
                    </div>
                    <div class="reset-password-callout__body">
                        <code class="reset-password-callout__value" id="resetPasswordValue"><?= esc((string) ($resetInfo['password'] ?? '')) ?></code>
                        <button type="button" class="btn btn-sm btn-outline-success js-copy-password" data-copy-target="#resetPasswordValue">
                            <i class="bi bi-clipboard" aria-hidden="true"></i><span>Copy</span>
                        </button>
                    </div>
                    <p class="reset-password-callout__hint">Share it with the user and ask them to change it in My Account.</p>
                </div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('warning')): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert" data-auto-dismiss-alert>
                    <?= esc(session()->getFlashdata('warning')) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('error')): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" data-auto-dismiss-alert>
                    <?= esc(session()->getFlashdata('error')) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (session()->getFlashdata('family_record_saved')): ?>
                <span id="familyDraftSavedMarker" hidden></span>
            <?php endif; ?>

            <?php /* components/card takes its own $bodyView/$bodyData, and CI4 shares
                     view data between view() calls, so clear this shell's pair on the
                     way down or every card without an explicit body renders the page
                     body again. */ ?>
            <?= view($bodyView, array_merge(['bodyView' => null, 'bodyData' => []], $bodyData ?? [])) ?>

            </main>
    </div>
</div>

<?php /* Shared modal target. The *-modal.js loaders fetch ?partial=1 fragments
         (add/edit record, accounts, sectors, services, audit) into #familyModalBody. */ ?>
<?= view('components/modal', [
    'id' => 'familyModal',
    'modalClass' => 'floating-family-modal',
    'attrs' => 'aria-label="Record details" data-bs-backdrop="static" data-bs-keyboard="false"',
    'size' => 'modal-xl',
    'title' => 'Record',
    'titleId' => 'familyModalLabel',
    'bodyId' => 'familyModalBody',
    'bodyHtml' => '<div class="family-modal-loading" role="status" aria-live="polite"><div class="spinner-border text-primary" aria-hidden="true"></div><span>Loading...</span></div>',
    'footerHtml' => '<button type="button" class="btn btn-outline-secondary family-modal-close" data-bs-dismiss="modal">Close</button>',
]) ?>

<?= view('Family/action-confirm-modal') ?>

<?= view('Accounts/status-confirm-modal') ?>

<?php /* Per-row audit detail modal, populated client-side by audit-detail-modal.js
         from the clicked row's data-* attributes (no AJAX). */ ?>
<?= view('components/modal', [
    'id' => 'auditDetailModal',
    'modalClass' => 'audit-detail-modal',
    'attrs' => 'aria-labelledby="auditDetailTitle"',
    'size' => 'modal-lg',
    'title' => 'Audit Entry Details',
    'titleId' => 'auditDetailTitle',
    'bodyHtml' => '<p class="audit-detail-full" id="auditDetailFull">-</p>',
    'footerHtml' => '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>',
]) ?>

<?php /* The single toast node assets/js/toast.js targets. Without it showToast()
         returns silently, which leaves the Excel import with no progress, no
         "Review and Fix" link, and no error text. */ ?>
<?= view('components/toast') ?>

<?php foreach (array_merge(asset_scripts('core'), asset_scripts('admin')) as $scriptPath): ?>
<script src="<?= esc(asset_url($scriptPath), 'attr') ?>"></script>
<?php endforeach; ?>
<?php // FullCalendar (~275KB) only ships to the Schedule tab, not every dashboard page. ?>
<?php if (($activePage ?? '') === 'distribution' && ($distributionTab ?? '') === 'schedule'): ?>
<?php foreach (asset_scripts('schedule') as $scriptPath): ?>
<script src="<?= esc(asset_url($scriptPath), 'attr') ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
<script src="<?= esc(asset_url('assets/js/session-timeout.js'), 'attr') ?>" data-timeout-seconds="<?= esc((string) $idleTimeoutSeconds) ?>" data-logout-url="<?= site_url('logout?timeout=1') ?>" data-home-url="<?= site_url('/') ?>" data-keep-alive-url="<?= site_url('session/keep-alive') ?>"></script>

<?php if ($openModal = session()->getFlashdata('openModal')): ?>
<?php /* The modal to auto-open after a redirect is handed to JS as data, not as an
         inline script: dashboard-modal-loader.js (already loaded, owns modal
         behaviour) reads this node and clicks the matching trigger button. */ ?>
<div data-open-modal="<?= esc($openModal, 'attr') ?>" data-open-modal-id="<?= esc((string) session()->getFlashdata('openModalId'), 'attr') ?>" hidden></div>
<?php endif; ?>

</body>
</html>
