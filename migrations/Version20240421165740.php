<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240421165740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->createIndexIfNotExists('transaction', 'charge_method_idx', '`charge_method`');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $this->dropIndexIfExists('transaction', 'charge_method_idx');
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
