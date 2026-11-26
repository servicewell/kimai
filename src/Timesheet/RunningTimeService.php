<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Timesheet;

use App\Entity\Timesheet;
use Doctrine\ORM\QueryBuilder;

/**
 * Read-only duration projections. Never changes the tracked timesheet entities.
 */
final class RunningTimeService
{
    private readonly \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $requestTime = $_SERVER['REQUEST_TIME'] ?? time();
        $this->now = $now ?? new \DateTimeImmutable('@' . (\is_int($requestTime) ? $requestTime : time()));
    }

    public function getDuration(Timesheet $timesheet): int
    {
        if (!$timesheet->isRunning()) {
            return $timesheet->getDuration() ?? 0;
        }

        return $this->calculateDuration($timesheet->getBegin(), $timesheet->getBreak());
    }

    public function getReferenceTime(): \DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * @return array<string, int<0, max>>
     */
    public function getRunningDurationsByDay(QueryBuilder $builder): array
    {
        $running = clone $builder;
        $running->resetDQLPart('groupBy')->resetDQLPart('orderBy');
        $running->select('DISTINCT t.id AS runningId', 't.begin AS runningBegin', 't.break AS runningBreak', 'DATE(t.date) AS day');
        $running->andWhere('t.end IS NULL');
        $running->setFirstResult(0)->setMaxResults(null);

        /** @var list<array{day: string, runningBegin: ?\DateTimeInterface, runningBreak: ?int}> $rows */
        $rows = $running->getQuery()->getArrayResult();
        $durations = [];
        foreach ($rows as $row) {
            $day = $row['day'];
            $duration = $this->calculateDuration($row['runningBegin'], $row['runningBreak'] ?? 0);
            $durations[$day] = ($durations[$day] ?? 0) + $duration;
        }

        return $durations;
    }

    /**
     * @return int<0, max>
     */
    private function calculateDuration(?\DateTimeInterface $begin, int $break): int
    {
        return $begin === null ? 0 : max(0, $this->now->getTimestamp() - $begin->getTimestamp() - $break);
    }
}
