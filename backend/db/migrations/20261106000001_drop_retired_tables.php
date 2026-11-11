<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * Drops the tables no code uses: the chatless share relay's four and the
 * hand-written changelog. A database the baseline accepts may still hold them,
 * since the baseline leaves tables it doesn't define alone. Their indexes go
 * with them.
 *
 * Where they are already gone, IF EXISTS makes it a no-op.
 */
final class DropRetiredTables extends AbstractMigration
{
    /** The relay's child tables first, then plugin_clients (their parent), then the changelog. */
    private const array TABLES = ['plugin_messages', 'plugin_blocks', 'plugin_client_identities', 'plugin_clients', 'changelog'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->execute("DROP TABLE IF EXISTS {$table}");
        }
    }

    /** Nothing reads the tables' rows, and the backups hold them, so there is nothing to bring back. */
    public function down(): never
    {
        throw new IrreversibleMigrationException('The retired tables are not brought back; restore a backup to see their rows.');
    }
}
