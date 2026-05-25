<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\RatingSubmittedEvent;
use App\Service\Achievement\AchievementEvaluatorService;
use App\Service\Achievement\RatingConditionResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Triggers achievement evaluation for rating-based conditions
 * whenever a user submits (or updates) a rating for a class.
 *
 * Fires on RatingSubmittedEvent, dispatched from:
 *  - ProfileController::rateTakenSession()  (front-end rating form)
 */
#[AsEventListener]
final readonly class AchievementRatingListener
{
    public function __construct(
        private AchievementEvaluatorService $achievementEvaluator,
    ) {
    }

    public function __invoke(RatingSubmittedEvent $event): void
    {
        $user = $event->getUser();
        if (null === $user) {
            return;
        }

        $this->achievementEvaluator->evaluateForUser($user, RatingConditionResolver::SUPPORTED_KEYS);
    }
}
