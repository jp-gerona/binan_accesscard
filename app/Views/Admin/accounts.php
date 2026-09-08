<?php
/**
 * Account Management page (Admin > Accounts).
 *
 * Rendered inside layout.php. The four role lists arrive separately and are
 * merged into one table here, so role is a column rather than a section. Whether the
 * Add and Edit controls appear is decided server side and passed in, because Admin
 * and Developer differ in what they may change; this view only honours the flags.
 */

$adminAccounts = $adminAccounts ?? [];
$employeeAccounts = $employeeAccounts ?? [];
$viewerAccounts = $viewerAccounts ?? [];
$scannerAccounts = $scannerAccounts ?? [];
$canCreateAccounts = (bool) ($canCreateAccounts ?? false);
$canEditAccounts = (bool) ($canEditAccounts ?? false);
$currentRole = (string) ($currentRole ?? '');
$isDeveloper = $currentRole === 'Developer';
$isAdmin = $currentRole === 'Admin';
$accounts = array_merge($adminAccounts, $employeeAccounts, $viewerAccounts, $scannerAccounts);
?>

<div class="accounts-page" data-account-management>
    <?php /* Toolbar above the card, Manage Records standard. Client mode: the account list is
             fully loaded, so the keyword and the panel checkboxes filter rows in the browser
             (accounts-modal.js) and records-filter-panel.js renders the pills - no reload.
             Checkbox groups, like Manage Records: nothing checked means no filter, so there
             is no "All" choice and no pill for the default state. */ ?>
    <?php
    $accountLevelsGroup = [
        'name' => 'account_level',
        'label' => 'Level',
        'type' => 'checkbox',
        'options' => [
            ['value' => 'administrator', 'label' => 'Administrator', 'pill' => 'Administrator', 'checked' => false],
            ['value' => 'encoder', 'label' => 'Encoder', 'pill' => 'Encoder', 'checked' => false],
            ['value' => 'viewer', 'label' => 'Viewer', 'pill' => 'Viewer', 'checked' => false],
            ['value' => 'scanner', 'label' => 'Scanner', 'pill' => 'Scanner', 'checked' => false],
        ],
        'attrs' => 'data-account-level-filter',
    ];

    $accountStatusesGroup = [
        'name' => 'account_status',
        'label' => 'Status',
        'type' => 'checkbox',
        'options' => [
            ['value' => 'active', 'label' => 'Active', 'pill' => 'Active', 'checked' => false],
            ['value' => 'inactive', 'label' => 'Inactive', 'pill' => 'Inactive', 'checked' => false],
        ],
        'attrs' => 'data-account-status-filter',
    ];

    $actionsHtml = '';
    if ($canCreateAccounts) {
        $actionsHtml = '<button class="' . btn('add') . ' flex-fill js-open-account-create-modal" type="button" data-modal-url="' . esc(site_url('accounts/create'), 'attr') . '" data-modal-title="Create Account"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create Account</button>';
    }
    ?>
    <?= view('components/toolbar', [
        'isClient' => true,
        'formAria' => 'Filter accounts',
        'searchPlaceholder' => 'Search accounts...',
        'searchName' => 'q',
        'searchAttrs' => 'data-account-search aria-label="Search accounts by username"',
        'clearAttrs' => 'data-account-clear-filters',
        'pillsId' => 'accountFilterPills',
        'actionsHtml' => $actionsHtml,
        'filterGroups' => [$accountLevelsGroup, $accountStatusesGroup],
    ]) ?>

    <section class="card batch-card" aria-labelledby="accounts-title" data-table-paginate data-paginate-key="accounts" data-paginate-label="accounts">
        <div class="card-body">
            <h2 class="batch-pane-title">Account Management</h2>
            <?= view('Admin/accounts-body', [
                'accounts' => $accounts,
                'canEditAccounts' => $canEditAccounts,
                'isDeveloper' => $isDeveloper,
                'isAdmin' => $isAdmin,
            ]) ?>
            <div class="mt-3 small text-muted">
                <?= view('components/table_footer', ['clientKey' => 'accounts', 'entityLabel' => 'accounts']) ?>
            </div>
        </div>
    </section>
</div>
