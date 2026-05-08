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
    name: 'app:seed:dashboard:rollback',
    description: 'Rollback de seed dashboard usando archivos de IDs en seed_dashboard/data',
)]
class SeedDashboardRollbackCommand extends Command
{
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

        $files = [
            'reservations' => $dir . '/reservations.jsonl',
            'transactions' => $dir . '/transactions.jsonl',
            'sessions' => $dir . '/sessions.jsonl',
        ];

        $io->title('Rollback seed dashboard por IDs');
        if (!$io->confirm('Esta acción eliminará datos seed. ¿Continuar?', false)) {
            $io->text('Cancelado.');
            return Command::SUCCESS;
        }

        $resIds = $this->loadIds($files['reservations']);
        $txIds = $this->loadIds($files['transactions']);
        $sessionIds = $this->loadIds($files['sessions']);

        $deletedRes = $this->deleteByIds('reservation', $resIds);
        $deletedTx = $this->deleteByIds('transaction', $txIds);
        $deletedSessions = $this->deleteByIds('`session`', $sessionIds);

        $io->definitionList(
            ['Reservas eliminadas' => $deletedRes],
            ['Transacciones eliminadas' => $deletedTx],
            ['Sesiones eliminadas' => $deletedSessions],
        );

        $io->success('Rollback finalizado.');

        return Command::SUCCESS;
    }

    private function loadIds(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $ids = [];
        $h = fopen($file, 'r');
        if ($h === false) {
            return [];
        }
        while (($line = fgets($h)) !== false) {
            $item = json_decode($line, true);
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        fclose($h);

        return array_values(array_unique($ids));
    }

    private function deleteByIds(string $table, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'DELETE FROM ' . $table . ' WHERE id IN (' . $placeholders . ')';
            $deleted += $this->connection->executeStatement($sql, $chunk);
        }

        return $deleted;
    }
}
