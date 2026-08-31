<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

/*
 * Authentication
 */
$routes->get('/', 'Auth\AuthController::index');
$routes->match(['GET', 'POST'], 'login', 'Auth\AuthController::login');
$routes->post('login/confirm', 'Auth\AuthController::confirmLogin');
$routes->get('logout', 'Auth\AuthController::logout');
$routes->get('session/keep-alive', 'Auth\AuthController::keepAlive');

/*
 * Pages. One URI each; the roleNav filter names the manifest key that decides
 * which roles may reach it (see app/Config/Navigation.php).
 */
$routes->get('dashboard', 'Admin\DashboardController::dashboard', ['filter' => 'roleNav:dashboard']);
$routes->get('records', 'Admin\DashboardController::manageRecords', ['filter' => 'roleNav:records']);
$routes->get('reference-data', 'Admin\DashboardController::referenceData', ['filter' => 'roleNav:reference-data']);
$routes->get('cards', 'Admin\DashboardController::cards', ['filter' => 'roleNav:cards']);
$routes->get('distribution', 'Admin\DistributionController::distribution', ['filter' => 'roleNav:distribution']);
$routes->get('accounts', 'Admin\DashboardController::accounts', ['filter' => 'roleNav:accounts']);
$routes->get('audit-trails', 'Admin\DashboardController::auditTrails', ['filter' => 'roleNav:audit-trails']);

/*
 * Family records. Entry and import are pages without sidebar links, reached from
 * the Family Records toolbar.
 */
$routes->group('records', ['filter' => 'roleNav:records'], static function (RouteCollection $routes): void {
    $routes->get('data', 'Families\FamilyDataTableController::dataTable');
    $routes->get('qr-check', 'Families\FamilyController::qrAvailability');
    $routes->get('template', 'Families\FamilyImportController::downloadTemplate');
});

$routes->group('records', ['filter' => 'roleNav:records-entry'], static function (RouteCollection $routes): void {
    $routes->get('entry', 'Families\FamilyController::createFamily');
    // The POST that the entry form submits to, so it sits under the key that
    // names creating a record rather than the one that names editing one. Both
    // keys resolve to the same roles, so this is naming, not access.
    $routes->post('', 'Families\FamilyController::store');
});

$routes->group('records', ['filter' => 'roleNav:records-import'], static function (RouteCollection $routes): void {
    $routes->get('import', 'Families\FamilyImportController::importForm');
    $routes->post('import', 'Families\FamilyImportController::import');
    $routes->get('import/status/(:num)', 'Families\FamilyImportController::importStatus/$1');
    $routes->get('import/review/(:num)', 'Families\FamilyImportController::reviewPage/$1');
    $routes->get('import/review/(:num)/rows', 'Families\FamilyImportController::reviewRows/$1');
    $routes->post('import/review/(:num)/commit', 'Families\FamilyImportController::reviewCommit/$1');
    $routes->post('import/review/(:num)/cancel', 'Families\FamilyImportController::reviewCancel/$1');
    $routes->post('import/review/(:num)/apply', 'Families\FamilyImportController::reviewRowApply/$1');
});

$routes->group('records', ['filter' => 'roleNav:records-completeness'], static function (RouteCollection $routes): void {
    $routes->get('completeness', 'Admin\DashboardController::dataCompleteness');
});

/*
 * Resource convention: the bare id shows the record read-only, `/edit` is the
 * form. The mode is a path segment, not a query flag, so the route filter can
 * gate who reaches the editable page at all.
 */
$routes->get('records/(:num)', 'Families\FamilyController::profile/$1', ['filter' => 'roleNav:records-profile']);
$routes->get('records/(:num)/edit', 'Families\FamilyController::edit/$1', ['filter' => 'roleNav:records-edit']);

$routes->group('records', ['filter' => 'roleNav:records-update'], static function (RouteCollection $routes): void {
    $routes->post('(:num)/update', 'Families\FamilyController::update/$1');
    $routes->post('(:num)/archive', 'Families\FamilyController::archive/$1');
    $routes->post('(:num)/restore', 'Families\FamilyController::restore/$1');
});

/*
 * Reference data actions. Pages merged into reference-data; these are its writes.
 */
$routes->group('reference-data', ['filter' => 'roleNav:reference-data'], static function (RouteCollection $routes): void {
    $routes->post('sectors/create', 'Lookups\SectorController::create');
    $routes->post('sectors/update/(:num)', 'Lookups\SectorController::update/$1');
    $routes->post('sectors/delete/(:num)', 'Lookups\SectorController::delete/$1');
    $routes->post('sectors/archive/(:num)', 'Lookups\SectorController::archive/$1');
    $routes->post('sectors/restore/(:num)', 'Lookups\SectorController::restore/$1');

    $routes->post('categories/create', 'Lookups\CategoryController::create');
    $routes->post('categories/update/(:num)', 'Lookups\CategoryController::update/$1');
    $routes->post('categories/archive/(:num)', 'Lookups\CategoryController::archive/$1');
    $routes->post('categories/restore/(:num)', 'Lookups\CategoryController::restore/$1');

    $routes->post('services/create', 'Lookups\ServiceController::create');
    $routes->post('services/update/(:num)', 'Lookups\ServiceController::update/$1');
    $routes->post('services/delete/(:num)', 'Lookups\ServiceController::delete/$1');
    $routes->post('services/archive/(:num)', 'Lookups\ServiceController::archive/$1');
    $routes->post('services/restore/(:num)', 'Lookups\ServiceController::restore/$1');

    $routes->post('subsidy-types/create', 'Admin\SubsidyTypesController::create');
    $routes->post('subsidy-types/archive/(:num)', 'Admin\SubsidyTypesController::archive/$1');
    $routes->post('subsidy-types/restore/(:num)', 'Admin\SubsidyTypesController::restore/$1');
    $routes->post('subsidy-types/delete/(:num)', 'Admin\SubsidyTypesController::deleteType/$1');
});

/*
 * Access cards and distribution actions.
 */
$routes->group('cards', ['filter' => 'roleNav:cards'], static function (RouteCollection $routes): void {
    $routes->post('generate', 'Cards\QrCardController::batch');
    $routes->get('card/(:num)', 'Cards\QrCardController::card/$1');
    $routes->get('lookup/(:any)', 'Cards\QrCardController::lookup/$1');
    $routes->get('heads', 'Cards\QrCardController::heads');
});

$routes->group('distribution', ['filter' => 'roleNav:distribution'], static function (RouteCollection $routes): void {
    $routes->get('batches/preview', 'Admin\DistributionController::previewEligibility');
    $routes->post('batches/close/(:num)', 'Admin\DistributionController::closeBatch/$1');
    $routes->post('void/(:num)', 'Admin\DistributionController::voidDistribution/$1');
    $routes->get('schedule/feed', 'Admin\DistributionController::scheduleFeed');
    $routes->post('schedule/save', 'Admin\DistributionController::saveSchedule');
    $routes->post('schedule/(:num)/delete', 'Admin\DistributionController::deleteSchedule/$1');
});

/*
 * The same URL prefix on a different key: these two are read-only and back the
 * dashboard's Distribution pane, which renders for every staff role, so they
 * cannot sit behind the Distribution page's narrower guard. See the
 * 'dashboard-reports' note in Config\Navigation.
 */
$routes->group('distribution', ['filter' => 'roleNav:dashboard-reports'], static function (RouteCollection $routes): void {
    $routes->get('reports/stats', 'Admin\ReportsController::stats');
    $routes->get('reports/pdf', 'Admin\ReportsController::pdf');
});

/*
 * Accounts. The developer-only creation endpoints keep their own guard inside the
 * controller, because they gate on Developer rather than on a page.
 */
$routes->group('accounts', ['filter' => 'roleNav:accounts'], static function (RouteCollection $routes): void {
    $routes->get('create', 'Accounts\AccountController::createForm');
    $routes->get('edit/(:num)', 'Accounts\AccountController::editForm/$1');
    $routes->post('update', 'Accounts\AccountController::update');
    $routes->post('reset-password', 'Accounts\AccountController::resetPassword');
    $routes->post('disable', 'Accounts\AccountController::disableEmployee');
    $routes->post('enable', 'Accounts\AccountController::enableEmployee');
    $routes->post('', 'Accounts\AccountController::create');
    $routes->post('status', 'Accounts\AccountController::updateStatus');
});

// Self-service My Account (any logged-in non-developer). Not a manifest page.
$routes->get('account/profile', 'Accounts\ProfileController::myAccount');
$routes->post('account/profile/update', 'Accounts\ProfileController::update');

/**
 * Scanner module (subsidy distribution). Scanner/Admin/Developer only - each action
 * calls RoleAccess::requireRole() internally (mirrors the Cards controller).
 */
$routes->group('scanner', static function (RouteCollection $routes): void {
    $routes->get('scan', 'Scanner\ScanController::scan');
    $routes->get('performance', 'Scanner\ScanController::performance');
    $routes->get('stats', 'Scanner\ScanController::stats');
    $routes->post('log', 'Scanner\ScanController::logAid');
    $routes->get('history/(:num)', 'Scanner\ScanController::history/$1');
});
