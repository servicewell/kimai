<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\API;

use App\Entity\User;
use App\Tests\DataFixtures\ActivityFixtures;
use App\Tests\DataFixtures\CustomerFixtures;
use App\Tests\DataFixtures\ProjectFixtures;
use App\Tests\DataFixtures\TimesheetFixtures;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
class ActionsControllerTest extends APIControllerBaseTestCase
{
    public function testIsSecure(): void
    {
        $this->assertUrlIsSecured('/api/actions/timesheet/1/index/en');
    }

    public function testGetTimesheetActions(): void
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_USER);

        $items = $this->importFixture(new TimesheetFixtures($this->getUserByRole(User::ROLE_USER), 1));

        $views = [
            'index' => [
                'repeat',
                'edit',
                'copy',
                'divider0',
                'trash',
            ],
            'calendar' => [
                'repeat',
                'edit',
                'copy',
                'split_timesheet',
                'divider0',
                'trash',
            ],
            'custom' => [
                'repeat',
                'edit',
                'copy',
                'divider0',
            ],
        ];

        foreach ($views as $view => $entries) {
            $this->assertAccessIsGranted($client, \sprintf('/api/actions/timesheet/%s/%s/en', $items[0]->getId(), $view));
            $result = json_decode($client->getResponse()->getContent(), true);

            self::assertIsArray($result);
            self::assertSame($entries, array_column($result, 'id'));
            self::assertIsArray($result[0]);
            self::assertSame($view === 'calendar' ? 'Continue to work on' : 'Start again', $result[0]['title']);
            if ($view === 'calendar') {
                $split = $result[3];
                self::assertIsArray($split);
                self::assertSame('Split', $split['title']);
                self::assertIsArray($split['attr']);
                self::assertSame('PATCH', $split['attr']['data-method']);
                self::assertSame('kimai.timesheetUpdate', $split['attr']['data-event']);
                self::assertArrayNotHasKey('data-question', $split['attr']);
            }
            foreach ($result as $item) {
                self::assertIsArray($item);
                self::assertApiResponseTypeStructure('PageActionItem', $item);
            }
        }
    }

    public function testCalendarContinueActionStartsNewTimer(): void
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_USER);
        $fixture = new TimesheetFixtures($this->getUserByRole(User::ROLE_USER), 1);
        $fixture->setStartDate(new \DateTime('-3 days'));
        [$original] = $this->importFixture($fixture);
        $this->assertAccessIsGranted($client, \sprintf('/api/actions/timesheet/%s/calendar/sv', $original->getId()));

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $actions = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($actions);
        $action = $actions[0];
        self::assertIsArray($action);
        self::assertSame('repeat', $action['id']);
        self::assertSame('Fortsätt arbeta med', $action['title']);
        self::assertIsString($action['class']);
        self::assertStringContainsString('api-link', $action['class']);
        $attributes = $action['attr'];
        self::assertIsArray($attributes);
        self::assertArrayNotHasKey('data-question', $attributes);
        self::assertSame('kimai.timesheetStart kimai.timesheetUpdate', $attributes['data-event']);
        self::assertSame('PATCH', $attributes['data-method']);
        self::assertIsString($attributes['data-href']);
        self::assertIsString($attributes['data-payload']);

        $beforeStart = time();
        $this->request($client, $attributes['data-href'], 'PATCH', [], $attributes['data-payload']);
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $started = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($started);
        self::assertNotSame($original->getId(), $started['id']);
        self::assertSame($original->getProject()?->getId(), $started['project']);
        self::assertSame($original->getActivity()?->getId(), $started['activity']);
        self::assertNull($started['end']);
        self::assertIsString($started['begin']);
        $begin = (new \DateTimeImmutable($started['begin']))->getTimestamp();
        // The default tracking mode rounds the current start time down to a minute.
        self::assertGreaterThanOrEqual($beforeStart - 60, $begin);
        self::assertLessThanOrEqual(time(), $begin);
    }

    public function testGetActivityActions(): void
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_SUPER_ADMIN);

        $customers = $this->importFixture(new CustomerFixtures(1));

        $projectFixture = new ProjectFixtures(1);
        $projectFixture->setCustomers($customers);
        $projects = $this->importFixture($projectFixture);

        $activityFixture = new ActivityFixtures(1);
        $activityFixture->setProjects($projects);
        $activities = $this->importFixture($activityFixture);

        $views = [
            'index' => [
                'details',
                'edit',
                'permissions',
                'divider0',
                'filter',
                'divider1',
                'trash',
            ],
            'custom' => [
                'details',
                'edit',
                'permissions',
                'divider0',
                'filter',
            ],
        ];

        foreach ($views as $view => $entries) {
            $this->assertAccessIsGranted($client, \sprintf('/api/actions/activity/%s/%s/en', $activities[0]->getId(), $view));
            $result = json_decode($client->getResponse()->getContent(), true);

            self::assertIsArray($result);
            foreach ($result as $item) {
                self::assertIsArray($item);
                self::assertApiResponseTypeStructure('PageActionItem', $item);
            }

            $i = 0;
            foreach ($entries as $id) {
                self::assertIsArray($result[$i]);
                self::assertEquals($id, $result[$i]['id'], \sprintf('Failed action "%s" with name "%s" in view "%s"', $i, $id, $view));
                $i++;
            }
        }
    }

    public function testGetProjectActions(): void
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_SUPER_ADMIN);

        $customers = $this->importFixture(new CustomerFixtures(1));

        $projectFixture = new ProjectFixtures(1);
        $projectFixture->setCustomers($customers);
        $projects = $this->importFixture($projectFixture);

        $views = [
            'index' => [
                'details',
                'edit',
                'permissions',
                'divider0',
                'filter',
                'divider1',
                'copy',
                'report_project_details',
                'trash',
            ],
            'custom' => [
                'details',
                'edit',
                'permissions',
                'divider0',
                'filter',
                'divider1',
                'copy',
                'report_project_details',
            ],
        ];

        foreach ($views as $view => $entries) {
            $this->assertAccessIsGranted($client, \sprintf('/api/actions/project/%s/%s/en', $projects[0]->getId(), $view));
            $result = json_decode($client->getResponse()->getContent(), true);

            self::assertIsArray($result);
            foreach ($result as $item) {
                self::assertIsArray($item);
                self::assertApiResponseTypeStructure('PageActionItem', $item);
            }

            $i = 0;
            foreach ($entries as $id) {
                self::assertIsArray($result[$i]);
                self::assertEquals($id, $result[$i]['id'], \sprintf('Failed action "%s" with name "%s" in view "%s"', $i, $id, $view));
                $i++;
            }
        }
    }

    public function testGetCustomerActions(): void
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_SUPER_ADMIN);

        $customers = $this->importFixture(new CustomerFixtures(1));

        $views = [
            'index' => [
                'details',
                'edit',
                'permissions',
                'divider0',
                'filter',
                'divider1',
                'report',
                'trash',
            ],
            'custom' => [
                'details',
                'edit',
                'permissions',
                'divider0',
                'filter',
                'divider1',
                'report',
            ],
        ];

        foreach ($views as $view => $entries) {
            $this->assertAccessIsGranted($client, \sprintf('/api/actions/customer/%s/%s/en', $customers[0]->getId(), $view));
            $result = json_decode($client->getResponse()->getContent(), true);

            self::assertIsArray($result);
            foreach ($result as $item) {
                self::assertIsArray($item);
                self::assertApiResponseTypeStructure('PageActionItem', $item);
            }

            $i = 0;
            foreach ($entries as $id) {
                self::assertIsArray($result[$i]);
                self::assertEquals($id, $result[$i]['id'], \sprintf('Failed action "%s" with name "%s" in view "%s"', $i, $id, $view));
                $i++;
            }
        }
    }
}
