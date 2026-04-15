<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add detailed branch office fields for dynamic home and branch selector content';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $columns = [
            'zone' => 'ALTER TABLE `branch_office` ADD `zone` VARCHAR(100) DEFAULT NULL',
            'plaza' => 'ALTER TABLE `branch_office` ADD `plaza` VARCHAR(150) DEFAULT NULL',
            'address' => 'ALTER TABLE `branch_office` ADD `address` VARCHAR(255) DEFAULT NULL',
            'phone' => 'ALTER TABLE `branch_office` ADD `phone` VARCHAR(25) DEFAULT NULL',
        ];

        foreach ($columns as $columnName => $sql) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(1)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :tableName
                   AND column_name = :columnName',
                [
                    'tableName' => 'branch_office',
                    'columnName' => $columnName,
                ]
            );

            if (0 === $exists) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $columns = ['phone', 'address', 'plaza', 'zone'];

        foreach ($columns as $columnName) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(1)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :tableName
                   AND column_name = :columnName',
                [
                    'tableName' => 'branch_office',
                    'columnName' => $columnName,
                ]
            );

            if ($exists > 0) {
                $this->addSql(sprintf('ALTER TABLE `branch_office` DROP COLUMN `%s`', $columnName));
            }
        }
    }
}
