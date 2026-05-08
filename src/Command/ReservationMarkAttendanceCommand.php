<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Session;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:reservations:mark-attended',
    description: 'Marca asistencias en reservas para el mes actual y el anterior con una proporcion objetivo.',
)]
class ReservationMarkAttendanceCommand extends AbstractCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'ratio',
                'r',
                InputOption::VALUE_REQUIRED,
                'Porcentaje objetivo de asistencias (1-100).',
                '80'
            )
            ->addOption(
                'months',
                'm',
                InputOption::VALUE_REQUIRED,
                'Numero de meses hacia atras incluyendo el actual (2 = mes actual + anterior).',
                '2'
            )
            ->addOption(
                'seed',
                null,
                InputOption::VALUE_REQUIRED,
                'Semilla aleatoria para resultados reproducibles.',
                null
            )
            ->addOption(
                'reset',
                null,
                InputOption::VALUE_NONE,
                'Recalcula exactamente el ratio: primero pone attended=0 en elegibles y despues marca el porcentaje.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Aplica cambios. Si no se indica, solo simula.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ratio = (int) $input->getOption('ratio');
        $months = (int) $input->getOption('months');
        $seed = $input->getOption('seed');
        $reset = (bool) $input->getOption('reset');
        $apply = (bool) $input->getOption('apply');

        if ($ratio < 1 || $ratio > 100) {
            $this->msgError('El valor de --ratio debe estar entre 1 y 100.');

            return Command::INVALID;
        }

        if ($months < 1) {
            $this->msgError('El valor de --months debe ser mayor o igual a 1.');

            return Command::INVALID;
        }

        if (null !== $seed && !is_numeric((string) $seed)) {
            $this->msgError('El valor de --seed debe ser numerico.');

            return Command::INVALID;
        }

        if (null !== $seed) {
            mt_srand((int) $seed);
        }

        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
        $rangeStart = $monthStart->modify(sprintf('-%d month', $months - 1));
        $rangeEnd = new \DateTimeImmutable('last day of this month 23:59:59');

        $this->msgInfo(sprintf(
            'Rango: %s a %s',
            $rangeStart->format('Y-m-d'),
            $rangeEnd->format('Y-m-d')
        ));
        $this->msgInfo(sprintf('Objetivo de asistencia: %d%%', $ratio));
        $this->msgInfo(sprintf('Modo: %s', $apply ? 'APLICAR CAMBIOS' : 'SIMULACION (sin cambios)'));

        $connection = $this->em->getConnection();
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
SELECT r.id, r.attended
FROM reservation r
INNER JOIN `session` s ON s.id = r.session_id
WHERE r.is_available = 1
  AND s.status != :statusCancel
  AND s.date_start BETWEEN :dateStart AND :dateEnd
SQL,
            [
                'statusCancel' => Session::STATUS_CANCEL,
                'dateStart' => $rangeStart->format('Y-m-d'),
                'dateEnd' => $rangeEnd->format('Y-m-d'),
            ]
        );

        $total = count($rows);
        if (0 === $total) {
            $this->msgInfo('No hay reservas elegibles en el rango indicado.');

            return Command::SUCCESS;
        }

        $allIds = [];
        $attendedIds = [];
        $notAttendedIds = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $allIds[] = $id;

            $isAttended = in_array((string) $row['attended'], ['1', 'true'], true) || 1 === (int) $row['attended'];
            if ($isAttended) {
                $attendedIds[] = $id;
            } else {
                $notAttendedIds[] = $id;
            }
        }

        $currentAttended = count($attendedIds);
        $targetAttended = (int) round(($total * $ratio) / 100);

        $toSetTrueIds = [];
        $toSetFalseCount = 0;

        if ($reset) {
            $toSetFalseCount = $total;
            $candidateIds = $allIds;
            shuffle($candidateIds);
            $toSetTrueIds = array_slice($candidateIds, 0, $targetAttended);
        } else {
            $needed = $targetAttended - $currentAttended;
            if ($needed > 0) {
                $candidateIds = $notAttendedIds;
                shuffle($candidateIds);
                $toSetTrueIds = array_slice($candidateIds, 0, min($needed, count($candidateIds)));
            }
        }

        $estimatedFinalAttended = $reset
            ? count($toSetTrueIds)
            : min($total, $currentAttended + count($toSetTrueIds));

        $estimatedFinalRatio = round(($estimatedFinalAttended / $total) * 100, 2);

        $this->msgInfo(sprintf('Reservas elegibles: %d', $total));
        $this->msgInfo(sprintf('Asistidas actuales: %d (%.2f%%)', $currentAttended, ($currentAttended / $total) * 100));
        $this->msgInfo(sprintf('Objetivo asistidas: %d (%.2f%%)', $targetAttended, ($targetAttended / $total) * 100));
        if ($reset) {
            $this->msgInfo(sprintf('Se reiniciaran attended=0 en: %d reservas elegibles', $toSetFalseCount));
        }
        $this->msgInfo(sprintf('Reservas a marcar attended=1: %d', count($toSetTrueIds)));
        $this->msgInfo(sprintf('Resultado estimado final: %d asistidas (%.2f%%)', $estimatedFinalAttended, $estimatedFinalRatio));

        if (!$apply) {
            $this->msg('Simulacion finalizada. Para aplicar usa --apply.');

            return Command::SUCCESS;
        }

        $connection->beginTransaction();
        try {
            if ($reset) {
                $connection->executeStatement(
                    <<<'SQL'
UPDATE reservation r
INNER JOIN `session` s ON s.id = r.session_id
SET r.attended = 0
WHERE r.is_available = 1
  AND s.status != :statusCancel
  AND s.date_start BETWEEN :dateStart AND :dateEnd
SQL,
                    [
                        'statusCancel' => Session::STATUS_CANCEL,
                        'dateStart' => $rangeStart->format('Y-m-d'),
                        'dateEnd' => $rangeEnd->format('Y-m-d'),
                    ]
                );
            }

            foreach (array_chunk($toSetTrueIds, 500) as $idsChunk) {
                $connection->executeStatement(
                    'UPDATE reservation SET attended = 1 WHERE id IN (:ids)',
                    ['ids' => $idsChunk],
                    ['ids' => ArrayParameterType::INTEGER]
                );
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->msgError(sprintf('Error al actualizar asistencias: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $this->msgInfo('Cambios aplicados correctamente.');

        return Command::SUCCESS;
    }
}
