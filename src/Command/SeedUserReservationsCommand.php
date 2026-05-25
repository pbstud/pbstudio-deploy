<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asigna reservas en todas las sesiones abiertas para un usuario concreto.
 * Pensado para pruebas locales del módulo de logros (attended_classes).
 *
 * Uso:
 *   php bin/console app:seed:user-reservations --user-id=14567
 *   php bin/console app:seed:user-reservations --user-id=14567 --dry-run
 */
#[AsCommand(
    name: 'app:seed:user-reservations',
    description: 'Crea reservas en todas las sesiones abiertas para un usuario (solo pruebas locales)',
)]
class SeedUserReservationsCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'ID del usuario al que asignar reservas')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Muestra qué haría sin insertar nada')
            ->addOption('allow-duplicates', null, InputOption::VALUE_NONE, 'Inserta aunque ya exista reserva en la sesión');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = (int) $input->getOption('user-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $allowDuplicates = (bool) $input->getOption('allow-duplicates');

        if ($userId <= 0) {
            $io->error('Debes indicar --user-id con un ID válido.');
            return Command::FAILURE;
        }

        // Verificar que el usuario existe
        $user = $this->connection->fetchAssociative(
            'SELECT id, name, lastname, email FROM user WHERE id = ?',
            [$userId]
        );
        if ($user === false) {
            $io->error(sprintf('No existe ningún usuario con id=%d.', $userId));
            return Command::FAILURE;
        }

        $io->title(sprintf(
            'Seed de reservas para: %s %s (id=%d)',
            $user['name'],
            $user['lastname'],
            $userId
        ));

        // Buscar la transacción más reciente con clases disponibles o ilimitada
        $transaction = $this->connection->fetchAssociative(
            'SELECT id, package_total_classes, package_is_unlimited, expiration_at
             FROM transaction
             WHERE user_id = ?
               AND expiration_at >= NOW()
             ORDER BY expiration_at DESC
             LIMIT 1',
            [$userId]
        );

        if ($transaction === false) {
            $io->error('El usuario no tiene ninguna transacción vigente. Crea un paquete primero.');
            return Command::FAILURE;
        }

        $transactionId = (int) $transaction['id'];
        $io->text(sprintf(
            'Transacción seleccionada: id=%d | clases=%s | vence=%s',
            $transactionId,
            $transaction['package_is_unlimited'] ? 'ilimitadas' : (string) $transaction['package_total_classes'],
            $transaction['expiration_at']
        ));

        // Sesiones abiertas (status=1) donde el usuario NO tiene reserva (salvo --allow-duplicates)
        $sql = $allowDuplicates
            ? 'SELECT s.id, s.date_start, s.available_capacity
               FROM session s
               WHERE s.status = 1
               ORDER BY s.date_start ASC'
            : 'SELECT s.id, s.date_start, s.available_capacity
               FROM session s
               WHERE s.status = 1
                 AND s.id NOT IN (
                     SELECT r.session_id FROM reservation r WHERE r.user_id = ?
                 )
               ORDER BY s.date_start ASC';

        $sessions = $allowDuplicates
            ? $this->connection->fetchAllAssociative($sql)
            : $this->connection->fetchAllAssociative($sql, [$userId]);

        if (empty($sessions)) {
            $io->success('El usuario ya tiene reserva en todas las sesiones abiertas. Nada que hacer.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Sesiones abiertas sin reserva del usuario: %d', count($sessions)));

        if ($dryRun) {
            $io->note('--dry-run activo: no se insertará nada.');
            $io->listing(array_map(
                static fn(array $s) => sprintf('Sesión %d — %s', $s['id'], $s['date_start']),
                array_slice($sessions, 0, 20)
            ));
            if (count($sessions) > 20) {
                $io->text(sprintf('... y %d más', count($sessions) - 20));
            }
            return Command::SUCCESS;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $batch = [];
        $inserted = 0;

        foreach ($sessions as $session) {
            $sessionId = (int) $session['id'];
            $createdAt  = $session['date_start'] ?? $now;

            $batch[] = [
                'user_id'          => $userId,
                'transaction_id'   => $transactionId,
                'session_id'       => $sessionId,
                'is_available'     => 1,
                'place_number'     => 1,
                'cancellation_at'  => null,
                'attended'         => 0,
                'rating_exercise'  => null,
                'rating_instructor'=> null,
                'rating_class_type'=> null,
                'rated_at'         => null,
                'changed_at'       => null,
                'created_at'       => $createdAt,
                'updated_at'       => $now,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->insertBatch($batch);
                $inserted += count($batch);
                $batch = [];
                $io->text(sprintf('  ... %d reservas insertadas', $inserted));
            }
        }

        if (!empty($batch)) {
            $this->insertBatch($batch);
            $inserted += count($batch);
        }

        // Actualizar available_capacity en las sesiones afectadas
        $sessionIds = array_column($sessions, 'id');
        foreach ($sessionIds as $sid) {
            $this->connection->executeStatement(
                'UPDATE session
                 SET available_capacity = GREATEST(0, available_capacity - 1)
                 WHERE id = ?',
                [(int) $sid]
            );
        }

        $io->success(sprintf(
            '✓ %d reservas creadas para el usuario %d (transaction_id=%d). attended=0 en todas.',
            $inserted,
            $userId,
            $transactionId
        ));
        $io->text('Ahora puedes marcar asistencia desde el backend para disparar el logro.');

        return Command::SUCCESS;
    }

    private function insertBatch(array $rows): void
    {
        $cols = [
            'user_id', 'transaction_id', 'session_id',
            'is_available', 'place_number', 'cancellation_at',
            'attended', 'rating_exercise', 'rating_instructor', 'rating_class_type',
            'rated_at', 'changed_at', 'created_at', 'updated_at',
        ];

        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $sql = 'INSERT INTO reservation (' . implode(',', $cols) . ') VALUES ' . implode(',', $placeholders);
        $this->connection->executeStatement($sql, $params);
    }
}
