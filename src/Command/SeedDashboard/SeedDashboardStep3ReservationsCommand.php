<?php

declare(strict_types=1);

namespace App\Command\SeedDashboard;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:dashboard:step3-reservations',
    description: 'Genera reservas y asistencias desde archivos de IDs en seed_dashboard/data',
)]
class SeedDashboardStep3ReservationsCommand extends Command
{
    private const BATCH_SIZE = 500;
    private const FILL_RATE = 0.87;
    private const ATTEND_RATE = 0.88;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dir', null, InputOption::VALUE_OPTIONAL, 'Directorio de archivos seed', 'seed_dashboard/data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = (string) $input->getOption('dir');
        $sessionsFile = $dir . '/sessions.jsonl';
        $txFile = $dir . '/transactions.jsonl';
        $reservationIdsFile = $dir . '/reservations.jsonl';

        if (!file_exists($sessionsFile) || !file_exists($txFile)) {
            $io->error('Faltan archivos de entrada. Ejecuta primero step1 y step2.');
            return Command::FAILURE;
        }

        if (file_exists($reservationIdsFile)) {
            unlink($reservationIdsFile);
        }

        $io->title('Step 3: Seed de reservas y asistencias');

        $txByUser = $this->loadTransactionsByUser($txFile);
        if (empty($txByUser)) {
            $io->error('No se pudieron cargar transacciones desde archivo.');
            return Command::FAILURE;
        }

        $sessionsHandle = fopen($sessionsFile, 'r');
        if ($sessionsHandle === false) {
            $io->error('No se pudo abrir sessions.jsonl');
            return Command::FAILURE;
        }

        $batchRows = [];
        $totalReservations = 0;
        $totalAttended = 0;
        $totalSessionsUsed = 0;

        while (($line = fgets($sessionsHandle)) !== false) {
            $session = json_decode($line, true);
            if (!is_array($session)) {
                continue;
            }

            if ((int) ($session['status'] ?? 1) !== 0) {
                continue;
            }

            $sessionId = (int) $session['id'];
            $sessionDate = (string) $session['date'];
            $capacity = max(1, (int) $session['capacity']);
            $target = min($capacity, max(1, (int) round($capacity * self::FILL_RATE)));

            $candidates = $this->getCandidates($txByUser, $sessionDate);
            if (empty($candidates)) {
                continue;
            }

            shuffle($candidates);
            $selected = array_slice($candidates, 0, $target);
            if (empty($selected)) {
                continue;
            }

            $place = 1;
            foreach ($selected as $candidate) {
                $attended = mt_rand(1, 100) <= (int) round(self::ATTEND_RATE * 100) ? 1 : 0;
                if ($attended === 1) {
                    $totalAttended++;
                }

                $batchRows[] = [
                    'user_id' => $candidate['userId'],
                    'transaction_id' => $candidate['transactionId'],
                    'session_id' => $sessionId,
                    'is_available' => 1,
                    'place_number' => $place,
                    'cancellation_at' => null,
                    'attended' => $attended,
                    'rating_exercise' => null,
                    'rating_instructor' => null,
                    'rating_class_type' => null,
                    'rated_at' => null,
                    'changed_at' => null,
                    'created_at' => $sessionDate . ' 06:30:00',
                    'updated_at' => $sessionDate . ' 06:30:00',
                ];

                $this->consumeTransaction($txByUser, (int) $candidate['userId'], (int) $candidate['txIndex']);

                $place++;
                $totalReservations++;
            }

            $totalSessionsUsed++;

            $availableCapacity = max(0, $capacity - count($selected));
            $this->connection->executeStatement('UPDATE `session` SET available_capacity = ? WHERE id = ?', [
                $availableCapacity,
                $sessionId,
            ]);

            if (count($batchRows) >= self::BATCH_SIZE) {
                $this->insertReservationBatch($batchRows, $reservationIdsFile);
                $batchRows = [];
            }
        }

        fclose($sessionsHandle);

        if (!empty($batchRows)) {
            $this->insertReservationBatch($batchRows, $reservationIdsFile);
        }

        $rate = $totalReservations > 0 ? round(($totalAttended / $totalReservations) * 100, 2) : 0.0;

        $io->success('Reservas seed insertadas.');
        $io->definitionList(
            ['Sesiones con reservas' => $totalSessionsUsed],
            ['Reservas insertadas' => $totalReservations],
            ['Asistencias insertadas' => $totalAttended . ' (' . $rate . '%)'],
            ['Archivo IDs reservas' => $reservationIdsFile],
        );

        $io->text('Siguiente: php bin/console app:benchmark:dashboard --no-debug');

        return Command::SUCCESS;
    }

    private function loadTransactionsByUser(string $txFile): array
    {
        $handle = fopen($txFile, 'r');
        if ($handle === false) {
            return [];
        }

        $result = [];
        while (($line = fgets($handle)) !== false) {
            $item = json_decode($line, true);
            if (!is_array($item)) {
                continue;
            }
            $userId = (int) ($item['userId'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            if (!isset($result[$userId])) {
                $result[$userId] = [];
            }
            $result[$userId][] = [
                'transactionId' => (int) ($item['id'] ?? 0),
                'startDate' => (string) ($item['startDate'] ?? ''),
                'endDate' => (string) ($item['endDate'] ?? ''),
                'remaining' => (int) ($item['remaining'] ?? 0),
                'isUnlimited' => (bool) ($item['isUnlimited'] ?? false),
            ];
        }

        fclose($handle);
        return $result;
    }

    private function getCandidates(array $txByUser, string $sessionDate): array
    {
        $candidates = [];
        foreach ($txByUser as $userId => $txList) {
            foreach ($txList as $idx => $tx) {
                if ($tx['transactionId'] <= 0) {
                    continue;
                }
                if ($tx['startDate'] <= $sessionDate && $tx['endDate'] >= $sessionDate) {
                    if ($tx['isUnlimited'] || $tx['remaining'] > 0) {
                        $candidates[] = [
                            'userId' => (int) $userId,
                            'transactionId' => (int) $tx['transactionId'],
                            'txIndex' => (int) $idx,
                        ];
                        break;
                    }
                }
            }
        }
        return $candidates;
    }

    private function consumeTransaction(array &$txByUser, int $userId, int $txIndex): void
    {
        if (!isset($txByUser[$userId][$txIndex])) {
            return;
        }
        if ($txByUser[$userId][$txIndex]['isUnlimited']) {
            return;
        }
        if ($txByUser[$userId][$txIndex]['remaining'] > 0) {
            $txByUser[$userId][$txIndex]['remaining']--;
        }
    }

    private function insertReservationBatch(array $rows, string $reservationIdsFile): void
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

        $firstId = (int) $this->connection->lastInsertId();
        foreach ($rows as $idx => $row) {
            $id = $firstId + $idx;
            $line = json_encode([
                'id' => $id,
                'sessionId' => (int) $row['session_id'],
                'transactionId' => (int) $row['transaction_id'],
                'userId' => (int) $row['user_id'],
                'attended' => (int) $row['attended'],
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            file_put_contents($reservationIdsFile, $line, FILE_APPEND);
        }
    }
}
