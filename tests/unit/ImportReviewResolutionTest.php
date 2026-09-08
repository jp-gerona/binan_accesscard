<?php

namespace Tests\Unit;

use App\Libraries\ImportReviewResolution;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class ImportReviewResolutionTest extends CIUnitTestCase
{
    public function testResolutionDiscardsDuplicateCopiesAndRestoreMakesThemActive(): void
    {
        $rows = [
            ['sheetRow' => 4, 'data' => []],
            ['sheetRow' => 5, 'data' => []],
            ['sheetRow' => 6, 'data' => []],
        ];

        $discarded = ImportReviewResolution::discardGroup([], [['qr' => '6001', 'rows' => [4, 5, 6]]], 4);

        $this->assertSame(['keptRow' => 4, 'reason' => 'duplicate'], $discarded[5]);
        $this->assertSame(['keptRow' => 4, 'reason' => 'duplicate'], $discarded[6]);
        $this->assertSame([4], array_column(ImportReviewResolution::activeRows($rows, $discarded), 'sheetRow'));
        $this->assertSame([], ImportReviewResolution::restore($discarded, 5));
    }

    public function testResolutionRejectsKeepRowOutsideCandidateGroups(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImportReviewResolution::discardGroup([], [['qr' => '6001', 'rows' => [4, 5, 6]]], 7);
    }
}
