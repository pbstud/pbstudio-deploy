<?php

declare(strict_types=1);

namespace App\Service\Reservation;

use App\Entity\Session;
use App\Entity\Transaction;
use Psr\Log\LoggerInterface;

final class PackageRestrictionEvaluator
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function isApplicable(Transaction $transaction, Session $session): bool
    {
        $transactionId = $transaction->getId();
        $sessionId = $session->getId();

        if (!$transaction->isPackageHasRestrictions()) {
            $this->logger->debug('[PackageRestriction][EvaluatorSkip]', [
                'transaction_id' => $transactionId,
                'session_id' => $sessionId,
                'reason' => 'package_without_restrictions',
            ]);

            return true;
        }

        $hourRestrictions = $this->normalizeHourRestrictions($transaction->getPackageRestrictionHours());
        $days = $this->normalizeValues($transaction->getPackageRestrictionDays(), 0, 6);
        $instructors = $this->normalizeIds($transaction->getPackageRestrictionInstructorIds());
        $disciplines = $this->normalizeIds($transaction->getPackageRestrictionDisciplineIds());
        $branches = $this->normalizeIds($transaction->getPackageRestrictionBranchIds());

        $this->logger->debug('[PackageRestriction][EvaluatorInput]', [
            'transaction_id' => $transactionId,
            'session_id' => $sessionId,
            'normalized' => [
                'hours' => $hourRestrictions,
                'days' => $days,
                'instructors' => $instructors,
                'disciplines' => $disciplines,
                'branches' => $branches,
            ],
            'session' => [
                'start' => $session->getDateTimeStart()?->format('Y-m-d H:i:s'),
                'day_w' => (int) $session->getDateTimeStart()->format('w'),
                'instructor_id' => $session->getInstructor()?->getId(),
                'discipline_id' => $session->getDiscipline()?->getId(),
                'branch_id' => $session->getBranchOffice()?->getId(),
            ],
        ]);

        if (!$this->matchesHourRestriction($session, $hourRestrictions)) {
            $this->logger->debug('[PackageRestriction][EvaluatorRejected]', [
                'transaction_id' => $transactionId,
                'session_id' => $sessionId,
                'reason' => 'hour_window_mismatch',
            ]);

            return false;
        }

        if ($days) {
            $sessionDay = (int) $session->getDateTimeStart()->format('w');
            if (!in_array($sessionDay, $days, true)) {
                $this->logger->debug('[PackageRestriction][EvaluatorRejected]', [
                    'transaction_id' => $transactionId,
                    'session_id' => $sessionId,
                    'reason' => 'day_mismatch',
                    'session_day' => $sessionDay,
                ]);

                return false;
            }
        }

        if ($instructors) {
            $id = $session->getInstructor()?->getId();
            if (!$id || !in_array($id, $instructors, true)) {
                $this->logger->debug('[PackageRestriction][EvaluatorRejected]', [
                    'transaction_id' => $transactionId,
                    'session_id' => $sessionId,
                    'reason' => 'instructor_mismatch',
                    'session_instructor_id' => $id,
                ]);

                return false;
            }
        }

        if ($disciplines) {
            $id = $session->getDiscipline()?->getId();
            if (!$id || !in_array($id, $disciplines, true)) {
                $this->logger->debug('[PackageRestriction][EvaluatorRejected]', [
                    'transaction_id' => $transactionId,
                    'session_id' => $sessionId,
                    'reason' => 'discipline_mismatch',
                    'session_discipline_id' => $id,
                ]);

                return false;
            }
        }

        if ($branches) {
            $id = $session->getBranchOffice()?->getId();
            if (!$id || !in_array($id, $branches, true)) {
                $this->logger->debug('[PackageRestriction][EvaluatorRejected]', [
                    'transaction_id' => $transactionId,
                    'session_id' => $sessionId,
                    'reason' => 'branch_mismatch',
                    'session_branch_id' => $id,
                ]);

                return false;
            }
        }

        $this->logger->debug('[PackageRestriction][EvaluatorAccepted]', [
            'transaction_id' => $transactionId,
            'session_id' => $sessionId,
        ]);

        return true;
    }

    /**
     * @return array<int>
     */
    private function normalizeValues(?array $raw, int $min, int $max): array
    {
        if (!$raw) {
            return [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $intValue = (int) $value;
            if ($intValue < $min || $intValue > $max) {
                continue;
            }
            $values[] = $intValue;
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @return array<int>
     */
    private function normalizeIds(?array $raw): array
    {
        if (!$raw) {
            return [];
        }

        $values = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $intValue = (int) $value;
            if ($intValue <= 0) {
                continue;
            }
            $values[] = $intValue;
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @return array{exactMinutes: array<int>}
     */
    private function normalizeHourRestrictions(?array $raw): array
    {
        $result = [
            'exactMinutes' => [],
        ];

        if (!$raw) {
            return $result;
        }

        foreach ($raw as $value) {
            if (is_string($value) && preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $value)) {
                [$hh, $mm] = explode(':', $value);
                $result['exactMinutes'][] = ((int) $hh * 60) + (int) $mm;

                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $number = (int) $value;
            if ($number >= 0 && $number <= 23) {
                // Snapshot legacy (entero hora): se convierte a HH:00 exacto.
                $result['exactMinutes'][] = $number * 60;

                continue;
            }

            if ($number >= 0 && $number <= 1439) {
                $result['exactMinutes'][] = $number;
            }
        }

        $result['exactMinutes'] = array_values(array_unique($result['exactMinutes']));
        sort($result['exactMinutes']);

        return $result;
    }

    /**
     * @param array{exactMinutes: array<int>} $hourRestrictions
     */
    private function matchesHourRestriction(Session $session, array $hourRestrictions): bool
    {
        if (empty($hourRestrictions['exactMinutes'])) {
            return true;
        }

        $start = $session->getDateTimeStart();
        $minutesOfDay = ((int) $start->format('H')) * 60 + ((int) $start->format('i'));

        foreach ($hourRestrictions['exactMinutes'] as $minuteValue) {
            if ($minutesOfDay === (int) $minuteValue) {
                return true;
            }
        }

        return false;
    }
}
