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
    name: 'app:seed:dashboard:step2-transactions',
    description: 'Genera transacciones seed de 100 usuarios y guarda IDs/ventanas en seed_dashboard/data/transactions.jsonl',
)]
class SeedDashboardStep2TransactionsCommand extends Command
{
    private const DATE_FROM = '2026-01-01';
    private const SEED_MARKER = 'seed_2026';
    private const BATCH_SIZE = 300;
    private const NUM_USERS = 100;

    private const CHARGE_METHODS = ['payment.cash', 'payment.card', 'payment.pos'];

    private const UNLIMITED_PKG = [12, 0, '4999.00', 'g', 30, true];
    private const MID_PKGS = [
        [14, 8, '2499.00', 'g', 30, false],
        [15, 12, '3499.00', 'g', 45, false],
        [16, 16, '3999.00', 'g', 60, false],
    ];
    private const SMALL_PKGS = [
        [13, 5, '1299.00', 'g', 30, false],
        [6, 1, '350.00', 'g', 15, false],
        [17, 4, '2799.00', 'i', 30, false],
        [18, 8, '4999.00', 'i', 30, false],
        [1, 1, '750.00', 'i', 15, false],
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Fecha inicio Y-m-d', self::DATE_FROM)
            ->addOption('users', null, InputOption::VALUE_OPTIONAL, 'Cantidad de usuarios activos', (string) self::NUM_USERS)
            ->addOption('reset-file', null, InputOption::VALUE_NONE, 'Reiniciar archivos de salida de IDs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromRaw = (string) $input->getOption('from');
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw);
        $numUsers = max(1, (int) $input->getOption('users'));

        if (!$from) {
            $io->error('Formato de --from inválido. Usa Y-m-d.');
            return Command::FAILURE;
        }

        $today = new \DateTimeImmutable('today');

        $dir = 'seed_dashboard/data';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $txFile = $dir . '/transactions.jsonl';
        $usersFile = $dir . '/users.json';
        if ($input->getOption('reset-file')) {
            if (file_exists($txFile)) {
                unlink($txFile);
            }
            if (file_exists($usersFile)) {
                unlink($usersFile);
            }
        }

        $io->title('Step 2: Seed de transacciones');

        $users = $this->connection->fetchAllAssociative(
            'SELECT id, branch_office_id FROM user WHERE enabled = 1 ORDER BY RAND() LIMIT ' . $numUsers
        );

        if (empty($users)) {
            $io->error('No se encontraron usuarios activos.');
            return Command::FAILURE;
        }

        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $countUsers = count($users);
        $unlimitedCount = (int) ceil($countUsers * 0.40);
        $midCount = (int) ceil($countUsers * 0.35);

        $transactions = [];
        foreach ($users as $index => $user) {
            $userId = (int) $user['id'];
            $branchId = (int) ($user['branch_office_id'] ?? 1);
            if (!in_array($branchId, [1, 2, 3], true)) {
                $branchId = 1;
            }

            if ($index < $unlimitedCount) {
                $segment = 'unlimited';
            } elseif ($index < $unlimitedCount + $midCount) {
                $segment = 'mid';
            } else {
                $segment = 'small';
            }

            $cursor = $from->modify('+' . rand(0, 10) . ' days');

            while ($cursor <= $today) {
                if ($segment === 'unlimited') {
                    $pkg = self::UNLIMITED_PKG;
                } elseif ($segment === 'mid') {
                    $pkg = self::MID_PKGS[array_rand(self::MID_PKGS)];
                } else {
                    $pkg = self::SMALL_PKGS[array_rand(self::SMALL_PKGS)];
                }

                [$pkgId, $totalClasses, $amount, $type, $daysExpiry, $isUnlimited] = $pkg;
                $expiration = $cursor->modify('+' . $daysExpiry . ' days');
                $dt = $cursor->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', rand(9, 20), rand(0, 59));

                $transactions[] = [
                    'user_id' => $userId,
                    'package_id' => $pkgId,
                    'package_total_classes' => $totalClasses,
                    'package_is_unlimited' => $isUnlimited ? 1 : 0,
                    'package_amount' => $amount,
                    'package_type' => $type,
                    'package_days_expiry' => $daysExpiry,
                    'package_has_restrictions' => 0,
                    'package_restriction_hours' => null,
                    'package_restriction_days' => null,
                    'package_restriction_instructor_ids' => null,
                    'package_restriction_discipline_ids' => null,
                    'package_restriction_branch_ids' => null,
                    'charge_method' => self::CHARGE_METHODS[array_rand(self::CHARGE_METHODS)],
                    'charge_id' => self::SEED_MARKER,
                    'charge_auth_code' => null,
                    'card_name' => null,
                    'card_type' => null,
                    'card_brand' => null,
                    'card_issuer' => null,
                    'card_last4' => null,
                    'is_expired' => 0,
                    'is_frozen' => 0,
                    'frozen_at' => null,
                    'frozen_days_remaining' => null,
                    'frozen_seconds_remaining' => null,
                    'expiration_at' => $expiration->format('Y-m-d H:i:s'),
                    'expired_at' => $expiration->format('Y-m-d H:i:s'),
                    'is_completed' => 0,
                    'status' => 1,
                    'refunded_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'have_sessions_available' => 1,
                    'branch_office_id' => $branchId,
                    'discount' => 0,
                    'package_special_price' => null,
                    'coupon_discount' => null,
                    'coupon_id' => null,
                    'total' => $amount,
                    'created_at' => $dt,
                    'updated_at' => $dt,
                    '_startDate' => $cursor->format('Y-m-d'),
                    '_endDate' => $expiration->format('Y-m-d'),
                    '_remaining' => $isUnlimited ? 999999 : (int) $totalClasses,
                ];

                if (count($transactions) >= self::BATCH_SIZE) {
                    $this->insertBatch($transactions, $txFile);
                    $transactions = [];
                }

                $cursor = $expiration->modify('+' . rand(1, 5) . ' days');
            }
        }

        if (!empty($transactions)) {
            $this->insertBatch($transactions, $txFile);
        }

        $io->success('Transacciones seed insertadas y archivadas en IDs.');
        $io->text('Archivo IDs: ' . $txFile);
        $io->text('Archivo usuarios: ' . $usersFile);
        $io->text('Siguiente: php bin/console app:seed:dashboard:step3-reservations --no-debug');

        return Command::SUCCESS;
    }

    private function insertBatch(array $rows, string $txFile): void
    {
        $cols = [
            'user_id', 'package_id', 'package_total_classes', 'package_is_unlimited',
            'package_amount', 'package_type', 'package_days_expiry', 'package_has_restrictions',
            'package_restriction_hours', 'package_restriction_days',
            'package_restriction_instructor_ids', 'package_restriction_discipline_ids', 'package_restriction_branch_ids',
            'charge_method', 'charge_id', 'charge_auth_code',
            'card_name', 'card_type', 'card_brand', 'card_issuer', 'card_last4',
            'is_expired', 'is_frozen', 'frozen_at', 'frozen_days_remaining', 'frozen_seconds_remaining',
            'expiration_at', 'expired_at', 'is_completed', 'status', 'refunded_at',
            'error_code', 'error_message', 'have_sessions_available', 'branch_office_id',
            'discount', 'package_special_price', 'coupon_discount', 'coupon_id',
            'total', 'created_at', 'updated_at',
        ];

        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            foreach ($cols as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $sql = 'INSERT INTO transaction (' . implode(',', $cols) . ') VALUES ' . implode(',', $placeholders);
        $this->connection->executeStatement($sql, $params);

        $firstId = (int) $this->connection->lastInsertId();
        foreach ($rows as $idx => $row) {
            $id = $firstId + $idx;
            $line = json_encode([
                'id' => $id,
                'userId' => (int) $row['user_id'],
                'startDate' => $row['_startDate'],
                'endDate' => $row['_endDate'],
                'remaining' => (int) $row['_remaining'],
                'isUnlimited' => ((int) $row['package_is_unlimited']) === 1,
            ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
            file_put_contents($txFile, $line, FILE_APPEND);
        }
    }
}
