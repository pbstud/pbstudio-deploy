<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\User;
use App\Repository\TransactionRepository;

/**
 * Resolves achievement conditions based on the user's long-term activity (antigüedad).
 *
 * All three keys share the same underlying metric — the number of distinct calendar
 * months in which the user had at least one active PAID transaction — and differ only
 * in how that value is presented:
 *
 *  - active_months       → total count of covered months (integer).
 *  - active_years        → floor(covered_months / 12) — full years of activity.
 *  - consolidated_client → same count as active_months; the name reflects the semantic
 *                          meaning ("cliente consolidado"), not a different calculation.
 *
 * IMPORTANT — completed-month semantics:
 *  A calendar month is only credited once it has fully elapsed. The current (in-progress)
 *  month is never counted, even if the user already has a transaction in it.
 *  This is enforced by capping the evaluation window to the last day of the previous
 *  calendar month before the month-coverage loop runs.
 *
 * A transaction "covers" calendar month M when:
 *   createdAt ≤ last_day(M)  AND  (expirationAt ≥ first_day(M)  OR  expirationAt IS NULL)
 *
 * Period-type semantics (identical to PurchaseConditionResolver):
 *   PERIOD_TYPE_NONE     → all-time: windowEnd = end of current month, no lower bound.
 *   PERIOD_TYPE_DEADLINE → windowEnd = min(deadline month, current month);
 *                          lowerBound = createdAt month when includeHistoricalData = true, else no lower bound.
 *   PERIOD_TYPE_WINDOW   → windowEnd = min(deadline month, current month),
 *                          lowerBound = period_window_start month.
 *   PERIOD_TYPE_DAYS     → windowEnd = end of current month,
 *                          lowerBound = floor(period_days / 30) months ago.
 *
 * One DB query per evaluation; the month-coverage check runs in PHP to avoid N+1 queries.
 */
final readonly class SeniorityConditionResolver
{
    /** Condition keys handled by this resolver. */
    public const SUPPORTED_KEYS = [
        'active_months',
        'active_years',
        'consolidated_client',
    ];

    public function __construct(
        private TransactionRepository $transactionRepository,
    ) {
    }

    public function supports(string $conditionKey): bool
    {
        return in_array($conditionKey, self::SUPPORTED_KEYS, true);
    }

    /**
     * Returns the current metric value for the given achievement and user.
     */
    public function resolve(User $user, Achievement $achievement): int
    {
        $months = $this->countCoveredMonths($user, $achievement);

        return match ($achievement->getConditionKey()) {
            'active_years' => (int) floor($months / 12),
            default        => $months, // active_months + consolidated_client
        };
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Counts the total number of calendar months in which the user had at least
     * one active PAID transaction, optionally bounded by the achievement's period_type.
     *
     * Uses the same single-query + PHP-loop strategy as consecutive_paid_months.
     */
    private function countCoveredMonths(User $user, Achievement $achievement): int
    {
        $today = new \DateTimeImmutable('first day of this month midnight');

        // Determine windowEnd (upper bound) and optional lowerBound.
        $windowEnd  = $today->modify('last day of this month')->setTime(23, 59, 59);
        $lowerBound = null;

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DEADLINE:
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineMonth = new \DateTimeImmutable($deadline->format('Y-m-01 00:00:00'));
                    $refMonth      = $deadlineMonth <= $today ? $deadlineMonth : $today;
                    $windowEnd     = $refMonth->modify('last day of this month')->setTime(23, 59, 59);
                }
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineMonth = new \DateTimeImmutable($deadline->format('Y-m-01 00:00:00'));
                    $refMonth      = $deadlineMonth <= $today ? $deadlineMonth : $today;
                    $windowEnd     = $refMonth->modify('last day of this month')->setTime(23, 59, 59);
                }
                $start = $achievement->getPeriodWindowStart();
                if ($start !== null) {
                    $lowerBound = new \DateTimeImmutable($start->format('Y-m-01 00:00:00'));
                }
                break;

            case Achievement::PERIOD_TYPE_DAYS:
                $months  = max(1, (int) floor(max(30, (int) $achievement->getPeriodDays()) / 30));
                $rolling = $today->modify('-' . $months . ' months');
                // flag ON → floor at max(createdAt_month, rolling_lower); flag OFF → pure rolling.
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $ca         = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-01 00:00:00'));
                    $lowerBound = $ca > $rolling ? $ca : $rolling;
                } else {
                    $lowerBound = $rolling;
                }
                break;
        }

        // Enforce completed-month semantics: a calendar month can only be credited once
        // it has fully elapsed. The current month is still in progress and therefore
        // must NOT be counted, even if the user already has a transaction in it.
        // Cap the upper bound to the last day of the most recently completed month.
        $lastCompletedEnd = $today->modify('-1 month')->modify('last day of this month')->setTime(23, 59, 59);
        if ($windowEnd > $lastCompletedEnd) {
            $windowEnd = $lastCompletedEnd;
        }

        // Single DB query: all PAID transactions created on or before windowEnd.
        $rows = $this->transactionRepository->findPaidTransactionsForConsecutiveMonths($user, $windowEnd);

        if ($rows === []) {
            return 0;
        }

        // Pre-parse DateTimeInterface values once.
        $txRanges = [];
        foreach ($rows as $row) {
            $created  = \DateTimeImmutable::createFromInterface($row['createdAt']);
            $expires  = $row['expirationAt'] !== null
                ? \DateTimeImmutable::createFromInterface($row['expirationAt'])
                : null;
            $txRanges[] = [$created, $expires];
        }

        // For PERIOD_TYPE_NONE and PERIOD_TYPE_DEADLINE with flag ON: constrain lower bound to creation month.
        if ($lowerBound === null && $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
            $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-01 00:00:00'));
        }

        // For PERIOD_TYPE_NONE with no explicit lowerBound, derive it from the
        // earliest transaction's month (same strategy as consecutive_paid_months).
        if ($lowerBound === null) {
            $minCreated = min(array_map(static fn (array $r): \DateTimeImmutable => $r[0], $txRanges));
            $lowerBound = new \DateTimeImmutable($minCreated->format('Y-m-01 00:00:00'));
        }

        // Walk every month from lowerBound to windowEnd and count covered ones.
        $month = new \DateTimeImmutable($windowEnd->format('Y-m-01 00:00:00'));
        $count = 0;

        while ($month >= $lowerBound) {
            $firstDay = $month;
            $lastDay  = $month->modify('last day of this month')->setTime(23, 59, 59);

            foreach ($txRanges as [$created, $expires]) {
                if ($created <= $lastDay && ($expires === null || $expires >= $firstDay)) {
                    ++$count;
                    break; // month is covered — no need to check remaining transactions
                }
            }

            $month = $month->modify('-1 month');
        }

        return $count;
    }
}
