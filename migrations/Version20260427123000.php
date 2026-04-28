<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add package restrictions fields, discount date range fields, transaction snapshot fields, and set transaction.package_id FK to ON DELETE SET NULL';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->addColumnIfNotExists('package', '`has_restrictions` TINYINT(1) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists('package', '`restriction_hours` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('package', '`restriction_days` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('package', '`restriction_instructor_ids` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('package', '`restriction_discipline_ids` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('package', '`restriction_branch_ids` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('package', '`special_price_date_start` DATE DEFAULT NULL');
        $this->addColumnIfNotExists('package', '`special_price_date_end` DATE DEFAULT NULL');

        $this->addColumnIfNotExists('transaction', '`package_has_restrictions` TINYINT(1) NOT NULL DEFAULT 0');
        $this->addColumnIfNotExists('transaction', '`package_restriction_hours` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('transaction', '`package_restriction_days` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('transaction', '`package_restriction_instructor_ids` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('transaction', '`package_restriction_discipline_ids` JSON DEFAULT NULL');
        $this->addColumnIfNotExists('transaction', '`package_restriction_branch_ids` JSON DEFAULT NULL');

        $this->createIndexIfNotExists('package', 'idx_package_has_restrictions', '`has_restrictions`');
        $this->createIndexIfNotExists('transaction', 'idx_transaction_package_has_restrictions', '`package_has_restrictions`');

        $this->dropForeignKeyIfExists('transaction', 'FK_723705D1F44CABFF');
        $this->addSql('ALTER TABLE `transaction` MODIFY `package_id` INT DEFAULT NULL');
        $this->addForeignKeyIfNotExists('transaction', 'FK_723705D1F44CABFF', 'ALTER TABLE `transaction` ADD CONSTRAINT FK_723705D1F44CABFF FOREIGN KEY (`package_id`) REFERENCES `package` (`id`) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->dropForeignKeyIfExists('transaction', 'FK_723705D1F44CABFF');
        $this->addForeignKeyIfNotExists('transaction', 'FK_723705D1F44CABFF', 'ALTER TABLE `transaction` ADD CONSTRAINT FK_723705D1F44CABFF FOREIGN KEY (`package_id`) REFERENCES `package` (`id`)');

        $this->dropIndexIfExists('transaction', 'idx_transaction_package_has_restrictions');
        $this->dropIndexIfExists('package', 'idx_package_has_restrictions');

        $this->dropColumnIfExists('transaction', 'package_has_restrictions');
        $this->dropColumnIfExists('transaction', 'package_restriction_hours');
        $this->dropColumnIfExists('transaction', 'package_restriction_days');
        $this->dropColumnIfExists('transaction', 'package_restriction_instructor_ids');
        $this->dropColumnIfExists('transaction', 'package_restriction_discipline_ids');
        $this->dropColumnIfExists('transaction', 'package_restriction_branch_ids');

        $this->dropColumnIfExists('package', 'special_price_date_start');
        $this->dropColumnIfExists('package', 'special_price_date_end');
        $this->dropColumnIfExists('package', 'has_restrictions');
        $this->dropColumnIfExists('package', 'restriction_hours');
        $this->dropColumnIfExists('package', 'restriction_days');
        $this->dropColumnIfExists('package', 'restriction_instructor_ids');
        $this->dropColumnIfExists('package', 'restriction_discipline_ids');
        $this->dropColumnIfExists('package', 'restriction_branch_ids');
    }

    private function addColumnIfNotExists(string $tableName, string $columnDefinition): void
    {
        if (preg_match('/`([^`]+)`/', $columnDefinition, $matches) !== 1) {
            throw new \RuntimeException(sprintf('No se pudo extraer nombre de columna desde definición: %s', $columnDefinition));
        }

        $columnName = $matches[1];

        if (!$this->columnExists($tableName, $columnName)) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD %s', $tableName, $columnDefinition));
        }
    }

    private function dropColumnIfExists(string $tableName, string $columnName): void
    {
        if ($this->columnExists($tableName, $columnName)) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP `%s`', $tableName, $columnName));
        }
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName' => $tableName,
                'columnName' => $columnName,
            ]
        );

        return $exists > 0;
    }

    private function createIndexIfNotExists(string $tableName, string $indexName, string $columns): void
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND index_name = :indexName',
            [
                'tableName' => $tableName,
                'indexName' => $indexName,
            ]
        );

        if (0 === $exists) {
            $this->addSql(sprintf('CREATE INDEX `%s` ON `%s` (%s)', $indexName, $tableName, $columns));
        }
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND index_name = :indexName',
            [
                'tableName' => $tableName,
                'indexName' => $indexName,
            ]
        );

        if ($exists > 0) {
            $this->addSql(sprintf('DROP INDEX `%s` ON `%s`', $indexName, $tableName));
        }
    }

    private function foreignKeyExists(string $tableName, string $constraintName): bool
    {
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND constraint_name = :constraintName
               AND constraint_type = "FOREIGN KEY"',
            [
                'tableName' => $tableName,
                'constraintName' => $constraintName,
            ]
        );

        return $exists > 0;
    }

    private function dropForeignKeyIfExists(string $tableName, string $constraintName): void
    {
        if ($this->foreignKeyExists($tableName, $constraintName)) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $constraintName));
        }
    }

    private function addForeignKeyIfNotExists(string $tableName, string $constraintName, string $sql): void
    {
        if (!$this->foreignKeyExists($tableName, $constraintName)) {
            $this->addSql($sql);
        }
    }
}
