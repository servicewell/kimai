<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Timesheet;

use App\Entity\Timesheet;
use App\Timesheet\RunningTimeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunningTimeService::class)]
class RunningTimeServiceTest extends TestCase
{
    public static function getRunningDurations(): array
    {
        return [
            ['2026-11-24 09:00:00+01:00', '2026-11-24 10:00:00+01:00', 0, 3600],
            ['2026-11-24 09:00:00+01:00', '2026-11-24 10:00:00+01:00', 600, 3000],
            ['2026-11-24 11:00:00+01:00', '2026-11-24 10:00:00+01:00', 0, 0],
            ['2026-11-24 09:59:30+01:00', '2026-11-24 10:00:00+01:00', 60, 0],
            ['2026-11-24 09:59:23+01:00', '2026-11-24 10:00:00+01:00', 0, 37],
            ['2026-12-31 23:00:00+01:00', '2027-01-01 01:00:00+01:00', 0, 7200],
            ['2026-03-29 01:30:00+01:00', '2026-03-29 03:30:00+02:00', 0, 3600],
            ['2026-10-25 02:30:00+02:00', '2026-10-25 02:30:00+01:00', 0, 3600],
            ['2026-11-24 08:00:00+00:00', '2026-11-24 10:00:00+01:00', 0, 3600],
        ];
    }

    #[DataProvider('getRunningDurations')]
    public function testRunningDuration(string $begin, string $now, int $break, int $expected): void
    {
        $reference = new \DateTimeImmutable($now);
        $service = new RunningTimeService($reference);
        $timesheet = new Timesheet();
        $timesheet->setBegin(new \DateTime($begin));
        $timesheet->setBreak($break);

        self::assertSame($expected, $service->getDuration($timesheet));
        self::assertSame($reference, $service->getReferenceTime());
        self::assertSame(0, $timesheet->getDuration());
        self::assertNull($timesheet->getEnd());
    }

    public function testMissingBegin(): void
    {
        self::assertSame(0, (new RunningTimeService())->getDuration(new Timesheet()));
    }

    public function testStoppedDurationIsNotRecalculated(): void
    {
        $timesheet = new Timesheet();
        $timesheet->setBegin(new \DateTime('2026-11-24 09:00:00'));
        $timesheet->setEnd(new \DateTime('2026-11-24 10:01:00'));
        $timesheet->setDuration(4500);

        self::assertSame(4500, (new RunningTimeService())->getDuration($timesheet));
        $timesheet->setDuration(null);
        self::assertSame(3660, (new RunningTimeService())->getDuration($timesheet));
    }
}
