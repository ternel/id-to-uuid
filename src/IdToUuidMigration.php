<?php

/*
 * This file is part of the cap-collectif/id-to-uuid project.
 *
 * (c) Cap Collectif <coucou@cap-collectif.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CapCollectif\IdToUuid;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Id\UuidGenerator;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class IdToUuidMigration extends AbstractMigration implements ContainerAwareInterface
{
    protected $em;
    protected $idToUuidMap = [];
    protected $generator;
    protected $fks;
    protected $extendedFks;
    protected $table;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->em = $container->get('doctrine')->getManager();
        $this->connection = $this->em->getConnection();
        $this->schemaManager = $this->connection->getSchemaManager();
        $this->generator = new UuidGenerator();
    }

    public function up(Schema $schema):void
    {
    }

    public function migrate(string $tableName, string $uuidColumnName = '__uuid__', bool $extendToLevel2 = false)
    {
        $this->write('Migrating ' . $tableName . '.id to UUIDs...');
        $this->prepare($tableName, $extendToLevel2);

        if ($extendToLevel2) {
            //dd($this->fks, $this->extendedFks);
        }
        $this->addUuidFields($uuidColumnName);
        $this->generateUuidsToReplaceIds($uuidColumnName);
        $this->addThoseUuidsToTablesWithFK();
        $this->deletePreviousFKs();
        $this->renameNewFKsToPreviousNames();
        $this->dropIdPrimaryKeyAndSetUuidToPrimaryKey($uuidColumnName);
        $this->restoreConstraintsAndIndexes();
        $this->write('Successfully migrated ' . $tableName . '.id to UUIDs!');
    }

    public function down(Schema $schema):void
    {
    }

    private function isForeignKeyNullable($table, $key)
    {
        foreach ($table->getColumns() as $column) {
            if ($column->getName() === $key) {
                return !$column->getNotnull();
            }
        }
        throw new \Exception('Unable to find ' . $key . 'in ' . $table);
    }

    private function prepare(string $tableName, bool $extendToLevel2)
    {
        $this->table = $tableName;
        $this->fks = [];
        $this->extendedFks = [];
        $this->idToUuidMap = [];

        foreach ($this->schemaManager->listTables() as $table) {
            $foreignKeys = $this->schemaManager->listTableForeignKeys($table->getName());
            foreach ($foreignKeys as $foreignKey) {
                $key = $foreignKey->getColumns()[0];
                if ($foreignKey->getForeignTableName() === $this->table) {
                    $fk = [
                      'table' => $table->getName(),
                      'key' => $key,
                      'tmpKey' => $key . '_to_uuid',
                      'nullable' => $this->isForeignKeyNullable($table, $key),
                      'name' => $foreignKey->getName(),
                      'primaryKey' => $table->getPrimaryKeyColumns(),
                    ];
                    if ($foreignKey->onDelete()) {
                        $fk['onDelete'] = $foreignKey->onDelete();
                    }

                    foreach ($this->schemaManager->listTableDetails($table->getName())->getIndexes() as $index) {
                        if ($index->isUnique() && count($index->getColumns()) > 1 && in_array($key, $index->getColumns())) {
                            $fk['uniqueMultipleKey'] = [
                                'name' => $index->getName(),
                                'columns' => $index->getColumns(),
                            ];
                        }
                    }

                    $this->fks[] = $fk;
                }
            }
        }

        if (count($this->fks) > 0) {
            $this->write('-> Detected the following foreign keys :');
            foreach ($this->fks as $fk) {
                $this->write('  * ' . $fk['table'] . '.' . $fk['key']);

                if ($extendToLevel2) {
                    foreach ($this->schemaManager->listTables() as $table) {
                        $foreignKeys = $this->schemaManager->listTableForeignKeys($table->getName());
                        foreach ($foreignKeys as $foreignKey) {
                            $key = $foreignKey->getColumns()[0];
                            if ($foreignKey->getForeignTableName() === $fk['table']
                                //&& $table->getName() === $fk['table'] . '_translation'
                            ) {
                                $fk = [
                                    'table' => $table->getName(),
                                    'fkTable' => $fk['table'],
                                    'key' => $key,
                                    'tmpKey' => $key . '_to_uuid',
                                    'nullable' => $this->isForeignKeyNullable($table, $key),
                                    'name' => $foreignKey->getName(),
                                    'primaryKey' => $table->getPrimaryKeyColumns(),
                                ];
                                if ($foreignKey->onDelete()) {
                                    $fk['onDelete'] = $foreignKey->onDelete();
                                }

                                foreach ($this->schemaManager->listTableDetails($table->getName())->getIndexes() as $index) {
                                    if ($index->isUnique() && count($index->getColumns()) > 1 && in_array($key, $index->getColumns())) {
                                        $fk['uniqueMultipleKey'] = [
                                            'name' => $index->getName(),
                                            'columns' => $index->getColumns(),
                                        ];
                                    }
                                }

                                $this->extendedFks[] = $fk;
                            }
                        }
                    }
                }
            }

            return;
        }

        $this->write('-> 0 foreign key detected.');
    }

    private function addUuidFields(string $uuidColumnName)
    {
        $this->connection->executeQuery('ALTER TABLE ' . $this->table . " ADD $uuidColumnName CHAR(36) COMMENT '(DC2Type:guid)' FIRST");
        foreach ($this->fks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD ' . $fk['tmpKey'] . ' CHAR(36) COMMENT \'(DC2Type:guid)\'');
        }
        foreach ($this->extendedFks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD ' . $fk['tmpKey'] . ' CHAR(36) COMMENT \'(DC2Type:guid)\'');
        }
    }

    private function generateUuidsToReplaceIds(string $uuidColumnName)
    {
        $fetchs = $this->connection->fetchAll('SELECT id from ' . $this->table);
        if (count($fetchs) > 0) {
            $this->write('-> Generating ' . count($fetchs) . ' UUID(s)...');
            foreach ($fetchs as $fetch) {
                $id = $fetch['id'];
                $uuid = $this->generator->generate($this->em, null);
                $this->idToUuidMap[$id] = $uuid;
                $this->connection->update($this->table, [$uuidColumnName => $uuid], ['id' => $id]);
            }
        }
    }

    private function addThoseUuidsToTablesWithFK()
    {
        if (0 === count($this->fks)) {
            return;
        }
        $this->write('-> Adding UUIDs to tables with foreign keys...');
        foreach ($this->fks as $fk) {
            $selectPk = implode(',', $fk['primaryKey']);
            $fetchs = $this->connection->fetchAll('SELECT ' . $selectPk . ', ' . $fk['key'] . ' FROM ' . $fk['table']);
            if (count($fetchs) > 0) {
                $this->write('  * Adding ' . count($fetchs) . ' UUIDs to "' . $fk['table'] . '.' . $fk['key'] . '"...');
                foreach ($fetchs as $fetch) {
                    // do something when the value of foreign key is not null
                    if ($fetch[$fk['key']]) {
                        $queryPk = array_flip($fk['primaryKey']);
                        foreach ($queryPk as $key => $value) {
                            $queryPk[$key] = $fetch[$key];
                        }
                        $this->connection->update(
                          $fk['table'],
                          [$fk['tmpKey'] => $this->idToUuidMap[$fetch[$fk['key']]]],
                          $queryPk
                        );
                    }
                }
            }
        }
        foreach ($this->extendedFks as $fk) {
            $selectPk = implode(',', $fk['primaryKey']);
            $fetchs = $this->connection->fetchAll('SELECT ' . $selectPk . ', ' . $fk['key'] . ' FROM ' . $fk['table']);
            if (count($fetchs) > 0) {
                $this->write('  * Adding ' . count($fetchs) . ' UUIDs to "' . $fk['table'] . '.' . $fk['key'] . '"...');
                foreach ($fetchs as $fetch) {
                    // do something when the value of foreign key is not null
                    if ($fetch[$fk['key']]) {
                        $queryPk = array_flip($fk['primaryKey']);
                        foreach ($queryPk as $key => $value) {
                            $queryPk[$key] = $fetch[$key];
                        }
                        $this->connection->update(
                          $fk['table'],
                          [$fk['tmpKey'] => $this->idToUuidMap[$fetch[$fk['key']]]],
                          $queryPk
                        );
                    }
                }
            }
        }
    }

    private function deletePreviousFKs()
    {
        $this->write('-> Deleting previous id foreign keys...');
        foreach ($this->extendedFks as $fk) {
            $this->write('--> Extended');

            $this->doDeletePreviousFKs($fk);
        }

        foreach ($this->fks as $fk) {
            $this->write('--> Normal');

            $this->doDeletePreviousFKs($fk);
        }
    }

    private function doDeletePreviousFKs(array $fk)
    {
        if (isset($fk['primaryKey'])) {
            try {
                // drop primary key if not already dropped
                $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP PRIMARY KEY');
            } catch (\Exception $e) {
            }
        }

        if (isset($fk['uniqueMultipleKey'])) {
            if ($fk['uniqueMultipleKey']['name'] === 'PRIMARY') {
                try {
                    // drop primary key if not already dropped
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP PRIMARY KEY');
                } catch (\Exception $e) {
                }
            } else {
                $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP INDEX ' . $fk['uniqueMultipleKey']['name']);
            }
        }

        $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP FOREIGN KEY ' . $fk['name']);
        $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP COLUMN ' . $fk['key']);
    }

    private function renameNewFKsToPreviousNames()
    {
        $this->write('-> Renaming temporary uuid foreign keys to previous foreign keys names...');
        foreach ($this->fks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' CHANGE ' . $fk['tmpKey'] . ' ' . $fk['key'] . ' CHAR(36) ' . ($fk['nullable'] ? '' : 'NOT NULL ') . 'COMMENT \'(DC2Type:guid)\'');
        }
        foreach ($this->extendedFks as $fk) {
            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' CHANGE ' . $fk['tmpKey'] . ' ' . $fk['key'] . ' CHAR(36) ' . ($fk['nullable'] ? '' : 'NOT NULL ') . 'COMMENT \'(DC2Type:guid)\'');
        }
    }

    private function dropIdPrimaryKeyAndSetUuidToPrimaryKey(string $uuidColumnName)
    {
        $this->write('-> Creating the uuid primary key...');
        $this->connection->executeQuery('ALTER TABLE ' . $this->table . ' DROP PRIMARY KEY, DROP COLUMN id');
        foreach ($this->extendedFks as $fk) {
            try {
                $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' DROP PRIMARY KEY, DROP COLUMN id');
            } catch (\Exception $e) {
            }
        }

        $this->connection->executeQuery('ALTER TABLE ' . $this->table . " CHANGE $uuidColumnName id CHAR(36) NOT NULL COMMENT '(DC2Type:guid)'");
        foreach ($this->extendedFks as $fk) {
            try {
                $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . " CHANGE $uuidColumnName id CHAR(36) NOT NULL COMMENT '(DC2Type:guid)'");
            } catch (\Exception $e) {
            }
        }

        $this->connection->executeQuery('ALTER TABLE ' . $this->table . ' ADD PRIMARY KEY (id)');
        foreach ($this->extendedFks as $fk) {
            try {
                $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD PRIMARY KEY (id)');
            } catch (\Exception $e) {
            }
        }
    }

    private function restoreConstraintsAndIndexes()
    {
        $this->write('-> Restore constrains and indexes...');
        foreach ($this->fks as $fk) {
            if (isset($fk['primaryKey'])) {
                try {
                    // restore primary key if not already restored
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD PRIMARY KEY (' . implode(',', $fk['primaryKey']) . ')');
                } catch (\Exception $e) {
                }
            }

            if (isset($fk['uniqueMultipleKey'])) {
                if ($fk['uniqueMultipleKey']['name'] === 'PRIMARY') {
                    try {
                        $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD PRIMARY KEY (' . implode(', ', $fk['uniqueMultipleKey']['columns']) . ')');
                    } catch (\Exception $e) {
                    }
                } else {
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD UNIQUE ' . $fk['uniqueMultipleKey']['name'] . ' (' . implode(', ', $fk['uniqueMultipleKey']['columns']) . ')');
                }
            }

            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD CONSTRAINT ' . $fk['name'] . ' FOREIGN KEY (' . $fk['key'] . ') REFERENCES ' . $this->table . ' (id)' .
              (isset($fk['onDelete']) ? ' ON DELETE ' . $fk['onDelete'] : '')
            );
            $this->connection->executeQuery('CREATE INDEX ' . str_replace('FK_', 'IDX_', $fk['name']) . ' ON ' . $fk['table'] . ' (' . $fk['key'] . ')');
        }

        foreach ($this->extendedFks as $fk) {
            if (isset($fk['primaryKey'])) {
                try {
                    // restore primary key if not already restored
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD PRIMARY KEY (' . implode(',', $fk['primaryKey']) . ')');
                } catch (\Exception $e) {
                }
            }

            if (isset($fk['uniqueMultipleKey'])) {
                if ($fk['uniqueMultipleKey']['name'] === 'PRIMARY') {
                    try {
                        $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD PRIMARY KEY (' . implode(', ', $fk['uniqueMultipleKey']['columns']) . ')');
                    } catch (\Exception $e) {
                    }
                } else {
                    $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD UNIQUE ' . $fk['uniqueMultipleKey']['name'] . ' (' . implode(', ', $fk['uniqueMultipleKey']['columns']) . ')');
                }
            }

            $this->connection->executeQuery('ALTER TABLE ' . $fk['table'] . ' ADD CONSTRAINT ' . $fk['name'] . ' FOREIGN KEY (' . $fk['key'] . ') REFERENCES ' . $fk['fkTable'] . ' (id)' .
                (isset($fk['onDelete']) ? ' ON DELETE ' . $fk['onDelete'] : '')
            );
            $this->connection->executeQuery('CREATE INDEX ' . str_replace('FK_', 'IDX_', $fk['name']) . ' ON ' . $fk['table'] . ' (' . $fk['key'] . ')');
        }
    }
}
