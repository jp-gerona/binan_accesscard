<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Controllers\Concerns\DashboardPartialsTrait;
use App\Controllers\HomeRoleAccessTrait;
use App\Libraries\DashboardPageBuilder;

/**
 * Renders the dashboard pages and their AJAX partials behind the flat routes
 * (`dashboard`, `records`, `reference-data`, `cards`, `accounts`,
 * `audit-trails`), guarded per page by the `roleNav` route filter. One
 * controller serves every staff role, because there is one URL per page.
 *
 * Page rendering is delegated to App\Libraries\DashboardPageBuilder: this
 * controller only decides WHICH page to show, the builder assembles the view data
 * and returns the rendered HTML. Authentication and session lifecycle live in
 * App\Controllers\Auth\AuthController (the controller the auth routes target);
 * the `normalizeRole` helper used by the account partial comes from
 * App\Controllers\HomeRoleAccessTrait.
 */
class DashboardController extends BaseController
{
    use HomeRoleAccessTrait;
    use DashboardPartialsTrait;

    // Each page action below maps to a route in Config\Routes and to the
    // matching manifest key in Config\Navigation. Access is the roleNav filter's
    // job, so no action re-guards the role here.

    /**
     * GET `dashboard`. Delegates to DashboardPageBuilder to assemble stats and
     * render the shell on the "dashboard" page.
     */
    public function dashboard(): string
    {
        return (new DashboardPageBuilder($this->request))->renderPage('dashboard');
    }

    /**
     * GET `accounts`. Renders the full accounts page, or-when the request
     * is an AJAX/partial fetch from the dashboard-just the accounts fragment.
     */
    public function accounts(): string
    {
        if ($this->isPartialRequest()) {
            return $this->renderAccountsPartial();
        }

        return (new DashboardPageBuilder($this->request))->renderPage('accounts');
    }

    /**
     * GET `records`. Renders the family records list page, or the
     * list fragment for AJAX search/pagination.
     */
    public function manageRecords(): string
    {
        if ($this->isPartialRequest()) {
            return $this->renderRecordListPartial();
        }

        return (new DashboardPageBuilder($this->request))->renderPage('records');
    }

    /**
     * GET `records/completeness`. The Card Readiness work queue: active Heads
     * whose required access-card data needs follow-up.
     */
    public function dataCompleteness(): string
    {
        return (new DashboardPageBuilder($this->request))->renderPage('records-completeness');
    }

    /** GET `records/completeness/download`. The queue as a filter-honouring .xlsx file. */
    public function completenessDownload(): \CodeIgniter\HTTP\ResponseInterface
    {
        return (new DashboardPageBuilder($this->request))->completenessDownloadResponse();
    }

    /**
     * GET `audit-trails`. Renders the audit log page, or the audit fragment
     * for AJAX search/filtering.
     */
    public function auditTrails(): string
    {
        if ($this->isPartialRequest()) {
            return $this->renderAuditPartial();
        }

        return (new DashboardPageBuilder($this->request))->renderPage('audit-trails');
    }

    /**
     * GET `reference-data`. One page for the lookup tables (Sectors, Services,
     * Categories, Subsidy Types), switched by ?tab=. Mutations still post to the
     * Lookups\* and SubsidyTypes controllers, which keep their own guards.
     */
    public function referenceData(): string
    {
        return (new DashboardPageBuilder($this->request))->renderPage('reference-data');
    }

    /**
     * GET `cards`. Renders the QR access-card batch page in the dashboard
     * shell. Generation/lookup are handled by Cards\QrCardController.
     */
    public function cards(): string
    {
        return (new DashboardPageBuilder($this->request))->renderPage('cards');
    }

    // The AJAX partial methods below back the dashboard shell's fetch-loaded
    // sections (accounts, family list, audit, and so on): when ?partial=1 or an
    // XHR header is present (isPartialRequest(), from DashboardPartialsTrait),
    // they return just the inner view fragment instead of the whole page.
    // Front-end loader: assets/js/dashboard/*-modal.js.

    /**
     * Returns just the accounts table fragment for the dashboard's AJAX loader
     * (assets/js/dashboard/*-modal.js); renders `Admin/accounts` with data from
     * DashboardPageBuilder.
     */
    private function renderAccountsPartial(): string
    {
        $currentRole = $this->normalizeRole((string) session()->get('role'));

        if (! in_array($currentRole, ['Developer', 'Admin'], true)) {
            return '<div class="alert alert-danger mb-0">Developer or Admin access is required for account management.</div>';
        }

        $viewData = (new DashboardPageBuilder($this->request))->buildViewData('accounts');

        return view('Admin/accounts', [
            'adminAccounts' => $viewData['adminAccounts'] ?? [],
            'employeeAccounts' => $viewData['employeeAccounts'] ?? [],
            'viewerAccounts' => $viewData['viewerAccounts'] ?? [],
            'scannerAccounts' => $viewData['scannerAccounts'] ?? [],
            'searchTerm' => $viewData['searchTerm'] ?? '',
            'searchFilters' => $viewData['searchFilters'] ?? [],
            'canCreateAccounts' => $viewData['canCreateAccounts'] ?? false,
            'canEditAccounts' => $viewData['canEditAccounts'] ?? false,
            'currentRole' => $viewData['currentRole'] ?? '',
        ]);
    }

    /**
     * Returns the family records list fragment (table rows) for the
     * manage-records AJAX search/pagination. Renders `Family/list` with data from
     * DashboardPageBuilder, which scopes the list's controls to the session role.
     */
    private function renderRecordListPartial(): string
    {
        return view(
            'Family/list',
            (new DashboardPageBuilder($this->request))->buildRecordListViewData()
        );
    }

    /**
     * Returns the audit-trail list fragment for the audit AJAX search/filter.
     * Renders `Admin/audit-trails`.
     */
    private function renderAuditPartial(): string
    {
        $viewData = (new DashboardPageBuilder($this->request))->buildViewData('audit-trails');

        return view('Admin/audit-trails', [
            'recentAudits' => $viewData['recentAudits'] ?? [],
            'searchTerm' => $viewData['searchTerm'] ?? '',
            'searchFilters' => $viewData['searchFilters'] ?? [],
            'auditActionOptions' => $viewData['auditActionOptions'] ?? [],
            'auditListData' => $viewData['auditListData'] ?? [],
        ]);
    }
}
