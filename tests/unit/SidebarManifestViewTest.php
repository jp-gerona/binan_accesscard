<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Renders the sidebar the way layout.php drives it and asserts that what a role
 * sees comes from the manifest, not from a hand-written list per layout.
 */
final class SidebarManifestViewTest extends CIUnitTestCase
{
    private function render(string $role, string $activePage = 'records'): string
    {
        return view('components/dashboard_sidebar', [
            'role'       => $role,
            'activePage' => $activePage,
        ]);
    }

    public function testAdminSeesEveryLink(): void
    {
        $html = $this->render('Admin');

        foreach (['Dashboard', 'Family Records', 'Reference Data', 'Card Readiness',
                  'Access Cards', 'Distribution', 'Account Management', 'Audit Trails'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function testEncoderSeesNoAccountManagement(): void
    {
        $html = $this->render('Encoder');

        $this->assertStringContainsString('Family Records', $html);
        $this->assertStringNotContainsString('Account Management', $html);
        $this->assertStringNotContainsString('Audit Trails', $html);
    }

    public function testHeadingsRenderOncePerGroup(): void
    {
        $html = $this->render('Admin');

        $this->assertSame(1, substr_count($html, '>Profiling<'));
        $this->assertSame(1, substr_count($html, '>Distribution</div>'));
    }

    public function testActivePageIsMarked(): void
    {
        $html = $this->render('Admin', 'cards');

        $this->assertMatchesRegularExpression(
            '#<a class="[^"]*\bnav-link\b[^"]*\bactive\b[^"]*"[^>]*href="[^"]*/cards"#',
            $html
        );
    }
}
