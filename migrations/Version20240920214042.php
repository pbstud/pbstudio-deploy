<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Transaction expired at field.
 */
final class Version20240920214042 extends AbstractMigration
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

        if (! $this->hasColumn('transaction', 'expired_at')) {
            $this->addSql('ALTER TABLE transaction ADD expired_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        if ($this->hasColumn('transaction', 'expired_at')) {
            $this->addSql('ALTER TABLE transaction DROP expired_at');
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
