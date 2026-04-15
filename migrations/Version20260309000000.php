<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260309000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squashed migration from #6 onwards: session audit, seat layouts, strategic indexes, and reservation ratings';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS session_audit (
                id INT AUTO_INCREMENT NOT NULL,
                session_id INT NOT NULL,
                admin_user_identifier VARCHAR(255) DEFAULT NULL,
                user_identifier VARCHAR(255) DEFAULT NULL,
                audit_type VARCHAR(50) NOT NULL,
                reason LONGTEXT DEFAULT NULL,
                disabled_places JSON DEFAULT NULL,
                affected_users JSON NOT NULL,
                affected_reservations_count INT DEFAULT 0,
                change_flow_id VARCHAR(36) DEFAULT NULL,
                reservation_id INT DEFAULT NULL,
                from_session_id INT DEFAULT NULL,
                to_session_id INT DEFAULT NULL,
                from_place SMALLINT DEFAULT NULL,
                to_place SMALLINT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_session (session_id),
                INDEX idx_audit_type (audit_type),
                INDEX idx_created (created_at),
                INDEX idx_change_flow (change_flow_id),
                FOREIGN KEY (session_id) REFERENCES session (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addColumnIfNotExists('exercise_room', 'seat_layout', 'JSON DEFAULT NULL');
        $this->addColumnIfNotExists('session', 'seat_layout', 'JSON DEFAULT NULL');

        $this->createIndexIfNotExists('transaction', 'created_at_idx', '`created_at`');
        $this->createIndexIfNotExists('transaction', 'status_charge_created_idx', '`status`, `charge_method`, `created_at`');
        $this->createIndexIfNotExists('transaction', 'user_status_expiration_idx', '`user_id`, `status`, `is_expired`, `expiration_at`');
        $this->createIndexIfNotExists('transaction', 'branch_created_at_idx', '`branch_office_id`, `created_at`');

        $this->createIndexIfNotExists('reservation', 'session_available_place_idx', '`session_id`, `is_available`, `place_number`');
        $this->createIndexIfNotExists('reservation', 'user_available_idx', '`user_id`, `is_available`');

        $this->addColumnIfNotExists('reservation', 'rating_exercise', 'SMALLINT DEFAULT NULL');
        $this->addColumnIfNotExists('reservation', 'rating_instructor', 'SMALLINT DEFAULT NULL');
        $this->addColumnIfNotExists('reservation', 'rating_class_type', 'SMALLINT DEFAULT NULL');
        $this->addColumnIfNotExists('reservation', 'rated_at', 'DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->dropColumnIfExists('reservation', 'rating_exercise');
        $this->dropColumnIfExists('reservation', 'rating_instructor');
        $this->dropColumnIfExists('reservation', 'rating_class_type');
        $this->dropColumnIfExists('reservation', 'rated_at');

        $this->dropIndexIfExists('reservation', 'user_available_idx');
        $this->dropIndexIfExists('reservation', 'session_available_place_idx');

        $this->dropIndexIfExists('transaction', 'branch_created_at_idx');
        $this->dropIndexIfExists('transaction', 'user_status_expiration_idx');
        $this->dropIndexIfExists('transaction', 'status_charge_created_idx');
        $this->dropIndexIfExists('transaction', 'created_at_idx');

        $this->dropColumnIfExists('exercise_room', 'seat_layout');
        $this->dropColumnIfExists('session', 'seat_layout');

        $this->addSql('DROP TABLE IF EXISTS session_audit');
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

    private function addColumnIfNotExists(string $tableName, string $columnName, string $definition): void
    {
        if (! $this->hasColumn($tableName, $columnName)) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD `%s` %s', $tableName, $columnName, $definition));
        }
    }

    private function dropColumnIfExists(string $tableName, string $columnName): void
    {
        if ($this->hasColumn($tableName, $columnName)) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP `%s`', $tableName, $columnName));
        }
    }

    private function hasColumn(string $tableName, string $columnName): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName' => $tableName,
                'columnName' => $columnName,
            ]
        ) > 0;
    }
}
