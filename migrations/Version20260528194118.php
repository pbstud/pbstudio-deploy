<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SCRUM: Add image field to discipline table.
 */
final class Version20260528194118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image VARCHAR(150) to discipline table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on MySQL.'
        );

        if (!$this->columnExists('discipline', 'image')) {
            $this->addSql('ALTER TABLE discipline ADD image VARCHAR(150) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on MySQL.'
        );

        if ($this->columnExists('discipline', 'image')) {
            $this->addSql('ALTER TABLE discipline DROP image');
        }
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName'  => $tableName,
                'columnName' => $columnName,
            ]
        );
    }
}