<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add editable color field for instructors on staff_profile, migrate only instructor rows, and cleanup legacy staff color column';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $profileExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName' => 'staff_profile',
                'columnName' => 'color_hex',
            ]
        );

        if (0 === $profileExists) {
            $this->addSql('ALTER TABLE `staff_profile` ADD `color_hex` VARCHAR(7) DEFAULT NULL');
        }

        $staffExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName' => 'staff',
                'columnName' => 'color_hex',
            ]
        );

        if ($staffExists > 0) {
            $this->addSql('UPDATE `staff_profile` sp INNER JOIN `staff` s ON s.id = sp.staff_id INNER JOIN `instructors_disciplines` i ON i.staff_id = s.id SET sp.color_hex = s.color_hex WHERE sp.color_hex IS NULL AND s.color_hex IS NOT NULL');
            $this->addSql('ALTER TABLE `staff` DROP COLUMN `color_hex`');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            'mysql' !== $this->connection->getDatabasePlatform()->getName(),
            'Migration can only be executed safely on mysql.'
        );

        $profileExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName' => 'staff_profile',
                'columnName' => 'color_hex',
            ]
        );

        $staffExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(1)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :tableName
               AND column_name = :columnName',
            [
                'tableName' => 'staff',
                'columnName' => 'color_hex',
            ]
        );

        if (0 === $staffExists) {
            $this->addSql('ALTER TABLE `staff` ADD `color_hex` VARCHAR(7) DEFAULT NULL');
        }

        if ($profileExists > 0) {
            $this->addSql('UPDATE `staff` s INNER JOIN `staff_profile` sp ON sp.staff_id = s.id INNER JOIN `instructors_disciplines` i ON i.staff_id = s.id SET s.color_hex = sp.color_hex WHERE s.color_hex IS NULL AND sp.color_hex IS NOT NULL');
            $this->addSql('ALTER TABLE `staff_profile` DROP COLUMN `color_hex`');
        }
    }
}
