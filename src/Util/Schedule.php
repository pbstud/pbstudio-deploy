<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Schedule.
 */
class Schedule
{
    private string $firstHour;

    private string $lastHour;

    private \ArrayObject $schedules;

    /**
     * Schedule constructor.
     */
    public function __construct()
    {
        $this->firstHour = '06:00';
        $this->lastHour = '21:00';

        $this->calculateSchedules();
    }

    /**
     * @return string
     */
    public function getFirstHour(): string
    {
        return $this->firstHour;
    }

    /**
     * @return string
     */
    public function getLastHour(): string
    {
        return $this->lastHour;
    }

    /**
     * @return \ArrayObject
     */
    public function getSchedules(): \ArrayObject
    {
        return $this->schedules;
    }

    /**
     * Calculate schedules.
     */
    private function calculateSchedules(): void
    {
        $timeStart = strtotime($this->firstHour);
        $timeEnd = strtotime($this->lastHour);

        $schedules = [];

        while ($timeStart <= $timeEnd) {
            $time = date('H:i', $timeStart);
            $schedules[$time] = $time;

            $timeStart = strtotime('+30 minutes', $timeStart);
        }

        $this->schedules = new \ArrayObject($schedules);
    }
}
