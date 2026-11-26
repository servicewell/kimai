<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\WorkingTime;

use App\Entity\Timesheet;
use App\Entity\User;
use App\Entity\WorkingTime;
use App\Tests\Repository\AbstractRepositoryTestCase;
use App\Tests\Timesheet\RunningTimesheetTestTrait;
use App\WorkingTime\Mode\WorkingTimeModeFactory;
use App\WorkingTime\WorkingTimeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(WorkingTimeService::class)]
#[Group('integration')]
class WorkingTimeServiceTest extends AbstractRepositoryTestCase
{
    use RunningTimesheetTestTrait;

    public function testDisplayIncludesRunningTimeButApprovalPersistsStoppedTime(): void
    {
        [$user] = $this->createRunningTimesheets();
        $em = $this->getEntityManager();
        $modeFactory = self::getContainer()->get(WorkingTimeModeFactory::class);
        self::assertInstanceOf(WorkingTimeModeFactory::class, $modeFactory);
        $service = new WorkingTimeService(
            $em->getRepository(Timesheet::class),
            $em->getRepository(WorkingTime::class),
            new EventDispatcher(),
            $modeFactory,
            $em->getRepository(User::class),
            $this->createRunningTimeService()
        );
        $now = $this->createRunningTimeService()->getReferenceTime();
        $day = new \DateTimeImmutable('2015-07-15 Europe/Stockholm');
        $stopped = $service->getMonth($user, $day, $now);
        $display = $service->getYearForDisplay($user, $day, $now)->getMonth($day);
        self::assertSame(3600, $stopped->getActualTime());
        self::assertSame(12000, $display->getActualTime());
        self::assertSame(10200, $display->getDay($day)->getActualTime());
        self::assertSame(3600, $display->getDay($day)->getWorkingTime()?->getActualTime());

        $service->approveMonth($user, $display, $now, $this->getUserByRole(User::ROLE_SUPER_ADMIN));
        $approved = $service->getYearForDisplay($user, $day, $now)->getMonth($day);
        self::assertSame(3600, $approved->getActualTime());
        self::assertSame(3600, $approved->getDay($day)->getWorkingTime()?->getActualTime());
        self::assertTrue($approved->getDay($day)->isLocked());
    }
}
