<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * A file sold through Kiosk: its page stays open to everyone, its download
 * only to buyers, its uploader and moderators.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('depot_files', function (Blueprint $table) {
            $table->boolean('paid')->default(false);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('depot_files', function (Blueprint $table) {
            $table->dropColumn('paid');
        });
    },
];
