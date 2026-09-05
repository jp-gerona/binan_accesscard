<?php

namespace Tests\Unit;

use App\Libraries\DashboardPageBuilder;
use App\Models\Scanner\DistributionBatchModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Database\DumpSchema;

/**
 * The dashboard overview's schedule card: a month grid with a spanning bar
 * per batch and the next batches listed underneath. Read only, so no form
 * and no button.
 *
 * Dates are computed relative to today rather than hardcoded so the test
 * cannot rot as the calendar moves.
 */
final class DashboardScheduleCardTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        cache()->clean();
        \Config\Services::resetSingle('renderer');

        $db = db_connect();
        DumpSchema::create($db);
        $db->table('users')->insert(['userID' => 1, 'username' => 'boss', 'account_level' => 'administrator', 'isactive' => 'Enable', 'password' => 'x']);
        $db->table('subsidy')->insert(['subsidy_type_id' => 1, 'name' => 'Rice']);
    }

    protected function tearDown(): void
    {
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    /** Inserts a batch row with the given name, dates and colour. */
    private function insertBatch(int $id, string $start, string $end, string $color = 'purple'): void
    {
        db_connect()->table('distribution_batch')->insert([
            'batch_id'         => $id,
            'name'             => 'Batch ' . $id,
            'venue'            => 'City Hall Quadrangle',
            'subsidy_type_id'  => 1,
            'scheduled_start'  => $start,
            'scheduled_end'    => $end,
            'daily_start_time' => '08:00:00',
            'daily_end_time'   => '16:00:00',
            'color'            => $color,
            'started_at'       => null,
            'closed_at'        => null,
            'eligible_count'   => 0,
        ]);
    }

    /** Invokes the private grid builder directly, the way DashboardRoleGuardsTest reaches recordListRoleFlags(). */
    private function buildGrid(): array
    {
        $method  = new \ReflectionMethod(DashboardPageBuilder::class, 'buildScheduleGrid');
        $builder = (new \ReflectionClass(DashboardPageBuilder::class))->newInstanceWithoutConstructor();

        return $method->invoke($builder, model(DistributionBatchModel::class));
    }

    /** The first Monday in the current month whose next day still falls in the same month, so a 2 day run never crosses a week or month boundary. */
    private static function mondayWithRoomInMonth(): int
    {
        $daysInMonth = (int) date('t');
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            if ((int) date('N', strtotime($date)) === 1 && $day + 1 <= $daysInMonth) {
                return $day;
            }
        }

        self::fail('current month has no Monday with a following day, which should not happen');
    }

    /** The first Saturday in the current month whose next day (a Sunday) still falls in the same month, so the run crosses a week boundary without leaving the month. */
    private static function saturdayWithRoomInMonth(): int
    {
        $daysInMonth = (int) date('t');
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            if ((int) date('N', strtotime($date)) === 6 && $day + 1 <= $daysInMonth) {
                return $day;
            }
        }

        self::fail('current month has no Saturday with a following day, which should not happen');
    }

    public function testMultiDayBatchWithinOneWeekProducesASingleBar(): void
    {
        $day = self::mondayWithRoomInMonth();
        $start = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $end   = date('Y-m-') . str_pad((string) ($day + 1), 2, '0', STR_PAD_LEFT);
        $this->insertBatch(1, $start, $end);

        $grid = $this->buildGrid();

        $this->assertCount(1, $grid['bars'], 'a run inside one week produces exactly one bar');
        $leading = (int) date('w', strtotime(date('Y-m-01')));
        $offset  = $leading + $day - 1;
        $this->assertSame(intdiv($offset, 7), $grid['bars'][0]['weekIndex']);
        $this->assertSame($offset % 7, $grid['bars'][0]['startCol']);
        $this->assertSame(2, $grid['bars'][0]['span']);
        $this->assertSame('purple', $grid['bars'][0]['color']);
    }

    public function testBatchCrossingAWeekBoundaryProducesTwoBars(): void
    {
        $day = self::saturdayWithRoomInMonth();
        $start = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $end   = date('Y-m-') . str_pad((string) ($day + 1), 2, '0', STR_PAD_LEFT);
        $this->insertBatch(2, $start, $end);

        $grid = $this->buildGrid();

        $this->assertCount(2, $grid['bars'], 'a run split by a Sunday produces one bar per week row');
        $leading    = (int) date('w', strtotime(date('Y-m-01')));
        $satOffset  = $leading + $day - 1;
        $first  = $grid['bars'][0];
        $second = $grid['bars'][1];

        $this->assertSame(intdiv($satOffset, 7), $first['weekIndex']);
        $this->assertSame(6, $first['startCol'], 'Saturday is the last column of its week');
        $this->assertSame(1, $first['span']);

        $this->assertSame(intdiv($satOffset, 7) + 1, $second['weekIndex']);
        $this->assertSame(0, $second['startCol'], 'Sunday opens the following week row');
        $this->assertSame(1, $second['span']);
    }

    public function testBatchSpanningIntoNextMonthIsClippedToTheVisibleMonth(): void
    {
        $lastDay = (int) date('t');
        $start   = date('Y-m-') . str_pad((string) $lastDay, 2, '0', STR_PAD_LEFT);
        $end     = date('Y-m-d', strtotime(date('Y-m-t') . ' +3 days'));
        $this->insertBatch(3, $start, $end);

        $grid = $this->buildGrid();

        $totalSpan = array_sum(array_column($grid['bars'], 'span'));
        $this->assertSame(1, $totalSpan, 'only the last day of the visible month is covered, the rest falls outside it');
    }

    public function testTodayIsMarked(): void
    {
        $grid  = $this->buildGrid();
        $today = (int) date('j');

        $marked = 0;
        foreach ($grid['weeks'] as $week) {
            foreach ($week as $cell) {
                if (! $cell['isOutside'] && $cell['day'] === $today) {
                    $this->assertTrue($cell['isToday']);
                    $marked++;
                } elseif ($cell['day'] !== null) {
                    $this->assertFalse($cell['isToday']);
                }
            }
        }

        $this->assertSame(1, $marked, 'exactly one cell is today');
    }

    public function testCardNamesTheUpcomingBatchAndItsVenue(): void
    {
        $this->insertBatch(4, date('Y-m-d', strtotime('+2 days')), date('Y-m-d', strtotime('+3 days')));

        $body = $this->withSession(['user_id' => 1, 'username' => 'boss', 'role' => 'administrator', 'is_logged_in' => true])
            ->get('dashboard')
            ->getBody();

        $this->assertStringContainsString('Batch 4', $body);
        $this->assertStringContainsString('City Hall Quadrangle', $body);
    }

    public function testDistributionsTableSeparatesAPlottedBatchFromARunningOne(): void
    {
        // Both rows have closed_at NULL, which is what the pill used to test
        // on its own: it labelled a batch plotted for next week "open".
        $this->insertBatch(6, date('Y-m-d', strtotime('+7 days')), date('Y-m-d', strtotime('+8 days')));
        db_connect()->table('distribution_batch')->update(
            ['started_at' => date('Y-m-d H:i:s')],
            ['batch_id' => 6]
        );
        $this->insertBatch(7, date('Y-m-d', strtotime('+14 days')), date('Y-m-d', strtotime('+15 days')));

        $body = $this->withSession(['user_id' => 1, 'username' => 'boss', 'role' => 'administrator', 'is_logged_in' => true])
            ->get('dashboard')
            ->getBody();

        $this->assertStringContainsString('>Open</span>', $body, 'the started batch reads as open');
        $this->assertStringContainsString('>Scheduled</span>', $body, 'the plotted batch reads as scheduled');
        $this->assertStringContainsString('Not yet started', $body);
    }

    public function testCardIsReadOnly(): void
    {
        $body = $this->withSession(['user_id' => 1, 'username' => 'boss', 'role' => 'administrator', 'is_logged_in' => true])
            ->get('dashboard')
            ->getBody();

        $this->assertStringNotContainsString('id="scheduleFormModal"', $body);
        $this->assertStringContainsString('tab=schedule', $body, 'the card links to the calendar');
    }

    public function testEmptyScheduleDegradesToTheMutedSentence(): void
    {
        $body = $this->withSession(['user_id' => 1, 'username' => 'boss', 'role' => 'administrator', 'is_logged_in' => true])
            ->get('dashboard')
            ->getBody();

        $this->assertStringContainsString('Nothing scheduled. Plot a distribution on the calendar.', $body);
    }

    public function testCardCarriesNoMonthArrows(): void
    {
        $body = $this->withSession(['user_id' => 1, 'username' => 'boss', 'role' => 'administrator', 'is_logged_in' => true])
            ->get('dashboard')
            ->getBody();

        $this->assertStringNotContainsString('dash-schedule-nav', $body, 'the arrows never worked, so they are not drawn');
    }

    public function testBarsCarryTheFullNameAsATooltip(): void
    {
        $day   = self::mondayWithRoomInMonth();
        $start = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $this->insertBatch(5, $start, $start);

        $body = $this->withSession(['user_id' => 1, 'username' => 'boss', 'role' => 'administrator', 'is_logged_in' => true])
            ->get('dashboard')
            ->getBody();

        // A bar is one column wide at its narrowest, so the label is usually
        // clipped: the tooltip is the only place the whole name is readable.
        // bindTooltips() in view-interactions.js turns the attributes into a
        // real Bootstrap tooltip; body is the container because the bar itself
        // is overflow:hidden and would clip it.
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $body);
        $this->assertStringContainsString('data-bs-container="body"', $body);
        $this->assertStringContainsString('title="Batch 5', $body);
    }
}
