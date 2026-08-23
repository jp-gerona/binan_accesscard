<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Database\DumpSchema;

/**
 * Renders the dashboard for real and reads the HTML back.
 *
 * The rest of the dashboard suite greps source files, which cannot see whether
 * a link a view builds actually carries the query params it needs. These cases
 * fetch the page through the router with a session and assert on the response
 * body: that the Distribution pane's own links keep the reader inside the pane,
 * and that a role without distribution access still lands on a dashboard with
 * something on it.
 *
 * The schema comes from the SQL dump (Tests\Support\Database\DumpSchema); rows
 * are the few this file asserts on.
 */
final class DashboardPaneUrlFeatureTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const BATCH_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();

        cache()->clean();
        // The renderer is a singleton and CI4 keeps view data on it between
        // render() calls, so one case's tab strip would otherwise seed the
        // next case's.
        \Config\Services::resetSingle('renderer');

        $db = db_connect();
        DumpSchema::create($db);

        $db->table('users')->insertBatch([
            ['userID' => 1, 'username' => 'boss', 'account_level' => 'administrator', 'isactive' => 'Enable', 'password' => 'x'],
            ['userID' => 2, 'username' => 'keyer', 'account_level' => 'encoder', 'isactive' => 'Enable', 'password' => 'x'],
            ['userID' => 3, 'username' => 'looker', 'account_level' => 'viewer', 'isactive' => 'Enable', 'password' => 'x'],
            ['userID' => 4, 'username' => 'dev', 'account_level' => 'developer', 'isactive' => 'Enable', 'password' => 'x'],
        ]);
        $db->table('subsidy')->insert(['subsidy_type_id' => 1, 'name' => 'Rice']);
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'Canlalay']);
        $db->table('distribution_batch')->insert([
            'batch_id'        => self::BATCH_ID,
            'name'            => 'August rice',
            'subsidy_type_id' => 1,
            'started_at'      => '2026-08-01 08:00:00',
            'closed_at'       => null,
            'eligible_count'  => 12,
        ]);

        // Twelve eligible heads, none served, so a 10-per-page Remaining tab
        // has a second page and therefore real pagination links to inspect.
        $members = [];
        $roster  = [];
        for ($id = 1; $id <= 12; $id++) {
            $members[] = [
                'memberID' => $id, 'lastname' => 'Head' . $id, 'firstname' => 'Test',
                'middlename' => '', 'headID' => $id, 'barangayID' => 1,
                'contactnumber' => '', 'dt_deleted' => null,
            ];
            $roster[] = ['batch_id' => self::BATCH_ID, 'headID' => $id];
        }
        $db->table('member')->insertBatch($members);
        $db->table('batch_eligibility')->insertBatch($roster);
    }

    protected function tearDown(): void
    {
        DumpSchema::drop(db_connect());
        cache()->clean();

        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function session(string $role, int $userId, string $username): array
    {
        return ['is_logged_in' => true, 'role' => $role, 'user_id' => $userId, 'username' => $username];
    }

    /**
     * One scan on 2026-08-01, inside self::BATCH_ID's roster, by a scanner
     * account. The base fixture logs no scans at all (heatmap/stations tests
     * that want an empty state rely on that), so the two cases below that need
     * a day with rows to pick from seed this themselves rather than growing
     * setUp() for every other case in this file.
     */
    private function seedOneScan(): void
    {
        $db = db_connect();
        $db->table('users')->insert(['userID' => 5, 'username' => 'scanner1', 'password' => 'x', 'account_level' => 'scanner', 'isactive' => 'Enable']);
        $db->table('qr_control')->insert(['control_no' => 1, 'headID' => 1]);
        $db->table('subsidy_distribution')->insert([
            'control_no' => 1, 'memberID' => 1, 'subsidy_type_id' => 1,
            'claim_date' => '2026-08-01', 'batch_id' => self::BATCH_ID,
            'userID' => 5, 'dt_created' => '2026-08-01 09:00:00',
        ]);
    }

    /**
     * Every href in $html that points at the dashboard and carries $needle,
     * with entity-encoded ampersands decoded back so a caller can grep params.
     *
     * @return list<string>
     */
    private function dashboardLinks(string $html, string $needle): array
    {
        preg_match_all('/href="([^"]*dashboard\?[^"]*)"/', $html, $matches);

        $links = array_map(static fn (string $href): string => html_entity_decode($href), $matches[1]);

        return array_values(array_filter($links, static fn (string $href): bool => str_contains($href, $needle)));
    }

    public function testEveryCardRendersTogetherOnOnePaneLoad(): void
    {
        $body = $this->withSession($this->session('administrator', 1, 'boss'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID)
            ->getBody();

        // The Distribution pane rendered, and the Overview pane did not.
        $this->assertStringNotContainsString('Families profiled', $body);

        // All four subjects at once, which is the point of the restructure:
        // reading two of them no longer costs the first.
        $this->assertStringContainsString('id="activityCard"', $body);
        $this->assertStringContainsString('id="barangayCard"', $body);
        $this->assertStringContainsString('id="stationsTable"', $body);
        $this->assertStringContainsString('id="remainingCard"', $body);

        // No sub-tab strip carrying ?tab= on this pane any more.
        $this->assertSame([], $this->dashboardLinks($body, 'tab='));

        // Batch picker: submitting it must not drop the pane.
        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="view" value="distribution"\s*\/?>/',
            $body
        );
        $this->assertStringNotContainsString('<input type="hidden" name="tab"', $body);
    }

    /**
     * A tablist whose tabs control nothing is worse than no tablist: a screen
     * reader announces a control relationship the page does not have. Every
     * aria-controls has to name a real pane, that pane has to be a tabpanel,
     * and it has to point back at the tab. Asserted against the rendered page
     * rather than the view sources, because the ids are built in two files and
     * a grep of either alone cannot see them meet.
     */
    public function testEveryCardTabControlsARealPanel(): void
    {
        $body = $this->withSession($this->session('administrator', 1, 'boss'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID)
            ->getBody();

        preg_match_all('/id="([^"]+)"\s+class="nav-link[^"]*"[^>]*aria-controls="([^"]+)"/', $body, $tabs, PREG_SET_ORDER);

        // Expected count is derived from the rendered page, not a hardcoded
        // number, so a future card_tabs strip does not have to remember to
        // bump one here: components/card_tabs.php renders exactly one
        // role="tabpanel" per tab it declares, so the two counts must match.
        // At least one strip is still required, or an empty page would pass
        // by having zero of both.
        $tablistCount = preg_match_all('/role="tablist"/', $body);
        $panelCount   = preg_match_all('/role="tabpanel"/', $body);
        $this->assertGreaterThan(0, $tablistCount, 'Expected at least one card tab strip.');
        $this->assertCount($panelCount, $tabs, 'Expected as many card tabs as tabpanels rendered on the page.');

        foreach ($tabs as [, $tabId, $paneId]) {
            $this->assertSame(
                1,
                preg_match(
                    '/<div id="' . preg_quote($paneId, '/') . '" role="tabpanel" aria-labelledby="'
                        . preg_quote($tabId, '/') . '"/',
                    $body
                ),
                $tabId . ' does not control a tabpanel that names it back.'
            );
        }

        // Two strips share the pane key "table" only by accident of wording, so
        // the ids they build must still be distinct.
        $paneIds = array_column($tabs, 2);
        $this->assertSame($paneIds, array_unique($paneIds), 'Two panes claimed the same id.');
    }

    /**
     * batch-stations-table.php renders twice on this page once a day with
     * rows is picked (the Stations card's All and Per day panes). Two elements
     * sharing an id is invalid HTML the moment that happens, which a source
     * grep of the view cannot see because it only exists once two view() calls
     * both resolve to non-empty output. Rendered end to end instead.
     */
    public function testStationsCardRendersNoDuplicateTableIdOnceADayIsPicked(): void
    {
        $this->seedOneScan();

        $body = $this->withSession($this->session('administrator', 1, 'boss'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID . '&day=2026-08-01')
            ->getBody();

        $this->assertSame(
            1,
            substr_count($body, 'id="stationsTable"'),
            'id="stationsTable" must appear exactly once even when the Per day pane has rows.'
        );
        $this->assertStringContainsString(
            'id="stationsTableDay"',
            $body,
            'the Per day pane must render its own table id, not reuse the All pane\'s.'
        );
    }

    /**
     * station-modal.js delegates its click listener from #stationsCard, not
     * one table's id, so it can answer a row in either pane. That only works
     * if the Per day table still carries data-scanner-id for a role that may
     * drill in; this is the row-level half of the delegation fix.
     */
    public function testPerDayRowCarriesDataScannerIdForADrillInRole(): void
    {
        $this->seedOneScan();

        $body = $this->withSession($this->session('administrator', 1, 'boss'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID . '&day=2026-08-01')
            ->getBody();

        $dayPanePos = strpos($body, 'id="stations-pane-day"');
        $this->assertNotFalse($dayPanePos, 'stations-pane-day not found');
        $this->assertStringContainsString('data-scanner-id', substr($body, $dayPanePos));
    }

    /**
     * The caption is the only description a screen reader gets of what a
     * heatmap's rows are, and the Activity card renders the same partial for
     * days and for weekdays. One caption serving both would tell a reader the
     * weekday grid has one row per day.
     */
    public function testEachHeatmapCaptionDescribesItsOwnRows(): void
    {
        $body = $this->withSession($this->session('administrator', 1, 'boss'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID)
            ->getBody();

        // Both grids are empty for this fixture (no scans), and an empty grid
        // renders no table at all, so drive the partial directly instead.
        // Every parameter explicit, for the same reason the Activity card
        // passes them all: the dashboard render above left its own values on
        // the renderer, so a call leaning on the defaults would inherit them.
        $days = view('Admin/batch-heatmap', [
            'heatmap'    => ['days' => ['2026-08-01'], 'hours' => [8], 'cells' => ['2026-08-01' => [8 => ['families' => 3, 'state' => 'served']]], 'max' => 3],
            'rowLabels'  => ['2026-08-01' => 'Aug 1'],
            'gridId'     => 'peakHeatmap',
            'selectable' => true,
            'caption'    => 'Families served by hour, one row per day',
        ]);
        $weekdays = view('Admin/batch-heatmap', [
            'heatmap'    => ['days' => ['2'], 'hours' => [8], 'cells' => ['2' => [8 => ['families' => 3, 'state' => 'served']]], 'max' => 3],
            'rowLabels'  => ['2' => 'Tuesday'],
            'gridId'     => 'weekdayHeatmap',
            'selectable' => false,
            'caption'    => 'Families served by hour, one row per weekday',
        ]);

        $this->assertStringContainsString('one row per day</caption>', $days);
        $this->assertStringContainsString('one row per weekday</caption>', $weekdays);
        $this->assertStringContainsString('id="weekdayHeatmap"', $weekdays);
        // A weekday is not a day the batch ran, so it offers no day filter.
        $this->assertStringNotContainsString('heatmap-day', $weekdays);
        $this->assertStringContainsString('heatmap-day', $days);

        // And the card is what wires the weekday grid up that way. Read off the
        // source, not $body: this fixture logs no scans, and an empty grid
        // renders the empty-state line with no table and so no caption.
        $this->assertStringNotContainsString('one row per weekday', $body);
        $card = file_get_contents(APPPATH . 'Views/Admin/batch-activity-card.php');
        $this->assertStringContainsString("'caption' => 'Families served by hour, one row per weekday'", $card);
    }

    public function testRemainingPaginationAndFormsStayInsideTheDistributionPane(): void
    {
        $body = $this->withSession($this->session('administrator', 1, 'boss'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID . '&per_page=10')
            ->getBody();

        $pageLinks = $this->dashboardLinks($body, 'page=');
        $this->assertNotSame([], $pageLinks, 'Expected paginated Remaining links.');
        foreach ($pageLinks as $href) {
            $this->assertStringContainsString('view=distribution', $href, $href);
            $this->assertStringContainsString('batch=' . self::BATCH_ID, $href, $href);
        }

        // Three forms carry the pane back: the batch picker, the Remaining
        // search box and its page-size selector.
        $this->assertGreaterThanOrEqual(
            3,
            preg_match_all('/<input type="hidden" name="view" value="distribution"\s*\/?>/', $body),
            'The batch picker and both Remaining forms must carry the pane.'
        );
    }


    public function testEncoderAndViewerBothGetTheRealDashboard(): void
    {
        foreach ([['encoder', 2, 'keyer'], ['viewer', 3, 'looker']] as [$role, $userId, $username]) {
            $body = $this->withSession($this->session($role, $userId, $username))->get('dashboard')->getBody();

            // The outer strip, both its tabs, and the Overview pane it lands on.
            $this->assertStringContainsString('?view=distribution', $body, $role);
            $this->assertStringContainsString('Program to date', $body, $role);
            $this->assertStringContainsString('Families profiled', $body, $role);
            $this->assertStringContainsString('Access cards issued', $body, $role);
            $this->assertStringContainsString('never served', $body, $role);
            $this->assertStringContainsString('August rice', $body, $role);
        }
    }

    public function testEncoderReachesTheDistributionPaneToo(): void
    {
        $body = $this->withSession($this->session('encoder', 2, 'keyer'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID)
            ->getBody();

        $this->assertStringContainsString('id="stationsTable"', $body);
        $this->assertStringContainsString('Eligible', $body);
    }



    /**
     * The station modal reads scanner/stats, which answers Scanner, Admin and
     * Developer only. An Encoder sees the table but must get no control that
     * would 403, and no modal markup to go with it.
     */
    public function testEncoderGetsStationsWithoutADrillIn(): void
    {
        $body = $this->withSession($this->session('encoder', 2, 'keyer'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID)
            ->getBody();

        $this->assertStringContainsString('data-can-drill-in="0"', $body);
        $this->assertStringNotContainsString('data-scanner-id=', $body);
        $this->assertStringNotContainsString('id="stationModal"', $body);
    }

    /** The same table, for a role that may open a station. */
    public function testDeveloperGetsTheStationModal(): void
    {
        $body = $this->withSession($this->session('developer', 4, 'dev'))
            ->get('dashboard?view=distribution&batch=' . self::BATCH_ID)
            ->getBody();

        $this->assertStringContainsString('data-can-drill-in="1"', $body);
        $this->assertStringContainsString('id="stationModal"', $body);
        $this->assertStringNotContainsString('scanner/performance', $body);
    }
}
