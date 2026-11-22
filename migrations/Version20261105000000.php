<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105000000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the complete application schema and seed initial settings and private blacklist';
    }

    #[\Override]
    public function isTransactional(): bool
    {
        // MySQL DDL commits implicitly.
        return false;
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        /** @var array{settings: array<string, string>, blacklisted_ids: list<string>} $data */
        $data = require __DIR__ . '/../config/initial-data.php';
        foreach ($this->applicationSchema()->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
        // DBAL does not model mixed index directions.
        $this->addSql('CREATE INDEX permits_created_fingerprint ON permits (created_at DESC, fingerprint ASC)');
        $this->seed($data);
    }

    /** @param array{settings: array<string, string>, blacklisted_ids: list<string>} $data */
    public function seed(array $data): void
    {
        foreach ($data['settings'] as $key => $value) {
            $this->addSql('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
        }
        foreach ($data['blacklisted_ids'] as $id) {
            $this->addSql(
                'INSERT INTO blacklisted_ids (id_hash, permit_id) VALUES (?, ?)',
                [hash(algo: 'sha256', data: $id), $id],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach ($this->applicationSchema()->toDropSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
    }

    private function applicationSchema(): Schema
    {
        return Schema::editor()->setTables(
            $this->table(
                'app_settings',
                'setting_key',
                $this->column(name: 'setting_key', type: Types::STRING, length: 64),
                $this->column(name: 'setting_value', type: Types::TEXT),
            ),
            // Hash keys make exact ID matching independent of the database collation.
            $this->table(
                'blacklisted_ids',
                'id_hash',
                $this->column(name: 'id_hash', type: Types::STRING, length: 64),
                $this->column(name: 'permit_id', type: Types::TEXT),
                $this->column(name: 'reason', type: Types::TEXT, nullable: true),
            ),
            $this->table(
                'permits',
                'fingerprint',
                $this->column(name: 'fingerprint', type: Types::STRING, length: 64),
                $this->column(name: 'permit_id', type: Types::TEXT),
                $this->column(name: 'plate', type: Types::TEXT),
                $this->column(name: 'apartment', type: Types::TEXT),
                $this->column(name: 'guest', type: Types::BOOLEAN),
                $this->column(name: 'expires_on', type: Types::DATE_IMMUTABLE),
                $this->column(name: 'created_at', type: Types::DATETIME_IMMUTABLE),
                Column::editor()->setUnquotedName('signature_version')->setTypeName(Types::SMALLINT)
                    ->setDefaultValue(1)->create(),
            ),
            Table::editor()->setUnquotedName('admin_sessions')->setColumns(
                Column::editor()->setUnquotedName('token_hash')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('csrf_token')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('authenticated')->setTypeName(Types::BOOLEAN)->create(),
                Column::editor()->setUnquotedName('expires_at')->setTypeName(Types::BIGINT)->create(),
                Column::editor()->setUnquotedName('credential_hash')->setTypeName(Types::STRING)->setLength(64)
                    ->setNotNull(false)
                    ->create(),
            )->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('token_hash')->create())
                ->setIndexes($this->index(name: 'admin_sessions_expires_at', columns: ['expires_at']))->create(),
            Table::editor()->setUnquotedName('admin_login_attempts')->setColumns(
                Column::editor()->setUnquotedName('ip_hash')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('attempts')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('window_started')->setTypeName(Types::BIGINT)->create(),
            )->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('ip_hash')->create())
                ->setIndexes($this->index(name: 'admin_login_attempts_window', columns: ['window_started']))->create(),
            Table::editor()->setUnquotedName('permit_checks')->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('permit_id')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('id_truncated')->setTypeName(Types::BOOLEAN)->create(),
                Column::editor()->setUnquotedName('method')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('path')->setTypeName(Types::STRING)->setLength(32)->create(),
                Column::editor()->setUnquotedName('result')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('reason')->setTypeName(Types::STRING)->setLength(32)
                    ->setNotNull(false)
                    ->create(),
                Column::editor()->setUnquotedName('status')->setTypeName(Types::SMALLINT)->create(),
                Column::editor()->setUnquotedName('checked_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                $this->column(name: 'expires_on', type: Types::DATE_IMMUTABLE, nullable: true),
                $this->column(name: 'apartment', type: Types::STRING, length: 255, nullable: true),
            )->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->setIndexes(
                    $this->index(name: 'permit_checks_result_id', columns: ['result', 'id']),
                    $this->index(name: 'permit_checks_checked_at', columns: ['checked_at']),
                )->create(),
            Table::editor()->setUnquotedName('admin_audit')->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create(),
                $this->column(name: 'actor', type: Types::TEXT),
                $this->column(name: 'action', type: Types::STRING, length: 32),
                $this->column(name: 'target_hash', type: Types::STRING, length: 64),
                $this->column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE),
            )->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                ->setIndexes($this->index(name: 'admin_audit_target', columns: ['target_hash', 'id']))->create(),
        )->create();
    }

    /**
     * @param non-empty-string                 $name
     * @param non-empty-list<non-empty-string> $columns
     */
    private function index(string $name, array $columns): Index
    {
        return Index::editor()->setUnquotedName($name)->setUnquotedColumnNames(...$columns)->create();
    }

    /** @param non-empty-string $name */
    private function column(string $name, string $type, ?int $length = null, bool $nullable = false): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName($type)
            ->setLength($length)->setNotNull(!$nullable)->create();
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $key
     */
    private function table(string $name, string $key, Column $first, Column ...$others): Table
    {
        return Table::editor()->setUnquotedName($name)->setColumns($first, ...$others)
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames($key)->create())->create();
    }
}
