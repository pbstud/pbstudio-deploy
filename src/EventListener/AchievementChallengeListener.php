<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\ChallengeEarnedEvent;
use App\Service\Achievement\AchievementEvaluatorService;
use App\Service\Achievement\CommunityConditionResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Triggers achievement evaluation for the `challenges_completed` meta-condition
 * whenever a challenge-type achievement (difficulty IS NOT NULL) is earned.
 *
 * Flow:
 *   AchievementEvaluatorService awards a challenge
 *     → dispatch(ChallengeEarnedEvent)
 *       → this listener → evaluateForUser($user, ['challenges_completed'])
 *
 * No recursion risk: `challenges_completed` achievements have difficulty = null,
 * so awarding them will NOT re-dispatch ChallengeEarnedEvent.
 */
#[AsEventListener]
final readonly class AchievementChallengeListener
{
    public function __construct(
        private AchievementEvaluatorService $achievementEvaluator,
    ) {
    }

    public function __invoke(ChallengeEarnedEvent $event): void
    {
        $this->achievementEvaluator->evaluateForUser(
            $event->getUser(),
            CommunityConditionResolver::SUPPORTED_KEYS,
        );
    }
}
