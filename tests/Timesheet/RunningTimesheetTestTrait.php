<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Timesheet;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Timesheet\RunningTimeService;

trait RunningTimesheetTestTrait
{
    protected function createRunningTimeService(): RunningTimeService
    {
        return new RunningTimeService(new \DateTimeImmutable('2015-07-16 01:00:00 Europe/Stockholm'));
    }

    /**
     * @return array{User, Project, Activity, Timesheet}
     */
    protected function createRunningTimesheets(): array
    {
        $em = $this->getEntityManager();
        $user = $this->getUserByRole(User::ROLE_USER);
        $project = $em->getRepository(Project::class)->find(1);
        $activity = $em->getRepository(Activity::class)->find(1);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Activity::class, $activity);
        $user->setTimezone('Europe/Stockholm');

        $stopped = new Timesheet();
        $stopped->setBegin(new \DateTime('2015-07-15 20:00:00 Europe/Stockholm'));
        $stopped->setEnd(new \DateTime('2015-07-15 21:00:00 Europe/Stockholm'));
        $stopped->setUser($user)->setProject($project)->setActivity($activity)->setBillable(true)->setExported(true);
        $em->persist($stopped);

        $running = new Timesheet();
        $running->setBegin(new \DateTime('2015-07-15 23:00:00 Europe/Stockholm'));
        $running->setBreak(600);
        $running->setUser($user)->setProject($project)->setActivity($activity)->setBillable(true);
        $em->persist($running);

        $second = new Timesheet();
        $second->setBegin(new \DateTime('2015-07-16 00:30:00 Europe/Stockholm'));
        $second->setUser($user)->setProject($project)->setActivity($activity)->setBillable(false);
        $em->persist($second);
        $em->flush();

        return [$user, $project, $activity, $running];
    }
}
