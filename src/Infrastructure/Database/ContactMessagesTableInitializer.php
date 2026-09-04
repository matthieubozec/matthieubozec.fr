<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

final readonly class ContactMessagesTableInitializer
{
    public function __construct(
        private Connection $connection,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function initialize(): void
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if ($schemaManager->tablesExist(['contact_messages'])) {
                $this->logger?->info('Table contact_messages déjà existante, aucune action.');

                return;
            }

            $schema = new Schema();
            $table = $schema->createTable('contact_messages');

            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => true]);
            $table->addColumn('prestation_type', Types::STRING, ['length' => 50, 'notnull' => false]);
            $table->addColumn('budget', Types::STRING, ['length' => 50, 'notnull' => false]);
            $table->addColumn('message', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::DATE_IMMUTABLE, ['notnull' => true]);
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames('id')
                    ->create()
            );

            foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
                $this->connection->executeStatement($sql);
            }

            $this->logger?->info('Table contact_messages créée avec succès.');
        } catch (\Exception $e) {
            $this->logger?->error('Erreur lors de l\'initialisation de la table contact_messages', [
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
