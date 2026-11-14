<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Three indexes were created with names of their own, which Laravel does not
 * prefix: two installs sharing one database (PostgreSQL and SQLite index
 * names are schema-wide) could not both create them. The create migrations no
 * longer name them; this gives an existing install the same generated names.
 *
 * The new index goes in before the old one comes out. On MySQL a foreign key
 * needs an index starting with its column, and the old index may be the one
 * category_id's and file_id's keys are using.
 */
return [
    'up' => function (Builder $schema) {
        $indexes = [
            ['depot_files', ['category_id', 'approved_at', 'released_at'], 'depot_files_browse'],
            ['depot_files', ['approved_at', 'download_count'], 'depot_files_popular'],
            ['depot_download_log', ['file_id', 'fingerprint', 'created_at'], 'depot_download_log_seen'],
        ];

        $prefix = $schema->getConnection()->getTablePrefix();

        foreach ($indexes as [$table, $columns, $old]) {
            $generated = strtolower($prefix.$table.'_'.implode('_', $columns).'_index');

            if (! $schema->hasIndex($table, $generated)) {
                $schema->table($table, fn (Blueprint $t) => $t->index($columns));
            }

            if ($schema->hasIndex($table, $old)) {
                $schema->table($table, fn (Blueprint $t) => $t->dropIndex($old));
            }
        }
    },
    // The old names were the bug; there is nothing to go back to.
    'down' => function (Builder $schema) {
    },
];
