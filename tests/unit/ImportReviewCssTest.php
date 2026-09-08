<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Review round 1 Finding: the discarded-row rule used var(--bs-secondary-color),
 * a CSS variable Bootstrap only defines in 5.3. The dashboard runs Bootstrap
 * 5.2.3, where the variable never resolves, so discarded rows were not muted.
 * Muted text in 5.2 is --bs-gray-600 (#6c757d, the value .text-muted renders),
 * so the scoped rule must use that instead.
 */
final class ImportReviewCssTest extends CIUnitTestCase
{
    private function css(): string
    {
        return (string) file_get_contents(FCPATH . 'css/managerecord.css');
    }

    public function testDiscardedRowTextUsesTheBootstrap52MutedGray(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/#importReview\s+\.import-review-discarded\s*>\s*td\s*\{[^}]*color\s*:\s*var\(--bs-gray-600\)\s*;[^}]*\}/s',
            $css,
            'The #importReview discarded-row rule must mute text with Bootstrap 5.2\'s muted gray (--bs-gray-600).'
        );
    }

    public function testDiscardedRowRuleDoesNotUseTheBootstrap53OnlyMutedVariable(): void
    {
        $css = $this->css();

        $this->assertDoesNotMatchRegularExpression(
            '/#importReview\s+\.import-review-discarded\s*>\s*td\s*\{[^}]*--bs-secondary-color/s',
            $css,
            'The discarded-row rule must not reference --bs-secondary-color, which Bootstrap 5.2.3 does not define.'
        );
    }
}