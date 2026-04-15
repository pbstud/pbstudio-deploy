<?php

declare(strict_types=1);

namespace App\Util;

final class SeatLayoutMapper
{
    private const MAX_SLOTS = 36;

    /**
     * Normalize disabled seats for a session capacity.
     *
     * Business rule: when a class is created with a capacity lower than the
     * room capacity, inherited disabled seats from the room are ignored to
     * avoid inconsistent availability in the class snapshot.
     *
     * @param array<int|string, int|string>|null $placesNotAvailable
     */
    public static function buildPersistedPlacesNotAvailable(?array $placesNotAvailable, int $sessionCapacity, ?int $roomCapacity = null): ?array
    {
        $capacity = max(0, $sessionCapacity);
        if ($capacity <= 0) {
            return null;
        }

        $parsed = array_values(array_unique(array_filter(
            array_map('intval', $placesNotAvailable ?? []),
            static fn (int $place) => $place >= 1 && $place <= $capacity
        )));

        sort($parsed);

        return $parsed ?: null;
    }

    /**
     * Build sanitized seat=>slot layout for persistence.
     *
     * If input layout is empty/invalid, it returns a sequential layout
     * (1=>1, 2=>2, ...) up to capacity to guarantee a usable snapshot.
     *
     * @param array<int|string, int|string>|null $seatLayout
     * @return array<string, int>|null
     */
    public static function buildPersistedSeatLayout(?array $seatLayout, int $capacity): ?array
    {
        $maxSeat = max(0, $capacity);
        if ($maxSeat <= 0) {
            return null;
        }

        $layout = [];
        $usedSlots = [];

        foreach ($seatLayout ?? [] as $seatNum => $slotNum) {
            $seat = (int) $seatNum;
            $slot = (int) $slotNum;

            if ($seat < 1 || $seat > $maxSeat) {
                continue;
            }

            if ($slot < 1 || $slot > self::MAX_SLOTS || isset($usedSlots[$slot])) {
                continue;
            }

            $layout[(string) $seat] = $slot;
            $usedSlots[$slot] = true;
        }

        if ([] === $layout) {
            $limit = min($maxSeat, self::MAX_SLOTS);

            for ($seat = 1; $seat <= $limit; ++$seat) {
                $layout[(string) $seat] = $seat;
            }

            return $layout;
        }

        ksort($layout, SORT_NUMERIC);

        return $layout;
    }

    /**
     * Build reverse map: slot number => seat number.
     *
     * Only seats in range [1..$maxSeat] are included. Use this to prevent
     * seats from an inherited room layout (higher capacity) from appearing
     * as reservable in a class with a lower capacity.
     *
     * @param array<int|string, int|string>|null $seatLayout
     * @param int $maxSeat  Session capacity (0 = no upper limit)
     * @return array<int, int>
     */
    public static function buildSlotToSeatMap(?array $seatLayout, int $maxSeat = 0): array
    {
        $slotToSeat = [];

        foreach ($seatLayout ?? [] as $seatNum => $slotNum) {
            $seat = (int) $seatNum;
            $slot = (int) $slotNum;

            if ($seat < 1 || $slot < 1 || $slot > self::MAX_SLOTS) {
                continue;
            }

            if ($maxSeat > 0 && $seat > $maxSeat) {
                continue;
            }

            $slotToSeat[$slot] = $seat;
        }

        ksort($slotToSeat);

        return $slotToSeat;
    }

    /**
     * Build display map with sequential fallback when layout is missing or invalid.
     *
     * This keeps frontend seat modals usable even when a session has malformed
     * layout data or inherits an empty map after capacity filtering.
     *
     * @return array<int, int>
     */
    public static function buildDisplaySlotToSeatMap(?array $seatLayout, int $capacity): array
    {
        $maxSeat = max(0, $capacity);
        $normalizedLayout = self::buildPersistedSeatLayout($seatLayout, $maxSeat);

        if (null === $normalizedLayout) {
            return [];
        }

        return self::buildSlotToSeatMap($normalizedLayout, $maxSeat);
    }
}
