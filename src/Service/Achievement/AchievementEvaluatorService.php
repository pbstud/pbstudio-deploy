<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\User;
use App\Event\ChallengeEarnedEvent;
use App\Repository\AchievementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Core achievement evaluation engine.
 *
 * Usage:
 *   $evaluator->evaluateForUser($user, AttendanceConditionResolver::SUPPORTED_KEYS);
 *
 * The service:
 *  1. Loads all active achievements whose conditionKey matches the supplied list.
 *  2. Skips achievements already earned.
 *  3. Resolves the current metric value for each achievement.
 *  4. Awards the achievement and fires a notification if the condition is met.
 *  5. Flushes once at the end (only when at least one achievement was awarded).
 *
 * To add a new resolver (e.g. TransactionConditionResolver), inject it here and
 * add a branch in resolveCurrentValue().
 */
final class AchievementEvaluatorService
{
    public function __construct(
        private readonly AchievementRepository $achievementRepository,
        private readonly AttendanceConditionResolver $attendanceResolver,
        private readonly PurchaseConditionResolver $purchaseResolver,
        private readonly SeniorityConditionResolver $seniorityResolver,
        private readonly RatingConditionResolver $ratingResolver,
        private readonly CommunityConditionResolver $communityResolver,
        private readonly AchievementNotificationService $notificationService,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Evaluates all active achievements whose conditionKey is in $conditionKeys
     * for the given user and awards any that have been newly met.
     *
     * @param string[] $conditionKeys
     */
    public function evaluateForUser(User $user, array $conditionKeys): void
    {
        $achievements = $this->achievementRepository->findActiveByConditionKeys($conditionKeys);
        if ([] === $achievements) {
            return;
        }

        $dirty = false;

        foreach ($achievements as $achievement) {
            // Skip achievements the user has already earned.
            if ($user->hasEarnedAchievement((int) $achievement->getId())) {
                continue;
            }

            // Window/deadline boundary check — skip before hitting the DB.
            // PERIOD_TYPE_WINDOW  : active only when period_window_start ≤ today ≤ period_deadline.
            // PERIOD_TYPE_DEADLINE: active only when today ≤ period_deadline.
            // PERIOD_TYPE_DAYS / PERIOD_TYPE_NONE: no hard boundary, always open.
            //
            // When a window has expired and the user never earned the achievement,
            // clear any stored partial progress so the frontend shows no stale data.
            if (!$this->isWindowOpen($achievement)) {
                $aid = (int) $achievement->getId();
                if ($user->getAchievementProgressValue($aid) != 0) {
                    $user->clearAchievementProgressValue($aid);
                    $dirty = true;
                }
                continue;
            }

            $achievementId = (int) $achievement->getId();

            // ── PERIOD_TYPE_NONE + purchase: threshold check ──────────────────────
            //
            // For all-time purchase metrics (total_amount / total_transactions) with
            // no time window, check whether the all-time total meets the target and
            // award once. Uses a dedicated path (instead of the generic single-earn
            // below) so float amounts are stored with proper precision.
            if ($achievement->getPeriodType() === Achievement::PERIOD_TYPE_NONE) {
                $ck = $achievement->getConditionKey() ?? '';
                if ($this->purchaseResolver->supports($ck)) {
                    $allTimeTotal = (float) $this->purchaseResolver->resolve($user, $achievement);
                    $target       = (float) $achievement->getTargetValue();

                    if ($target <= 0.0) {
                        continue; // guard: misconfigured achievement
                    }

                    if ($allTimeTotal >= $target) {
                        $user->addEarnedAchievement(
                            $achievementId,
                            $achievement->getName() ?? '',
                            $achievement->getBadgeLevel() ?? Achievement::BADGE_LEVEL_BRONZE,
                            $achievement->getBadgeColor() ?? '',
                            $achievement->getCategoryKey() ?? '',
                            currentValue: round($allTimeTotal, 2),
                        );

                        $this->notificationService->notifyUnlocked($user, $achievement, false);
                        $dirty = true;

                        $user->clearAchievementProgressValue($achievementId);
                    } else {
                        // Below threshold: update partial progress for the progress bar.
                        $progressValue = round($allTimeTotal, 2);
                        if ($progressValue != $user->getAchievementProgressValue($achievementId)) {
                            $user->setAchievementProgressValue($achievementId, $progressValue);
                            $dirty = true;
                        }
                    }

                    continue; // fully handled; skip the single-earn path below
                }
            }

            // ── Resolve current metric value ──────────────────────────────────────
            $current = $this->resolveCurrentValue($user, $achievement);

            if (null === $current) {
                // No resolver registered for this key — silent skip
                continue;
            }

            $conditionMet = $this->meetsCondition($current, $achievement);

            if ($conditionMet) {
                $user->addEarnedAchievement(
                    $achievementId,
                    $achievement->getName() ?? '',
                    $achievement->getBadgeLevel() ?? Achievement::BADGE_LEVEL_BRONZE,
                    $achievement->getBadgeColor() ?? '',
                    $achievement->getCategoryKey() ?? '',
                    currentValue: is_int($current) ? $current : round((float) $current, 2),
                );

                // Achievement earned — remove any partial progress entry.
                $user->clearAchievementProgressValue($achievementId);
                $this->notificationService->notifyUnlocked($user, $achievement, false);
                $dirty = true;

                // If this is a challenge (difficulty IS NOT NULL), trigger a second
                // evaluation pass for the challenges_completed meta-condition.
                // No recursion risk: challenges_completed achievements have difficulty = null.
                if ($achievement->getDifficulty() !== null) {
                    $this->dispatcher->dispatch(new ChallengeEarnedEvent($user, $achievement));
                }
            } else {
                // Condition not yet met — persist partial progress for the progress bar.
                $progressValue = is_int($current) ? $current : round((float) $current, 2);

                if ($progressValue != $user->getAchievementProgressValue($achievementId)) {
                    $user->setAchievementProgressValue($achievementId, $progressValue);
                    $dirty = true;
                }
            }
        }

        if ($dirty) {
            $this->em->flush();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the current metric value, or null if no resolver supports the key.
     */
    private function resolveCurrentValue(User $user, Achievement $achievement): int|float|null
    {
        $key = $achievement->getConditionKey() ?? '';

        if ($this->attendanceResolver->supports($key)) {
            return $this->attendanceResolver->resolve($user, $achievement);
        }

        if ($this->purchaseResolver->supports($key)) {
            return $this->purchaseResolver->resolve($user, $achievement);
        }

        if ($this->seniorityResolver->supports($key)) {
            return $this->seniorityResolver->resolve($user, $achievement);
        }

        if ($this->ratingResolver->supports($key)) {
            return $this->ratingResolver->resolve($user, $achievement);
        }

        if ($this->communityResolver->supports($key)) {
            return $this->communityResolver->resolve($user, $achievement);
        }

        // Additional resolvers (CronConditionResolver, …)
        // will be injected and delegated here in subsequent subtasks (SCRUM-288/289).

        return null;
    }

    /**
     * Returns true if today falls within the achievement's active window.
     *
     * PERIOD_TYPE_WINDOW   — open only when period_window_start ≤ today AND today ≤ period_deadline.
     *                        A null period_window_start means "no lower bound" (open from the beginning).
     *                        A null period_deadline means "no upper bound" (open indefinitely).
     * PERIOD_TYPE_DEADLINE — open only when today ≤ period_deadline.
     *                        Null deadline = always open (treat as PERIOD_TYPE_NONE).
     * PERIOD_TYPE_DAYS / PERIOD_TYPE_NONE — no hard boundary: always returns true.
     *
     * Comparisons use calendar-day midnight to avoid time-of-day edge cases
     * (e.g. an event at 23:55 on the last day of the campaign must still count).
     */
    private function isWindowOpen(Achievement $achievement): bool
    {
        $today = new \DateTimeImmutable('today midnight');

        return match ($achievement->getPeriodType()) {
            Achievement::PERIOD_TYPE_WINDOW => $this->checkDateRange(
                $achievement->getPeriodWindowStart(),
                $achievement->getPeriodDeadline(),
                $today,
            ),
            Achievement::PERIOD_TYPE_DEADLINE => $achievement->getPeriodDeadline() === null
                || $today <= new \DateTimeImmutable($achievement->getPeriodDeadline()->format('Y-m-d') . ' 23:59:59'),
            default => true,
        };
    }

    /**
     * Returns true if $today falls within [$from, $until] (both bounds optional).
     */
    private function checkDateRange(
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $until,
        \DateTimeImmutable  $today,
    ): bool {
        if ($from !== null && $today < new \DateTimeImmutable($from->format('Y-m-d'))) {
            return false; // window not yet open
        }

        if ($until !== null && $today > new \DateTimeImmutable($until->format('Y-m-d') . ' 23:59:59')) {
            return false; // window already closed
        }

        return true;
    }

    /**
     * Returns true if $current satisfies the achievement's comparison rule against its target.
     */
    private function meetsCondition(int|float $current, Achievement $achievement): bool
    {
        $target = (float) ($achievement->getTargetValue() ?? '0');

        if ($target <= 0) {
            return false;
        }

        return match ($achievement->getComparisonOperator()) {
            Achievement::COMPARISON_OPERATOR_GT => $current > $target,
            Achievement::COMPARISON_OPERATOR_EQ => $current == $target,
            default                             => $current >= $target, // GTE (default)
        };
    }
}
