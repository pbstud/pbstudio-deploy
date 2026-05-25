<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\User;
use App\Repository\ReservationRepository;

/**
 * Resolves achievement conditions based on the user's rating activity.
 *
 * Supported key:
 *  - rating_votes_min → count of reservations where the user submitted at least one rating
 *                       (ratingExercise, ratingInstructor, or ratingClassType is not null).
 *
 * No conditionContext fields are required for this condition. The target value and
 * period settings are read directly from the Achievement entity.
 *
 * Period semantics:
 *   PERIOD_TYPE_NONE     → all-time count (no date filter).
 *   PERIOD_TYPE_DAYS     → rolling window: ratings submitted in the last `period_days` calendar days.
 *   PERIOD_TYPE_DEADLINE -> from = null (all history up to deadline) when includeHistoricalData = false;
 *                          from = createdAt when includeHistoricalData = true. Upper bound = min(deadline, now).
 *   PERIOD_TYPE_WINDOW   -> explicit campaign window: from period_window_start to period_deadline. Flag ignored.
 */
final readonly class RatingConditionResolver
{
    /** Condition keys handled by this resolver. */
    public const SUPPORTED_KEYS = [
        'rating_votes_min',
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
     * Returns the current metric value (count of rated reservations) for the given achievement and user.
     */
    public function resolve(User $user, Achievement $achievement): int
    {
        $now = $this->computeUntil($achievement);

        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_DAYS => $this->reservationRepository->countRatedByUser(
                $user,
                $this->computeDaysLower($achievement),
                $now,
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $this->reservationRepository->countRatedByUser(
                $user,
                // flag ON → floor at createdAt; flag OFF → null (full history up to deadline).
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                $now,
            ),
            Achievement::PERIOD_TYPE_WINDOW => $this->reservationRepository->countRatedByUser(
                $user,
                $achievement->getPeriodWindowStart() !== null
                    ? new \DateTimeImmutable($achievement->getPeriodWindowStart()->format('Y-m-d'))
                    : null,
                $now,
            ),
            default => $this->reservationRepository->countRatedByUser(
                $user,
                $achievement->isIncludeHistoricalData() && $achievement->getCreatedAt() !== null
                    ? new \DateTimeImmutable($achievement->getCreatedAt()->format('Y-m-d'))
                    : null,
                new \DateTimeImmutable(),
            ),
        };
    }

    /**
     * Lower bound for PERIOD_TYPE_DAYS: max(now - periodDays, midnight(createdAt)) when includeHistoricalData is false.
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
     * Upper bound: min(periodDeadline, now). Ensures no future ratings are counted.
     */
    private function computeUntil(Achievement $achievement): \DateTimeImmutable
    {
        $now      = new \DateTimeImmutable();
        $deadline = $achievement->getPeriodDeadline();

        if ($deadline !== null) {
            // Normalize to end-of-day so ratings submitted during the deadline
            // day are included. The DATE column has time 00:00:00 by default.
            $d = (new \DateTimeImmutable($deadline->format('Y-m-d')))->setTime(23, 59, 59);

            return $d < $now ? $d : $now;
        }

        return $now;
    }
}
