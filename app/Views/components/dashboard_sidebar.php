<?php
/**
 * Shared dashboard sidebar (SB Admin 1 sb-sidenav), rendered from the navigation
 * manifest. Every link, its heading, and whether this role sees it comes from
 * Config\Navigation, so adding a page is one entry there rather than an edit here.
 *
 * Kiosk pages render via Scanner/kiosk-layout and never use this component. The
 * brand link lives in the topnav partial.
 *
 * $role       normalized role label ('Admin', 'Encoder', ...)
 * $activePage manifest key of the page being rendered
 */

use Config\Navigation;

$role = (string) ($role ?? '');
$activePage = (string) ($activePage ?? '');
if ($activePage === 'dashboard' && ($_GET['view'] ?? '') === 'distribution') {
    $activePage = 'dashboard-distribution';
}
$links = Navigation::linksFor($role);
$renderedHeading = null;
?>
    <nav class="app-sidebar-nav d-flex flex-column h-100 <?= esc(strtolower($role), 'attr') ?>" id="dashboard-sidebar">
        <!-- Brand Header -->
        <?php /* Height and bottom border match the content topbar so the divider
                 reads as one line across the shell, not two panels. */ ?>
        <div class="sidebar-brand-header border-bottom px-3 d-flex align-items-center justify-content-between">
            <a class="text-decoration-none text-dark d-flex align-items-center text-nowrap" href="<?= site_url('dashboard') ?>">
                <img src="<?= asset_url('assets/image/binan.png') ?>" alt="City of Binan Logo" height="36" class="me-2">
                <span class="fw-normal" style="font-size: 0.875rem;">Bi&ntilde;an Access Card MIS</span>
            </a>
            <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>

        <!-- Nav Links -->
        <div class="sidebar-menu-wrapper flex-grow-1 overflow-y-auto pb-3 px-2">
            <div class="nav flex-column gap-1">
                <?php foreach ($links as $link): ?>
                    <?php if ($link['heading'] !== $renderedHeading): ?>
                        <div class="sidebar-menu-heading text-uppercase text-muted fw-bold mt-3 mb-1 px-3" style="font-size: 0.65rem; letter-spacing: 0.05em;"><?= esc($link['heading']) ?></div>
                        <?php $renderedHeading = $link['heading']; ?>
                    <?php endif; ?>
                    <a class="nav-link rounded px-3 py-1 text-dark d-flex align-items-center <?= $link['key'] === $activePage ? 'active' : '' ?>" href="<?= esc(site_url($link['route'])) ?>" style="font-size: 0.85rem;">
                        <div class="sidebar-nav-icon me-2"><i class="bi <?= esc($link['icon']) ?>" style="font-size: 1.1rem;" aria-hidden="true"></i></div>
                        <span><?= esc($link['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>



        <!-- Sidebar Footer -->
        <div class="sidebar-footer border-top p-2" style="background-color: var(--token-gray-50);">
            <?= view('Partials/sidebar-account-menu', [
                'user' => $user ?? [],
                'username' => $username ?? 'User',
                'accountLevelLabel' => $accountLevelLabel ?? 'Account',
                'accountSettingsUrl' => $accountSettingsUrl ?? site_url('account/profile'),
                'accountSettingsMode' => $accountSettingsMode ?? 'modal'
            ]) ?>
        </div>
    </nav>
