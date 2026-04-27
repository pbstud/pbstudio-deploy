<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add package restrictions fields and transaction snapshot fields; set transaction.package_id FK to ON DELETE SET NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE `package`
                ADD `has_restrictions` TINYINT(1) NOT NULL DEFAULT 0,
                ADD `restriction_hours` JSON DEFAULT NULL,
                ADD `restriction_days` JSON DEFAULT NULL,
                ADD `restriction_instructor_ids` JSON DEFAULT NULL,
                ADD `restriction_discipline_ids` JSON DEFAULT NULL,
                ADD `restriction_branch_ids` JSON DEFAULT NULL'
        );

        $this->addSql(
            'ALTER TABLE `transaction`
                ADD `package_has_restrictions` TINYINT(1) NOT NULL DEFAULT 0,
                ADD `package_restriction_hours` JSON DEFAULT NULL,
                ADD `package_restriction_days` JSON DEFAULT NULL,
                ADD `package_restriction_instructor_ids` JSON DEFAULT NULL,
                ADD `package_restriction_discipline_ids` JSON DEFAULT NULL,
                ADD `package_restriction_branch_ids` JSON DEFAULT NULL'
        );

        $this->addSql('CREATE INDEX idx_package_has_restrictions ON `package` (`has_restrictions`)');
        $this->addSql('CREATE INDEX idx_transaction_package_has_restrictions ON `transaction` (`package_has_restrictions`)');

        $this->addSql('ALTER TABLE `transaction` DROP FOREIGN KEY FK_723705D1F44CABFF');
        $this->addSql('ALTER TABLE `transaction` MODIFY `package_id` INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `transaction` ADD CONSTRAINT FK_723705D1F44CABFF FOREIGN KEY (`package_id`) REFERENCES `package` (`id`) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `transaction` DROP FOREIGN KEY FK_723705D1F44CABFF');
        $this->addSql('ALTER TABLE `transaction` ADD CONSTRAINT FK_723705D1F44CABFF FOREIGN KEY (`package_id`) REFERENCES `package` (`id`)');

        $this->addSql('DROP INDEX idx_transaction_package_has_restrictions ON `transaction`');
        $this->addSql('DROP INDEX idx_package_has_restrictions ON `package`');

        $this->addSql(
            'ALTER TABLE `transaction`
                DROP `package_has_restrictions`,
                DROP `package_restriction_hours`,
                DROP `package_restriction_days`,
                DROP `package_restriction_instructor_ids`,
                DROP `package_restriction_discipline_ids`,
                DROP `package_restriction_branch_ids`'
        );

        $this->addSql(
            'ALTER TABLE `package`
                DROP `has_restrictions`,
                DROP `restriction_hours`,
                DROP `restriction_days`,
                DROP `restriction_instructor_ids`,
                DROP `restriction_discipline_ids`,
                DROP `restriction_branch_ids`'
        );
    }
}
