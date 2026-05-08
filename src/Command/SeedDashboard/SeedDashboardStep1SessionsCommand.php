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
    name: 'app:seed:dashboard:step1-sessions',
    description: 'Genera sesiones seed por día y guarda IDs en seed_dashboard/data/sessions.jsonl',
)]
class SeedDashboardStep1SessionsCommand extends Command
{
    private const DATE_FROM = '2026-01-01';
    private const SEED_MARKER = 'seed_2026';
    private const BATCH_SIZE = 300;

    private const ROOMS = [
        [1, 10, 'g', 2, 1, ['07:00:00', '10:00:00', '18:00:00']],
        [8, 15, 'g', 5, 1, ['08:00:00', '11:00:00', '19:00:00']],
        [10, 10, 'g', 6, 1, ['09:00:00', '17:00:00']],
        [2, 5, 'i', 2, 1, ['10:00:00', '12:00:00']],
        [5, 9, 'g', 2, 2, ['07:00:00', '08:00:00', '09:00:00', '10:00:00', '18:00:00', '19:00:00']],
    ];

    private const INSTRUCTOR_IDS = [1, 2, 5, 7, 9, 10, 11, 12, 13, 15, 30, 37];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Fecha inicio Y-m-d', self::DATE_FROM)
            ->addOption('reset-file', null, InputOption::VALUE_NONE, 'Reiniciar archivo de salida de IDs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fromRaw = (string) $input->getOption('from');
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw);
        if (!$from) {
            $io->error('Formato de --from inválido. Usa Y-m-d.');
            return Command::FAILURE;
        }

        $today = new \DateTimeImmutable('today');
        $dir = 'seed_dashboard/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $sessionsFile = $dir . '/sessions.jsonl';

        if ($input->getOption('reset-file') && file_exists($sessionsFile)) {
            unlink($sessionsFile);
        }

        $io->title('Step 1: Seed de sesiones');
        $io->text('Rango: ' . $from->format('Y-m-d') . ' → ' . $today->format('Y-m-d'));

        $cols = [
            'date_start', 'time_start', 'exercise_room_id', 'exercise_room_capacity',
            'available_capacity', 'type', 'discipline_id', 'instructor_id', 'status',
            'branch_office_id', 'information', 'places_not_available', 'seat_layout',
            'created_at', 'updated_at',
        ];

        $batch = [];
        $totalInserted = 0;
        $cursor = $from;

        while ($cursor <= $today) {
            $dateStr = $cursor->format('Y-m-d');
            $status = $cursor < $today ? 0 : 1;

            foreach (self::ROOMS as [$roomId, $capacity, $type, $disciplineId, $branchId, $times]) {
                foreach ($times as $time) {
                    $batch[] = [
                        'date_start' => $dateStr,
                        'time_start' => $time,
                        'exercise_room_id' => $roomId,
                        'exercise_room_capacity' => $capacity,
                        'available_capacity' => $capacity,
                        'type' => $type,
                        'discipline_id' => $disciplineId,
                        'instructor_id' => self::INSTRUCTOR_IDS[array_rand(self::INSTRUCTOR_IDS)],
                        'status' => $status,
                        'branch_office_id' => $branchId,
                        'information' => self::SEED_MARKER,
                        'places_not_available' => null,
                        'seat_layout' => null,
                        'created_at' => $dateStr . ' 06:00:00',
                        'updated_at' => $dateStr . ' 06:00:00',
                    ];

                    if (count($batch) >= self::BATCH_SIZE) {
                        $inserted = $this->insertBatch($batch, $cols, $sessionsFile);
                        $totalInserted += $inserted;
                        $batch = [];
                    }
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        if (!empty($batch)) {
            $totalInserted += $this->insertBatch($batch, $cols, $sessionsFile);
        }

        file_put_contents($dir . '/meta.json', json_encode([
            'marker' => self::SEED_MARKER,
            'dateFrom' => $from->format('Y-m-d'),
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'sessionsInserted' => $totalInserted,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $io->success("Sesiones insertadas: {$totalInserted}");
        $io->text('IDs guardados en: ' . $sessionsFile);
        $io->text('Siguiente: php bin/console app:seed:dashboard:step2-transactions --no-debug');

        return Command::SUCCESS;
    }

    private function insertBatch(array $rows, array $cols, string $sessionsFile): int
    {
        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $sql = 'INSERT INTO `session` (' . implode(',', $cols) . ') VALUES ' . implode(',', $placeholders);
        $this->connection->executeStatement($sql, $params);

        $firstId = (int) $this->connection->lastInsertId();
        foreach ($rows as $idx => $row) {
            $id = $firstId + $idx;
            $line = json_encode([
                'id' => $id,
                'date' => $row['date_start'],
                'time' => $row['time_start'],
                'status' => (int) $row['status'],
                'capacity' => (int) $row['exercise_room_capacity'],
                'branchOfficeId' => (int) $row['branch_office_id'],
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            file_put_contents($sessionsFile, $line, FILE_APPEND);
        }

        return count($rows);
    }
}
