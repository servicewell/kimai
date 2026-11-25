<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Repository\Result;

use App\Entity\Timesheet;
use App\Repository\Query\TimesheetQuery;
use App\Repository\Result\TimesheetResult;
use App\Tests\Repository\AbstractRepositoryTestCase;
use App\Tests\Timesheet\RunningTimesheetTestTrait;
use App\Timesheet\RunningTimeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(TimesheetResult::class)]
#[Group('integration')]
class TimesheetResultTest extends AbstractRepositoryTestCase
{
    use RunningTimesheetTestTrait;

    public function testRunningDurationDoesNotChangeResultsCountsOrPagination(): void
    {
        [$user] = $this->createRunningTimesheets();
        $repository = $this->getEntityManager()->getRepository(Timesheet::class);
        $query = new TimesheetQuery();
        $query->setUser($user);
        $query->setPageSize(2);
        $result = $repository->getTimesheetResult($query, true);
        $entries = $result->getResults();
        self::assertCount(3, $entries);
        self::assertSame($entries, $result->getResults());
        $expected = 0;
        $runningTime = new RunningTimeService();
        foreach ($entries as $entry) {
            $expected += $runningTime->getDuration($entry);
        }
        self::assertSame($expected, $result->getStatistic()->getDuration());
        self::assertSame(3, $result->getStatistic()->getCount());
        self::assertSame($result->getStatistic(), $result->getStatistic());
        self::assertCount(3, iterator_to_array($result->toIterable()));
        self::assertCount(2, $result->getPagerfanta()->getCurrentPageResults());
        self::assertSame(3600, $repository->getTimesheetResult($query)->getStatistic()->getDuration());
    }
}
