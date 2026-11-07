<?php

/**
 * Regenerate db/schema.sql from the migrations in db/migrations.
 *
 * Runs every migration on an empty throwaway database and writes what they
 * built (Tarot\Database\Schema::build()). It never opens the local database or
 * reads TAROT_DATA_DIR, so a dev copy that has drifted can't leak into the
 * committed schema. Run it after adding a migration and commit the file with
 * it: the tests load it, and SchemaTest fails while it is out of date.
 *
 * Usage (from backend/):
 *   php scripts/dump_schema.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tarot\Database\Schema;

$sql = Schema::build();
file_put_contents(__DIR__ . '/../db/schema.sql', $sql);

echo 'Wrote ' . preg_match_all('/^CREATE /m', $sql) . " schema objects to db/schema.sql\n";
