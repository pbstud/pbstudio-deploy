<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\User;
use App\Repository\ReservationRepository;

/**
 * Resolves achievement conditions that are based on session attendance.
 *
 * Currently supported keys:
 *  - attended_classes         → total sessions attended by the user (attended = true)
 *  - unique_days_attended     → distinct calendar days with at least one attended session
 *  - weekly_frequency         → minimum weekly count across all N individual 7-day blocks;
 *                               the wizard stores the evaluation window as periodDays = N × 7.
 *                               EVERY week in the window must reach the threshold (min ≥ target).
 *
 * New attendance-based keys (SCRUM-288 phase 2+) should be added here:
 *  multi_discipline_count, discipline_classes,
 *  checkin_morning_count, checkin_evening_count, weekend_attendance,
 *  unique_instructors_count, consecutive_same_time_slot
 *
 * Added in this phase:
 *  - consecutive_days     → longest unbroken streak of calendar days with at least one attended session
 *  - consecutive_weeks    → longest unbroken streak of consecutive 7-day blocks with at least one attended session
 *  - no_show_free_days    → days elapsed since the user's last no-show (active reservation on a closed session,
 *                           not attended); rewards commitment — cancelling properly does NOT break the streak
 *  - discipline_classes   → attended classes filtered by a specific discipline ID stored in
 *                           conditionContext["discipline_id"]; supports all four period types
 */
final readonly class AttendanceConditionResolver
{
    /** Condition keys handled by this resolver. */
    public const SUPPORTED_KEYS = [
        'attended_classes',
        'unique_days_attended',
        'weekly_frequency',
        'consecutive_days',
        'consecutive_weeks',
        'no_show_free_days',
        'discipline_classes',
        'multi_discipline_count',
        'unique_instructors_count',
        'friend_joint_classes',
        'checkin_morning_count',
        'checkin_evening_count',
        'weekend_attendance',
        'consecutive_same_time_slot',
    ];

    public function __construct(
        private ReservationRepository $reservationRepository,
    ) {
    }

    public function supports(string $conditionKey): bool
    {
        return in_array($conditionKey, self::SUPPORTED_KEYS, true);
    }

    /**
     * Returns the current metric value for the given achievement and user.
     * The return type is int|float to accommodate future amount-based attendance metrics.
     */
    public function resolve(User $user, Achievement $achievement): int|float
    {
        return match ($achievement->getConditionKey()) {
            'attended_classes'      => $this->resolveAttendedClasses($user, $achievement),
            'unique_days_attended'  => $this->resolveUniqueDaysAttended($user, $achievement),
            'weekly_frequency'      => $this->resolveWeeklyFrequency($user, $achievement),
            'consecutive_days'      => $this->resolveConsecutiveDays($user, $achievement),
            'consecutive_weeks'     => $this->resolveConsecutiveWeeks($user, $achievement),
            'no_show_free_days'     => $this->resolveNoShowFreeDays($user, $achievement),
            'discipline_classes'       => $this->resolveDisciplineClasses($user, $achievement),
            'multi_discipline_count'    => $this->resolveMultiDisciplineCount($user, $achievement),
            'unique_instructors_count'  => $this->resolveUniqueInstructorsCount($user, $achievement),
            'friend_joint_classes'      => $this->resolveFriendJointClasses($user, $achievement),
            'checkin_morning_count'     => $this->resolveCheckinMorningCount($user, $achievement),
            'checkin_evening_count'     => $this->resolveCheckinEveningCount($user, $achievement),
            'weekend_attendance'        => $this->resolveWeekendAttendance($user, $achievement),
            'consecutive_same_time_slot' => $this->resolveConsecutiveSameTimeSlot($user, $achievement),
            default                     => 0,
        };
    }

    /**
     * Resolves the attended-classes count respecting the achievement's period type.
     * Same period logic as resolveUniqueDaysAttended — see that method for notes.
     */
    private function resolveAttendedClasses(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countAttendedByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countAttendedByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countAttendedByUser(
                $user,
                // Fixed window: lower bound is always periodWindowStart; flag is irrelevant.
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countAttendedByUser(
                $user,
                // NONE: flag ON → floor at createdAt; flag OFF → null (all-time).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Resolves the unique-days-attended count respecting the achievement's period type.
     *
     * PERIOD_TYPE_NONE     → all-time count (no date filter).
     * PERIOD_TYPE_DAYS     → rolling window: only days within the last `period_days` calendar days.
     * PERIOD_TYPE_DEADLINE → from = null (all history up to deadline) when includeHistoricalData = false;
     *                          from = createdAt when includeHistoricalData = true. Upper = min(deadline, now).
     * PERIOD_TYPE_WINDOW   → explicit campaign window: from period_window_start up to period_deadline.
     */
    private function resolveUniqueDaysAttended(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countUniqueDaysAttendedByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countUniqueDaysAttendedByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countUniqueDaysAttendedByUser(
                $user,
                // Fixed window: lower bound is always periodWindowStart; flag is irrelevant.
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countUniqueDaysAttendedByUser(
                $user,
                // NONE: flag ON → floor at createdAt; flag OFF → null (all-time).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Resolves weekly_frequency by evaluating each individual 7-day block separately.
     *
     * The evaluation window is divided into N non-overlapping 7-day blocks going backwards
     * from today, where N = round(periodDays / 7).  For each block the actual attendance
     * count is computed; the resolver returns the MINIMUM count across all blocks.
     *
     * The evaluator then checks: min_weekly_count >= targetValue.
     * If every single week reached the threshold, the minimum is also >= threshold → earned.
     * If even one week fell short, the minimum drops below threshold → not earned.
     *
     * Example — threshold=4, periodDays=35 (5 weeks), today=2026-05-19:
     *   Week 0 (May 13–19): 5 ✅
     *   Week 1 (May 06–12): 4 ✅
     *   Week 2 (Apr 29–May 05): 6 ✅
     *   Week 3 (Apr 22–28): 4 ✅
     *   Week 4 (Apr 15–21): 3 ❌  → min = 3 < 4 → NOT earned
     *
     * Block boundaries:
     *   Block i: [ midnight − (i×7 + 6) days,  midnight − (i×7) days ]  (both inclusive, DATE comparison)
     */
    private function resolveWeeklyFrequency(User $user, Achievement $achievement): int
    {
        $today  = new \DateTimeImmutable('today midnight');
        $refDay = $today;
        $lowerBound = null;

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DEADLINE:
                // Upper bound: min(deadline, today).
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                // Fixed window: periodWindowStart → min(deadline, today). Flag is irrelevant.
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                if ($achievement->getPeriodWindowStart() !== null) {
                    $lowerBound = new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'));
                }
                break;

            default:
                // NONE: flag ON → floor at createdAt; flag OFF → no floor.
                // DAYS: flag ON → floor at max(createdAt, rolling_lower) so weeks outside
                //        periodDays are never scanned; flag OFF → lowerBound stays null and
                //        $weeks is derived from periodDays in the block below.
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $ca = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
                    if ($achievement->getPeriodType() === Achievement::PERIOD_TYPE_DAYS) {
                        // Mirror computeDaysLower(): never scan beyond the rolling window.
                        $rolling    = $refDay->modify('-' . (max(7, (int) $achievement->getPeriodDays()) - 1) . ' days');
                        $lowerBound = $ca > $rolling ? $ca : $rolling;
                    } else {
                        // NONE: createdAt is the only floor (no rolling window to cap against).
                        $lowerBound = $ca;
                    }
                }
                break;
        }

        // Number of 7-day blocks to evaluate.
        if ($lowerBound !== null) {
            // Any period type with a known lower bound → derive block count from actual window size.
            // This covers DEADLINE+flag=ON, WINDOW, DAYS (both flag states), and NONE+flag=ON.
            // The cap guard inside the loop still trims the last partial block correctly.
            $windowDays = max(1, (int) $lowerBound->diff($refDay)->days) + 1;
            $weeks      = max(1, (int) round($windowDays / 7));
        } elseif ($achievement->getPeriodType() === Achievement::PERIOD_TYPE_DEADLINE && $lowerBound === null) {
            // DEADLINE + flag OFF: no lower bound → scan up to 52 weeks back from refDay.
            // The early-exit on minCount=0 keeps this efficient.
            $weeks = 52;
        } else {
            // NONE + flag OFF: no lower bound, no periodDays → evaluate only the current week.
            $weeks = (int) round(max(7, (int) $achievement->getPeriodDays()) / 7);
        }

        $minCount  = PHP_INT_MAX;
        $evaluated = 0;

        for ($i = 0; $i < $weeks; $i++) {
            // Most-recent block first (i=0 includes refDay).
            $until = $refDay->modify('-' . ($i * 7) . ' days');
            $from  = $refDay->modify('-' . ($i * 7 + 6) . ' days');

            // Skip blocks whose upper bound falls entirely before the lower bound.
            if ($lowerBound !== null && $until < $lowerBound) {
                break;
            }
            // Cap the start of the last (oldest) partial block at the lower bound.
            if ($lowerBound !== null && $from < $lowerBound) {
                $from = $lowerBound;
            }

            $count     = $this->reservationRepository->countAttendedByUser($user, $from, $until);
            $minCount  = min($minCount, $count);
            $evaluated++;

            // Early exit: once the minimum is 0, no older block can bring it back up.
            if ($minCount === 0) {
                return 0;
            }
        }

        return ($evaluated === 0 || $minCount === PHP_INT_MAX) ? 0 : $minCount;
    }

    /**
     * Resolves the longest unbroken streak of consecutive calendar days on which the user
     * attended at least one session.
     *
     * Algorithm (mirrors consecutive_paid_months but per-day instead of per-month):
     *  1. Determine the reference day (upper bound) and optional lower bound from the period type.
     *  2. Fetch all distinct attended days in the window with a single DB query.
     *  3. Walk backwards day-by-day from refDay to lowerBound:
     *       - If the user attended that day → streak++, track maximum.
     *       - If not (gap) → reset streak to 0, but keep scanning earlier days.
     *  4. Return the maximum streak found anywhere in the window.
     *
     * Period types:
     *   PERIOD_TYPE_NONE     → no window; derive lower bound from the user's oldest attended day.
     *   PERIOD_TYPE_DAYS     → rolling window of `period_days` calendar days ending today.
     *   PERIOD_TYPE_DEADLINE → upper = min(deadline, today); lower = null (full history) when flag OFF,
     *                           lower = createdAt when flag ON.
     *   PERIOD_TYPE_WINDOW   → explicit campaign: from period_window_start to period_deadline.
     *
     * Example — target=7 days, window: May 1–20:
     *   May 01-07: attended every day (7) ✅ but then…
     *   May 08: missed → streak resets
     *   May 09-18: attended 10 consecutive days → maxStreak = 10 ✅ earned
     */
    private function resolveConsecutiveDays(User $user, Achievement $achievement): int
    {
        $today = new \DateTimeImmutable('today midnight');

        // ── 1. Determine reference day (upper bound) and lower bound ──────────
        $refDay     = $today;
        $lowerBound = null;

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DEADLINE:
                // Upper bound: min(deadline, today).
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    // Future deadline → cap at today (we cannot verify future attendance).
                    $refDay = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                // Fixed window: periodWindowStart → min(deadline, today). Flag is irrelevant.
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                $start = $achievement->getPeriodWindowStart();
                if ($start !== null) {
                    $lowerBound = new \DateTimeImmutable($start->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_DAYS:
                $days    = max(1, (int) $achievement->getPeriodDays());
                $rolling = $today->modify('-' . ($days - 1) . ' days');
                // flag ON → floor at createdAt; flag OFF → pure rolling window.
                $lowerBound = $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? max($rolling, new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d')))
                    : $rolling;
                break;
            // PERIOD_TYPE_NONE falls through: lowerBound stays null here, handled below.
        }

        // Only for PERIOD_TYPE_NONE: flag ON → floor at createdAt; flag OFF → null (all-time).
        // All other period types have already set their lower bound above.
        if ($achievement->getPeriodType() === Achievement::PERIOD_TYPE_NONE
            && $achievement->isIncludeHistoricalData()
            && $achievement->getCreatedAt() !== null
        ) {
            $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
        }

        // ── 2. Single DB query: all distinct attended days in the window ───────
        $windowEnd    = $refDay->setTime(23, 59, 59);
        $attendedDays = $this->reservationRepository->findAttendedDaysInRange($user, $windowEnd, $lowerBound);

        // lowerBound is null when NONE+flag=OFF or DEADLINE+flag=OFF → all-time scan.
        // Derive a practical lower bound from the oldest attended day returned.
        // findAttendedDaysInRange orders newest-first, so the last element is the oldest.
        if ($lowerBound === null) {
            if ($attendedDays === []) {
                return 0;
            }
            $lowerBound = new \DateTimeImmutable($attendedDays[array_key_last($attendedDays)]);
        }

        if ($attendedDays === []) {
            return 0;
        }

        // Build O(1) lookup set (date string → true).
        $attendedSet = array_fill_keys($attendedDays, true);

        // ── 3. Walk backwards day-by-day, tracking the best streak ────────────
        $streak    = 0;
        $maxStreak = 0;
        $day       = $refDay;

        while ($day >= $lowerBound) {
            if (isset($attendedSet[$day->format('Y-m-d')])) {
                ++$streak;
                if ($streak > $maxStreak) {
                    $maxStreak = $streak;
                }
            } else {
                $streak = 0; // gap: reset, but keep scanning earlier days
            }

            $day = $day->modify('-1 day');
        }

        return $maxStreak;
    }

    /**
     * Resolves the longest unbroken streak of consecutive 7-day blocks in which the user
     * attended at least one session.
     *
     * A "week" is a non-overlapping 7-day block counted backwards from the reference day,
     * following the same convention used by resolveWeeklyFrequency():
     *   Block 0: [refDay − 6 days, refDay]
     *   Block 1: [refDay − 13 days, refDay − 7 days]
     *   Block i: [refDay − (i×7+6) days, refDay − (i×7) days]
     *
     * The algorithm (mirrors resolveConsecutiveDays but per-block):
     *  1. Determine refDay and lowerBound from the period type.
     *  2. Fetch all distinct attended days with a single DB query (reuses findAttendedDaysInRange).
     *  3. Iterate blocks from newest to oldest:
     *       - If at least one day in the block was attended → streak++, track max.
     *       - If no day attended in the block (gap) → reset streak to 0, keep scanning.
     *  4. Return the maximum streak found.
     *
     * Partial last block: when lowerBound falls inside a block, only the days from lowerBound
     * to blockEnd are checked — a single attended day still counts as a valid week.
     *
     * Example — target=4 weeks:
     *   Block 0 (May 14–20): attended → streak=1
     *   Block 1 (May  7–13): attended → streak=2
     *   Block 2 (Apr 30–May 6): missed → streak=0 (reset)
     *   Block 3 (Apr 23–29): attended → streak=1
     *   Block 4 (Apr 16–22): attended → streak=2
     *   Block 5 (Apr  9–15): attended → streak=3
     *   Block 6 (Apr  2–8):  attended → streak=4 ✅ maxStreak=4
     */
    private function resolveConsecutiveWeeks(User $user, Achievement $achievement): int
    {
        $today = new \DateTimeImmutable('today midnight');

        // ── 1. Determine reference day (upper bound) and optional lower bound ──
        $refDay     = $today;
        $lowerBound = null;

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DEADLINE:
                // Upper bound: min(deadline, today).
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                // Fixed window: periodWindowStart → min(deadline, today). Flag is irrelevant.
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                $start = $achievement->getPeriodWindowStart();
                if ($start !== null) {
                    $lowerBound = new \DateTimeImmutable($start->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_DAYS:
                $days    = max(7, (int) $achievement->getPeriodDays());
                $weeks   = (int) round($days / 7);
                $rolling = $refDay->modify('-' . ($weeks * 7 - 1) . ' days');
                // flag ON → floor at createdAt; flag OFF → pure rolling window.
                $lowerBound = $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? max($rolling, new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d')))
                    : $rolling;
                break;
            // PERIOD_TYPE_NONE falls through: lowerBound stays null here, handled below.
        }

        // Only for PERIOD_TYPE_NONE: flag ON → floor at createdAt; flag OFF → null (all-time).
        // All other period types have already set their lower bound above.
        if ($achievement->getPeriodType() === Achievement::PERIOD_TYPE_NONE
            && $achievement->isIncludeHistoricalData()
            && $achievement->getCreatedAt() !== null
        ) {
            $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
        }

        // ── 2. Single DB query: all distinct attended days in the window ───────
        $windowEnd    = $refDay->setTime(23, 59, 59);
        $attendedDays = $this->reservationRepository->findAttendedDaysInRange($user, $windowEnd, $lowerBound);

        // For PERIOD_TYPE_NONE with includeHistoricalData = false: lowerBound is still null,
        // meaning all-time. Derive a practical lower bound from oldest attended day.
        if ($lowerBound === null) {
            if ($attendedDays === []) {
                return 0;
            }
            $lowerBound = new \DateTimeImmutable($attendedDays[array_key_last($attendedDays)]);
        }

        if ($attendedDays === []) {
            return 0;
        }

        // Build O(1) lookup set.
        $attendedSet = array_fill_keys($attendedDays, true);

        // ── 3. Walk backwards block-by-block ─────────────────────────────────
        $streak    = 0;
        $maxStreak = 0;
        $i         = 0;

        while (true) {
            $blockEnd   = $refDay->modify('-' . ($i * 7) . ' days');
            $blockStart = $refDay->modify('-' . ($i * 7 + 6) . ' days');

            // Block is entirely before the lower bound → stop.
            if ($blockEnd < $lowerBound) {
                break;
            }

            // For the partial last block, only scan from lowerBound forward.
            $scanFrom = $blockStart >= $lowerBound ? $blockStart : $lowerBound;

            // Check each day in the block (max 7 iterations).
            $attended = false;
            $day      = $blockEnd;
            while ($day >= $scanFrom) {
                if (isset($attendedSet[$day->format('Y-m-d')])) {
                    $attended = true;
                    break;
                }
                $day = $day->modify('-1 day');
            }

            if ($attended) {
                ++$streak;
                if ($streak > $maxStreak) {
                    $maxStreak = $streak;
                }
            } else {
                $streak = 0; // gap: reset, keep scanning older blocks
            }

            ++$i;
        }

        return $maxStreak;
    }

    /**
     * Returns the longest streak of reservation-days without any no-show, within the
     * achievement's evaluation window.
     *
     * Only days where the user had at least one reservation on a CLOSED session are
     * counted — days with no reservations are completely ignored (neutral).
     * A day is a "breach" when at least one reservation that day was active (not
     * cancelled) and not attended.  A proper cancellation never penalises the streak.
     *
     * The algorithm mirrors resolveConsecutiveDays() but operates over reservation-days
     * instead of calendar-days:
     *  1. Query all reservation-days in the window with a hasNoShow flag (one row per day).
     *  2. Walk chronologically: clean day → streak++; breach day → reset streak to 0.
     *  3. Early-exit: as soon as streak >= target_value the achievement is fulfilled —
     *     return immediately without scanning further.
     *  4. Return maxStreak after the full scan if the target was never reached.
     *
     * Examples (target = 30):
     *  Reservation days: A✅ B✅ … (30 clean days) → early-exit, returns 30 ✅
     *  Reservation days: 25 clean, 1 breach, 10 clean → max = 25, returns 25 ❌
     */
    private function resolveNoShowFreeDays(User $user, Achievement $achievement): int
    {
        $today = new \DateTimeImmutable('today midnight');

        // ── 1. Determine refDay and lowerBound ─────────────────────────────────
        $refDay     = $today;
        $lowerBound = null;

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DEADLINE:
                // Upper bound: min(deadline, today).
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay      = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                // Fixed window: periodWindowStart → min(deadline, today). Flag is irrelevant.
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineDay = new \DateTimeImmutable($deadline->format('Y-m-d'));
                    $refDay      = $deadlineDay <= $today ? $deadlineDay : $today;
                }
                $start = $achievement->getPeriodWindowStart();
                if ($start !== null) {
                    $lowerBound = new \DateTimeImmutable($start->format('Y-m-d'));
                }
                break;

            case Achievement::PERIOD_TYPE_DAYS:
                $days    = max(1, (int) $achievement->getPeriodDays());
                $rolling = $refDay->modify('-' . $days . ' days');
                // flag ON → floor at createdAt; flag OFF → pure rolling window.
                $lowerBound = $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? max($rolling, new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d')))
                    : $rolling;
                break;
            // PERIOD_TYPE_NONE falls through: lowerBound stays null here, handled below.
        }

        // Only for PERIOD_TYPE_NONE: flag ON → floor at createdAt; flag OFF → null (all-time).
        // All other period types have already set their lower bound above.
        if ($achievement->getPeriodType() === Achievement::PERIOD_TYPE_NONE
            && $achievement->isIncludeHistoricalData()
            && $achievement->getCreatedAt() !== null
        ) {
            $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));
        }

        // ── 2. Query all reservation-days with breach flag ─────────────────────
        $windowEnd = $refDay->setTime(23, 59, 59);
        $days      = $this->reservationRepository->findReservationDaysWithNoShowFlag(
            $user,
            $windowEnd,
            $lowerBound,
        );

        if ($days === []) {
            return 0;
        }

        // ── 3. Walk chronologically; early-exit on target ─────────────────────
        $target    = (int) $achievement->getTargetValue();
        $streak    = 0;
        $maxStreak = 0;

        foreach ($days as $row) {
            if ($row['hasNoShow'] === 0) {
                ++$streak;
                if ($streak > $maxStreak) {
                    $maxStreak = $streak;
                }
                // Early-exit: target reached, no need to scan further.
                if ($streak >= $target) {
                    return $streak;
                }
            } else {
                $streak = 0; // breach day — reset streak, keep scanning
            }
        }

        return $maxStreak;
    }

    /**
     * Resolves the multi_discipline_count condition.
     *
     * conditionContext schema (set by the wizard):
     *   disciplineIds       int[]   — exact set of disciplines the user must combine
     *                                 (0 = individual sessions, i.e. type='i')
     *   includeIndividual   bool    — true when 0 is in disciplineIds (derived field, not used directly)
     *   attendancesRequired int     — minimum class count; 0 / absent = no class-count gate
     *   attendancesScope    string  — 'each'  → each discipline needs ≥ attendancesRequired classes
     *                                 'total' → combined total across all disciplines ≥ attendancesRequired
     *
     * targetValue = disciplineIds.length (the wizard always sets threshold == count of selected disciplines).
     * The resolver returns how many disciplines currently qualify; the evaluator checks
     * current >= targetValue, which fires when ALL disciplines meet the criteria.
     *
     * For 'total' scope both constraints (distinct count AND combined total) are encoded as:
     *   min(distinctVisited, floor(totalClasses / required × target))
     * This gives smooth progress (grows toward target) and only reaches target when both conditions hold.
     */
    private function resolveMultiDisciplineCount(User $user, Achievement $achievement): int
    {
        $ctx                = $achievement->getConditionContext() ?? [];
        $disciplineIds      = isset($ctx['disciplineIds']) && is_array($ctx['disciplineIds'])
            ? array_values(array_map('intval', $ctx['disciplineIds']))
            : [];
        $attendancesRequired = isset($ctx['attendancesRequired']) ? max(0, (int) $ctx['attendancesRequired']) : 0;
        $attendancesScope    = isset($ctx['attendancesScope']) && $ctx['attendancesScope'] === 'each' ? 'each' : 'total';

        // disciplineIds = [] means "any discipline" mode — the repo will return counts for all
        // group disciplines and the scope logic below operates on the full set.
        $hasIndividual = in_array(0, $disciplineIds, true);
        $realIds       = array_values(array_filter($disciplineIds, static fn (int $id) => $id > 0));
        $target        = (int) $achievement->getTargetValue();

        if ($target <= 0) {
            return 0;
        }

        // Resolve date window from period type (identical pattern to other resolvers).
        [$from, $until] = $this->resolvePeriodBounds($achievement);

        // Fetch per-discipline counts (group sessions only) — [discId => count].
        $counts = $this->reservationRepository->findAttendedCountsPerDiscipline($user, $realIds, $from, $until);

        // Add individual session count as a virtual discipline under key 0.
        if ($hasIndividual) {
            $indivCount = $this->reservationRepository->countIndividualSessionsAttended($user, $from, $until);
            if ($indivCount > 0) {
                $counts[0] = $indivCount;
            }
        }

        // ── Scope: each ──────────────────────────────────────────────────────────
        if ($attendancesScope === 'each') {
            $minPerDisc = $attendancesRequired > 0 ? $attendancesRequired : 1;
            return count(array_filter($counts, static fn (int $c) => $c >= $minPerDisc));
        }

        // ── Scope: total ─────────────────────────────────────────────────────────
        // Encode two constraints into one value:
        //   distinct_visited >= target  AND  sum(counts) >= attendancesRequired
        // Formula: min(distinct_visited, floor(sum / required × target))
        // — grows proportionally toward target as total classes accumulate
        // — never reaches target unless both conditions hold simultaneously
        $distinctVisited = count($counts);
        $total           = (int) array_sum($counts);

        if ($attendancesRequired <= 0) {
            // No attendance gate: just count distinct disciplines visited.
            return $distinctVisited;
        }

        return (int) min($distinctVisited, (int) floor($total / $attendancesRequired * $target));
    }

    private function resolveDisciplineClasses(User $user, Achievement $achievement): int
    {
        $ctx              = $achievement->getConditionContext() ?? [];
        $disciplineIds    = isset($ctx['disciplineIds']) && is_array($ctx['disciplineIds'])
            ? array_values(array_map('intval', $ctx['disciplineIds']))
            : [];
        $includeIndividual = isset($ctx['includeIndividual']) ? (bool) $ctx['includeIndividual'] : true;

        // Remove any zeroed IDs produced by invalid entries.
        $disciplineIds = array_values(array_filter($disciplineIds, static fn (int $id) => $id > 0));

        if ($disciplineIds === []) {
            // "Any discipline" mode: disciplineIds = [] in conditionContext means no specific discipline
            // is required — return the class count of the user's most-attended discipline (max across all).
            // Only group sessions are considered since individual sessions carry no discipline.
            [$from, $until] = $this->resolvePeriodBounds($achievement);
            $allCounts = $this->reservationRepository->findAttendedCountsPerDiscipline($user, [], $from, $until);

            return $allCounts !== [] ? (int) max($allCounts) : 0;
        }

        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countAttendedByUserAndDisciplines(
                $user,
                $disciplineIds,
                $includeIndividual,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countAttendedByUserAndDisciplines(
                $user,
                $disciplineIds,
                $includeIndividual,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countAttendedByUserAndDisciplines(
                $user,
                $disciplineIds,
                $includeIndividual,
                // Fixed window: lower bound is always periodWindowStart; flag is irrelevant.
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countAttendedByUserAndDisciplines(
                $user,
                $disciplineIds,
                $includeIndividual,
                // NONE: flag ON → floor at createdAt; flag OFF → null (all-time).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Resolves the unique_instructors_count condition: counts distinct instructors
     * whose attended sessions the user has taken, respecting the achievement's period type.
     *
     * PERIOD_TYPE_NONE     → all-time distinct instructors (no date filter).
     * PERIOD_TYPE_DAYS     → rolling window: sessions within the last `period_days` calendar days.
     * PERIOD_TYPE_DEADLINE → from = null (all history up to deadline) when includeHistoricalData = false;
     *                          from = createdAt when includeHistoricalData = true. Upper = min(deadline, now).
     * PERIOD_TYPE_WINDOW   → explicit campaign window: from period_window_start to period_deadline.
     * 
     * Sessions with a null instructor are excluded.
     */
    private function resolveUniqueInstructorsCount(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countDistinctInstructorsByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countDistinctInstructorsByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countDistinctInstructorsByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countDistinctInstructorsByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Resolves the friend_joint_classes condition: counts sessions where the user had
     * at least 2 attended reservations (i.e. they brought a friend to that class).
     *
     * A user can book more than one spot for the same session regardless of package type.
     * Any session where COUNT(attended reservations) >= 2 qualifies as a "class with a friend".
     *
     * Period bounds are applied on session.dateStart, consistent with other attendance queries.
     */
    private function resolveFriendJointClasses(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countSharedSessionsByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countSharedSessionsByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countSharedSessionsByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countSharedSessionsByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Resolves the checkin_morning_count condition: counts attended reservations for sessions
     * that start before 12:00 (morning classes), respecting the achievement's period type.
     *
     * Filters on s.timeStart (a dedicated TIME column) rather than using a SQL TIME() function.
     */
    private function resolveCheckinMorningCount(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countMorningAttendanceByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countMorningAttendanceByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countMorningAttendanceByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countMorningAttendanceByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Resolves the count of attended sessions that fall on a Saturday or Sunday,
     * regardless of the start time. Uses the DAYOFWEEK() DQL function (MySQL values:
     * 1 = Sunday, 7 = Saturday) registered via DoctrineExtensions.
     */
    /**
     * Resolves the count of attended sessions that start at or after 12:00 (vespertino).
     * Uses s.timeStart >= 12:00:00 — same TIME column comparison as resolveCheckinMorningCount
     * but with the inverse boundary (>= instead of <).
     */
    private function resolveCheckinEveningCount(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countEveningAttendanceByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countEveningAttendanceByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countEveningAttendanceByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countEveningAttendanceByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    private function resolveWeekendAttendance(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countWeekendAttendanceByUser(
                $user,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countWeekendAttendanceByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countWeekendAttendanceByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countWeekendAttendanceByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Counts total attended sessions that start at any of the time slots listed in
     * conditionContext["timeSlotIds"].
     *
     * Despite the condition key name (consecutive_same_time_slot), this resolver counts
     * the TOTAL number of sessions attended at the specified slots — not a consecutive streak.
     * The streak interpretation is handled at the product level by the target threshold.
     *
     * conditionContext schema (set by the wizard):
     *   timeSlotIds   string[]  — one or more "HH:MM" strings the user must attend
     *                             (e.g. ["08:00", "19:00"]). The query matches any listed slot.
     *
     * If timeSlotIds is missing, empty, or not an array the achievement is considered
     * misconfigured and 0 is returned so it never unlocks silently.
     */
    private function resolveConsecutiveSameTimeSlot(User $user, Achievement $achievement): int
    {
        $ctx      = $achievement->getConditionContext() ?? [];
        $rawSlots = isset($ctx['timeSlotIds']) && is_array($ctx['timeSlotIds'])
            ? array_values(array_filter($ctx['timeSlotIds'], static fn ($v) => is_string($v) && $v !== ''))
            : [];

        if ([] === $rawSlots) {
            return 0; // Misconfigured — timeSlotIds is required in conditionContext.
        }

        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countTimeSlotAttendanceByUser(
                $user,
                $rawSlots,
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countTimeSlotAttendanceByUser(
                $user,
                $rawSlots,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countTimeSlotAttendanceByUser(
                $user,
                $rawSlots,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ),
            default => $this->reservationRepository->countTimeSlotAttendanceByUser(
                $user,
                $rawSlots,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Returns [$from, $until] date boundaries derived from the achievement's period type.
     *
     * @return array{\DateTimeImmutable|null, \DateTimeInterface|null}
     */
    private function resolvePeriodBounds(Achievement $achievement): array
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => [
                $this->computeDaysLower($achievement),
                new \DateTimeImmutable(),
            ],
            Achievement::PERIOD_TYPE_DEADLINE => [
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ],
            Achievement::PERIOD_TYPE_WINDOW => [
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $this->computeUntil($achievement),
            ],
            default => [
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ], // PERIOD_TYPE_NONE
        };
    }

    /**
     * Lower bound for PERIOD_TYPE_DAYS only (do NOT call for DEADLINE/WINDOW).
     * - includeHistoricalData = true  → max(now − periodDays, midnight(createdAt)): rolling window floored at creation date.
     * - includeHistoricalData = false → now − periodDays: pure rolling window, uncapped by creation date.
     */
    private function computeDaysLower(Achievement $achievement): \DateTimeImmutable
    {
        $rolling = (new \DateTimeImmutable('midnight'))
            ->modify('-' . max(1, (int) $achievement->getPeriodDays()) . ' days');

        if ($achievement->isIncludeHistoricalData()
            && $achievement->getCreatedAt() !== null) {
            $ca = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'));

            return $ca > $rolling ? $ca : $rolling;
        }

        return $rolling;
    }

    /**
     * Upper bound for attendance queries: min(periodDeadline, now).
     * When there is no deadline returns now, ensuring no future sessions are counted.
     */
    private function computeUntil(Achievement $achievement): \DateTimeImmutable
    {
        $now      = new \DateTimeImmutable();
        $deadline = $achievement->getPeriodDeadline();

        if ($deadline !== null) {
            // Normalize to end-of-day so sessions/ratings that occur during the deadline
            // day are included. The DATE column has time 00:00:00 by default.
            $d = (new \DateTimeImmutable($deadline->format('Y-m-d')))->setTime(23, 59, 59);

            return $d < $now ? $d : $now;
        }

        return $now;
    }}