<?php

/**
 * Script de validación manual: Cambio de Sesión
 * 
 * Ejecutar como: php bin/console validate:reservation-changes
 * 
 * Valida:
 * 1. Reservaciones que fueron cambiadas (verificar marque changedAt)
 * 2. Auditoría bidireccional de cambios
 * 3. Vigencia de paquetes vs sesión destino
 * 4. Ventana temporal de cambios (2-12h antes)
 */

namespace App\Command;

use App\Entity\Session;
use App\Repository\ReservationRepository;
use App\Repository\SessionAuditRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'validate:reservation-changes',
    description: 'Valida cambios de sesión, fechas y restricciones'
)]
class ValidateReservationChangesCommand extends Command
{
    public function __construct(
        private ReservationRepository $reservationRepository,
        private SessionAuditRepository $sessionAuditRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('🔍 Validación de Cambios de Sesión');

        // ========================================
        $io->section('1️⃣ Reservaciones Marcadas como Cambiadas');
        // ========================================

        $changedReservations = $this->reservationRepository->findBy(['changedAt' => null], ordering: null);
        
        // Modificar para encontrar las que SÍ fueron cambiadas
        $dql = <<<DQL
            SELECT r FROM App\Entity\Reservation r
            WHERE r.changedAt IS NOT NULL
            ORDER BY r.changedAt DESC
        DQL;
        
        $query = $this->em->createQuery($dql);
        $changedReservations = $query->getResult();

        if (empty($changedReservations)) {
            $io->warning('No se encontraron reservaciones cambiadas en la BD');
        } else {
            $io->success(sprintf('✅ Se encontraron %d reservaciones cambiadas', count($changedReservations)));
            
            $table = new Table($output);
            $table->setHeaders(['ID Reserva', 'Usuario', 'Fecha Cambio', 'Sesión Original', 'Asiento Original', 'Estado']);
            
            foreach (array_slice($changedReservations, 0, 10) as $reservation) {
                $auditRecords = $this->sessionAuditRepository->findBy([
                    'reservationId' => $reservation->getId(),
                ]);

                $status = count($auditRecords) >= 2 ? '✅' : '⚠️';
                
                $table->addRow([
                    $reservation->getId(),
                    $reservation->getUser()?->getEmail() ?? 'N/A',
                    $reservation->getChangedAt()?->format('d/m/Y H:i:s') ?? 'NULL',
                    $reservation->getSession()?->getId() ?? 'N/A',
                    $reservation->getPlaceNumber() ?? 'N/A',
                    $status . ' (' . count($auditRecords) . ' registros)',
                ]);
            }
            
            $table->render();
        }

        // ========================================
        $io->section('2️⃣ Validación de Auditoría Bidireccional');
        // ========================================

        $dql = <<<DQL
            SELECT sa FROM App\Entity\SessionAudit sa
            WHERE sa.auditType IN ('user_changed', 'user_changed_from', 'user_changed_to')
            ORDER BY sa.createdAt DESC
        DQL;
        
        $query = $this->em->createQuery($dql);
        $auditRecords = $query->getResult();

        if (empty($auditRecords)) {
            $io->warning('No se encontraron cambios de usuario en la auditoría');
        } else {
            $io->success(sprintf('✅ Se encontraron %d registros de auditoría de cambio', count($auditRecords)));

            // Agrupar por change_flow_id
            $changeFlows = [];
            foreach ($auditRecords as $audit) {
                $flowId = $audit->getChangeFlowId();
                if (!isset($changeFlows[$flowId])) {
                    $changeFlows[$flowId] = [];
                }
                $changeFlows[$flowId][] = $audit;
            }

            $io->writeln(sprintf('📊 Flujos de cambio únicos: %d', count($changeFlows)));

            // Verificar que todos tengan exactamente 2 registros (entrada + salida)
            $inconsistencies = 0;
            foreach ($changeFlows as $flowId => $records) {
                if (count($records) !== 2) {
                    $io->error(sprintf('❌ Flujo %s tiene %d registros (esperados 2)', 
                        substr($flowId, 0, 8), count($records)));
                    $inconsistencies++;
                }
            }

            if ($inconsistencies === 0) {
                $io->success('✅ Todas las auditorías tienen estructura bidireccional (2 registros cada una)');
            } else {
                $io->warning(sprintf('⚠️ Se encontraron %d auditorías inconsistentes', $inconsistencies));
            }

            // Mostrar últimos 5 cambios
            $table = new Table($output);
            $table->setHeaders(['Flow ID', 'Reserva', 'Tipo', 'Sesión', 'Asiento', 'Timestamp']);
            
            foreach (array_slice($auditRecords, 0, 10) as $audit) {
                $table->addRow([
                    substr($audit->getChangeFlowId(), 0, 8),
                    $audit->getReservationId() ?? 'N/A',
                    $audit->getAuditType(),
                    $audit->getSessionId() ?? 'N/A',
                    $audit->getPlaceNumber() ?? 'N/A',
                    $audit->getCreatedAt()?->format('d/m H:i:s') ?? 'N/A',
                ]);
            }
            
            $table->render();
        }

        // ========================================
        $io->section('3️⃣ Validación de Vigencia de Paquete');
        // ========================================

        $dql = <<<DQL
            SELECT r, s, t FROM App\Entity\Reservation r
            JOIN r.session s
            LEFT JOIN r.transaction t
            WHERE r.changedAt IS NOT NULL
            AND t IS NOT NULL
            AND t.expirationAt IS NOT NULL
            ORDER BY r.changedAt DESC
        DQL;
        
        $query = $this->em->createQuery($dql);
        $reservationsWithTransaction = $query->getResult();

        $vigencyIssues = 0;
        foreach ($reservationsWithTransaction as $reservation) {
            $session = $reservation->getSession();
            $transaction = $reservation->getTransaction();
            
            if ($session && $transaction) {
                $sessionDate = $session->getDateStart();
                $expirationDate = $transaction->getExpirationAt();
                
                if ($sessionDate && $expirationDate && $sessionDate > $expirationDate) {
                    $io->error(sprintf(
                        '❌ Reserva %d: Sesión (%s) está DESPUÉS de vencimiento paquete (%s)',
                        $reservation->getId(),
                        $sessionDate->format('d/m/Y'),
                        $expirationDate->format('d/m/Y')
                    ));
                    $vigencyIssues++;
                }
            }
        }

        if ($vigencyIssues === 0) {
            $io->success('✅ Todas las sesiones destino están dentro de vigencia del paquete');
        } else {
            $io->warning(sprintf('⚠️ Se encontraron %d sesiones fuera de vigencia', $vigencyIssues));
        }

        // ========================================
        $io->section('4️⃣ Validación de Ventana Temporal (2-12h antes)');
        // ========================================

        $now = new \DateTimeImmutable();
        $timeFrom = $now->add(new \DateInterval('PT2H'));    // 2 horas después
        $timeTo = $now->add(new \DateInterval('PT12H'));     // 12 horas después

        $dql = <<<DQL
            SELECT r, s FROM App\Entity\Reservation r
            JOIN r.session s
            WHERE r.changedAt IS NOT NULL
            AND s.dateStart >= :from
            AND s.dateStart <= :to
        DQL;
        
        $query = $this->em->createQuery($dql);
        $query->setParameter('from', $timeFrom);
        $query->setParameter('to', $timeTo);
        
        $validWindowChanges = $query->getResult();

        if (empty($validWindowChanges)) {
            $io->info('ℹ️ No hay cambios recientes dentro de la ventana temporal (2-12h)');
        } else {
            $io->success(sprintf('✅ Se encontraron %d cambios dentro de la ventana válida', 
                count($validWindowChanges)));
        }

        // ========================================
        $io->section('5️⃣ Validación de Cambio Único (No Duplicados)');
        // ========================================

        $dql = <<<DQL
            SELECT r, r.id FROM App\Entity\Reservation r
            WHERE r.changedAt IS NOT NULL
            GROUP BY r.id
        DQL;
        
        $query = $this->em->createQuery($dql);
        $changedByUser = $query->getResult();

        // Verificar que cada reservación solo haya sido cambiada una vez
        $multipleChangeIssues = 0;
        foreach ($changedByUser as $reservation) {
            $changeAudits = $this->sessionAuditRepository->findBy([
                'reservationId' => $reservation->getId(),
                'auditType' => 'user_changed_to',
            ]);

            if (count($changeAudits) > 1) {
                $io->error(sprintf(
                    '❌ Reserva %d fue cambiada %d veces (debería ser solo 1)',
                    $reservation->getId(),
                    count($changeAudits)
                ));
                $multipleChangeIssues++;
            }
        }

        if ($multipleChangeIssues === 0) {
            $io->success('✅ Todas las reservaciones fueron cambiadas máximo 1 vez');
        } else {
            $io->warning(sprintf('⚠️ Se encontraron %d reservaciones con múltiples cambios', 
                $multipleChangeIssues));
        }

        // ========================================
        $io->section('📋 Resumen Final');
        // ========================================

        $totalChanged = count($changedReservations);
        $totalAudits = count($auditRecords);
        $vigencyOK = $vigencyIssues === 0;
        $multipleOK = $multipleChangeIssues === 0;

        $status = ($vigencyOK && $multipleOK) ? 'green' : 'yellow';
        $io->block([
            sprintf('Reservaciones cambiat: %d', $totalChanged),
            sprintf('Registros auditoría: %d', $totalAudits),
            sprintf('Vigencia paquete: %s', $vigencyOK ? '✅ OK' : '❌ Issues'),
            sprintf('Cambio único: %s', $multipleOK ? '✅ OK' : '❌ Issues'),
        ], null, 'fg=white;bg=' . $status, ' ');

        return Command::SUCCESS;
    }
}
