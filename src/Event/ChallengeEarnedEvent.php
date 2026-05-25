<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Achievement;
use App\Entity\User;

/**
 * Dispatched by AchievementEvaluatorService immediately after a challenge-type
 * achievement (difficulty IS NOT NULL) is awarded to a user.
 *
 * This event is the trigger for the `challenges_completed` meta-condition:
 * whenever a challenge is earned, the evaluator re-checks whether the user
 * has now reached a `challenges_completed` threshold.
 *
 * NOTE: `challenges_completed` achievements themselves have difficulty = null,
 * so awarding them will never re-dispatch this event (no infinite recursion).
 */
final class ChallengeEarnedEvent
{
    public function __construct(
        private readonly User $user,
        private readonly Achievement $achievement,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAchievement(): Achievement
    {
        return $this->achievement;
    }
}
