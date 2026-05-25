<?php

declare(strict_types=1);

namespace App\Service\Achievement;

use App\Entity\Achievement;
use App\Entity\User;
use App\Repository\AchievementRepository;

/**
 * Resolves achievement conditions for the `comunidad` category.
 *
 * Supported key:
 *  - challenges_completed → counts how many challenge-type achievements
 *    (difficulty IS NOT NULL) the user has already earned.
 *
 * Design notes:
 *  - This condition has no period_type: it is always all-time. Challenges are
 *    one-time achievements and their own period/difficulty constraints are baked
 *    into each individual challenge achievement.
 *  - The count is derived from `user.earnedAchievements` (in-memory JSON array on
 *    the User entity) cross-referenced against the `achievement` table to filter
 *    only those with difficulty IS NOT NULL.
 *  - Triggered via ChallengeEarnedEvent, dispatched by AchievementEvaluatorService
 *    immediately after a challenge is awarded.
 */
final readonly class CommunityConditionResolver
{
    /** Condition keys handled by this resolver. */
    public const SUPPORTED_KEYS = [
        'challenges_completed',
    ];

    public function __construct(
        private AchievementRepository $achievementRepository,
    ) {
    }

    public function supports(string $conditionKey): bool
    {
        return in_array($conditionKey, self::SUPPORTED_KEYS, true);
    }

    /**
     * Returns how many challenge-type achievements the user has earned.
     *
     * The $achievement parameter (the meta-achievement being evaluated) is
     * accepted for interface consistency but not used: the period type is
     * always all-time for this condition.
     */
    public function resolve(User $user, Achievement $achievement): int
    {
        $earnedIds = array_column($user->getEarnedAchievements(), 'achievementId');

        if ([] === $earnedIds) {
            return 0;
        }

        return $this->achievementRepository->countEarnedChallenges($earnedIds);
    }
}
