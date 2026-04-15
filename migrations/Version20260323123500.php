<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323123500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for ratings report pagination and filtering';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        // Speeds up ratings report aggregation by available/rated reservations grouped by session.
        $this->createIndexIfNotExists(
            'reservation',
            'reservation_available_rated_session_idx',
            '`is_available`, `rated_at`, `session_id`'
        );

        // Speeds up status/date/schedule filtering and ordering in the ratings report.
        $this->createIndexIfNotExists(
            'session',
            'session_status_date_time_idx',
            '`status`, `date_start`, `time_start`'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->dropIndexIfExists('session', 'session_status_date_time_idx');
        $this->dropIndexIfExists('reservation', 'reservation_available_rated_session_idx');
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
}
