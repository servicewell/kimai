<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Timesheet;

use App\Configuration\SystemConfiguration;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Event\TimesheetCreatePostEvent;
use App\Event\TimesheetCreatePreEvent;
use App\Event\TimesheetDeleteMultiplePreEvent;
use App\Event\TimesheetDeletePreEvent;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetRestartPostEvent;
use App\Event\TimesheetRestartPreEvent;
use App\Event\TimesheetStopPostEvent;
use App\Event\TimesheetStopPreEvent;
use App\Event\TimesheetUpdateMultiplePostEvent;
use App\Event\TimesheetUpdateMultiplePreEvent;
use App\Event\TimesheetUpdatePostEvent;
use App\Event\TimesheetUpdatePreEvent;
use App\Repository\TimesheetRepository;
use App\Security\AccessDeniedException;
use App\Timesheet\TrackingMode\TrackingModeInterface;
use App\Validator\ValidationException;
use App\Validator\ValidationFailedException;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TimesheetService
{
    /**
     * @var array<string>
     */
    private array $doNotValidateCodes = [];

    public function __construct(
        private readonly SystemConfiguration $configuration,
        private readonly TimesheetRepository $repository,
        private readonly TrackingModeService $trackingModeService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * Calls prepareNewTimesheet() automatically if $request is not null.
     *
     * @param User $user
     * @param Request|null $request
     * @return Timesheet
     */
    public function createNewTimesheet(User $user, ?Request $request = null): Timesheet
    {
        $timesheet = new Timesheet();
        $timesheet->setUser($user);

        if (null !== $request) {
            $this->prepareNewTimesheet($timesheet, $request);
        }

        return $timesheet;
    }

    public function prepareNewTimesheet(Timesheet $timesheet, ?Request $request = null): Timesheet
    {
        if (null !== $timesheet->getId()) {
            throw new InvalidArgumentException('Cannot prepare timesheet, already persisted');
        }

        $event = new TimesheetMetaDefinitionEvent($timesheet);
        $this->dispatcher->dispatch($event);

        $mode = $this->trackingModeService->getActiveMode();
        $mode->create($timesheet, $request);

        $timesheet->setBillableMode(Timesheet::BILLABLE_AUTOMATIC);

        return $timesheet;
    }

    /**
     * @param Timesheet $timesheet
     * @param Timesheet $copyFrom
     * @return Timesheet
     * @throws ValidationFailedException for invalid timesheets or running timesheets that should be stopped
     * @throws InvalidArgumentException for already persisted timesheets
     * @throws AccessDeniedException if user is not allowed to start timesheet
     */
    public function restartTimesheet(Timesheet $timesheet, Timesheet $copyFrom): Timesheet
    {
        $this->dispatcher->dispatch(new TimesheetRestartPreEvent($timesheet, $copyFrom));
        $this->saveNewTimesheet($timesheet); // @phpstan-ignore method.deprecated
        $this->dispatcher->dispatch(new TimesheetRestartPostEvent($timesheet, $copyFrom));

        return $timesheet;
    }

    /**
     * @throws ValidationFailedException for invalid timesheets or running timesheets that should be stopped
     * @throws InvalidArgumentException for already persisted timesheets
     * @throws AccessDeniedException if user is not allowed to start timesheet
     * @deprecated since 2.36.0 - use saveTimesheet() instead
     */
    public function saveNewTimesheet(Timesheet $timesheet): Timesheet
    {
        if (null !== $timesheet->getId()) {
            throw new InvalidArgumentException('Cannot create timesheet, already persisted');
        }

        $permission = ($timesheet->isRunning()) ? 'start' : 'create';
        if (!$this->auth->isGranted($permission, $timesheet)) {
            throw new AccessDeniedException(\sprintf('You are not allowed to %s this timesheet record', $permission));
        }

        $this->repository->begin();
        try {
            $this->validateTimesheet($timesheet);
            $this->fixTimezone($timesheet);

            $this->dispatcher->dispatch(new TimesheetCreatePreEvent($timesheet));
            $this->repository->save($timesheet);
            $this->dispatcher->dispatch(new TimesheetCreatePostEvent($timesheet));

            if ($timesheet->isRunning()) {
                try {
                    $this->stopActiveEntries($timesheet);
                } catch (ValidationFailedException $vex) {
                    // could happen for timesheets that were started in the future (end before begin)
                    // or if you try to create a new timesheet while an old one is running for too long
                    throw new ValidationFailedException($vex->getViolations(), 'Cannot stop running timesheet');
                }
            }

            $this->repository->commit();
        } catch (\Exception $ex) {
            $this->repository->rollback();
            throw $ex;
        }

        return $timesheet;
    }

    public function saveTimesheet(Timesheet $timesheet): Timesheet
    {
        if ($timesheet->getId() === null) {
            return $this->saveNewTimesheet($timesheet); // @phpstan-ignore method.deprecated
        } else {
            return $this->updateTimesheet($timesheet); // @phpstan-ignore method.deprecated
        }
    }

    public function canSplitTimesheet(Timesheet $timesheet): bool
    {
        $begin = $timesheet->getBegin();
        $end = $timesheet->getEnd();

        // A fixed fee applies per record. Splitting would charge it twice.
        return $begin !== null && $end !== null && $end->getTimestamp() - $begin->getTimestamp() >= 2
            && !$timesheet->isExported() && $timesheet->getFixedRate() === null
            && $this->getActiveTrackingMode()->canUpdateTimesWithAPI()
            && $this->auth->isGranted('edit', $timesheet) && $this->auth->isGranted('create', $timesheet);
    }

    public function splitTimesheet(int $id, int $expectedBegin, int $expectedEnd): void
    {
        [$first, $second] = $this->repository->updateWithLock($id, function (?Timesheet $first) use ($expectedBegin, $expectedEnd): array {
            if ($first === null) {
                throw new NotFoundHttpException('Timesheet not found');
            }

            $begin = $first->getBegin();
            $end = $first->getEnd();
            if ($begin === null || $end === null || !$this->canSplitTimesheet($first)) {
                throw new AccessDeniedException('You cannot split this timesheet');
            }

            if ($begin->getTimestamp() !== $expectedBegin || $end->getTimestamp() !== $expectedEnd) {
                throw new ConflictHttpException('The timesheet changed. Open the calendar menu again to split it.');
            }

            $midpoint = (clone $begin)->setTimestamp($expectedBegin + intdiv($expectedEnd - $expectedBegin, 2));
            $break = $first->getBreak();
            $firstBreak = intdiv($break, 2);
            $second = clone $first;
            $first->setEnd($midpoint)->setDuration(null);
            $first->setBreak($firstBreak);
            $second->setBegin(clone $midpoint)->setEnd(clone $end)->setDuration(null);
            $second->setBreak($break - $firstBreak);

            if (!$this->auth->isGranted('create', $second)) {
                throw new AccessDeniedException('You cannot create the second half of this timesheet');
            }

            // Preserve the exact shared boundary even when the configured start and end rounding differ.
            $first->setTimeRoundingEnabled(false);
            $second->setTimeRoundingEnabled(false);
            try {
                $this->validateTimesheet($first);
                $this->fixTimezone($first);
                $this->dispatcher->dispatch(new TimesheetUpdatePreEvent($first));
                $this->repository->save($first);

                // Validate against the shortened original so overlap and budget checks see the new interval.
                $this->validateTimesheet($second);
                $this->fixTimezone($second);
                $this->dispatcher->dispatch(new TimesheetCreatePreEvent($second));
                $this->repository->save($second);

                if ($first->getFixedRate() !== null || $second->getFixedRate() !== null) {
                    throw new ConflictHttpException('A fixed fee now applies to this timesheet.');
                }
            } finally {
                $first->setTimeRoundingEnabled(true);
                $second->setTimeRoundingEnabled(true);
            }

            return [$first, $second];
        });

        $this->dispatcher->dispatch(new TimesheetUpdatePostEvent($first));
        $this->dispatcher->dispatch(new TimesheetCreatePostEvent($second));
    }

    /**
     * Does NOT validate the given timesheet.
     *
     * @deprecated since 2.36.0 - use saveTimesheet() instead
     */
    public function updateTimesheet(Timesheet $timesheet): Timesheet
    {
        $this->fixTimezone($timesheet);

        $this->dispatcher->dispatch(new TimesheetUpdatePreEvent($timesheet));
        $this->repository->save($timesheet);
        $this->dispatcher->dispatch(new TimesheetUpdatePostEvent($timesheet));

        return $timesheet;
    }

    /**
     * Does NOT validate the given timesheets!
     *
     * @param array $timesheets
     * @return array
     * @throws \Exception
     */
    public function updateMultipleTimesheets(array $timesheets): array
    {
        $this->dispatcher->dispatch(new TimesheetUpdateMultiplePreEvent($timesheets));
        $this->repository->saveMultiple($timesheets);
        $this->dispatcher->dispatch(new TimesheetUpdateMultiplePostEvent($timesheets));

        return $timesheets;
    }

    /**
     * Validates the given timesheet, especially important for not setting a wrong end date.
     * But also to check that all required data is set.
     *
     * @param Timesheet $timesheet
     * @param bool $validate
     * @throws ValidationException for already stopped timesheets
     * @throws ValidationFailedException
     */
    public function stopTimesheet(Timesheet $timesheet, bool $validate = true): void
    {
        if (null !== $timesheet->getEnd()) {
            // timesheet already stopped, nothing to do. in previous version, this method did throw a:
            // new ValidationException('Timesheet entry already stopped');
            // but this was removed, because it can happen in the frontend when using multiple tabs/devices and should
            // simply be ignored - showing the message to the user with a "danger status" is not necessary
            return;
        }

        $begin = clone $timesheet->getBegin();
        $now = new \DateTime('now', $begin->getTimezone());

        $timesheet->setBegin($begin);
        $timesheet->setEnd($now);

        if ($validate) {
            $this->validateTimesheet($timesheet);
        }

        $this->dispatcher->dispatch(new TimesheetStopPreEvent($timesheet));
        $this->repository->save($timesheet);
        $this->dispatcher->dispatch(new TimesheetStopPostEvent($timesheet));
    }

    public function deleteTimesheet(Timesheet $timesheet): void
    {
        $this->dispatcher->dispatch(new TimesheetDeletePreEvent($timesheet));
        $this->repository->delete($timesheet);
    }

    public function deleteMultipleTimesheets(array $timesheets): void
    {
        $this->dispatcher->dispatch(new TimesheetDeleteMultiplePreEvent($timesheets));
        $this->repository->deleteMultiple($timesheets);
    }

    /**
     * @param string[] $groups
     * @throws ValidationFailedException
     */
    public function validateTimesheet(Timesheet $timesheet, array $groups = []): void
    {
        $errors = $this->validator->validate($timesheet, null, $groups);

        if ($errors->count() > 0) {
            /** @var ConstraintViolation $error */
            foreach ($errors as $error) {
                if (\in_array($error->getCode(), $this->doNotValidateCodes, true)) {
                    continue;
                }

                throw new ValidationFailedException($errors);
            }
        }
    }

    /**
     * @param array<string> $validationCodes
     */
    public function setIgnoreValidationCodes(array $validationCodes): void
    {
        $this->doNotValidateCodes = $validationCodes;
    }

    /**
     * Makes sure, that the timesheet record has the timezone of the user.
     *
     * This fixes #1442 and prevents a wrong time if a teamlead edits the
     * timesheet for an employee living in another timezone.
     *
     * @param Timesheet $timesheet
     */
    private function fixTimezone(Timesheet $timesheet)
    {
        if (null !== ($timezone = $timesheet->getTimezone()) && $timezone !== $timesheet->getUser()->getTimezone()) {
            $timesheet->setTimezone($timesheet->getUser()->getTimezone());
        }
    }

    /**
     * Stops active records if more than allowed are running for the timesheet user.
     *
     * The given $timesheet will be ignored and not stopped (assuming it is the latest one that was re-started).
     *
     * @param Timesheet $timesheet
     * @return int
     * @throws ValidationException
     * @throws ValidationFailedException
     */
    private function stopActiveEntries(Timesheet $timesheet): int
    {
        $hardLimit = $this->configuration->getTimesheetActiveEntriesHardLimit();
        $activeEntries = $this->repository->getActiveEntries($timesheet->getUser());

        if (empty($activeEntries)) {
            return 0;
        }

        $activeEntries = array_reverse($activeEntries);
        $needsStop = \count($activeEntries) - $hardLimit;
        $counter = 0;

        foreach ($activeEntries as $activeEntry) {
            if ($timesheet->getId() !== $activeEntry->getId() && $needsStop > 0) {
                $this->stopTimesheet($activeEntry);
                $needsStop--;
                $counter++;
            }
        }

        return $counter;
    }

    public function getActiveTrackingMode(): TrackingModeInterface
    {
        return $this->trackingModeService->getActiveMode();
    }

    public function stopAll(): int
    {
        $activeEntries = $this->repository->getActiveEntries();
        $counter = 0;

        foreach ($activeEntries as $timesheet) {
            $this->stopTimesheet($timesheet, false);
            $counter++;
        }

        return $counter;
    }
}
