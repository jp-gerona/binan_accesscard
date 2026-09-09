<?php

namespace Tests\Unit;

use App\Libraries\ImportReviewQuery;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The review table's query arrives from a URL, so every value is attacker-controlled.
 * These tests pin the clamping: a hostile per-page must not turn one request into a
 * 10,000-row render, and an unknown severity must fall back to showing everything
 * rather than silently hiding rows the operator needs to see.
 *
 * @internal
 */
final class ImportReviewQueryTest extends CIUnitTestCase
{
    public function testItDefaultsToTheFirstPageOfTwentyFiveShowingEverything(): void
    {
        $query = ImportReviewQuery::fromArray([]);

        $this->assertSame(1, $query->page);
        $this->assertSame(25, $query->per);
        $this->assertSame('all', $query->severity);
        $this->assertSame([], $query->code);
        $this->assertSame('', $query->q);
        $this->assertSame(0, $query->offset());
    }

    public function testItAcceptsTheAllowedPageSizes(): void
    {
        $this->assertSame(50, ImportReviewQuery::fromArray(['per' => '50'])->per);
        $this->assertSame(100, ImportReviewQuery::fromArray(['per' => '100'])->per);
    }

    public function testItClampsAPageSizeThatIsNotOnTheList(): void
    {
        // A crafted per=100000 would render the whole file and defeat the paging.
        $this->assertSame(25, ImportReviewQuery::fromArray(['per' => '100000'])->per);
        $this->assertSame(25, ImportReviewQuery::fromArray(['per' => '0'])->per);
        $this->assertSame(25, ImportReviewQuery::fromArray(['per' => 'abc'])->per);
    }

    public function testItFloorsThePageAtOne(): void
    {
        $this->assertSame(1, ImportReviewQuery::fromArray(['page' => '0'])->page);
        $this->assertSame(1, ImportReviewQuery::fromArray(['page' => '-4'])->page);
        $this->assertSame(7, ImportReviewQuery::fromArray(['page' => '7'])->page);
    }

    public function testOffsetFollowsPageAndPerPage(): void
    {
        $this->assertSame(100, ImportReviewQuery::fromArray(['page' => '3', 'per' => '50'])->offset());
    }

    public function testItRejectsAnUnknownSeverityRatherThanHidingRows(): void
    {
        $this->assertSame('all', ImportReviewQuery::fromArray(['severity' => 'nonsense'])->severity);
        $this->assertSame('blocking', ImportReviewQuery::fromArray(['severity' => 'blocking'])->severity);
        $this->assertSame('warning', ImportReviewQuery::fromArray(['severity' => 'warning'])->severity);
        $this->assertSame('problems', ImportReviewQuery::fromArray(['severity' => 'problems'])->severity);
    }

    public function testItTrimsTheSearchAndEveryCode(): void
    {
        $query = ImportReviewQuery::fromArray([
            'q' => '  cruz  ',
            'code' => [' SEX ', '', ' AGE-ELIG '],
        ]);

        $this->assertSame('cruz', $query->q);
        $this->assertSame(['SEX', 'AGE-ELIG'], $query->code);
    }
}
