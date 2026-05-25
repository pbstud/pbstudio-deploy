<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\TransactionSuccessEvent;
use App\Service\Achievement\AchievementEvaluatorService;
use App\Service\Achievement\PurchaseConditionResolver;
use App\Service\Achievement\SeniorityConditionResolver;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Triggers achievement evaluation for purchase-based conditions
 * whenever a transaction is confirmed as paid.
 *
 * Fires on TransactionSuccessEvent, which is dispatched from:
 *  - Backend\TransactionController (admin manual transaction)
 *  - PackageController (front-end package purchase)
 *  - GiftCardController (gift card redemption flow)
 */
#[AsEventListener]
final readonly class AchievementTransactionListener
{
    public function __construct(
        private AchievementEvaluatorService $achievementEvaluator,
    ) {
    }

    public function __invoke(TransactionSuccessEvent $event): void
    {
        if (!$event->getTransaction()->isPaid()) {
            return;
        }

        $this->achievementEvaluator->evaluateForUser(
            $event->getUser(),
            array_merge(
                PurchaseConditionResolver::SUPPORTED_KEYS,
                SeniorityConditionResolver::SUPPORTED_KEYS,
            ),
        );
    }
}
