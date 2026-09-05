<?php

namespace App\Libraries;

use App\Models\Audit\AuditTrailsModel;
use App\Models\DashboardModel;
use App\Models\Lookups\BarangayModel;
use App\Models\Families\MemberModel;
use App\Models\SearchModel;
use App\Models\Lookups\CategoryModel;
use App\Models\Lookups\SectorModel;
use App\Models\Lookups\ServiceModel;
use App\Models\Scanner\QrControlModel;
use App\Models\Scanner\SubsidyDistributionModel;
use App\Models\Scanner\SubsidyTypeModel;
use App\Models\Scanner\SubsidyStatsModel;
use App\Models\Scanner\DistributionBatchModel;
use App\Support\FamilyProfilingFormV2;
use App\Libraries\RoleAccess;
use App\Libraries\Scanner\BatchScope;
use App\Models\ViewLayoutModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\IncomingRequest;
use Config\IdleTimeout;
use Config\Navigation;

/**
 * Central view-data assembler for the dashboard. Admin\DashboardController delegates here so
 * controllers only choose WHICH page to show while this class gathers all the
 * models' data and renders the one shell view. The main place to look when
 * debugging what a dashboard page displays.
 */
class DashboardPageBuilder
{
    /**
     * Manifest page key => the view the shell renders in its main area. A key
     * with no entry here falls back to the dashboard body.
     *
     * @var array<string, string>
     */
    private const BODY_VIEWS = [
        'dashboard'      => 'Pages/dashboard',
        'records'        => 'Family/list',
        'reference-data' => 'Pages/reference-data',
        'cards'          => 'Cards/batch_form',
        'distribution'   => 'Pages/distribution',
        'accounts'       => 'Admin/accounts',
        'audit-trails'   => 'Admin/audit-trails',
        'records-completeness' => 'Family/completeness',
    ];

    /** The member columns whose blanks the Data Completeness report chases. */
    private const COMPLETENESS_LABELS = [
        'birthday' => 'Birthday', 'sex' => 'Sex', 'civilstatus' => 'Civil Status',
        'education' => 'Education', 'job' => 'Job', 'salary' => 'Monthly Income',
    ];

    /** Head-only columns the report chases (members inherit the head's). */
    private const HEAD_COMPLETENESS_LABELS = ['address' => 'Address', 'barangayID' => 'Barangay'];

    /** Holds the current request so query params (search/filters/page) are available. */
    public function __construct(private IncomingRequest $request) {}

    /**
     * The shell's account variables: who the topbar names, and what the sidebar's
     * user link may reach.
     *
     * A controller that renders `layout` directly (the import wizard, the family
     * entry and profile pages) never runs buildViewData(), so without this it
     * hands the shell no user at all and the topbar falls back to the word "User"
     * while the operator is signed in as themselves. Session-only, so it costs
     * nothing to call from a page that assembles the rest of its data itself.
     *
     * @return array{user: array, username: string, accountLevelLabel: string, canManageAccounts: bool}
     */
    public static function shellAccountData(): array
    {
        $role = RoleAccess::normalizeRole((string) session()->get('role'));

        try {
            $user = SessionAccount::user();
        } catch (DatabaseException $e) {
            // The name in the topbar is not worth a 500. Fall back to the session,
            // which already carries the username the operator signed in with -
            // but only the display fields. Casting the whole session here would
            // hand auth_token and every other key to the views as $user.
            $user = [
                'username' => (string) (session()->get('username') ?? ''),
                'userID'   => (int) session()->get('user_id'),
            ];
        }

        return [
            'user'              => $user,
            'username'          => (string) (session()->get('username') ?? 'Admin'),
            'accountLevelLabel' => SessionAccount::levelLabel(),
            'canManageAccounts' => in_array($role, ['Developer', 'Admin'], true),
        ];
    }

    /** Normalized role label for the session, or null when the role is unknown. */
    private function currentRole(): ?string
    {
        return RoleAccess::normalizeRole((string) session()->get('role'));
    }

    /**
     * Manage Records row-action flags for a role: Add/Edit for the entry roles,
     * Archive/Restore for managers, none of it for a Viewer. Pulled out of
     * buildMemberListData() so it can be unit tested without a database. Row
     * actions are gated again server side in FamilyDataTablePresenter.
     *
     * @return array{0: bool, 1: bool, 2: bool} [canEdit, canArchive, canRestoreArchived]
     */
    private function recordListRoleFlags(?string $role): array
    {
        $canEdit = in_array($role, ['Developer', 'Admin', 'Encoder'], true);
        $canArchive = in_array($role, ['Developer', 'Admin'], true);

        return [$canEdit, $canArchive, $canArchive];
    }

    /**
     * Renders the dashboard shell (`layout`) on the given manifest page for
     * whatever role the session holds. Who may reach the page is the `roleNav`
     * route filter's decision (see app/Config/Navigation.php), not this class's.
     * Frontend: returns the full page HTML.
     */
    public function renderPage(string $activePage): string
    {
        return view('layout', $this->buildViewData($activePage));
    }

    /**
     * Assembles every variable the shell and the active page's body view need:
     * session user and role, the body view plus its data, account lists, recent
     * families/audits, sector/service lists, dashboard stats, search
     * term/filters, and view formatter closures (formatDate/Status/etc.). Also
     * reused to build AJAX partials.
     */
    public function buildViewData(string $activePage): array
    {
        $layoutModel    = new ViewLayoutModel();
        $dashboardModel = new DashboardModel();
        $searchModel = new SearchModel();
        $searchTerm = trim((string) $this->request->getGet('q'));
        $searchFilters = $this->searchFilters();
        $hasSearchFilters = $this->hasSearchFilters($searchFilters);
        $currentRole = $this->currentRole();
        $isDeveloper = $currentRole === 'Developer';
        $isAdmin = $currentRole === 'Admin';
        $canManageAccounts = $isDeveloper || $isAdmin;
        $isAccounts = $activePage === 'accounts' && $canManageAccounts;
        $isDashboard = $activePage === 'dashboard';

        // Both manager roles read the same list through the search model, so the
        // page's search box and role/status filters work for an Admin too. Neither
        // query returns developer accounts.
        $users = $isAccounts ? $searchModel->staffAccounts($searchTerm, $searchFilters) : [];
        $sectorModel = new SectorModel();
        $serviceModel = new ServiceModel();

        $sectorOptions = $sectorModel->getSectorOptions();

        // Keep legacy file-backed Developer audit rows (NULL userID) visible only to
        // Developers. New Developer activity has a real userID like every DB account.
        $includeDeveloperAudits = $currentRole === 'Developer';
        $auditListData = $activePage === 'audit-trails'
            ? $this->buildAuditListData($includeDeveloperAudits, null, 'audit-trails')
            : [];
        // Only the Audit Trails page shows every user's audit rows. An Encoder has
        // no Audit Trails page, so their own recent activity rides the dashboard.
        $recentAudits = $auditListData['rows'] ?? [];
        $myAudits = $isDashboard && $currentRole === 'Encoder'
            ? (new AuditTrailsModel())->getByUser((int) session()->get('user_id'), 10)
            : [];
        $memberListData = $activePage === 'records'
            ? $this->buildMemberListData()
            : [];
        $completenessData = $activePage === 'records-completeness'
            ? $this->buildCompletenessViewData()
            : [];

        // Reference Data page: the lookup tables share one page, switched by ?tab=.
        // Only the active tab's list bundle is built (the tab strip is server-side
        // and only the active pane renders). Categories and Subsidy Types are
        // managers-only tabs; everyone else gets the two read-only lists.
        $referenceTabs = $canManageAccounts
            ? ['sectors', 'services', 'categories', 'subsidy-types']
            : ['sectors', 'services'];
        $referenceTab = (string) $this->request->getGet('tab');
        $referenceTab = in_array($referenceTab, $referenceTabs, true) ? $referenceTab : 'sectors';
        $isReference = $activePage === 'reference-data';

        $sectorListData = $isReference && $referenceTab === 'sectors'
            ? $this->buildLookupListData($sectorModel, 'reference-data', 'sectorID')
            : [];
        $serviceListData = $isReference && $referenceTab === 'services'
            ? $this->buildLookupListData($serviceModel, 'reference-data', 'serviceID')
            : [];
        $categoryListData = $isReference && $referenceTab === 'categories'
            ? $this->buildLookupListData(new CategoryModel(), 'reference-data', 'categoryID')
            : [];
        $subsidyTypeListData = $isReference && $referenceTab === 'subsidy-types'
            ? $this->buildLookupListData(model(SubsidyTypeModel::class), 'reference-data', 'subsidy_type_id')
            : [];

        // Distribution page: batches and the log share one page, switched by
        // ?tab=. Data gated so other pages don't run these queries.
        $distributionTab = (string) $this->request->getGet('tab');
        $distributionTab = in_array($distributionTab, ['schedule', 'batches', 'log'], true) ? $distributionTab : 'schedule';
        $isSchedule      = $activePage === 'distribution' && $distributionTab === 'schedule';
        $isBatches       = $activePage === 'distribution' && $distributionTab === 'batches';
        $isDistributions = $activePage === 'distribution' && $distributionTab === 'log';
        $batchModel      = model(DistributionBatchModel::class);
        $distributionListData = $isDistributions
            ? $this->buildDistributionListData()
            : [];

        // The dashboard batch zone had its own ?tab= (Barangay / Stations /
        // Remaining) until those three became independent cards that all render
        // at once. Nothing on this pane switches on ?tab= any more, so the
        // parameter is not read here; ?tab= belongs to the Distribution page
        // alone ($distributionTab above).

        // Two panes share the dashboard page. An unknown ?view= falls back to
        // Overview rather than erroring, the same way an out-of-batch ?day=
        // falls back to all days in buildReportsData().
        $dashboardView = (string) $this->request->getGet('view');
        $dashboardView = in_array($dashboardView, ['overview', 'distribution'], true) ? $dashboardView : 'overview';

        // The batch the outer tab strip carries between the two panes. On the
        // Overview pane it can only come off the query, since no batch data is
        // assembled there and the selection still has to survive the trip. On
        // the Distribution pane the resolved id wins instead (below), because
        // that is the batch on screen: with no ?batch= the page shows the open
        // one, and an unknown ?batch= resolves to nothing, which the strip
        // should stop carrying rather than hand to the other pane.
        $requestedBatch  = $this->request->getGet('batch');
        $selectedBatchId = is_scalar($requestedBatch) ? max(0, (int) $requestedBatch) : 0;

        // Distribution analytics now live on the dashboard (combined totals +
        // per-kiosk table), batch-scoped only (no date filter). Gated on the
        // page and the pane, not on the role: every role reaches the dashboard
        // and both panes, but neither pane pays for the other's queries.
        $reportsData = $isDashboard && $dashboardView === 'distribution'
            ? $this->buildReportsData($batchModel)
            : [
                'batches'        => [],
                'batchId'        => 0,
                'batchRow'       => null,
                'batchOpen'      => false,
                'batchSnapshot'  => $this->emptyBatchSnapshot(),
                'selectedDay'    => null,
                'weekdayHeatmap' => ['days' => [], 'hours' => [], 'cells' => [], 'max' => 0],
                'remainingPage'  => $this->emptyRemainingPage(),
            ];

        // Hide the logged-in user's own account from Account Management; self-service
        // changes belong in My Account.
        $currentUserId = (int) session()->get('user_id');
        $visibleAccounts = $currentUserId > 0
            ? array_values(array_filter($users, static fn ($account) => (int) ($account['userID'] ?? 0) !== $currentUserId))
            : $users;

        $isActiveStatus = static function (mixed $value): bool {
            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (int) $value === 1;
            }

            $normalized = strtolower(trim((string) $value));

            return in_array($normalized, ['enable', 'enabled', 'active', '1', 'true', 'yes', 'on'], true);
        };

        $viewData = [
            'user' => $this->currentSessionUser(),
            'activePage' => $activePage,
            'role' => $currentRole ?? '',
            'modeLabel' => $layoutModel->adminModeLabel($isDeveloper),
            // Developers and admins share account-management features. Developer
            // targets remain protected, and only Developers may toggle Administrators.
            'canManageAccounts' => $canManageAccounts,
            'canCreateAccounts' => $canManageAccounts,
            'canEditAccounts' => $canManageAccounts,
            'canManageLookups' => $canManageAccounts,
            'currentRole' => $currentRole,
            'referenceTabs'      => $referenceTabs,
            'myAudits'           => $myAudits,
            'adminAccounts'      => array_values(array_filter($visibleAccounts, static fn ($account) => $account['role'] === 'administrator')),
            // 'encoder' is the raw DB enum value for the Encoder role; the rows here
            // come straight from the users table (account_level aliased back to
            // `role` by SearchModel::staffAccounts()).
            'employeeAccounts'   => array_values(array_filter($visibleAccounts, static fn ($account) => $account['role'] === 'encoder')),
            'viewerAccounts'     => array_values(array_filter($visibleAccounts, static fn ($account) => $account['role'] === 'viewer')),
            'scannerAccounts'    => array_values(array_filter($visibleAccounts, static fn ($account) => $account['role'] === 'scanner')),
            'recentAudits'       => $recentAudits,
            'auditListData'      => $auditListData,
            'recordListData'      => $memberListData,
            'memberListData'      => $memberListData,
            'sectors'            => $sectorListData['rows'] ?? ($isReference ? $this->fetchVisibleSectors($sectorModel) : []),
            'services'           => $serviceListData['rows'] ?? ($isReference ? $this->fetchVisibleServices($serviceModel) : []),
            'categories'         => $categoryListData['rows'] ?? ($isReference ? $this->fetchVisibleCategories(new CategoryModel()) : []),
            'referenceTab'       => $referenceTab,
            'distributionTab'    => $distributionTab,
            'sectorListData'     => $sectorListData,
            'serviceListData'    => $serviceListData,
            'categoryListData'   => $categoryListData,
            // Same "all batches" list feeds the Distribution page's Batches tab
            // and the dashboard's batch selector; only one of the two gates is
            // ever true for a given page load.
            'batches'            => $isBatches ? $batchModel->allBatches() : ($reportsData['batches'] ?? []),
            'activeBatch'        => $isBatches ? $batchModel->activeBatch() : null,
            'activeSubsidyTypes' => ($isBatches || $isSchedule) ? model(SubsidyTypeModel::class)->active() : [],
            // Barangay/sector option lists for the schedule and batch-open forms'
            // eligibility filters (Task 10). Only fetched on those tabs.
            'barangayOptions'    => ($isBatches || $isSchedule) ? model(BarangayModel::class)->activeList() : [],
            'batchSectorOptions' => ($isBatches || $isSchedule) ? $sectorModel->getActive() : [],
            // Access Cards page: the batch filter's barangay dropdown. Names, not
            // ids, because headsForCards() compares against the stored name.
            'cardBarangayNames'  => $activePage === 'cards' ? model(BarangayModel::class)->activeNames() : [],
            'scheduleColors'     => $isSchedule ? DistributionBatchModel::COLORS : [],
            'venueSuggestions'   => $isSchedule ? $this->venueSuggestions($batchModel) : [],
            'subsidyTypes'       => $subsidyTypeListData['rows'] ?? [],
            'subsidyTypeListData' => $subsidyTypeListData,
            'distributionListData' => $distributionListData,
            'distributions'      => $distributionListData['rows'] ?? [],
            'batchRow'           => $reportsData['batchRow'],
            'batchOpen'          => $reportsData['batchOpen'],
            'batchSnapshot'      => $reportsData['batchSnapshot'],
            'remainingPage'      => $reportsData['remainingPage'],
            'selectedDay'        => $reportsData['selectedDay'],
            'weekdayHeatmap'     => $reportsData['weekdayHeatmap'],
            'batchHeadline'      => self::batchHeadline(
                $reportsData['batchSnapshot'],
                $reportsData['selectedDay']
            ),
            'dashboardView'      => $dashboardView,
            'selectedBatchId'    => $isDashboard && $dashboardView === 'distribution'
                ? (int) ($reportsData['batchId'] ?? 0)
                : $selectedBatchId,
            'overviewStats'      => $isDashboard && $dashboardView === 'overview'
                ? $dashboardModel->programStats()
                : ['families' => 0, 'cardsIssued' => 0, 'distributions' => 0, 'everServed' => 0, 'neverServed' => 0],
            'distributionRows'   => $isDashboard && $dashboardView === 'overview'
                ? $this->buildDistributionRows($batchModel)
                : [],
            'upcomingSchedule'   => $isDashboard && $dashboardView === 'overview'
                ? $this->buildUpcomingSchedule($batchModel)
                : [],
            'scheduleGrid'       => $isDashboard && $dashboardView === 'overview'
                ? $this->buildScheduleGrid($batchModel)
                : ['weeks' => [], 'bars' => []],
            // Only the roles that may open the record-entry page get the Add and
            // Import buttons on the records list (Config\Navigation, records-entry).
            'canCreateFamily'    => in_array($currentRole, Navigation::pageRoles('records-entry'), true),
            'username'           => (string) (session()->get('username') ?? 'Admin'),
            'accountLevelLabel'  => SessionAccount::levelLabel(),
            'searchTerm'         => $searchTerm,
            'searchFilters'      => $searchFilters,
            'hasSearchFilters'   => $hasSearchFilters,
            'selectedFilterDate' => (string) ($searchFilters['date'] ?? $searchFilters['date_from'] ?? ''),
            'sectorOptions'      => $sectorOptions,
            'auditActionOptions' => $searchModel->auditActions(),
            'idleTimeoutSeconds' => (new IdleTimeout())->seconds,
            'isDeveloper'        => $isDeveloper,
            'isAdmin'            => $isAdmin,
            'showAdminActions'   => $isDeveloper,
            'showEmployeeActions' => $isDeveloper || $isAdmin,
            'adminColspan'       => $isDeveloper ? 5 : 4,
            'employeeColspan'    => ($isDeveloper || $isAdmin) ? 5 : 4,
            'adminColumnClass'   => $isDeveloper ? 'col-lg-6' : 'col-lg-12',
            'employeeColumnClass' => $isDeveloper ? 'col-lg-6' : 'col-lg-12',
            'isActiveStatus'     => $isActiveStatus,
            'formatStatus'       => static function (mixed $value) use ($isActiveStatus): string {
                return $isActiveStatus($value) ? 'Enable' : 'Disabled';
            },
            'formatDate'         => static function (mixed $value): string {
                $timestamp = strtotime((string) $value);

                return $timestamp === false ? '' : date('Y-m-d', $timestamp);
            },
            'formatTime'         => static function (mixed $value): string {
                $timestamp = strtotime((string) $value);

                return $timestamp === false ? '' : date('h:i A', $timestamp);
            },
            'formatAuditMember'  => static function (array $audit): string {
                $memberName = trim((string) ($audit['member_name'] ?? ''));

                if ($memberName === '') {
                    $memberName = trim((string) ($audit['firstname'] ?? '') . ' ' . (string) ($audit['lastname'] ?? ''));
                }

                return $memberName === '' ? '-' : $memberName;
            },
            'formatAuditUser'    => static function (array $audit): string {
                $username = trim((string) ($audit['username'] ?? $audit['userID'] ?? ''));
                $role     = trim((string) ($audit['user_role'] ?? ''));
                $role     = RoleAccess::normalizeRole($role) ?? $role;

                return $role === '' ? $username : $username . ' (' . $role . ')';
            },
        ];

        // The shell renders one body view. Everything above is already shared view
        // data, so only the records list and the completeness queue need their own
        // bundles handed over.
        $viewData['bodyView'] = self::BODY_VIEWS[$activePage] ?? 'Pages/dashboard';
        $viewData['bodyData'] = $activePage === 'records' ? $memberListData : $completenessData;

        return $viewData;
    }

    /** Public entry for the records-list AJAX partial (DashboardPartialsTrait). */
    public function buildRecordListViewData(): array
    {
        return $this->buildMemberListData();
    }

    /**
     * The Data Completeness page: every family whose records carry a blank the
     * import now demotes to a warning, shaped as a chase list. Families with
     * HEAD-level gaps sort first (a head with no barangay is a more urgent chase
     * than a child with no education), then by total gap count. ?barangay= and
     * ?field= narrow the table; the tiles always describe the whole queue.
     */
    public function buildCompletenessViewData(): array
    {
        $rows      = (new MemberModel())->completenessRows();
        $qrByHead  = (new QrControlModel())->controlsForHeads(array_column($rows['heads'], 'memberID'));
        $barangays = (new \App\Models\Lookups\BarangayModel())->nameMap();

        $membersByHead = [];

        foreach ($rows['members'] as $member) {
            $membersByHead[(int) $member['headID']][] = $member;
        }

        $families = [];

        foreach ($rows['heads'] as $head) {
            $headID        = (int) $head['memberID'];
            $activeMembers = $membersByHead[$headID] ?? [];
            $members       = [];

            foreach ($activeMembers as $member) {
                $gaps = self::blankLabels($member, self::COMPLETENESS_LABELS);

                if ($gaps === []) {
                    continue;
                }

                $members[] = [
                    'name'         => trim(($member['firstname'] ?? '') . ' ' . ($member['lastname'] ?? '')),
                    'relationship' => (string) ($member['relationship'] ?? 'MEMBER'),
                    'gaps'         => $gaps,
                ];
            }

            $headGaps = array_merge(
                self::blankLabels($head, self::COMPLETENESS_LABELS),
                self::blankLabels($head, self::HEAD_COMPLETENESS_LABELS)
            );

            if ($headGaps === [] && $members === []) {
                continue;
            }

            $families[] = [
                'headID'   => $headID,
                'qr'       => $qrByHead[$headID] ?? null,
                'head'     => trim(($head['firstname'] ?? '') . ' ' . ($head['lastname'] ?? '')),
                'barangay' => (string) ($barangays[(int) ($head['barangayID'] ?? 0)] ?? ''),
                'headGaps'    => $headGaps,
                'members'     => $members,
                'memberCount' => 1 + count($activeMembers),
                'gapCount'    => count($headGaps) + array_sum(array_map(static fn (array $m): int => count($m['gaps']), $members)),
            ];
        }

        usort($families, static function (array $a, array $b): int {
            return [$a['headGaps'] === [], $b['gapCount']] <=> [$b['headGaps'] === [], $a['gapCount']];
        });

        // Tiles describe the whole queue, before any filter narrows the table.
        $tiles = ['families' => count($families), 'headGaps' => 0];
        $fieldTotals = [];

        foreach ($families as $family) {
            $tiles['headGaps'] += $family['headGaps'] !== [] ? 1 : 0;

            foreach ($family['headGaps'] as $gap) {
                $fieldTotals[$gap] = ($fieldTotals[$gap] ?? 0) + 1;
            }

            foreach ($family['members'] as $member) {
                foreach ($member['gaps'] as $gap) {
                    $fieldTotals[$gap] = ($fieldTotals[$gap] ?? 0) + 1;
                }
            }
        }

        // ?field= keeps only families carrying that gap; ?barangay= matches the name.
        $filterField    = trim((string) $this->request->getGet('field'));
        $filterBarangay = trim((string) $this->request->getGet('barangay'));

        if ($filterField !== '') {
            $families = array_values(array_filter($families, static function (array $f) use ($filterField): bool {
                if (in_array($filterField, $f['headGaps'], true)) {
                    return true;
                }

                foreach ($f['members'] as $member) {
                    if (in_array($filterField, $member['gaps'], true)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($filterBarangay !== '') {
            $families = array_values(array_filter($families,
                static fn (array $f): bool => strcasecmp($f['barangay'], $filterBarangay) === 0));
        }

        $perPage = 25;
        $page    = max(1, (int) $this->request->getGet('page'));

        return [
            'tiles'          => $tiles + ['byField' => $fieldTotals],
            'families'       => array_slice($families, ($page - 1) * $perPage, $perPage),
            'allFamilies'    => $families,                     // the download wants every matching row
            'filterBarangay' => $filterBarangay,
            'filterField'    => $filterField,
            'page'           => $page,
            'perPage'        => $perPage,
            'pageCount'      => (int) ceil(count($families) / $perPage) ?: 1,
        ];
    }

    /**
     * The completeness queue as an .xlsx download, honouring the page's filters.
     * Read-only: no mutation, therefore no audit row.
     */
    public function completenessDownloadResponse(): \CodeIgniter\HTTP\ResponseInterface
    {
        $data        = $this->buildCompletenessViewData();
        $spreadsheet = DataCompletenessExport::build($data['allFamilies']);

        $response = service('response');
        $response->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setHeader('Content-Disposition', 'attachment; filename="data-completeness-' . date('Y-m-d') . '.xlsx"');

        ob_start();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        $response->setBody((string) ob_get_clean());

        return $response;
    }

    /** @param array<string, string|null> $row @param array<string, string> $labels @return list<string> */
    private static function blankLabels(array $row, array $labels): array
    {
        $gaps = [];

        foreach ($labels as $field => $label) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $gaps[] = $label;
            }
        }

        return $gaps;
    }

    /**
     * Venues used before, newest first, for the schedule form's datalist. A
     * venue reference table would be disproportionate: venues repeat rarely and
     * a datalist gives most of the convenience.
     *
     * @return list<string>
     */
    private function venueSuggestions(DistributionBatchModel $batchModel): array
    {
        $seen = [];

        foreach ($batchModel->allBatches() as $batch) {
            $venue = trim((string) ($batch['venue'] ?? ''));
            if ($venue !== '' && ! in_array($venue, $seen, true)) {
                $seen[] = $venue;
            }
        }

        return $seen;
    }

    /** All sectors (active + archived) ordered by ID, for the admin sectors view. */
    private function fetchVisibleSectors(SectorModel $sectorModel): array
    {
        if (! $sectorModel->hasTable()) {
            return [];
        }

        return $sectorModel
            ->orderBy('sectorID', 'ASC')
            ->findAll();
    }

    /** All services (active + archived) ordered by ID, for the admin services view. */
    private function fetchVisibleServices(ServiceModel $serviceModel): array
    {
        if (! $serviceModel->hasTable()) {
            return [];
        }

        return $serviceModel
            ->orderBy('serviceID', 'ASC')
            ->findAll();
    }

    /** All categories (active + archived), official first, for the Manage Categories view. */
    private function fetchVisibleCategories(CategoryModel $categoryModel): array
    {
        if (! $categoryModel->hasTable()) {
            return [];
        }

        return $categoryModel->getAllIncluding();
    }

    /**
     * Builds a paginated lookup-management list (Sectors / Services / Categories).
     * Reads the q/status/page query params, runs the model's status-aware keyword
     * search (25/page), and returns the row page plus pagination + count metadata.
     * The model must expose searchLookup()/countLookup()/statusCounts() (all three
     * lookup models do). Frontend: the Lookups/* views + their database-search bar,
     * status dropdown and pagination controls.
     *
     * @param object $model     A lookup model (SectorModel|ServiceModel|CategoryModel).
     * @param string $listRoute Full-page route the search/pagination forms post to.
     * @param string $idField   Primary-key column name (unused in the query; kept for clarity).
     */
    private function buildLookupListData(object $model, string $listRoute, string $idField): array
    {
        $keyword = trim((string) $this->request->getGet('q'));
        $status  = strtolower(trim((string) $this->request->getGet('status')));
        $status  = in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all';
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPageOptions = [10, 25, 50, 100];
        $perPage = (int) $this->request->getGet('per_page');
        $perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 25;

        $searchKeyword = $keyword === '' ? null : $keyword;
        $total      = $model->countLookup($searchKeyword, $status);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $counts     = $model->statusCounts();

        return [
            'rows'          => $model->searchLookup($searchKeyword, $status, $perPage, ($page - 1) * $perPage),
            'keyword'       => $keyword,
            'status'        => $status,
            'page'          => $page,
            'perPage'       => $perPage,
            'perPageOptions'=> $perPageOptions,
            'totalPages'    => $totalPages,
            'totalRows'     => $total,
            'fromRecord'    => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'toRecord'      => min($total, $page * $perPage),
            'activeCount'   => (int) ($counts['active'] ?? 0),
            'archivedCount' => (int) ($counts['archived'] ?? 0),
            'listRoute'     => $listRoute,
        ];
    }

    /**
     * Builds a paginated audit-trail list bundle for the admin Audit Trails page
     * and the employee Activity page. Mirrors buildLookupListData(): reads the
     * q/action/page/per_page query params, runs the keyword + action/date search
     * (paginated), and returns the row page plus pagination + count metadata. The
     * action filter reuses searchFilters(). Frontend: the audit views' database
     * search bar, show-entries selector and pagination controls.
     *
     * $includeDeveloper shows Developer (NULL-userID) rows (admin only); $userId scopes
     * to one user's own rows (employee Activity), or null for all users.
     */
    private function buildAuditListData(bool $includeDeveloper, ?int $userId, string $listRoute): array
    {
        $searchModel = new SearchModel();
        $keyword = trim((string) $this->request->getGet('q'));
        $filters = $this->searchFilters();
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPageOptions = [10, 25, 50, 100];
        $perPage = (int) $this->request->getGet('per_page');
        $perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 25;

        $total = $userId === null
            ? $searchModel->countAuditTrails($keyword, $filters, $includeDeveloper)
            : $searchModel->countAuditTrailsByUser($userId, $keyword, $filters);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $userId === null
            ? $searchModel->auditTrails($keyword, $filters, $perPage, $includeDeveloper, $offset)
            : $searchModel->auditTrailsByUser($userId, $keyword, $filters, $perPage, $offset);

        return [
            'rows'          => $rows,
            'keyword'       => $keyword,
            'page'          => $page,
            'perPage'       => $perPage,
            'perPageOptions'=> $perPageOptions,
            'totalPages'    => $totalPages,
            'totalRows'     => $total,
            'fromRecord'    => $total === 0 ? 0 : $offset + 1,
            'toRecord'      => min($total, $page * $perPage),
            'listRoute'     => $listRoute,
        ];
    }

    /**
     * Builds the Distribution Log's paginated bundle. Mirrors
     * buildAuditListData(): reads q/page/per_page off the query, asks the model
     * for one page and the matching total, and returns the rows plus the
     * pagination metadata the view turns into links. Frontend: the Distribution
     * page's log tab.
     */
    private function buildDistributionListData(): array
    {
        $keyword = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPageOptions = [10, 25, 50, 100];
        $perPage = (int) $this->request->getGet('per_page');
        $perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 25;

        $model      = model(SubsidyDistributionModel::class);
        $total      = $model->countDistributions($keyword);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        return [
            'rows'          => $model->distributionsPage($keyword, $perPage, $offset),
            'keyword'       => $keyword,
            'page'          => $page,
            'perPage'       => $perPage,
            'perPageOptions'=> $perPageOptions,
            'totalPages'    => $totalPages,
            'totalRows'     => $total,
            'fromRecord'    => $total === 0 ? 0 : $offset + 1,
            'toRecord'      => min($total, $page * $perPage),
        ];
    }

    /**
     * Batch-scoped data for the dashboard's "this batch" zone: the cached
     * coverage/barangay/scanner/timeline snapshot plus one page of the
     * remaining-families list. The scoping batch defaults to the active batch,
     * else the most recent batch, and honors ?batch= when it matches a known
     * batch (BatchScope::resolve(), shared with the reports endpoint and the
     * scanner performance page).
     *
     * The remaining-families list used to be gated on its sub-tab being the one
     * showing. The pane is cards now and every card renders, so the query runs
     * on every Distribution-pane load. It stays paginated at the database
     * (buildRemainingPageData()) rather than fetched whole, which is what
     * actually keeps it affordable against the 100k-family target;
     * SubsidyStatsModel::remaining() lists everyone in one response and is not
     * what this calls. The card is still the only place that list appears: the
     * report PDF carries the count, not the names.
     */
    private function buildReportsData(DistributionBatchModel $batchModel): array
    {
        $batches = $batchModel->allBatches();
        $active  = $batchModel->activeBatch();

        [$batchId, $batch] = BatchScope::resolve($batches, $active, (int) $this->request->getGet('batch'));

        $isOpen = $batch !== null && ($batch['closed_at'] ?? null) === null;
        $stats  = model(SubsidyStatsModel::class);

        $snapshot = $batchId > 0 ? $stats->batchSnapshot($batchId, $isOpen) : $this->emptyBatchSnapshot();

        // A day that is not in this batch falls back to "all days" rather than
        // rendering a card full of zeroes. The only way to reach that state is
        // an edited URL or a link to a batch that has since been reimported.
        $requestedDay = (string) ($this->request->getGet('day') ?? '');
        $selectedDay  = in_array($requestedDay, $snapshot['days'] ?? [], true) ? $requestedDay : null;

        return [
            'batches'        => $batches,
            'batchId'        => $batchId,
            'batchRow'       => $batch,
            'batchOpen'      => $isOpen,
            'batchSnapshot'  => $snapshot,
            'selectedDay'    => $selectedDay,
            'weekdayHeatmap' => $this->weekdayHeatmap($stats),
            'remainingPage'  => $batchId > 0
                ? $this->buildRemainingPageData($stats, $batchId)
                : $this->emptyRemainingPage(),
        ];
    }

    /**
     * The all-time weekday grid, in the same shape ScannerMetrics::heatmap()
     * returns so one partial renders both views. Rows are the integer weekday,
     * 0 for Sunday, which is what weekdayHistogram() normalises to across both
     * database backends.
     *
     * Every hour a scan has ever landed in is a column; there is no daily
     * window to widen from, because this spans every batch the city has run and
     * they do not share one. Nothing here can be "closed" for that reason.
     *
     * Cached on its own key rather than the batch fingerprint: it is not about
     * a batch, and it changes only when scanning happens.
     *
     * @return array{days:list<string>,hours:list<int>,cells:array<string,array<int,array{families:int,state:string}>>,max:int}
     */
    private function weekdayHeatmap(SubsidyStatsModel $stats): array
    {
        $cached = cache('subsidy_weekday_heatmap');
        if (is_array($cached)) {
            return $cached;
        }

        $cells = [];
        $hours = [];
        $max   = 0;

        foreach ($stats->weekdayHistogram() as $bucket) {
            $day   = (string) $bucket['dow'];
            $hour  = (int) $bucket['hour'];
            $count = (int) $bucket['families'];

            $hours[$hour] = true;
            $max          = max($max, $count);

            $cells[$day][$hour] = ['families' => $count, 'state' => 'served'];
        }

        $hourList = array_keys($hours);
        sort($hourList);

        $days = array_keys($cells);
        sort($days);

        // Fill the gaps so every row has every column; an hour some weekday
        // never saw is a genuine zero here, not a closed station.
        foreach ($days as $day) {
            foreach ($hourList as $hour) {
                $cells[$day][$hour] ??= ['families' => 0, 'state' => 'empty'];
            }
            ksort($cells[$day]);
        }

        $grid = ['days' => $days, 'hours' => $hourList, 'cells' => $cells, 'max' => $max];
        cache()->save('subsidy_weekday_heatmap', $grid, 600);

        return $grid;
    }

    /**
     * The four figures across the top of the Distribution pane, for the day the
     * reader picked or for the whole batch when they picked none. Assembled
     * here rather than in the view because three of the four are derived
     * readings rather than snapshot fields, and because the day filter's
     * JavaScript (batch-heatmap.js) recomputes the same four from the same
     * payload: two derivations that have to agree, so the rule each one follows
     * is written down once, here.
     *
     * Eligible is the only one that ignores the day: a family is eligible for
     * the batch, not for a Tuesday. Scanners active does re-scope by day: the
     * snapshot's byScannerByDay rows carry the same per-day slice the PDF's
     * Rollout by day table already counts from
     * (Scanner/ReportsPdfGenerator::dayRows()), so the screen counts from the
     * same field rather than repeating the batch-wide byScanner total under a
     * day heading.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,array{value:string,sub:string}>
     */
    private static function batchHeadline(array $snapshot, ?string $selectedDay): array
    {
        $coverage = $snapshot['coverage'] ?? ['eligible' => 0, 'served' => 0, 'coverage' => 0];
        $heatmap  = $snapshot['heatmap'] ?? ['days' => [], 'hours' => [], 'cells' => [], 'max' => 0];

        // Scanner rows carry a TOTAL row last (userID 0), which is a fold of the
        // rows above it and not a station that turned up. With a day selected,
        // count that day's own rows from byScannerByDay instead of the
        // batch-wide byScanner fold, or a station that never showed up that day
        // still gets counted.
        $stations = 0;
        $scannerRows = $selectedDay === null
            ? ($snapshot['byScanner'] ?? [])
            : ($snapshot['byScannerByDay'][$selectedDay] ?? []);
        foreach ($scannerRows as $row) {
            if ((int) ($row['userID'] ?? 0) > 0) {
                $stations++;
            }
        }

        $servedOnDay = 0;
        $byHour      = [];
        foreach ($heatmap['cells'] as $day => $hours) {
            if ($selectedDay !== null && (string) $day !== $selectedDay) {
                continue;
            }

            foreach ($hours as $hour => $cell) {
                $families = (int) $cell['families'];
                $servedOnDay += $families;
                $byHour[(int) $hour] = ($byHour[(int) $hour] ?? 0) + $families;
            }
        }

        $peakHour     = null;
        $peakFamilies = 0;
        foreach ($byHour as $hour => $families) {
            if ($families > $peakFamilies) {
                $peakHour     = $hour;
                $peakFamilies = $families;
            }
        }

        $servedValue = $selectedDay === null ? (int) $coverage['served'] : $servedOnDay;

        return [
            'eligible' => [
                'value' => number_format((int) $coverage['eligible']),
                'sub'   => 'in this batch',
            ],
            'served' => [
                'value' => number_format($servedValue),
                'sub'   => $selectedDay === null
                    ? $coverage['coverage'] . '% of eligible'
                    : number_format((int) $coverage['served']) . ' across the batch',
            ],
            'peakHour' => [
                'value' => $peakHour === null
                    ? '-'
                    : date('ga', mktime($peakHour, 0)) . ' - ' . date('ga', mktime($peakHour + 1, 0)),
                'sub' => $peakHour === null ? 'no scans yet' : number_format($peakFamilies) . ' families',
            ],
            'scannersActive' => [
                'value' => number_format($stations),
                'sub'   => $selectedDay === null ? 'across the batch' : 'that day',
            ],
        ];
    }

    /**
     * Every distribution with its outcome, newest first, for the Overview
     * tab's table. Served comes from one grouped query rather than a call per
     * batch, so the table costs two queries regardless of how many batches
     * the city has run.
     *
     * @return list<array<string,mixed>>
     */
    private function buildDistributionRows(DistributionBatchModel $batchModel): array
    {
        $served = model(SubsidyStatsModel::class)->servedByBatch();

        return array_map(static function (array $batch) use ($served): array {
            $batchId  = (int) $batch['batch_id'];
            $eligible = (int) ($batch['eligible_count'] ?? 0);
            $count    = $served[$batchId] ?? 0;

            return $batch + [
                'eligible' => $eligible,
                'served'   => $count,
                'coverage' => $eligible === 0 ? 0 : (int) round($count / $eligible * 100),
            ];
        }, $batchModel->allBatches());
    }

    /**
     * At most two plotted batches for the dashboard's schedule card, earliest
     * first: the one running now counts as one of the two, so a running batch
     * leaves room for a single upcoming one. Closed batches are skipped.
     *
     * @return list<array{batch_id:int,name:string,venue:string,start:string,end:string,dailyStart:string,dailyEnd:string,color:string,status:string}>
     */
    private function buildUpcomingSchedule(DistributionBatchModel $batchModel): array
    {
        $today = date('Y-m-d');
        $rows  = [];

        foreach ($batchModel->scheduledBetween($today, date('Y-m-d', strtotime('+6 months'))) as $batch) {
            $status = 'upcoming';
            if ($batch['closed_at'] !== null) {
                continue;
            }
            if ($batch['started_at'] !== null) {
                $status = 'running';
            }

            $rows[] = [
                'batch_id'   => (int) $batch['batch_id'],
                'name'       => (string) $batch['name'],
                'venue'      => (string) $batch['venue'],
                'start'      => (string) $batch['scheduled_start'],
                'end'        => (string) $batch['scheduled_end'],
                'dailyStart' => substr((string) $batch['daily_start_time'], 0, 5),
                'dailyEnd'   => substr((string) $batch['daily_end_time'], 0, 5),
                'color'      => (string) $batch['color'],
                'status'     => $status,
            ];

            if (count($rows) === 2) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The dashboard schedule card's month grid, as cells to print day numbers
     * and bars to draw across them, so the view never touches date arithmetic.
     *
     * The leading and trailing blanks of the first and last week carry the
     * adjacent month's real day numbers (`isOutside` true) rather than being
     * left empty, matching a normal month calendar. A bar is one batch's
     * contiguous run of days within a single week row: a batch is clipped to
     * the visible month first, then split at each week boundary it crosses,
     * so a run over a Sunday becomes two bars and a run crossing into another
     * month is cut off at the edge. `startCol` and `lane` are both 0 based;
     * `lane` only rises above 0 when two batches cover the same days in the
     * same week, which the scheduler otherwise refuses.
     *
     * @return array{
     *     weeks: list<list<array{day: int, isToday: bool, isOutside: bool}>>,
     *     bars: list<array{weekIndex: int, startCol: int, span: int, lane: int, color: string, status: string, name: string}>
     * }
     */
    private function buildScheduleGrid(DistributionBatchModel $batchModel): array
    {
        $monthStart  = date('Y-m-01');
        $monthEnd    = date('Y-m-t');
        $today       = date('Y-m-d');
        $leading     = (int) date('w', strtotime($monthStart));
        $daysInMonth = (int) date('t');
        $weekCount   = (int) ceil(($leading + $daysInMonth) / 7);

        $weeks = [];
        for ($w = 0; $w < $weekCount; $w++) {
            $week = [];
            for ($c = 0; $c < 7; $c++) {
                $dayNum    = $w * 7 + $c - $leading + 1;
                $isDay     = $dayNum >= 1 && $dayNum <= $daysInMonth;
                $cellDate  = date('Y-m-d', strtotime($monthStart . ' ' . ($dayNum - 1) . ' days'));
                $week[]    = [
                    'day'       => (int) date('j', strtotime($cellDate)),
                    'isToday'   => $isDay && $cellDate === $today,
                    'isOutside' => ! $isDay,
                ];
            }
            $weeks[] = $week;
        }

        $bars = [];
        foreach ($batchModel->scheduledBetween($monthStart, $monthEnd) as $batch) {
            $start = max((string) $batch['scheduled_start'], $monthStart);
            $end   = min((string) $batch['scheduled_end'], $monthEnd);
            if ($start > $end) {
                continue;
            }

            $status = 'upcoming';
            if ($batch['closed_at'] !== null) {
                $status = 'done';
            } elseif ($batch['started_at'] !== null) {
                $status = 'running';
            }

            $startOffset = $leading + (int) date('j', strtotime($start)) - 1;
            $endOffset   = $leading + (int) date('j', strtotime($end)) - 1;

            for ($weekIndex = intdiv($startOffset, 7); $weekIndex <= intdiv($endOffset, 7); $weekIndex++) {
                $weekFrom = $weekIndex * 7;
                $weekTo   = $weekFrom + 6;
                $segFrom  = max($startOffset, $weekFrom);
                $segTo    = min($endOffset, $weekTo);

                $bars[] = [
                    'weekIndex' => $weekIndex,
                    'startCol'  => $segFrom - $weekFrom,
                    'span'      => $segTo - $segFrom + 1,
                    'lane'      => 0,
                    'color'     => (string) $batch['color'],
                    'status'    => $status,
                    'name'      => (string) $batch['name'],
                ];
            }
        }

        return ['weeks' => $weeks, 'bars' => self::assignScheduleLanes($bars)];
    }

    /**
     * Gives each bar the lowest lane index that keeps it clear of every other
     * bar sharing its week and columns, so two batches covering the same days
     * stack into separate rows instead of drawing on top of each other. The
     * scheduler refuses overlapping dates, so in practice every bar lands on
     * lane 0; this only matters if that rule is ever relaxed.
     *
     * @param list<array{weekIndex: int, startCol: int, span: int, lane: int, color: string, status: string, name: string}> $bars
     * @return list<array{weekIndex: int, startCol: int, span: int, lane: int, color: string, status: string, name: string}>
     */
    private static function assignScheduleLanes(array $bars): array
    {
        $lanesByWeek = [];

        foreach ($bars as &$bar) {
            $lane = 0;
            while (self::laneTaken($lanesByWeek, $bar, $lane)) {
                $lane++;
            }
            $bar['lane']                             = $lane;
            $lanesByWeek[$bar['weekIndex']][$lane][] = $bar;
        }

        return $bars;
    }

    /** Whether any bar already placed in this week and lane shares a column with $bar. */
    private static function laneTaken(array $lanesByWeek, array $bar, int $lane): bool
    {
        foreach ($lanesByWeek[$bar['weekIndex']][$lane] ?? [] as $placed) {
            if ($placed['startCol'] < $bar['startCol'] + $bar['span']
                && $bar['startCol'] < $placed['startCol'] + $placed['span']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paginated remaining-families bundle for the dashboard's Remaining tab.
     * The 'q' query param (family name or barangay) routes through
     * SubsidyStatsModel::remainingBuilder() for both the count and the page,
     * so the footer total and the rows shown can never disagree.
     */
    private function buildRemainingPageData(SubsidyStatsModel $stats, int $batchId): array
    {
        $perPageOptions = [10, 25, 50, 100];
        $keyword = trim((string) $this->request->getGet('q'));
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = (int) $this->request->getGet('per_page');
        $perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 25;

        $searchKeyword = $keyword === '' ? null : $keyword;
        $total      = $stats->remainingCount($batchId, $searchKeyword);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);

        return [
            'rows'          => $stats->remainingPage($batchId, $perPage, ($page - 1) * $perPage, $searchKeyword),
            'keyword'       => $keyword,
            'page'          => $page,
            'perPage'       => $perPage,
            'perPageOptions'=> $perPageOptions,
            'totalPages'    => $totalPages,
            'totalRows'     => $total,
            'fromRecord'    => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'toRecord'      => min($total, $page * $perPage),
        ];
    }

    /** The zero shape for buildRemainingPageData(), used when the tab isn't active or no batch is scoped. */
    private function emptyRemainingPage(): array
    {
        return [
            'rows' => [], 'keyword' => '', 'page' => 1, 'perPage' => 25, 'perPageOptions' => [10, 25, 50, 100],
            'totalPages' => 1, 'totalRows' => 0, 'fromRecord' => 0, 'toRecord' => 0,
        ];
    }

    /** The zero shape for SubsidyStatsModel::batchSnapshot(), used when no batch is scoped. */
    private function emptyBatchSnapshot(): array
    {
        return [
            'coverage'       => ['eligible' => 0, 'served' => 0, 'remaining' => 0, 'coverage' => 0, 'voided' => 0],
            'byBarangay'     => [],
            'timeline'       => [],
            'byDay'          => [],
            'heatmap'        => ['days' => [], 'hours' => [], 'cells' => [], 'max' => 0],
            'byScanner'      => [],
            'byScannerByDay' => [],
            'days'           => [],
        ];
    }

    /** Session user plus stored profile details for topbar/account menus. */
    private function currentSessionUser(): array
    {
        return SessionAccount::user();
    }

    /**
     * Builds the Manage Records list for whatever role the session holds: reads
     * the q/status/page/sector/date query params, runs the paginated family-head
     * search, and merges in the deep (whole-database) search results. The role
     * only decides which controls the list may offer, never which rows it sees.
     * Frontend: the family-list view + its filter and pagination controls.
     */
    private function buildMemberListData(): array
    {
        $role = $this->currentRole();
        [$canEdit, $canArchive, $canRestoreArchived] = $this->recordListRoleFlags($role);

        $keyword = trim((string) $this->request->getGet('q'));
        $status = strtolower(trim((string) $this->request->getGet('status')));
        $status = in_array($status, ['all', 'active', 'archived'], true) ? $status : 'all';
        $page = max(1, (int) $this->request->getGet('page'));
        $perPage = $this->recordsPerPage();

        // Manage Records FILTER controls (sector + date). Status (active/archived)
        // is handled separately above. Passed into MemberModel::searchFamilies().
        $filters = [
            'sectorID' => $this->request->getGet('sectorID'),
            'barangay' => $this->request->getGet('barangay'),
            'date'     => (string) $this->request->getGet('date'),
        ];

        $memberModel = new MemberModel();
        $searchKeyword = $keyword === '' ? null : $keyword;
        $totalFamilies = $memberModel->countSearchFamilies($searchKeyword, $status, $filters);
        $totalPages = max(1, (int) ceil($totalFamilies / $perPage));
        $page = min($page, $totalPages);

        return array_merge([
            'canEdit'           => $canEdit,
            'canArchive'        => $canArchive,
            'canRestoreArchived' => $canRestoreArchived,
            'families'          => $memberModel->searchFamilies($searchKeyword, $perPage, ($page - 1) * $perPage, $status, $filters),
            'fromRecord'        => $totalFamilies === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'isFullPage'        => true,
            'keyword'           => $keyword,
            // Full-page route so both the filter form and deep-search form reload the
            // whole Manage Records page (not the modal/partial list endpoint).
            'listRoute'         => 'records',
            'page'              => $page,
            'perPage'           => $perPage,
            'status'            => $status,
            'toRecord'          => min($totalFamilies, $page * $perPage),
            'totalFamilies'     => $totalFamilies,
            'totalPages'        => $totalPages,
            // Filter UI data.
            'sectorOptions'     => (new SectorModel())->getSectorOptions(),
            'barangayOptions'   => (new \App\Models\Lookups\BarangayModel())->activeNames(),
            'filters'           => $filters,
        ], $this->buildDeepSearchData($status));
    }

    /**
     * Builds the SECOND ("search the whole database") results panel for Manage Records.
     * Only populated when the deep-search box (deep_q) is used; otherwise deepKeyword is
     * empty and the view hides the panel. Delegates to App\Models\SearchModel::allMembers().
     */
    private function buildDeepSearchData(string $status): array
    {
        $deepKeyword = trim((string) $this->request->getGet('deep_q'));
        $scopeAll = strtolower(trim((string) $this->request->getGet('search_scope'))) === 'all';

        // Some links/forms can still submit search_scope=all with the keyword in `q`.
        // Treat that value as the deep keyword so the whole-database results panel opens.
        if ($deepKeyword === '' && $scopeAll) {
            $deepKeyword = trim((string) $this->request->getGet('q'));
        }

        // Deep search is active when explicitly requested (search_scope=all) or when a
        // deep keyword is present. An empty keyword with scope=all lists everyone in the
        // database (filters still narrow it), matching the "show what's in the DB" intent.
        $deepActive = $scopeAll || $deepKeyword !== '';

        if (! $deepActive) {
            return [
                'deepActive'     => false,
                'deepKeyword'    => '',
                'deepResults'    => [],
                'deepPage'       => 1,
                'deepTotal'      => 0,
                'deepTotalPages' => 1,
                'deepFromRecord' => 0,
                'deepToRecord'   => 0,
            ];
        }

        $perPage = $this->recordsPerPage();
        $page = max(1, (int) $this->request->getGet('deep_page'));
        $filters = [
            'status'   => $status,
            'sectorID' => $this->request->getGet('sectorID'),
            'barangay' => $this->request->getGet('barangay'),
            'date'     => (string) $this->request->getGet('date'),
        ];

        $searchModel = new SearchModel();
        $total = $searchModel->countAllMembers($deepKeyword, $filters);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return [
            'deepActive'     => true,
            'deepKeyword'    => $deepKeyword,
            'deepResults'    => $searchModel->allMembers($deepKeyword, $filters, $perPage, ($page - 1) * $perPage),
            'deepPage'       => $page,
            'deepTotal'      => $total,
            'deepTotalPages' => $totalPages,
            'deepFromRecord' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'deepToRecord'   => min($total, $page * $perPage),
        ];
    }

    /** Collects all supported search/filter query params into one array. */
    private function searchFilters(): array
    {
        return [
            'sectorID' => $this->request->getGet('sectorID'),
            'barangay' => $this->request->getGet('barangay'),
            'role' => (string) $this->request->getGet('role'),
            'status' => (string) $this->request->getGet('status'),
            'action' => (string) $this->request->getGet('action'),
            'date' => (string) $this->request->getGet('date'),
            'date_from' => (string) $this->request->getGet('date_from'),
            'date_to' => (string) $this->request->getGet('date_to'),
        ];
    }

    /** True if any search filter is set, used to decide search vs. default listing. */
    private function hasSearchFilters(array $filters): bool
    {
        foreach ($filters as $value) {
            if (is_array($value)) {
                if ($this->hasSearchFilters($value)) {
                    return true;
                }

                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '' && $normalized !== '__all') {
                return true;
            }
        }

        return false;
    }

    /** Whitelisted page sizes for Manage Records and deep-search pagination. */
    private function recordsPerPage(): int
    {
        $perPage = (int) $this->request->getGet('per_page');

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    }
}
