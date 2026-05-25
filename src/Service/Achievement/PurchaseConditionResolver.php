<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\User;
use App\Repository\GiftCardRepository;
use App\Repository\TransactionRepository;

/**
 * Resolves achievement conditions based on the user's purchase history.
 *
 * Currently supported keys:
 *  - total_amount            â†’ sum of `transaction.total` for all PAID transactions of the user,
 *                              regardless of payment method (currency amount spent).
 *  - total_transactions      â†’ count of PAID transactions for the user (number of purchases).
 *  - consecutive_paid_months â†’ length of the unbroken streak of calendar months where the user
 *                              had at least one active PAID transaction covering that month.
 *                              A transaction covers month M when:
 *                                createdAt â‰¤ last_day(M)  AND
 *                                (expirationAt â‰¥ first_day(M) OR expirationAt IS NULL)
 *                              The streak is counted backwards from the reference month; the
 *                              first month with no coverage breaks the chain.
 *  - gift_cards_count        â†’ number of non-cancelled gift cards purchased by the user
 *                              (purchaserUser = user), optionally bounded by the achievement's
 *                              period_type using GiftCard.purchasedAt.
 *  - specific_package_count  â†’ purchases of specific packages identified by ID, read from
 *                              conditionContext["packageIds"] (array of int).
 *                              conditionContext["packagePurchasesScope"]:
 *                                "total" (default) â†’ sum of purchases across all listed package IDs.
 *                                "each"            â†’ min(purchases per package ID); IDs with zero
 *                                                    purchases count as 0, ensuring all packages
 *                                                    must meet the threshold before unlocking.
 */
final readonly class PurchaseConditionResolver
{
    /** Condition keys handled by this resolver. */
    public const SUPPORTED_KEYS = [
        'total_amount',
        'total_transactions',
        'consecutive_paid_months',
        'gift_cards_count',
        'specific_package_count',
    ];

    public function __construct(
        private TransactionRepository $transactionRepository,
        private GiftCardRepository $giftCardRepository,
    ) {
    }

    public function supports(string $conditionKey): bool
    {
        return in_array($conditionKey, self::SUPPORTED_KEYS, true);
    }

    /**
     * Returns the current metric value for the given achievement and user.
     * Applies the achievement's period_type to bound the query window.
     */
    public function resolve(User $user, Achievement $achievement): int|float
    {
        return match ($achievement->getConditionKey()) {
            'total_amount'            => $this->resolveTotalAmount($user, $achievement),
            'total_transactions'      => $this->resolveTotalTransactions($user, $achievement),
            'consecutive_paid_months' => $this->resolveConsecutivePaidMonths($user, $achievement),
            'gift_cards_count'        => $this->resolveGiftCardsCount($user, $achievement),
            'specific_package_count'  => $this->resolveSpecificPackageCount($user, $achievement),
            default                   => 0,
        };
    }

    /**
     * Resolves the total_amount sum respecting the achievement's period type.
     *
     * PERIOD_TYPE_NONE     â†’ all-time sum (no date filter).
     * PERIOD_TYPE_DAYS     â†’ rolling window: only transactions within the last `period_days` calendar days.
     * PERIOD_TYPE_DEADLINE → from = null (all history up to deadline) when includeHistoricalData = false;
     *                        from = createdAt when includeHistoricalData = true. Upper = min(deadline, now) at 23:59:59.
     * PERIOD_TYPE_WINDOW   â†’ explicit campaign window: from period_window_start up to period_deadline at 23:59:59.
     *
     * $until is set to end-of-day because period_deadline is a DATE column (time = 00:00:00).
     * Transactions created any time on the deadline day must be included.
     */
    private function resolveTotalAmount(User $user, Achievement $achievement): float
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->transactionRepository->sumTotalPaidByUser(
                $user,
                $this->computeTransactionDaysLower($achievement),
                $this->computeTransactionUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->transactionRepository->sumTotalPaidByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->transactionRepository->sumTotalPaidByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
            default => $this->transactionRepository->sumTotalPaidByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
        };
    }

    /**
     * Resolves the total_transactions count respecting the achievement's period type.
     * Same period semantics as resolveTotalAmount() but counts rows instead of summing amounts.
     */
    private function resolveTotalTransactions(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->transactionRepository->countPaidTransactionsByUser(
                $user,
                $this->computeTransactionDaysLower($achievement),
                $this->computeTransactionUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->transactionRepository->countPaidTransactionsByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->transactionRepository->countPaidTransactionsByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
            default => $this->transactionRepository->countPaidTransactionsByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
        };
    }

    /**
     * Resolves the gift_cards_count for the user respecting the achievement's period type.
     * Counts non-cancelled gift cards purchased by the user, optionally bounded by purchasedAt.
     *
     * Same period semantics as resolveTotalTransactions() but operates on GiftCard.purchasedAt.
     */
    private function resolveGiftCardsCount(User $user, Achievement $achievement): int
    {
        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->giftCardRepository->countPurchasedByUser(
                $user,
                $this->computeTransactionDaysLower($achievement),
                $this->computeTransactionUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->giftCardRepository->countPurchasedByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->giftCardRepository->countPurchasedByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
            default => $this->giftCardRepository->countPurchasedByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null,
                $this->computeTransactionUntil($achievement),
            ),
        };
    }

    /**
     * Resolves specific_package_count respecting the achievement's period type.
     *
     * Reads conditionContext["package_ids"] (int[]) to identify target packages by ID.
     * Reads conditionContext["mode"] ("total"|"each", default "total") to determine aggregation:
     *
     *   "total" â†’ count all PAID transactions for the user where package.id IN package_ids.
     *   "each"  â†’ count PAID transactions per package_id, return the minimum. Package IDs
     *             with zero matching transactions contribute 0 to the minimum, ensuring every
     *             listed package must reach the threshold before the achievement unlocks.
     */
    private function resolveSpecificPackageCount(User $user, Achievement $achievement): int
    {
        $ctx        = $achievement->getConditionContext() ?? [];
        $packageIds = array_map('intval', (array) ($ctx['packageIds'] ?? []));
        $mode       = isset($ctx['packagePurchasesScope']) && $ctx['packagePurchasesScope'] === 'each' ? 'each' : 'total';

        if ($packageIds === []) {
            return 0;
        }

        // Build date bounds using the same period semantics as resolveTotalTransactions().
        $from  = null;
        $until = $this->computeTransactionUntil($achievement);

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DAYS:
                $from = $this->computeTransactionDaysLower($achievement);
                break;

            case Achievement::PERIOD_TYPE_DEADLINE:
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $from = $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'))
                    : null;
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                $from = $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d 00:00:00'))
                    : null;
                break;

            default:
                if ($achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
                    $from = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'));
                }
                break;
        }

        if ($mode === 'each') {
            $perPackage = $this->transactionRepository->countPaidTransactionsByUserPerPackage(
                $user,
                $packageIds,
                $from,
                $until,
            );

            // IDs absent from the result have 0 purchases â€” fill them in.
            $counts = [];
            foreach ($packageIds as $id) {
                $counts[] = $perPackage[$id] ?? 0;
            }

            return min($counts);
        }

        return $this->transactionRepository->countPaidTransactionsByUserAndPackages(
            $user,
            $packageIds,
            $from,
            $until,
        );
    }

    /**
     * Counts the unbroken streak of calendar months â€” ending at the reference month and
     * going backwards â€” where the user had at least one active PAID transaction.
     *
     * A transaction is "active" in month M when:
     *   createdAt â‰¤ last_day(M)  AND  (expirationAt â‰¥ first_day(M)  OR  expirationAt IS NULL)
     *
     * The reference month and optional lower bound depend on period_type:
     *   PERIOD_TYPE_NONE     â†’ ref = current month, no lower bound
     *   PERIOD_TYPE_DEADLINE → ref = min(deadline month, current month);
     *                          lowerBound = createdAt month when includeHistoricalData = true, else no lower bound.
     *   PERIOD_TYPE_WINDOW   â†’ ref = min(deadline month, current month),
     *                          lower bound = window_start month
     *   PERIOD_TYPE_DAYS     â†’ ref = current month,
     *                          lower bound = floor(period_days / 30) months ago
     *
     * One DB query is issued (all transactions up to ref month end); the consecutive
     * check is done in PHP to avoid N+1 queries.
     */
    private function resolveConsecutivePaidMonths(User $user, Achievement $achievement): int
    {
        $today = new \DateTimeImmutable('first day of this month midnight');

        // Determine reference month (most-recent month of the streak) and optional lower bound.
        $refMonth   = $today;
        $lowerBound = null;

        switch ($achievement->getPeriodType()) {
            case Achievement::PERIOD_TYPE_DEADLINE:
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineMonth = new \DateTimeImmutable($deadline->format('Y-m-01 00:00:00'));
                    // Future months cannot be verified â€” cap to today.
                    $refMonth = $deadlineMonth <= $today ? $deadlineMonth : $today;
                }
                break;

            case Achievement::PERIOD_TYPE_WINDOW:
                $deadline = $achievement->getPeriodDeadline();
                if ($deadline !== null) {
                    $deadlineMonth = new \DateTimeImmutable($deadline->format('Y-m-01 00:00:00'));
                    $refMonth = $deadlineMonth <= $today ? $deadlineMonth : $today;
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

        // Single DB query: all PAID transactions created on or before the last day of refMonth.
        $windowEnd = $refMonth->modify('last day of this month')->setTime(23, 59, 59);
        $rows      = $this->transactionRepository->findPaidTransactionsForConsecutiveMonths($user, $windowEnd);

        // Pre-parse DateTimeInterface values to DateTimeImmutable once (avoids repeated object creation).
        $txRanges = [];
        foreach ($rows as $row) {
            $created = \DateTimeImmutable::createFromInterface($row['createdAt']);
            $expires = $row['expirationAt'] !== null
                ? \DateTimeImmutable::createFromInterface($row['expirationAt'])
                : null; // null = never expires â†’ covers all future months from createdAt

            $txRanges[] = [$created, $expires];
        }
        // For PERIOD_TYPE_NONE and PERIOD_TYPE_DEADLINE with flag ON: constrain lower bound to creation month.
        if ($lowerBound === null && $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null) {
            $lowerBound = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-01 00:00:00'));
        }
        // For PERIOD_TYPE_NONE / DEADLINE+flag=OFF (no explicit lower bound), derive it from the
        // earliest PAID transaction already loaded â€” no extra query needed.
        // This lets the scan cover the user's full purchase history, resetting on gaps
        // and returning the longest consecutive streak found anywhere in that history.
        if ($lowerBound === null && $txRanges !== []) {
            $minCreated = null;
            foreach ($txRanges as [$created, $_]) {
                if ($minCreated === null || $created < $minCreated) {
                    $minCreated = $created;
                }
            }
            // Normalize to first day of that month so the loop boundary is clean.
            $lowerBound = new \DateTimeImmutable($minCreated->format('Y-m-01 00:00:00'));
        }

        // Walk backwards through the window counting the unbroken streak.
        //
        // All period types now have a lower bound:
        //   PERIOD_TYPE_NONE     â†’ first day of the month of the user's oldest PAID transaction.
        //   PERIOD_TYPE_WINDOW / DEADLINE / DAYS â†’ explicit window boundary.
        //
        // A gap resets the running counter to 0 but scanning continues â€” the streak may
        // restart earlier. Return the longest run found anywhere in the window.
        //
        // If the user has no transactions at all, $txRanges is empty â†’ no loop iteration
        // â†’ returns 0.
        $count    = 0;
        $maxCount = 0;
        $month    = $refMonth;

        while ($lowerBound !== null && $month >= $lowerBound) {
            $firstDay = $month;
            $lastDay  = $month->modify('last day of this month')->setTime(23, 59, 59);

            $covered = false;
            foreach ($txRanges as [$created, $expires]) {
                if ($created <= $lastDay && ($expires === null || $expires >= $firstDay)) {
                    $covered = true;
                    break;
                }
            }

            if ($covered) {
                $count++;
                if ($count > $maxCount) {
                    $maxCount = $count;
                }
            } else {
                $count = 0; // gap: reset and keep scanning earlier months
            }

            $month = $month->modify('-1 month');
        }

        return $maxCount;
    }

    /**
     * Lower bound for PERIOD_TYPE_DAYS: max(now - periodDays, midnight(createdAt)) when includeHistoricalData is false.
     */
    private function computeTransactionDaysLower(Achievement $achievement): \DateTimeImmutable
    {
        $rolling = (new \DateTimeImmutable('midnight'))
            ->modify('-' . max(1, (int) $achievement->getPeriodDays()) . ' days');

        if ($achievement->isIncludeHistoricalData()
            && $achievement->getCreatedAt() !== null) {
            $ca = new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d 00:00:00'));

            return $ca > $rolling ? $ca : $rolling;
        }

        return $rolling;
    }

    /**
     * Upper bound for transaction queries: min(periodDeadline at 23:59:59, now + 10 minutes).
     * The +10 min tolerance prevents clock-drift issues with the transaction that just triggered evaluation.
     * When there is no deadline returns now + 10 minutes.
     */
    private function computeTransactionUntil(Achievement $achievement): \DateTimeImmutable
    {
        $cap      = new \DateTimeImmutable('+10 minutes');
        $deadline = $achievement->getPeriodDeadline();

        if ($deadline !== null) {
            $endOfDay = (new \DateTimeImmutable($deadline->format('Y-m-d')))->setTime(23, 59, 59);

            return $endOfDay < $cap ? $endOfDay : $cap;
        }

        return $cap;
    }}