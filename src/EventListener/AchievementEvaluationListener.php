<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\AttendanceMarkedEvent;
use App\Service\Achievement\AchievementEvaluatorService;
use App\Service\Achievement\AttendanceConditionResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Evaluates achievement conditions that can be triggered by an attendance event.
 *
 * Listens to AttendanceMarkedEvent (dispatched by ReservationController when a
 * reservation is marked as attended). Delegates immediately to AchievementEvaluatorService.
 *
 * As more resolvers are added (transaction, cron, rating), additional __invoke
 * methods or separate listeners will be added for their respective events.
 */
#[AsEventListener]
final readonly class AchievementEvaluationListener
{
    public function __construct(
        private AchievementEvaluatorService $evaluator,
    ) {
    }

    public function __invoke(AttendanceMarkedEvent $event): void
    {
        $user = $event->getUser();
        if (null === $user) {
            return;
        }

        $this->evaluator->evaluateForUser($user, AttendanceConditionResolver::SUPPORTED_KEYS);
    }
}
