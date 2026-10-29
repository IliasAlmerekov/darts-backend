<?php
/**
 * This file is part of the darts backend.
 *
 * @license Proprietary
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029085410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace game out flags with one out rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game ADD out_rule VARCHAR(255) DEFAULT 'singleout' NOT NULL");
        $this->addSql("UPDATE game SET out_rule = CASE WHEN double_out = 1 THEN 'doubleout' WHEN triple_out = 1 THEN 'tripleout' ELSE 'singleout' END");
        $this->addSql('ALTER TABLE game DROP double_out, DROP triple_out');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game ADD double_out TINYINT DEFAULT 0 NOT NULL, ADD triple_out TINYINT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE game SET double_out = (out_rule = 'doubleout'), triple_out = (out_rule = 'tripleout')");
        $this->addSql('ALTER TABLE game DROP out_rule');
    }
}
