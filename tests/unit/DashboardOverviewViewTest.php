<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class DashboardOverviewViewTest extends CIUnitTestCase
{
    private function source(string $path): string
    {
        return file_get_contents(APPPATH . $path);
    }

    /**
     * The four cards read as one funnel: profiled, carded, served, and the
     * number of distributions those came out of. "Families never served" is not
     * among them because it is families minus ever-served, so as a card it
     * restated a number already on the row; it survives as the sub-line under
     * Families ever served.
     */
    public function testOverviewRendersTheFourCards(): void
    {
        $src = $this->source('Views/Pages/dashboard-overview.php');
        foreach (['Families profiled', 'Access cards issued', 'Families ever served', 'Distributions hosted'] as $label) {
            $this->assertStringContainsString($label, $src);
        }
    }

    public function testNeverServedIsASubLineNotACard(): void
    {
        $src = $this->source('Views/Pages/dashboard-overview.php');

        $this->assertStringContainsString('never served', $src);
        $this->assertStringNotContainsString("'label' => 'Families never served'", $src);
        $this->assertStringContainsString('kpi-sub', $src);
    }

    /** KPI tiles carry no icon, per the spec's exception to the card convention. */
    public function testKpiCardsCarryNoIcons(): void
    {
        $src = $this->source('Views/Pages/dashboard-overview.php');
        $this->assertStringNotContainsString('<i class="bi', $src);
        $this->assertStringNotContainsString('card-header', $src);
    }

    
    /** The old flat strip is gone; its two numbers now live on cards. */
    public function testProgramStripIsGone(): void
    {
        $src = $this->source('Views/Pages/dashboard.php');
        $this->assertStringNotContainsString('program-strip', $src);
    }

    public function testDistributionRowsLinkIntoTheDistributionTab(): void
    {
        $src = $this->source('Views/Pages/dashboard-overview.php');
        $this->assertStringContainsString('view=distribution', $src);
    }
}
