<?php

declare(strict_types=1);

namespace App\Service\WaitingList;

use App\Entity\Reservation;
use App\Repository\WaitingListRepository;

readonly class WaitingListCancellationWindowService
{
    private const GRACE_WINDOW_SECONDS = 3600;

    public function __construct(
        private WaitingListRepository $waitingListRepository,
    ) {
    }

    /**
     * @return array{is_from_waiting_list: bool, is_within_grace_window: bool, remaining_seconds: int, elapsed_seconds: int}
     */
    public function getStatus(Reservation $reservation): array
    {
        $user = $reservation->getUser();
        $session = $reservation->getSession();

        if ($user === null || $session === null) {
            return [
                'is_from_waiting_list' => false,
                'is_within_grace_window' => false,
                'remaining_seconds' => 0,
                'elapsed_seconds' => 0,
            ];
        }

        $isFromWaitingList = $this->waitingListRepository->hasConsumedEntryForUserAndSession($user, $session);

        if (!$isFromWaitingList) {
            return [
                'is_from_waiting_list' => false,
                'is_within_grace_window' => false,
                'remaining_seconds' => 0,
                'elapsed_seconds' => 0,
            ];
        }

        $createdAt = $reservation->getCreatedAt();
        if (!$createdAt instanceof \DateTimeInterface) {
            return [
                'is_from_waiting_list' => true,
                'is_within_grace_window' => false,
                'remaining_seconds' => 0,
                'elapsed_seconds' => self::GRACE_WINDOW_SECONDS,
            ];
        }

        $elapsedSeconds = max(0, time() - $createdAt->getTimestamp());
        $remainingSeconds = max(0, self::GRACE_WINDOW_SECONDS - $elapsedSeconds);

        return [
            'is_from_waiting_list' => true,
            'is_within_grace_window' => $remainingSeconds > 0,
            'remaining_seconds' => $remainingSeconds,
            'elapsed_seconds' => $elapsedSeconds,
        ];
    }

    public function canCancelWithoutPenalty(Reservation $reservation): bool
    {
        return $this->getStatus($reservation)['is_within_grace_window'];
    }
}
