<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SCRUM-273: persist three anniversary historical fields in user profile for cron-based dashboard reads';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $columns = $this->listUserColumns();

        if (!isset($columns['anniversary_transaction_history'])) {
            $this->addSql('ALTER TABLE `user` ADD `anniversary_transaction_history` JSON DEFAULT NULL');
            $this->addSql('UPDATE `user` SET `anniversary_transaction_history` = JSON_ARRAY() WHERE `anniversary_transaction_history` IS NULL');
            $this->addSql('ALTER TABLE `user` MODIFY `anniversary_transaction_history` JSON NOT NULL');
        }

        if (!isset($columns['anniversary_class_history'])) {
            $this->addSql('ALTER TABLE `user` ADD `anniversary_class_history` JSON DEFAULT NULL');
            $this->addSql('UPDATE `user` SET `anniversary_class_history` = JSON_ARRAY() WHERE `anniversary_class_history` IS NULL');
            $this->addSql('ALTER TABLE `user` MODIFY `anniversary_class_history` JSON NOT NULL');
        }

        if (!isset($columns['anniversary_window_history'])) {
            $this->addSql('ALTER TABLE `user` ADD `anniversary_window_history` JSON DEFAULT NULL');
            $this->addSql('UPDATE `user` SET `anniversary_window_history` = JSON_ARRAY() WHERE `anniversary_window_history` IS NULL');
            $this->addSql('ALTER TABLE `user` MODIFY `anniversary_window_history` JSON NOT NULL');
        }

        if (!$this->indexExists('user', 'idx_user_enabled_anniversary')) {
            $this->addSql('CREATE INDEX `idx_user_enabled_anniversary` ON `user` (`enabled`)');
        }

        // Compatibilidad por si una versión previa de esta migración ya creó columnas obsoletas.
        if (isset($columns['anniversary_snapshot_at'])) {
            $this->addSql('ALTER TABLE `user` DROP COLUMN `anniversary_snapshot_at`');
        }

        if (isset($columns['anniversary_class_years'])) {
            $this->addSql('ALTER TABLE `user` DROP COLUMN `anniversary_class_years`');
        }

        if (isset($columns['anniversary_transaction_years'])) {
            $this->addSql('ALTER TABLE `user` DROP COLUMN `anniversary_transaction_years`');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        if ($this->indexExists('user', 'idx_user_enabled_anniversary')) {
            $this->addSql('DROP INDEX `idx_user_enabled_anniversary` ON `user`');
        }

        $columns = $this->listUserColumns();

        if (isset($columns['anniversary_window_history'])) {
            $this->addSql('ALTER TABLE `user` DROP COLUMN `anniversary_window_history`');
        }

        if (isset($columns['anniversary_class_history'])) {
            $this->addSql('ALTER TABLE `user` DROP COLUMN `anniversary_class_history`');
        }

        if (isset($columns['anniversary_transaction_history'])) {
            $this->addSql('ALTER TABLE `user` DROP COLUMN `anniversary_transaction_history`');
        }

        // No recreamos columnas obsoletas en down.
    }

    /**
     * @return array<string, mixed>
     */
    private function listUserColumns(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT COLUMN_NAME
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName',
            ['tableName' => 'user']
        );

        $columns = [];
        foreach ($rows as $row) {
            $columnName = (string) ($row['COLUMN_NAME'] ?? '');
            if ('' !== $columnName) {
                $columns[$columnName] = true;
            }
        }

        return $columns;
    }

    private function indexExists(string $tableName, string $indexName): bool
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

        return $exists > 0;
    }
}
