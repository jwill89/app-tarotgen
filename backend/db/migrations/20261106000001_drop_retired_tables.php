<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Drops the tables the code stopped using before the baseline: the chatless
 * share relay's four (AUDIT §2.1) and the hand-written changelog (§9.13). The
 * one-off scripts dropped them on dev and, by the docs, on production; this
 * makes sure of it on any database the baseline accepts, since the baseline
 * leaves tables it doesn't define alone. Their indexes go with them.
 *
 * Where they are already gone, IF EXISTS makes it a no-op.
 */
final class DropRetiredTables extends AbstractMigration
{
    /** The relay's children before plugin_clients, as the one-off script dropped them. */
    private const array TABLES = ['plugin_messages', 'plugin_blocks', 'plugin_client_identities', 'plugin_clients', 'changelog'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->execute("DROP TABLE IF EXISTS {$table}");
        }
    }

    /** Their rows were dead and are in the backups; a future relay gets a fresh design (AUDIT §2.1). */
    public function down(): never
    {
        throw new IrreversibleMigrationException('The retired tables are not brought back; restore a backup to see their rows.');
    }
}
