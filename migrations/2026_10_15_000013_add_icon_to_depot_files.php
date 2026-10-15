<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A file's own icon, for the places it shows without a picture. For a Flarum
 * extension it is the one its composer.json declares, as the admin panel shows
 * it. An empty array means "looked, there is none", so nobody asks again.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('depot_files', 'icon')) {
            return;
        }
        $schema->table('depot_files', function (Blueprint $table) {
            $table->json('icon')->nullable()->after('buy_url');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('depot_files', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    },
];
